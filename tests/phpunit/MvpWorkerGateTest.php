<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use ECidade\Nextcloud\Application\WorkerBatchOptions;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public static function validBatches(): iterable
    {
        yield 'minimum' => ['--max=1', 1];
        yield 'typical' => ['--max=25', 25];
        yield 'maximum' => ['--max=100', 100];
    }

    #[DataProvider('validBatches')]
    public function testWorkerAcceptsBoundedBatch(string $argument, int $expected): void
    {
        self::assertSame($expected, WorkerBatchOptions::parseLimit($argument));
    }

    public static function invalidBatches(): iterable
    {
        yield 'zero' => ['--max=0'];
        yield 'more than maximum' => ['--max=101'];
        yield 'unbounded' => ['--max=1000'];
        yield 'negative' => ['--max=-1'];
        yield 'leading zero' => ['--max=01'];
        yield 'suffix' => ['--max=1;echo'];
        yield 'missing prefix' => ['1'];
        yield 'empty' => [''];
    }

    #[DataProvider('invalidBatches')]
    public function testWorkerRejectsUnsafeBatch(string $argument): void
    {
        $this->expectException(InvalidArgumentException::class);
        WorkerBatchOptions::parseLimit($argument);
    }

    public function testWorkerCliRejectsInvalidBatchBeforeDatabaseAccess(): void
    {
        $command = 'ECIDADE_MVP_EXPERIMENTAL=1 '
            . escapeshellarg(PHP_BINARY) . ' '
            . escapeshellarg(dirname(__DIR__, 2) . '/bin/mvp-worker.php')
            . ' --max=101 2>&1';
        $output = [];
        $exitCode = 0;
        exec($command, $output, $exitCode);
        self::assertSame(2, $exitCode);
        self::assertStringContainsString('--max=1..100', implode("\n", $output));
    }
}
