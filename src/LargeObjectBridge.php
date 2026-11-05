<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace ECidade\Nextcloud;

use ECidade\Nextcloud\Application\AdapterServiceV1;
use ECidade\Nextcloud\Domain\AdapterContext;
use ECidade\Nextcloud\Domain\AdapterFailure;
use ECidade\Nextcloud\Domain\OperationKey;
use ECidade\Nextcloud\Persistence\PgDocumentLedger;

/** Single implementation reused by all legacy-fork facades. */
class LargeObjectBridge
{
    private static $client;
    private static $spi;
    private static $context;
    private static $ledger;
    /** Bind an explicit authoritative SPI to the ERP transaction journal. */
    public static function bind(AdapterServiceV1 $spi, AdapterContext $context, PgDocumentLedger $ledger)
    {
        if (self::$spi !== null) {
            throw new \RuntimeException('Legacy SPI adapter already bound');
        }
        if ($context->surface() !== 'large-object-facade'
            || $context->backend() !== getenv('ECIDADE_ADAPTER')
            || !$spi->usesLedger($ledger)
            || !$ledger->usesConnection(self::connection())) {
            throw new \RuntimeException('Legacy SPI requires the selected fork and ERP SQL connection');
        }
        self::$spi = $spi;
        self::$context = $context;
        self::$ledger = $ledger;
    }

    private static function connection()
    {
        global $conn;
        if ((!is_resource($conn) && !(is_object($conn) && get_class($conn) === 'PgSql\\Connection'))
            || !function_exists('pg_transaction_status')) {
            throw new \RuntimeException('Legacy PostgreSQL connection unavailable');
        }
        return $conn;
    }
    public static function assertTransaction()
    {
        // Resolve the extension before PHP evaluates function arguments.
        // Without this, unavailable pgsql raises Error before connection() can fail closed.
        if (!function_exists('pg_transaction_status')) {
            throw new \RuntimeException('PostgreSQL extension unavailable');
        }
        if (self::$spi !== null && !self::$ledger->usesConnection(self::connection())) {
            throw new \RuntimeException('Legacy SQL connection changed after SPI binding');
        }
        if (pg_transaction_status(self::connection()) !== PGSQL_TRANSACTION_INTRANS) {
            throw new \RuntimeException('Sem transacao ativa.');
        }
    }
    private static function query($sql)
    {
        if (!pg_query(self::connection(), $sql)) {
            throw new \RuntimeException('Legacy DB transaction failed');
        }
    }
    private static function client()
    {
        if (self::$client === null) {
            self::$client = DocumentClient::fromEnvironment();
        }
        return self::$client;
    }
    public static function criaOID($existing = false)
    {
        if (!$existing) {
            self::query('BEGIN');
        }
        try {
            self::assertTransaction();
            if (self::$spi !== null) {
                // Allocate a logical OID, independent of the Nextcloud file ID.
                $m = self::$spi->reserve(self::$context, 'oid-' . OperationKey::generate());
                $id = $m['legacy_id'];
            } else {
                // Historical lab-only PoC. DocumentClient refuses non-opt-in use.
                $m = self::client()->reserve();
                $id = $m['id'];
            }
            if (!is_numeric($id) || (float)$id < 1 || (float)$id > 4294967295) {
                throw new \RuntimeException('Invalid legacy OID width');
            }
            $id = (int)$id;
            if (!$existing) {
                self::query('COMMIT');
            }
            return $id;
        } catch (\Exception $e) {
            if (!$existing) {
                @pg_query(self::connection(), 'ROLLBACK');
            }
            throw $e; // Reserved Nextcloud file may remain; reconciliation needed.
        }
    }
    public static function writeContent($bytes, $oid = null)
    {
        self::assertTransaction();
        if (!is_numeric($oid) || !is_string($bytes)) {
            throw new \InvalidArgumentException('Invalid OID or document');
        }
        if (self::$spi !== null) {
            $meta = self::$spi->inspect(self::$context, (int)$oid);
            if ($meta['deleted'] || $meta['pending_revision'] !== null) {
                throw new AdapterFailure(AdapterFailure::CONFLICT);
            }
            $key = OperationKey::generate();
            if ($meta['revision'] === 0) {
                self::$spi->create(self::$context, (int)$oid, $bytes, $key);
            } else {
                self::$spi->update(self::$context, (int)$oid, $bytes, $key);
            }
        } else {
            self::client()->write((int)$oid, $bytes);
        }
        return strlen($bytes);
    }
    public static function escrita($file, $oid = null)
    {
        self::assertTransaction();
        $bytes = @file_get_contents($file);
        if ($bytes === false) {
            throw new \RuntimeException('Cannot read uploaded file');
        }
        return self::writeContent($bytes, $oid);
    }
    public static function leitura($oid, $path)
    {
        self::assertTransaction();
        $bytes = self::$spi !== null
            ? self::$spi->read(self::$context, (int)$oid)
            : self::client()->read((int)$oid);
        return file_put_contents($path, $bytes) !== false;
    }
    public static function exclusao($oid)
    {
        self::assertTransaction();
        return self::$spi !== null
            ? self::$spi->delete(self::$context, (int)$oid)
            : self::client()->remove((int)$oid);
    }
    public static function gerarArquivoOid($path, $hasTransaction = false)
    {
        // Contass has this method. Keep both operations inside one transaction.
        if (!$hasTransaction) {
            self::query('BEGIN');
        }
        try {
            $oid = self::criaOID(true);
            if (!self::escrita($path, $oid)) {
                throw new \RuntimeException('Empty/failed document write');
            }
            if (!$hasTransaction) {
                self::query('COMMIT');
            }
            return $oid;
        } catch (\Exception $e) {
            if (!$hasTransaction) {
                @pg_query(self::connection(), 'ROLLBACK');
            }
            throw $e;
        }
    }
}
