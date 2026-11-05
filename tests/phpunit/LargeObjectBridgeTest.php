<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use ECidade\Nextcloud\LargeObjectBridge;
use PHPUnit\Framework\TestCase;

final class LargeObjectBridgeTest extends TestCase
{
    public function testNoTransactionFailsClosed(): void
    {
        $GLOBALS['conn'] = null;
        $this->expectException(RuntimeException::class);
        LargeObjectBridge::assertTransaction();
    }

    public function testNoTransactionRefusesDocumentWrite(): void
    {
        $GLOBALS['conn'] = null;
        $this->expectException(RuntimeException::class);
        LargeObjectBridge::writeContent('synthetic', 1);
    }

    public function testNoTransactionRefusesDocumentDelete(): void
    {
        $GLOBALS['conn'] = null;
        $this->expectException(RuntimeException::class);
        LargeObjectBridge::exclusao(1);
    }
}
