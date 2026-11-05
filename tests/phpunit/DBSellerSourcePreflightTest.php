<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use ECidade\Nextcloud\AdapterRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DBSellerSourcePreflightTest extends TestCase
{
    private string $fixture;
    private string $checkout;
    private AdapterRegistry $registry;

    protected function setUp(): void
    {
        $this->fixture = sys_get_temp_dir() . '/ecidade-dbseller-' . bin2hex(random_bytes(12));
        $this->checkout = $this->fixture . '/public';
        self::assertTrue(mkdir($this->checkout . '/ecidade/std', 0700, true));
        self::assertTrue(mkdir($this->fixture . '/modules/dbseller', 0700, true));
        self::assertSame(0, $this->git('init -q'));
        file_put_contents($this->checkout . '/ecidade/std/DBLargeObject.php', '<?php class DBLargeObject {}');
        self::assertSame(0, $this->git('add .'));
        self::assertSame(0, $this->git('-c user.name=Fixture -c user.email=fixture@example.invalid commit -qm fixture'));
        file_put_contents($this->fixture . '/modules/dbseller/DBLargeObject.php', '<?php // shim');
        $this->saveManifest($this->manifest());
        $this->registry = new AdapterRegistry($this->fixture);
    }

    protected function tearDown(): void
    {
        $this->remove($this->fixture);
    }

    private function git(string $arguments): int
    {
        exec('git -C ' . escapeshellarg($this->checkout) . ' ' . $arguments . ' 2>/dev/null', $output, $status);
        return $status;
    }

    private function revision(): string
    {
        return trim((string) shell_exec('git -C ' . escapeshellarg($this->checkout) . ' rev-parse HEAD'));
    }

    private function manifest(): array
    {
        $bytes = (string) file_get_contents($this->checkout . '/ecidade/std/DBLargeObject.php');
        return [
            'schema' => 2,
            'id' => 'dbseller',
            'name' => 'DBSeller',
            'version' => '0.1.0-lab',
            'tested' => true,
            'min_php' => '5.6.4',
            'upstream' => 'e-cidade/e-cidade-DBSeller@' . $this->revision(),
            'capabilities' => ['large-object-facade'],
            'gaps' => ['estorage-adapter'],
            'certification' => 'bootstrap-contract-lab',
            'production_ready' => false,
            'hooks' => [
                [
                    'type' => 'php-file-override',
                    'source' => [
                        'path' => 'ecidade/std/DBLargeObject.php',
                        'git_blob' => sha1('blob ' . strlen($bytes) . "\0" . $bytes),
                    ],
                    'target' => 'std/DBLargeObject.php',
                    'replacement' => 'DBLargeObject.php',
                    'contract' => 'DBLargeObject',
                ],
            ],
        ];
    }

    private function saveManifest(array $manifest): void
    {
        file_put_contents($this->fixture . '/modules/dbseller/module.json', json_encode($manifest));
    }

    public function testSharedRegistryValidatesDbSellerRevisionAndBlob(): void
    {
        $result = $this->registry->verifyPinnedCheckout('dbseller', $this->checkout);
        self::assertSame($this->revision(), $result['revision']);
        self::assertArrayHasKey('ecidade/std/DBLargeObject.php', $result['verified_hooks']);
    }

    public static function invalidContracts(): iterable
    {
        yield 'wrong repository pin' => ['upstream', 'other/e-cidade-DBSeller'];
        yield 'wrong revision' => ['upstream', 'e-cidade/e-cidade-DBSeller@' . '0'];
        yield 'wrong source blob' => ['git_blob', str_repeat('0', 40)];
        yield 'unsafe source path' => ['path', 'ecidade/../secrets.php'];
        yield 'missing source file' => ['path', 'ecidade/std/NotPresent.php'];
    }

    #[DataProvider('invalidContracts')]
    public function testSharedRegistryRejectsInvalidContracts(string $field, string $value): void
    {
        $manifest = $this->manifest();
        if ($field === 'upstream') {
            $manifest['upstream'] = $value;
        } else {
            $manifest['hooks'][0]['source'][$field] = $value;
        }
        $this->saveManifest($manifest);
        $this->expectException(RuntimeException::class);
        $this->registry->verifyPinnedCheckout('dbseller', $this->checkout);
    }

    public function testUnrecognizedCheckoutRevisionIsRejected(): void
    {
        file_put_contents($this->checkout . '/later.txt', 'unexpected change');
        self::assertSame(0, $this->git('add .'));
        self::assertSame(0, $this->git('-c user.name=Fixture -c user.email=fixture@example.invalid commit -qm later'));
        $this->expectException(RuntimeException::class);
        $this->registry->verifyPinnedCheckout('dbseller', $this->checkout);
    }

    public function testPublicDbSellerManifestDoesNotClaimUnsupportedCapabilities(): void
    {
        $manifest = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/modules/dbseller/module.json'), true);
        self::assertFalse($manifest['production_ready']);
        self::assertNotContains('estorage-adapter', $manifest['capabilities']);
        self::assertContains('large-object-facade', $manifest['capabilities']);
    }

    private function remove(string $directory): void
    {
        foreach (new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS) as $item) {
            if ($item->isDir() && !$item->isLink()) {
                $this->remove($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($directory);
    }
}
