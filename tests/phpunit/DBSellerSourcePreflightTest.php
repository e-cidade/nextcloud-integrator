<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/modules/dbseller/SourcePreflight.php';

use ECidade\Nextcloud\DBSeller\SourcePreflight;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DBSellerSourcePreflightTest extends TestCase
{
    private string $checkout;

    protected function setUp(): void
    {
        $this->checkout = sys_get_temp_dir() . '/ecidade-source-' . bin2hex(random_bytes(12));
        self::assertTrue(mkdir($this->checkout, 0700));
        self::assertSame(0, $this->git('init -q'));
        self::assertTrue(mkdir($this->checkout . '/ecidade/std', 0700, true));
        file_put_contents($this->checkout . '/ecidade/std/DBLargeObject.php', '<?php class DBLargeObject {}');
        self::assertSame(0, $this->git('add .'));
        self::assertSame(0, $this->git('-c user.name=Fixture -c user.email=fixture@example.invalid commit -qm fixture'));
    }

    protected function tearDown(): void
    {
        $this->remove($this->checkout);
    }

    private function git(string $arguments): int
    {
        exec('git -C ' . escapeshellarg($this->checkout) . ' ' . $arguments . ' 2>/dev/null', $output, $status);
        return $status;
    }

    private function manifest(): array
    {
        $revision = trim((string) shell_exec('git -C ' . escapeshellarg($this->checkout) . ' rev-parse HEAD'));
        return [
            'upstream' => 'e-cidade/e-cidade-DBSeller@' . $revision,
            'hooks' => [
                [
                    'source' => [
                        'path' => 'ecidade/std/DBLargeObject.php',
                        'git_blob' => SourcePreflight::blobId('<?php class DBLargeObject {}'),
                    ],
                ],
            ],
        ];
    }

    public function testPinnedPublicCheckoutWithMatchingBlobIsAccepted(): void
    {
        $result = SourcePreflight::verify($this->checkout, $this->manifest());
        self::assertCount(1, $result['verified_hooks']);
        self::assertSame('ecidade/std/DBLargeObject.php', $result['verified_hooks'][0]['path']);
    }

    public static function invalidContracts(): iterable
    {
        yield 'unexpected upstream repository' => ['upstream', 'example/another-fork@'];
        yield 'missing source hook' => ['hooks', []];
        yield 'path traversal' => ['path', 'ecidade/../secrets.php'];
        yield 'incorrect blob' => ['blob', str_repeat('0', 40)];
        yield 'missing source' => ['path', 'ecidade/std/Unknown.php'];
    }

    #[DataProvider('invalidContracts')]
    public function testInvalidContractsFailClosed(string $field, mixed $value): void
    {
        $manifest = $this->manifest();
        if ($field === 'upstream') {
            $manifest['upstream'] = $value . substr($manifest['upstream'], -40);
        } elseif ($field === 'hooks') {
            $manifest['hooks'] = $value;
        } elseif ($field === 'blob') {
            $manifest['hooks'][0]['source']['git_blob'] = $value;
        } else {
            $manifest['hooks'][0]['source']['path'] = $value;
        }
        $this->expectException(RuntimeException::class);
        SourcePreflight::verify($this->checkout, $manifest);
    }

    public function testUnpinnedCheckoutRevisionIsRejected(): void
    {
        $manifest = $this->manifest();
        file_put_contents($this->checkout . '/unrelated.txt', 'next commit');
        self::assertSame(0, $this->git('add .'));
        self::assertSame(0, $this->git('-c user.name=Fixture -c user.email=fixture@example.invalid commit -qm next'));
        $this->expectException(RuntimeException::class);
        SourcePreflight::verify($this->checkout, $manifest);
    }

    public function testProductionAndUnsupportedCapabilitiesRemainDisabled(): void
    {
        $manifest = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/modules/dbseller/module.json'), true);
        self::assertFalse($manifest['production_ready']);
        self::assertNotContains('estorage-adapter', $manifest['capabilities']);
    }

    private function remove(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
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
