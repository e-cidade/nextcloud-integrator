<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace ECidade\Nextcloud\Application;

use ECidade\Nextcloud\Domain\OperationKey;
use ECidade\Nextcloud\Domain\Scope;
use ECidade\Nextcloud\Persistence\PgDocumentLedger;
use ECidade\Nextcloud\Transport\NextcloudMvpClient;

/**
 * An OPTIONAL, SYNTHETIC-ONLY legacy facade experiment for DBSeller.
 *
 * Not activated by the current module manifest. It deliberately refuses to
 * run on non-disposable databases and never calls native pg_lo_* functions.
 *
 * Experimental SQL-visible read-after-write works before reconciliation,
 * but MUST be validated against concrete ERP callsites and permissions.
 */
final class MvpLegacyBridge
{
    private static $context;

    private static function context()
    {
        if (getenv('ECIDADE_MVP_EXPERIMENTAL') !== '1'
            || getenv('ECIDADE_SYNTHETIC_TEST') !== '1'
            || !preg_match('/^mvp-db-[a-z0-9]{6,24}$/D', (string)getenv('PGHOST'))
            || getenv('PGDATABASE') !== 'ecidade_synthetic_test') {
            throw new \RuntimeException('Experimental document bridge cannot run outside synthetic tests');
        }
        if (self::$context === null) {
            global $conn;
            $scope = new Scope(
                getenv('ECIDADE_MVP_INSTALLATION'),
                getenv('ECIDADE_MVP_TENANT')
            );
            $ledger = new PgDocumentLedger($conn);
            $remote = NextcloudMvpClient::fromEnvironment();
            self::$context = [
                'scope' => $scope,'ledger' => $ledger,
                'coordinator' => new DocumentCoordinator($ledger, $remote)
            ];
        }
        return self::$context;
    }

    private static function connection()
    {
        global $conn;
        if (!is_resource($conn) && !(is_object($conn)
            && get_class($conn) === 'PgSql\Connection')) {
            throw new \RuntimeException('Legacy SQL connection unavailable');
        }
        return $conn;
    }

    private static function query($statement)
    {
        if (@pg_query(self::connection(), $statement) === false) {
            throw new \RuntimeException('Legacy SQL transaction operation failed');
        }
    }

    private static function assertTransaction()
    {
        if (!function_exists('pg_transaction_status')
            || pg_transaction_status(self::connection()) !== PGSQL_TRANSACTION_INTRANS) {
            throw new \RuntimeException('Business transaction required');
        }
    }

    public static function criaOID($hasTransaction = false)
    {
        $context = self::context();
        if (!$hasTransaction) {
            if (pg_transaction_status(self::connection()) !== PGSQL_TRANSACTION_IDLE) {
                throw new \RuntimeException('Cannot open a nested legacy transaction');
            }
            self::query('BEGIN');
        }
        try {
            self::assertTransaction();
            // The caller's existing business table remains the authoritative
            // record for linking this OID to a municipal process/document.
            $reference = $context['coordinator']->reserve(
                $context['scope'],
                'synthetic-' . OperationKey::generate()
            );
            if (!$hasTransaction) {
                self::query('COMMIT');
            }
            return $reference['legacy_id'];
        } catch (\Exception $error) {
            if (!$hasTransaction) {
                @pg_query(self::connection(), 'ROLLBACK');
            }
            throw $error;
        }
    }

    public static function writeContent($bytes, $legacyId = null)
    {
        $context = self::context();
        self::assertTransaction();
        $context['coordinator']->write($context['scope'], $legacyId, $bytes);
        return strlen($bytes);
    }

    public static function escrita($path, $legacyId = null)
    {
        self::context();
        self::assertTransaction();
        $bytes = @file_get_contents($path);
        if (!is_string($bytes)) {
            throw new \RuntimeException('File is not readable');
        }
        return self::writeContent($bytes, $legacyId);
    }

    public static function leitura($legacyId, $path)
    {
        $context = self::context();
        self::assertTransaction();
        $bytes = $context['coordinator']->read($context['scope'], $legacyId);
        return file_put_contents($path, $bytes) !== false;
    }

    public static function exclusao($legacyId)
    {
        $context = self::context();
        self::assertTransaction();
        return $context['coordinator']->delete($context['scope'], $legacyId);
    }

    public static function gerarArquivoOid($path, $hasTransaction = false)
    {
        self::context();
        if (!$hasTransaction) {
            if (pg_transaction_status(self::connection()) !== PGSQL_TRANSACTION_IDLE) {
                throw new \RuntimeException('Cannot open a nested legacy transaction');
            }
            self::query('BEGIN');
        }
        try {
            $id = self::criaOID(true);
            self::escrita($path, $id);
            if (!$hasTransaction) {
                self::query('COMMIT');
            }
            return $id;
        } catch (\Exception $error) {
            if (!$hasTransaction) {
                @pg_query(self::connection(), 'ROLLBACK');
            }
            throw $error;
        }
    }
}
