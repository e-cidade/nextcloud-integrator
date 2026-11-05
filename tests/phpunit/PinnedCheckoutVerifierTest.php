<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use ECidade\Nextcloud\PinnedCheckoutVerifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PinnedCheckoutVerifierTest extends TestCase
{
    private string $checkout;

    protected function setUp(): void
    {
        $this->checkout = sys_get_temp_dir() . '/ecidade-revision-' . bin2hex(random_bytes(12));
        self::assertTrue(mkdir($this->checkout, 0700));
        self::assertSame(0, $this->git('init -q'));
        file_put_contents($this->checkout . '/fixture.php', '<?php // test');
        self::assertSame(0, $this->git('add fixture.php'));
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

    private function upstream(): string
    {
        $hash = trim((string) shell_exec('git -C ' . escapeshellarg($this->checkout) . ' rev-parse HEAD'));
        return 'e-cidade/e-cidade-DBSeller@' . $hash;
    }

    public function testPinnedRevisionAccepted(): void
    {
        self::assertSame(substr($this->upstream(), -40), PinnedCheckoutVerifier::verify($this->checkout, $this->upstream()));
    }

    public static function invalidPins(): iterable
    {
        yield 'no repository namespace' => ['bad-repo@'];
        yield 'unapproved repository namespace' => ['someone/DBSeller@'];
        yield 'invalid commit' => ['e-cidade/e-cidade-DBSeller@'];
    }

    #[DataProvider('invalidPins')]
    public function testInvalidPinRejected(string $prefix): void
    {
        $this->expectException(RuntimeException::class);
        PinnedCheckoutVerifier::verify($this->checkout, $prefix . str_repeat('a', 40));
    }

    public function testWrongRevisionRejected(): void
    {
        $this->expectException(RuntimeException::class);
        PinnedCheckoutVerifier::verify($this->checkout, 'e-cidade/e-cidade-DBSeller@' . str_repeat('0', 40));
    }

    public function testMissingGitRepositoryRejected(): void
    {
        $this->expectException(RuntimeException::class);
        PinnedCheckoutVerifier::verify(sys_get_temp_dir(), $this->upstream());
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
