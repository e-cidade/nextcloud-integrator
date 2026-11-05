<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace ECidade\Nextcloud;

/** Single implementation reused by all legacy-fork facades. */
class LargeObjectBridge
{
    private static $client;
    private static function connection()
    {
        global $conn;
        if (!is_resource($conn) || !function_exists('pg_transaction_status')) {
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
            $m = self::client()->reserve();
            $id = (int)$m['id'];
            if ($id < 1 || $id > 4294967295) {
                throw new \RuntimeException('Nextcloud file ID exceeds oid width');
            }
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
        self::client()->write((int)$oid, $bytes);
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
        return file_put_contents($path, self::client()->read((int)$oid)) !== false;
    }
    public static function exclusao($oid)
    {
        self::assertTransaction();
        return self::client()->remove((int)$oid);
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
