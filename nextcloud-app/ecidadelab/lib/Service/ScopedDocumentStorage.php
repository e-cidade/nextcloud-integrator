<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\EcidadeLab\Service;

use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\FilesMetadata\IFilesMetadataManager;

/**
 * Immutable-version document storage scoped to an authenticated service user.
 * All access is confined to the user's OWN Nextcloud folder.
 *
 * This does not manage PostgreSQL transactions and is disabled by default.
 */
final class ScopedDocumentStorage
{
    private const BASE = 'ECidadeMvp';
    private const MAX_BYTES = 8388608;

    public function __construct(
        private IRootFolder $root,
        private IFilesMetadataManager $metadataManager
    ) {
    }

    private function folder(array $scope, bool $create): Folder
    {
        $home = $this->root->getUserFolder($scope['uid']);
        $path = '';
        foreach ([self::BASE, $scope['installation'], $scope['tenant']] as $segment) {
            $path = $path === '' ? $segment : $path . '/' . $segment;
            if (!$home->nodeExists($path)) {
                if (!$create) {
                    throw new OCSNotFoundException('Document not found');
                }
                $home->newFolder($path);
            }
        }
        $result = $home->get($path);
        if (!$result instanceof Folder) {
            throw new OCSForbiddenException('Document storage folder is invalid');
        }
        return $result;
    }

    private function documentFolder(array $scope, string $documentKey, bool $create): Folder
    {
        $documentKey = ScopePolicy::opaqueKey($documentKey);
        $folder = $this->folder($scope, $create);
        if (!$folder->nodeExists($documentKey)) {
            if (!$create) {
                throw new OCSNotFoundException('Document not found');
            }
            $folder->newFolder($documentKey);
        }
        $doc = $folder->get($documentKey);
        if (!$doc instanceof Folder) {
            throw new OCSForbiddenException('Document container is invalid');
        }
        return $doc;
    }

    private function file(
        array $scope,
        string $documentKey,
        string $operationKey
    ): File {
        $operationKey = ScopePolicy::opaqueKey($operationKey);
        $dir = $this->documentFolder($scope, $documentKey, false);
        if (!$dir->nodeExists($operationKey . '.bin')) {
            throw new OCSNotFoundException('Document version not found');
        }
        $file = $dir->get($operationKey . '.bin');
        if (!$file instanceof File) {
            throw new OCSNotFoundException('Document version not found');
        }
        $this->details($file, $scope, $documentKey, $operationKey);
        return $file;
    }

    private function scopeDigest(array $scope): string
    {
        return hash('sha256', $scope['installation'] . "\0" . $scope['tenant']);
    }

    private function details(
        File $file,
        array $scope,
        string $documentKey,
        string $operationKey
    ): array {
        $meta = $this->metadataManager->getMetadata($file->getId());
        $keys = ['ecidade-mvp-scope', 'ecidade-mvp-document',
            'ecidade-mvp-operation', 'ecidade-mvp-state',
            'ecidade-mvp-checksum', 'ecidade-mvp-size', 'ecidade-mvp-created'];
        foreach ($keys as $key) {
            if (!$meta->hasKey($key)) {
                throw new OCSForbiddenException('Incomplete document metadata');
            }
        }
        if (!hash_equals($this->scopeDigest($scope), $meta->getString('ecidade-mvp-scope'))
            || !hash_equals($documentKey, $meta->getString('ecidade-mvp-document'))
            || !hash_equals($operationKey, $meta->getString('ecidade-mvp-operation'))) {
            throw new OCSForbiddenException('Document scope mismatch');
        }
        return [
            'id' => $file->getId(),
            'state' => $meta->getString('ecidade-mvp-state'),
            'checksum' => $meta->getString('ecidade-mvp-checksum'),
            'size' => $meta->getInt('ecidade-mvp-size'),
            'created_at' => $meta->getInt('ecidade-mvp-created'),
        ];
    }

    private function metadata(
        File $file,
        array $scope,
        string $documentKey,
        string $operationKey,
        string $content
    ): void {
        $metadata = $this->metadataManager->getMetadata($file->getId(), true);
        $metadata->setString('ecidade-mvp-scope', $this->scopeDigest($scope));
        $metadata->setString('ecidade-mvp-document', $documentKey);
        $metadata->setString('ecidade-mvp-operation', $operationKey);
        $metadata->setString('ecidade-mvp-state', 'staged');
        $metadata->setString('ecidade-mvp-checksum', hash('sha256', $content));
        $metadata->setInt('ecidade-mvp-size', strlen($content));
        $metadata->setInt('ecidade-mvp-created', time());
        $this->metadataManager->saveMetadata($metadata);
    }

    public function stage(
        array $scope,
        string $documentKey,
        string $operationKey,
        string $content
    ): array {
        ScopePolicy::opaqueKey($documentKey);
        ScopePolicy::opaqueKey($operationKey);
        if (strlen($content) > self::MAX_BYTES) {
            throw new OCSBadRequestException('Document exceeds experimental size limit');
        }

        $folder = $this->documentFolder($scope, $documentKey, true);
        $filename = $operationKey . '.bin';
        if ($folder->nodeExists($filename)) {
            // Strict idempotency: no second write if the key already exists.
            $file = $this->file($scope, $documentKey, $operationKey);
            $details = $this->details($file, $scope, $documentKey, $operationKey);
            if (!hash_equals($details['checksum'], hash('sha256', $content))
                || $details['size'] !== strlen($content)) {
                throw new OCSBadRequestException('Idempotency key already has different bytes');
            }
            return $details;
        }

        $file = $folder->newFile($filename, $content);
        try {
            $this->metadata($file, $scope, $documentKey, $operationKey, $content);
        } catch (\Throwable $error) {
            // An uncommitted file with missing metadata is not allowed to
            // become readable or be mistaken for a committed document.
            $file->delete();
            throw $error;
        }
        return $this->details($file, $scope, $documentKey, $operationKey);
    }

    public function finalize(array $scope, string $documentKey, string $operationKey): array
    {
        $file = $this->file($scope, $documentKey, $operationKey);
        $details = $this->details($file, $scope, $documentKey, $operationKey);
        if ($details['state'] === 'committed') {
            return $details; // Idempotent retry after worker crash.
        }
        if ($details['state'] !== 'staged') {
            throw new OCSForbiddenException('Document cannot be finalized');
        }
        $bytes = $file->getContent();
        if (strlen($bytes) !== $details['size']
            || !hash_equals($details['checksum'], hash('sha256', $bytes))) {
            throw new OCSForbiddenException('Staged document integrity verification failed');
        }
        $metadata = $this->metadataManager->getMetadata($file->getId(), true);
        $metadata->setString('ecidade-mvp-state', 'committed');
        $this->metadataManager->saveMetadata($metadata);
        return $this->details($file, $scope, $documentKey, $operationKey);
    }

    public function download(array $scope, string $documentKey, string $operationKey): string
    {
        $file = $this->file($scope, $documentKey, $operationKey);
        $details = $this->details($file, $scope, $documentKey, $operationKey);
        if ($details['state'] !== 'committed') {
            throw new OCSForbiddenException('Document version is not committed');
        }
        $bytes = $file->getContent();
        if (!hash_equals($details['checksum'], hash('sha256', $bytes))
            || $details['size'] !== strlen($bytes)) {
            throw new \RuntimeException('Document checksum verification failed');
        }
        return $bytes;
    }

    public function downloadPending(array $scope, string $documentKey, string $operationKey): string
    {
        $file = $this->file($scope, $documentKey, $operationKey);
        $details = $this->details($file, $scope, $documentKey, $operationKey);
        if (!in_array($details['state'], ['staged', 'committed'], true)) {
            throw new OCSForbiddenException('Document version is unavailable');
        }
        $bytes = $file->getContent();
        if (!hash_equals($details['checksum'], hash('sha256', $bytes))
            || $details['size'] !== strlen($bytes)) {
            throw new \RuntimeException('Document checksum verification failed');
        }
        return $bytes;
    }

    public function inspect(array $scope, string $documentKey, string $operationKey): array
    {
        $file = $this->file($scope, $documentKey, $operationKey);
        return $this->details($file, $scope, $documentKey, $operationKey);
    }

    public function abort(array $scope, string $documentKey, string $operationKey): void
    {
        ScopePolicy::opaqueKey($documentKey);
        ScopePolicy::opaqueKey($operationKey);
        try {
            $file = $this->file($scope, $documentKey, $operationKey);
        } catch (OCSNotFoundException $missing) {
            return; // Safe idempotency on already-absent staged versions.
        }
        $details = $this->details($file, $scope, $documentKey, $operationKey);
        if ($details['state'] !== 'staged' || $details['created_at'] > time() - 86400) {
            throw new OCSForbiddenException('Only stale staged versions can be aborted');
        }
        $file->delete();
    }

    public function listStaged(array $scope, int $before, int $limit): array
    {
        if ($before > time() - 86400 || $limit < 1 || $limit > 100) {
            throw new OCSBadRequestException('Unsafe staged inventory window');
        }
        $result = [];
        try {
            $folder = $this->folder($scope, false);
        } catch (OCSNotFoundException $missing) {
            return [];
        }
        foreach ($folder->getDirectoryListing() as $docFolder) {
            if (!$docFolder instanceof Folder
                || !preg_match('/^[a-f0-9]{64}$/D', $docFolder->getName())) {
                continue;
            }
            foreach ($docFolder->getDirectoryListing() as $node) {
                if (!$node instanceof File || !preg_match('/^[a-f0-9]{64}\.bin$/D', $node->getName())) {
                    continue;
                }
                $op = substr($node->getName(), 0, 64);
                $info = $this->details($node, $scope, $docFolder->getName(), $op);
                if ($info['state'] !== 'staged' || $info['created_at'] >= $before) {
                    continue;
                }
                $result[] = [
                    'document_key' => $docFolder->getName(),
                    'operation_key' => $op,
                    'created_at' => $info['created_at']
                ];
                if (count($result) >= $limit) {
                    return $result;
                }
            }
        }
        return $result;
    }
}
