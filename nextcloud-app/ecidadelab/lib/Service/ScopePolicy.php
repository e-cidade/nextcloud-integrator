<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\EcidadeLab\Service;

/**
 * Authorization from the Nextcloud authenticated UID and an ADMIN-DEFINED
 * configuration map. Request headers only corroborate, never authorize.
 *
 * Completely independent from Nextcloud internals for deterministic tests.
 */
final class ScopePolicy
{
    public static function resolve(
        ?string $authenticatedUid,
        mixed $principals,
        string $requestedInstallation,
        string $requestedTenant
    ): array {
        if ($authenticatedUid === null || $authenticatedUid === ''
            || !is_array($principals) || !isset($principals[$authenticatedUid])
            || !is_array($principals[$authenticatedUid])) {
            throw new \RuntimeException('Unauthorized document principal');
        }
        $entry = $principals[$authenticatedUid];
        if (!isset($entry['installation'], $entry['tenant'])
            || !is_string($entry['installation']) || !is_string($entry['tenant'])
            || !self::slug($entry['installation']) || !self::slug($entry['tenant'])) {
            throw new \RuntimeException('Invalid server-side document principal mapping');
        }
        if (!hash_equals($entry['installation'], $requestedInstallation)
            || !hash_equals($entry['tenant'], $requestedTenant)) {
            throw new \RuntimeException('Document scope forbidden');
        }
        return ['uid' => $authenticatedUid,
            'installation' => $entry['installation'], 'tenant' => $entry['tenant']];
    }

    public static function opaqueKey(string $key): string
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $key)) {
            throw new \InvalidArgumentException('Invalid opaque document key');
        }
        return $key;
    }

    private static function slug(string $value): bool
    {
        return (bool)preg_match('/^[a-z][a-z0-9_-]{2,63}$/D', $value);
    }
}
