<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use ECidade\Nextcloud\Domain\Scope;
use ECidade\Nextcloud\Transport\NextcloudMvpClient;
use PHPUnit\Framework\TestCase;

final class NextcloudMvpClientTest extends TestCase
{
    private function scope(): Scope
    {
        return new Scope('installation-a', 'municipio-a');
    }

    private function settings(): array
    {
        return [
            'url' => 'https://nextcloud.example.invalid/ocs/v2.php/apps/ecidadelab/api/v2',
            'user' => 'synthetic-service-a',
            'token' => 'synthetic-token-not-a-credential',
            'ca_file' => __FILE__,
        ];
    }

    public function testAcceptsHttpsWithCertificateAndFixedApiPath(): void
    {
        self::assertInstanceOf(
            NextcloudMvpClient::class,
            new NextcloudMvpClient($this->scope(), $this->settings())
        );
    }

    public function testRejectsPlainHttpEvenForTests(): void
    {
        $config = $this->settings();
        $config['url'] = str_replace('https:', 'http:', $config['url']);
        $this->expectException(InvalidArgumentException::class);
        new NextcloudMvpClient($this->scope(), $config);
    }

    public function testRejectsUserInfoInUrl(): void
    {
        $config = $this->settings();
        $config['url'] = 'https://user@example.invalid/ocs/v2.php/apps/ecidadelab/api/v2';
        $this->expectException(InvalidArgumentException::class);
        new NextcloudMvpClient($this->scope(), $config);
    }

    public function testRejectsUnapprovedPath(): void
    {
        $config = $this->settings();
        $config['url'] = 'https://nextcloud.example.invalid/ocs/v2.php/apps/otherapp/api/v2';
        $this->expectException(InvalidArgumentException::class);
        new NextcloudMvpClient($this->scope(), $config);
    }

    public function testRejectsInvalidCaFile(): void
    {
        $config = $this->settings();
        $config['ca_file'] = '/nonexistent/synthetic-certificate';
        $this->expectException(InvalidArgumentException::class);
        new NextcloudMvpClient($this->scope(), $config);
    }

    public function testRejectsEmptyToken(): void
    {
        $config = $this->settings();
        $config['token'] = '';
        $this->expectException(InvalidArgumentException::class);
        new NextcloudMvpClient($this->scope(), $config);
    }

    public function testRejectsCrossTenantBeforeNetworkOrFileAccess(): void
    {
        $client = new NextcloudMvpClient($this->scope(), $this->settings());
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('scope mismatch');
        $client->download(
            new Scope('installation-b', 'municipio-b'),
            str_repeat('a', 64),
            str_repeat('b', 64)
        );
    }

    public function testRejectsInvalidOpaqueDocumentKeyBeforeNetwork(): void
    {
        $client = new NextcloudMvpClient($this->scope(), $this->settings());
        $this->expectException(InvalidArgumentException::class);
        $client->download($this->scope(), '../escape', str_repeat('b', 64));
    }

    public function testDisabledByDefaultUnlessExplicitlyEnabled(): void
    {
        $prior = getenv('ECIDADE_MVP_EXPERIMENTAL');
        putenv('ECIDADE_MVP_EXPERIMENTAL=0');
        try {
            $this->expectException(RuntimeException::class);
            NextcloudMvpClient::fromEnvironment();
        } finally {
            if ($prior === false) {
                putenv('ECIDADE_MVP_EXPERIMENTAL');
            } else {
                putenv('ECIDADE_MVP_EXPERIMENTAL='.$prior);
            }
        }
    }
}
