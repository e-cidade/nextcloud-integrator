<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

/*
 * Runtime probe of pinned public e-Cidade original DBLargeObject class.
 * Synthetic PostgreSQL and synthetic bytes ONLY. No municipal data.
 * Does not load e-Cidade bootstrap or replace any distribution file.
 */
if (PHP_SAPI !== 'cli'
    || getenv('ECIDADE_SYNTHETIC_FORK_CONTRACT') !== '1'
    || getenv('PGDATABASE') !== 'ecidade_synthetic_test'
    || getenv('PGUSER') !== 'mvp_test'
    || !in_array(getenv('PGHOST'), ['mvp-db-ci0001', '127.0.0.1'], true)) {
    fwrite(STDERR, "Refusing non-synthetic environment\n");
    exit(2);
}
if (!extension_loaded('pgsql')) {
    throw new RuntimeException('Test container lacks pgsql');
}
$fork = isset($argv[1]) ? $argv[1] : null;
$upstream = isset($argv[2]) ? $argv[2] : null;
if (!in_array($fork, ['contass','dbseller','cpd'], true)
    || !is_string($upstream) || !is_file($upstream)
    || basename($upstream) !== 'DBLargeObject.php') {
    throw new RuntimeException('Invalid pinned source argument');
}
$conn = @pg_connect(
    'host=' . getenv('PGHOST') . ' port=5432 user=mvp_test dbname=ecidade_synthetic_test '
    . 'password=' . getenv('PGPASSWORD'),
    PGSQL_CONNECT_FORCE_NEW
);
if (!$conn) {
    throw new RuntimeException('Synthetic PostgreSQL unreachable');
}
class db_utils
{
    public static function inTransaction($connection = null)
    {
        global $conn;
        $connection = $connection ?: $conn;
        return pg_transaction_status($connection) === PGSQL_TRANSACTION_INTRANS;
    }
}
function db_inicio_transacao()
{
    global $conn;
    return pg_query($conn, 'BEGIN');
}
function db_fim_transacao()
{
    global $conn;
    return pg_query($conn, 'COMMIT');
}
function sqlTest($query)
{
    global $conn;
    $result = pg_query($conn, $query);
    if (!$result) {
        throw new RuntimeException('Synthetic SQL error');
    }
    return $result;
}
function assertTest($ok, $message)
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}
require_once $upstream;
$baseline = (int)pg_fetch_result(sqlTest('SELECT count(*) FROM pg_largeobject_metadata'), 0, 0);
$bytes = 'SYNTHETIC_FORK_'.$fork."\0" . str_repeat('X', 4096);
$input = tempnam(sys_get_temp_dir(), 'ecidade-src-');
$target = tempnam(sys_get_temp_dir(), 'ecidade-out-');
file_put_contents($input, $bytes);
$checks = 0;
try {
    // Original source contract: implicit transaction for OID creation.
    $oid = DBLargeObject::criaOID(false);
    assertTest(is_int($oid) && $oid > 0, 'Original reserve must return OID');
    $checks++;
    assertTest(
        pg_transaction_status($conn) === PGSQL_TRANSACTION_IDLE,
        'Original implicit transaction must be closed'
    );
    $checks++;
    sqlTest('BEGIN');
    if ($fork === 'contass') {
        $written = DBLargeObject::escrita($input, $oid);
    } else {
        $written = DBLargeObject::writeContent($bytes, $oid);
    }
    assertTest($written === strlen($bytes), 'Original write must return exact byte count');
    $checks++;
    assertTest(
        DBLargeObject::leitura($oid, $target) === true,
        'Original read must return true on successful export'
    );
    $checks++;
    assertTest(file_get_contents($target) === $bytes, 'Native read bytes mismatch');
    $checks++;
    // Exercise the other public write entrypoint, including Contass's
    // generate-file-OID operation. Both must preserve return contracts.
    if ($fork === 'contass') {
        $fileOid = DBLargeObject::gerarArquivoOid($input, true);
        assertTest(
            is_int($fileOid) && $fileOid > 0,
            'Original gerarArquivoOid(true) must return an OID'
        );
    } else {
        $fileOid = DBLargeObject::criaOID(true);
        assertTest(
            DBLargeObject::escrita($input, $fileOid) === strlen($bytes),
            'Original escrita must return the number of imported bytes'
        );
    }
    $checks++;
    assertTest(
        DBLargeObject::leitura($fileOid, $target) === true
        && file_get_contents($target) === $bytes,
        'Original file-writing entrypoint did not preserve bytes'
    );
    $checks++;
    sqlTest('COMMIT');
    sqlTest('BEGIN');
    assertTest(
        DBLargeObject::exclusao($fileOid) === true,
        'Original second document deletion failed'
    );
    $checks++;
    sqlTest('COMMIT');
    sqlTest('BEGIN');
    assertTest(
        DBLargeObject::exclusao($oid) === true,
        'Original delete must return true for existing LO'
    );
    $checks++;
    sqlTest('COMMIT');
    assertTest((int)pg_fetch_result(sqlTest('SELECT count(*) FROM pg_largeobject_metadata'), 0, 0)
        === $baseline, 'Original create+delete did not restore LO baseline');
    $checks++;
    sqlTest('BEGIN');
    $rolled = DBLargeObject::criaOID(true);
    DBLargeObject::escrita($input, $rolled);
    sqlTest('ROLLBACK');
    assertTest((int)pg_fetch_result(sqlTest('SELECT count(*) FROM pg_largeobject_metadata'), 0, 0)
        === $baseline, 'Native SQL rollback retained a large object');
    $checks++;
    try {
        DBLargeObject::leitura($oid, $target);
        throw new RuntimeException('Original read without transaction did not throw');
    } catch (Exception $expected) {
        assertTest(
            stripos($expected->getMessage(), 'transa') !== false,
            'Original no-transaction error changed'
        );
        $checks++;
    }
    echo 'PASS PINNED ORIGINAL ',$fork,' PHP ',PHP_VERSION,' checks=',$checks,
    ' OID integer write count/read bool/delete bool, rollback ok',PHP_EOL;
} finally {
    @unlink($input);
    @unlink($target);
    pg_close($conn);
}
