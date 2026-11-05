<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class MvpWorkerGateTest extends TestCase
{
    public function testWorkerCannotStartWithoutExplicitOptIn(): void
    {
        $command = 'ECIDADE_MVP_EXPERIMENTAL=0 '
            . escapeshellarg(PHP_BINARY) . ' '
            . escapeshellarg(dirname(__DIR__, 2) . '/bin/mvp-worker.php')
            . ' --max=1 2>&1';
        $output = [];
        $exitCode = 0;
        exec($command, $output, $exitCode);
        self::assertSame(2, $exitCode);
        self::assertStringContainsString('disabled', implode("\n", $output));
    }

    public function testWorkerNeverAcceptsUnboundedBatch(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/bin/mvp-worker.php');
        self::assertIsString($source);
        self::assertStringContainsString("preg_match('/^--max=", $source);
        self::assertStringContainsString('(int)$match[1] > 100', $source);
    }
}
