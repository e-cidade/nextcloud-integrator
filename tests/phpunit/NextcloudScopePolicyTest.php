<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use OCA\EcidadeLab\Service\ScopePolicy;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2)
    . '/nextcloud-app/ecidadelab/lib/Service/ScopePolicy.php';

final class NextcloudScopePolicyTest extends TestCase
{
    private array $principals;

    protected function setUp(): void
    {
        $this->principals = [
            'synthetic-service-a' => [
                'installation' => 'installation-a','tenant' => 'municipio-a'
            ],
            'synthetic-service-b' => [
                'installation' => 'installation-b','tenant' => 'municipio-b'
            ],
        ];
    }

    public function testMappedServiceCanOnlyAccessItsExactScope(): void
    {
        $scope = ScopePolicy::resolve(
            'synthetic-service-a',
            $this->principals,
            'installation-a',
            'municipio-a'
        );
        self::assertSame('synthetic-service-a', $scope['uid']);
        self::assertSame('installation-a', $scope['installation']);
        self::assertSame('municipio-a', $scope['tenant']);
    }

    public function testTenantHeaderCannotGrantAnotherMunicipality(): void
    {
        $this->expectException(RuntimeException::class);
        ScopePolicy::resolve(
            'synthetic-service-a',
            $this->principals,
            'installation-a',
            'municipio-b'
        );
    }

    public function testInstallationHeaderCannotGrantAnotherInstallation(): void
    {
        $this->expectException(RuntimeException::class);
        ScopePolicy::resolve(
            'synthetic-service-a',
            $this->principals,
            'installation-b',
            'municipio-a'
        );
    }

    public function testAnonymousSessionIsDenied(): void
    {
        $this->expectException(RuntimeException::class);
        ScopePolicy::resolve(null, $this->principals, 'installation-a', 'municipio-a');
    }

    public function testUnmappedAuthenticatedAccountIsDenied(): void
    {
        $this->expectException(RuntimeException::class);
        ScopePolicy::resolve(
            'other-nextcloud-user',
            $this->principals,
            'installation-a',
            'municipio-a'
        );
    }

    public function testEmptyServerBindingsAreDenied(): void
    {
        $this->expectException(RuntimeException::class);
        ScopePolicy::resolve('synthetic-service-a', [], 'installation-a', 'municipio-a');
    }

    public function testInvalidServerMappingIsDenied(): void
    {
        $this->principals['synthetic-service-a']['installation'] = '../escape';
        $this->expectException(RuntimeException::class);
        ScopePolicy::resolve(
            'synthetic-service-a',
            $this->principals,
            '../escape',
            'municipio-a'
        );
    }

    public function testClientCannotChooseAnUnknownAccount(): void
    {
        $this->expectException(RuntimeException::class);
        ScopePolicy::resolve(
            'synthetic-service-b',
            $this->principals,
            'installation-a',
            'municipio-a'
        );
    }

    public function testOpaqueDocumentKeyDeniesTraversal(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ScopePolicy::opaqueKey('../../document.bin');
    }

    public function testOpaqueKeysMustHaveFullCryptographicLength(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ScopePolicy::opaqueKey('012345');
    }

    public function testOpaqueKeyAcceptsValidHexDigest(): void
    {
        $key = str_repeat('a', 64);
        self::assertSame($key, ScopePolicy::opaqueKey($key));
    }
}
