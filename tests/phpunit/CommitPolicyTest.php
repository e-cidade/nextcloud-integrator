<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class CommitPolicyTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/ecidade-git-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->dir, 0700));
        $this->git('init', '--quiet');
        $this->git('config', 'user.name', 'Quality Test');
        $this->git('config', 'user.email', 'quality@example.invalid');
        $this->git('config', 'commit.gpgsign', 'false');
    }

    protected function tearDown(): void
    {
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $entry) {
            if ($entry->isDir() && !$entry->isLink()) {
                rmdir($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }
        rmdir($this->dir);
    }

    private function execTool(array $args): array
    {
        $parts = array_map('escapeshellarg', $args);
        $lines = [];
        $status = -1;
        exec(implode(' ', $parts) . ' 2>&1', $lines, $status);
        return [$status, implode("\n", $lines)];
    }

    private function git(string ...$args): string
    {
        [$status, $message] = $this->execTool(array_merge(['git', '-C', $this->dir], $args));
        self::assertSame(0, $status, $message);
        return trim($message);
    }

    private function verify(string $mode, string $base): array
    {
        $script = dirname(__DIR__, 2) . '/tools/check-commits.php';
        $previous = getcwd();
        chdir($this->dir);
        try {
            return $this->execTool([PHP_BINARY, $script, $mode, $base, 'HEAD']);
        } finally {
            chdir($previous);
        }
    }

    public function testDcoAndConventionalCommitAcceptanceAndRejection(): void
    {
        $this->git('commit', '--allow-empty', '--quiet', '-m', 'chore: initial fixture');
        $base = $this->git('rev-parse', 'HEAD');
        $this->git(
            'commit',
            '--allow-empty',
            '--quiet',
            '-m',
            'feat(adapter): add custom fork',
            '-m',
            'Signed-off-by: Quality Test <quality@example.invalid>'
        );

        [$dcoStatus, $dcoMessage] = $this->verify('--dco', $base);
        self::assertSame(0, $dcoStatus, $dcoMessage);
        [$subjectStatus, $subjectMessage] = $this->verify('--conventional', $base);
        self::assertSame(0, $subjectStatus, $subjectMessage);

        $this->git('commit', '--allow-empty', '--quiet', '-m', 'wrong title');
        [$dcoStatus, $dcoMessage] = $this->verify('--dco', $base);
        self::assertSame(1, $dcoStatus, $dcoMessage);
        self::assertStringContainsString('missing DCO', $dcoMessage);
        [$subjectStatus, $subjectMessage] = $this->verify('--conventional', $base);
        self::assertSame(1, $subjectStatus, $subjectMessage);
        self::assertStringContainsString('invalid Conventional Commit', $subjectMessage);
    }
}
