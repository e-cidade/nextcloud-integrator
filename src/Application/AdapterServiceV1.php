<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace ECidade\Nextcloud\Application;

use ECidade\Nextcloud\Contract\AdapterStorageV1;
use ECidade\Nextcloud\Contract\DocumentAccessPolicy;
use ECidade\Nextcloud\Contract\DocumentLedger;
use ECidade\Nextcloud\Domain\AdapterContext;
use ECidade\Nextcloud\Domain\AdapterFailure;
use ECidade\Nextcloud\Domain\OperationKey;

/**
 * One explicitly injected SPI facade for ALL forks. Never creates transport,
 * SQL connections, worker, credentials, or transactions on its own.
 */
final class AdapterServiceV1 implements AdapterStorageV1
{
    const VERSION = 1;

    private $coordinator;
    private $ledger;
    private $policy;
    private $backend;
    private $capabilities;

    public function __construct(
        DocumentCoordinator $coordinator,
        DocumentLedger $ledger,
        DocumentAccessPolicy $policy,
        array $manifest
    ) {
        if (!isset($manifest['id'], $manifest['storage_capabilities'])
            || !is_string($manifest['id']) || !is_array($manifest['storage_capabilities'])) {
            throw new AdapterFailure(AdapterFailure::UNSUPPORTED);
        }
        if (!preg_match('/^[a-z][a-z0-9_-]{1,40}$/D', $manifest['id'])
            || !empty($manifest['production_ready'])) {
            throw new AdapterFailure(AdapterFailure::UNSUPPORTED);
        }
        $this->backend = $manifest['id'];
        $this->capabilities = [];
        foreach ($manifest['storage_capabilities'] as $cap) {
            if (!is_array($cap) || !isset($cap['surface'], $cap['usage'], $cap['spi'], $cap['operations'])
                || !is_string($cap['surface']) || !is_array($cap['operations'])
                || $cap['spi'] !== self::VERSION) {
                throw new AdapterFailure(AdapterFailure::UNSUPPORTED);
            }
            if (isset($this->capabilities[$cap['surface']])) {
                throw new AdapterFailure(AdapterFailure::INVALID);
            }
            $this->capabilities[$cap['surface']] = $cap;
        }
        $this->coordinator = $coordinator;
        $this->ledger = $ledger;
        $this->policy = $policy;
    }

    private function check(AdapterContext $context, $operation, $legacyId = null)
    {
        if ($context->backend() !== $this->backend) {
            throw new AdapterFailure(AdapterFailure::DENIED);
        }
        $surface = $context->surface();
        if (!isset($this->capabilities[$surface])) {
            throw new AdapterFailure(AdapterFailure::UNSUPPORTED);
        }
        $cap = $this->capabilities[$surface];
        if ($cap['usage'] !== 'persistent-document' || !in_array($operation, $cap['operations'], true)) {
            throw new AdapterFailure(AdapterFailure::UNSUPPORTED);
        }
        // E-Storage does not share DBLargeObject's implicit parent/allowed
        // semantics. A legacy caller MUST present mapped metadata and the
        // trusted policy must verify both against authoritative ERP state.
        if ($surface === 'e-storage') {
            $attrs = $context->attributes();
            if (!isset($attrs['allowed'], $attrs['file_father'])
                || !is_array($attrs['allowed']) || count($attrs['allowed']) === 0
                || !is_string($attrs['file_father']) || $attrs['file_father'] === '') {
                throw new AdapterFailure(AdapterFailure::DENIED);
            }
        }
        try {
            $allowed = $this->policy->allows($context, $operation, $legacyId);
        } catch (\Exception $error) {
            throw new AdapterFailure(AdapterFailure::DENIED, $error);
        }
        if ($allowed !== true) {
            throw new AdapterFailure(AdapterFailure::DENIED);
        }
    }

    private function prefix(AdapterContext $context)
    {
        return 'spi1:' . $context->backend() . ':' . $context->surface() . ':';
    }

    private function binding(AdapterContext $context, $legacyId)
    {
        try {
            $row = $this->ledger->find($context->scope(), $legacyId);
        } catch (\InvalidArgumentException $error) {
            throw new AdapterFailure(AdapterFailure::INVALID, $error);
        } catch (\Exception $error) {
            throw new AdapterFailure(AdapterFailure::NOT_FOUND, $error);
        }
        if (!isset($row['business_key']) || strpos($row['business_key'], $this->prefix($context)) !== 0) {
            // Historical or other-backend IDs require explicit migration.
            throw new AdapterFailure(AdapterFailure::NOT_FOUND);
        }
        return $row;
    }

    private function transaction()
    {
        try {
            $this->ledger->assertBusinessTransaction();
        } catch (\Exception $error) {
            throw new AdapterFailure(AdapterFailure::TRANSACTION_REQUIRED, $error);
        }
    }

    public function reserve(AdapterContext $context, $businessKey)
    {
        $this->check($context, 'reserve');
        $this->transaction();
        if (!is_string($businessKey) || $businessKey === '' || strlen($businessKey) > 120
            || preg_match('/[\x00-\x1f\x7f]/', $businessKey)
            || strlen($this->prefix($context) . $businessKey) > 120) {
            throw new AdapterFailure(AdapterFailure::INVALID);
        }
        try {
            $ref = $this->ledger->reserveOnce($context->scope(), $this->prefix($context) . $businessKey);
        } catch (\Exception $error) {
            throw new AdapterFailure(AdapterFailure::STORAGE_FAILURE, $error);
        }
        return ['legacy_id' => $ref['legacy_id'], 'revision' => isset($ref['current_revision']) ? (int)$ref['current_revision'] : 0];
    }

    private function write(AdapterContext $context, $operation, $legacyId, $bytes, $operationKey)
    {
        $this->check($context, $operation, $legacyId);
        $this->transaction();
        try {
            $key = OperationKey::validate($operationKey);
        } catch (\InvalidArgumentException $error) {
            throw new AdapterFailure(AdapterFailure::INVALID, $error);
        }
        if (!is_string($bytes)) {
            throw new AdapterFailure(AdapterFailure::INVALID);
        }
        $row = $this->binding($context, $legacyId);
        $existing = $this->ledger->findWriteOperation($context->scope(), $legacyId, $key);
        if ($existing !== null
            && ($existing['checksum'] !== hash('sha256', $bytes)
                || (int)$existing['byte_count'] !== strlen($bytes))) {
            throw new AdapterFailure(AdapterFailure::CONFLICT);
        }
        if ($existing === null
            && (($operation === 'create' && (int)$row['current_revision'] !== 0)
                || ($operation === 'update' && (int)$row['current_revision'] === 0))) {
            throw new AdapterFailure(AdapterFailure::CONFLICT);
        }
        try {
            $receipt = $this->coordinator->write($context->scope(), $legacyId, $bytes, $key);
        } catch (\InvalidArgumentException $error) {
            throw new AdapterFailure(AdapterFailure::INVALID, $error);
        } catch (\Exception $error) {
            throw new AdapterFailure(AdapterFailure::STORAGE_FAILURE, $error);
        }
        return [
            'legacy_id' => $legacyId,
            'revision' => (int)$receipt['revision'],
            'state' => $receipt['state'],
            'operation_key' => $key,
        ];
    }

    public function create(AdapterContext $context, $legacyId, $bytes, $operationKey)
    {
        return $this->write($context, 'create', $legacyId, $bytes, $operationKey);
    }

    public function update(AdapterContext $context, $legacyId, $bytes, $operationKey)
    {
        return $this->write($context, 'update', $legacyId, $bytes, $operationKey);
    }

    public function read(AdapterContext $context, $legacyId)
    {
        $this->check($context, 'read', $legacyId);
        $this->binding($context, $legacyId);
        try {
            return $this->coordinator->read($context->scope(), $legacyId);
        } catch (\Exception $error) {
            throw new AdapterFailure(AdapterFailure::STORAGE_FAILURE, $error);
        }
    }

    public function delete(AdapterContext $context, $legacyId)
    {
        $this->check($context, 'delete', $legacyId);
        $this->transaction();
        $this->binding($context, $legacyId);
        try {
            return $this->coordinator->delete($context->scope(), $legacyId);
        } catch (\Exception $error) {
            throw new AdapterFailure(AdapterFailure::STORAGE_FAILURE, $error);
        }
    }

    public function inspect(AdapterContext $context, $legacyId)
    {
        $this->check($context, 'inspect', $legacyId);
        $row = $this->binding($context, $legacyId);
        return [
            'legacy_id' => $legacyId,
            'revision' => (int)$row['current_revision'],
            'pending_revision' => $row['pending_revision'] === null ? null : (int)$row['pending_revision'],
            'deleted' => $row['deleted_at'] !== null,
        ];
    }
}
