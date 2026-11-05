<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use ECidade\Nextcloud\DocumentClient;
use PHPUnit\Framework\TestCase;

final class DocumentClientTest extends TestCase
{
    private function config(): array
    {
        return [
            'url' => 'https://nextcloud.example.invalid/ocs/v2.php/apps/ecidadelab/api/v1/documents',
            'user' => 'service-account',
            'token' => 'synthetic-test-token',
            'tenant' => 'municipio-a',
            'ca_file' => __FILE__,
            'name' => 'documento-teste.pdf',
            'business' => 'processo-123',
            'http_host' => '',
        ];
    }

    public function testAcceptsValidHttpsConfiguration(): void
    {
        self::assertInstanceOf(DocumentClient::class, new DocumentClient($this->config()));
    }

    public function testRejectsHttpTransport(): void
    {
        $config = $this->config();
        $config['url'] = 'http://localhost:8080/';
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('HTTPS');
        new DocumentClient($config);
    }

    public function testRequiresTrustedCertificate(): void
    {
        $config = $this->config();
        $config['ca_file'] = '/missing/certificate.pem';
        $this->expectException(RuntimeException::class);
        new DocumentClient($config);
    }

    public function testRejectsTenantWithPathTraversal(): void
    {
        $config = $this->config();
        $config['tenant'] = '../../municipio-b';
        $this->expectException(RuntimeException::class);
        new DocumentClient($config);
    }

    public function testRejectsFilenameWithSlash(): void
    {
        $config = $this->config();
        $config['name'] = '../../documento.pdf';
        $this->expectException(RuntimeException::class);
        new DocumentClient($config);
    }

    public function testRejectsHeaderInjection(): void
    {
        $config = $this->config();
        $config['business'] = "record\r\nAuthorization: Basic malicious";
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Control characters');
        new DocumentClient($config);
    }

    public function testRefusesMissingAuthenticationToken(): void
    {
        $config = $this->config();
        $config['token'] = '';
        $this->expectException(RuntimeException::class);
        new DocumentClient($config);
    }

    public function testRefusesZeroDocumentIdBeforeTransport(): void
    {
        $client = new DocumentClient($this->config());
        $this->expectException(InvalidArgumentException::class);
        $client->inspect(0);
    }

    public function testRefusesInvalidDocumentIdBeforeTransport(): void
    {
        $client = new DocumentClient($this->config());
        $this->expectException(InvalidArgumentException::class);
        $client->read('../admin');
    }

    public function testRefusesNonStringUpload(): void
    {
        $client = new DocumentClient($this->config());
        $this->expectException(InvalidArgumentException::class);
        $client->write(1, ['not bytes']);
    }
}
