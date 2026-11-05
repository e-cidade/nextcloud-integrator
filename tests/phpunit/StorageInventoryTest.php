<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class StorageInventoryTest extends TestCase
{
    private string $source;

    protected function setUp(): void
    {
        $this->source = sys_get_temp_dir() . '/ecidade-inventory-'
            . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->source, 0700));
        self::assertTrue(mkdir($this->source . '/modules', 0700));
        file_put_contents(
            $this->source . '/modules/document.php',
            "<?php\nDBLargeObject::leitura(\$oid, \$path);\npg_lo_open(\$db,\$oid,'r');\n"
            . "StorageHelper::uploadArquivo(\$path);\nfile_put_contents(\$path,\$bytes);\n"
        );
        file_put_contents(
            $this->source . '/schema.sql',
            "CREATE TABLE synthetic (payload bytea);\n"
        );
        file_put_contents(
            $this->source . '/modules/other.php',
            "<?php\n// fake unrelated source\n"
        );
    }

    protected function tearDown(): void
    {
        foreach (glob($this->source . '/modules/*') as $path) {
            unlink($path);
        }
        unlink($this->source . '/schema.sql');
        rmdir($this->source . '/modules');
        rmdir($this->source);
    }

    private function runInventory(bool $allow): array
    {
        $command = escapeshellarg(PHP_BINARY) . ' '
            . escapeshellarg(dirname(__DIR__, 2) . '/bin/inventory.php') . ' '
            . escapeshellarg('--source=' . $this->source)
            . ($allow ? ' --synthetic-test' : '');
        $stdout = [];
        $exit = 0;
        $before = getenv('ECIDADE_INVENTORY_SYNTHETIC');
        putenv('ECIDADE_INVENTORY_SYNTHETIC=1');
        try {
            exec($command . ' 2>&1', $stdout, $exit);
        } finally {
            if ($before === false) {
                putenv('ECIDADE_INVENTORY_SYNTHETIC');
            } else {
                putenv('ECIDADE_INVENTORY_SYNTHETIC='.$before);
            }
        }
        return [$exit,implode("\n", $stdout)];
    }

    public function testInventoryRefusesImplicitSourceAccess(): void
    {
        [$exit, $text] = $this->runInventory(false);
        self::assertSame(2, $exit);
        self::assertStringContainsString('Refusing inventory', $text);
    }

    public function testInventoryReturnsOnlyRelativePathsAndCategories(): void
    {
        [$exit, $text] = $this->runInventory(true);
        self::assertSame(0, $exit, $text);
        self::assertStringNotContainsString($this->source, $text);
        $data = json_decode($text, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(3, $data['scanned_files']);
        self::assertFalse($data['truncated']);
        self::assertSame(1, $data['counts']['db_large_object']);
        self::assertSame(1, $data['counts']['native_pg_lo']);
        self::assertSame(1, $data['counts']['estorage']);
        self::assertSame(1, $data['counts']['bytea']);
        self::assertSame(
            ['modules/document.php', 'schema.sql'],
            array_column($data['candidates'], 'path')
        );
    }
}
