<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

/*
 * Executes the actual fork replacement facade through SPI v1, the SAME
 * PostgreSQL connection, and the shared outbox. All bytes are synthetic;
 * this deliberately is NOT a full ERP bootstrap or a production test.
 */
$ciHost = getenv('PGHOST') === '127.0.0.1' && getenv('GITHUB_ACTIONS') === 'true';
$containerHost = getenv('PGHOST') === 'mvp-db-ci0001'
    && getenv('ECIDADE_SYNTHETIC_TEST') === '1' && is_file('/.dockerenv');
if (PHP_SAPI !== 'cli' || getenv('ECIDADE_SYNTHETIC_FORK_CONTRACT') !== '1'
    || (!$ciHost && !$containerHost)
    || getenv('PGDATABASE') !== 'ecidade_synthetic_test'
    || getenv('PGUSER') !== 'mvp_test' || !getenv('PGPASSWORD')
    || !extension_loaded('pgsql')) {
    fwrite(STDERR, "Refusing non-disposable facade/SPI test environment\n");
    exit(2);
}

$fork = isset($argv[1]) ? $argv[1] : '';
if (!in_array($fork, ['dbseller', 'contass', 'cpd'], true)
    || $fork !== getenv('ECIDADE_ADAPTER')) {
    throw new RuntimeException('Invalid fork selector');
}
$root = dirname(dirname(__DIR__));
putenv('ECIDADE_INTEGRATOR_ROOT=' . $root);
spl_autoload_register(function ($class) use ($root) {
    $prefix = 'ECidade\\Nextcloud\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }
    $file = $root . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});
require_once $root . '/tests/fixtures/FakeRemoteDocuments.php';
require_once $root . '/modules/' . $fork . '/DBLargeObject.php';

use ECidade\Nextcloud\Application\AdapterServiceV1;
use ECidade\Nextcloud\Application\DocumentCoordinator;
use ECidade\Nextcloud\Application\DocumentReconciler;
use ECidade\Nextcloud\Contract\DocumentAccessPolicy;
use ECidade\Nextcloud\Domain\AdapterContext;
use ECidade\Nextcloud\Domain\Scope;
use ECidade\Nextcloud\LargeObjectBridge;
use ECidade\Nextcloud\Persistence\PgDocumentLedger;

class SyntheticForkPolicy implements DocumentAccessPolicy
{
    public $revoked = false;
    public function allows(AdapterContext $context, $operation, $legacyId)
    {
        return !$this->revoked
            && $context->actor() === 'synthetic-actor'
            && $context->scope()->equals(new Scope('installation-a', 'municipio-a'));
    }
}

function requireFork($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException('Fork SPI runtime failure: ' . $message);
    }
}
function forkSql($conn, $query)
{
    $result = @pg_query($conn, $query);
    if (!$result) {
        throw new RuntimeException('Disposable SQL statement failed');
    }
    return $result;
}
function forkDenied($callback)
{
    try {
        call_user_func($callback);
    } catch (Exception $error) {
        return true;
    }
    return false;
}
function forkConnect()
{
    $db = @pg_connect(
        'host=' . getenv('PGHOST') . ' port=5432 dbname=ecidade_synthetic_test'
        . ' user=mvp_test password=' . getenv('PGPASSWORD') . ' connect_timeout=5',
        PGSQL_CONNECT_FORCE_NEW
    );
    if (!$db) {
        throw new RuntimeException('Disposable database unavailable');
    }
    return $db;
}

$conn = forkConnect();
$reader = forkConnect();
$input = tempnam(sys_get_temp_dir(), 'spi-input-');
$output = tempnam(sys_get_temp_dir(), 'spi-output-');
$checks = 0;
try {
    // Never run an ERP migration: schema exists only in the test database.
    $tables = (int)pg_fetch_result(forkSql(
        $conn,
        "SELECT count(*) FROM information_schema.tables WHERE table_schema='ecidade_nextcloud'"
    ), 0, 0);
    if ($tables === 0) {
        forkSql($conn, file_get_contents($root . '/database/001_opt_in_mvp_schema.sql'));
    } else {
        requireFork($tables === 2, 'incomplete synthetic schema');
    }
    $baseline = (int)pg_fetch_result(
        forkSql($conn, 'SELECT count(*) FROM pg_largeobject_metadata'),
        0,
        0
    );
    $scope = new Scope('installation-a', 'municipio-a');
    $ledger = new PgDocumentLedger($conn);
    $readLedger = new PgDocumentLedger($reader);
    $remote = new FakeRemoteDocuments();
    $coordinator = new DocumentCoordinator($ledger, $remote);
    $policy = new SyntheticForkPolicy();
    $manifest = [
        'id' => $fork,
        'production_ready' => false,
        'storage_capabilities' => [[
            'surface' => 'large-object-facade',
            'usage' => 'persistent-document',
            'spi' => 1,
            'operations' => ['reserve', 'create', 'read', 'update', 'delete', 'inspect'],
        ]],
    ];
    $spi = new AdapterServiceV1($coordinator, $ledger, $policy, $manifest);
    $context = new AdapterContext($scope, $fork, 'large-object-facade', 'synthetic-actor');
    requireFork(forkDenied(function () use ($spi, $context, $readLedger) {
        LargeObjectBridge::bind($spi, $context, $readLedger);
    }), 'SPI allowed a different SQL journal');
    LargeObjectBridge::bind($spi, $context, $ledger);
    ++$checks;

    // The native facade owns BEGIN/COMMIT only when explicitly requested.
    $oid = DBLargeObject::criaOID(false);
    requireFork(is_int($oid) && $oid >= 3000000000, 'logical OID allocation');
    requireFork(pg_transaction_status($conn) === PGSQL_TRANSACTION_IDLE, 'implicit COMMIT');
    ++$checks;
    requireFork(forkDenied(function () use ($oid, $output) {
        DBLargeObject::leitura($oid, $output);
    }), 'legacy read without SQL transaction must fail');
    ++$checks;

    $original = 'synthetic-fork-spi-'.$fork."\0" . str_repeat('A', 4096);
    $updated = "synthetic-updated\0" . str_repeat('B', 3072);
    file_put_contents($input, $original);
    forkSql($conn, 'BEGIN');
    if ($fork === 'contass') {
        $written = DBLargeObject::escrita($input, $oid);
    } else {
        $written = DBLargeObject::writeContent($original, $oid);
    }
    requireFork($written === strlen($original), 'write return value');
    requireFork(DBLargeObject::leitura($oid, $output) === true
        && file_get_contents($output) === $original, 'same-transaction read bytes');
    ++$checks;

    // The second SQL connection must not see uncommitted bytes.
    $other = new DocumentCoordinator($readLedger, $remote);
    requireFork(forkDenied(function () use ($other, $scope, $oid) {
        $other->read($scope, $oid);
    }), 'concurrent uncommitted read');
    requireFork($readLedger->claimNext($scope) === null, 'worker saw uncommitted outbox');
    ++$checks;
    forkSql($conn, 'COMMIT');
    forkSql($conn, 'BEGIN');
    requireFork(DBLargeObject::leitura($oid, $output) === true
        && file_get_contents($output) === $original, 'post-commit read before worker');
    forkSql($conn, 'COMMIT');
    $worker = new DocumentReconciler($readLedger, $remote, $scope);
    requireFork($worker->runOnce(), 'shared worker did not finalize');
    ++$checks;

    // Only the SQL identity is exposed. A synthetic remote file ID is small.
    requireFork($oid !== 101, 'Nextcloud file ID was used as legacy OID');
    $foreign = new AdapterContext(
        new Scope('installation-b', 'municipio-b'),
        $fork,
        'large-object-facade',
        'synthetic-actor'
    );
    requireFork(forkDenied(function () use ($spi, $foreign, $oid) {
        $spi->read($foreign, $oid);
    }), 'cross-tenant read');
    $policy->revoked = true;
    forkSql($conn, 'BEGIN');
    requireFork(forkDenied(function () use ($oid, $output) {
        DBLargeObject::leitura($oid, $output);
    }), 'revoked actor read');
    forkSql($conn, 'ROLLBACK');
    $policy->revoked = false;
    ++$checks;

    // A failed business transaction must restore the previous published bytes.
    forkSql($conn, 'BEGIN');
    file_put_contents($input, $updated);
    requireFork(DBLargeObject::escrita($input, $oid) === strlen($updated), 'update file facade');
    requireFork(DBLargeObject::leitura($oid, $output)
        && file_get_contents($output) === $updated, 'uncommitted update read');
    requireFork(forkDenied(function () use ($oid, $updated, $input, $fork) {
        if ($fork === 'contass') {
            DBLargeObject::escrita($input, $oid);
        } else {
            DBLargeObject::writeContent($updated, $oid);
        }
    }), 'second concurrent revision must be rejected');
    forkSql($conn, 'ROLLBACK');
    forkSql($conn, 'BEGIN');
    requireFork(DBLargeObject::leitura($oid, $output)
        && file_get_contents($output) === $original, 'rollback preserved previous version');
    forkSql($conn, 'COMMIT');
    ++$checks;

    // A subsequent committed update is an immutable revision, not overwrite.
    forkSql($conn, 'BEGIN');
    requireFork(DBLargeObject::escrita($input, $oid) === strlen($updated), 'committed update');
    forkSql($conn, 'COMMIT');
    forkSql($conn, 'BEGIN');
    requireFork(DBLargeObject::leitura($oid, $output)
        && file_get_contents($output) === $updated, 'read after update commit');
    forkSql($conn, 'COMMIT');
    requireFork($worker->runOnce(), 'update worker finalize');
    requireFork(count($remote->objects) === 3, 'immutable versions and orphan expected');
    requireFork(
        $worker->discardOrphans($scope, time() - 86400, 10) === 1,
        'rollback orphan cleanup'
    );
    ++$checks;

    // Exercise the separate file-writing entrypoint without allocating PG LOs.
    file_put_contents($input, $original);
    forkSql($conn, 'BEGIN');
    if ($fork === 'contass') {
        $second = DBLargeObject::gerarArquivoOid($input, true);
    } else {
        $second = DBLargeObject::criaOID(true);
        requireFork(
            DBLargeObject::escrita($input, $second) === strlen($original),
            'file upload return value'
        );
    }
    requireFork(is_int($second) && $second !== $oid, 'second logical OID');
    requireFork(DBLargeObject::leitura($second, $output)
        && file_get_contents($output) === $original, 'file upload read');
    forkSql($conn, 'COMMIT');
    requireFork($worker->runOnce(), 'second file finalize');
    ++$checks;

    // Prohibit native historical references from silently becoming new IDs.
    forkSql($conn, 'SELECT lo_create(2999999999::oid)');
    requireFork(forkDenied(function () use ($spi, $context) {
        $spi->read($context, 2999999999);
    }), 'unmapped historical OID was accepted');
    ++$checks;

    // Delete must be a SQL tombstone; rollback cannot delete remote bytes.
    forkSql($conn, 'BEGIN');
    requireFork(DBLargeObject::exclusao($oid) === true, 'legacy delete result');
    forkSql($conn, 'ROLLBACK');
    forkSql($conn, 'BEGIN');
    requireFork(DBLargeObject::leitura($oid, $output)
        && file_get_contents($output) === $updated, 'rolled back delete');
    requireFork(DBLargeObject::exclusao($oid) === true, 'committed delete result');
    forkSql($conn, 'COMMIT');
    forkSql($conn, 'BEGIN');
    requireFork(forkDenied(function () use ($oid, $output) {
        DBLargeObject::leitura($oid, $output);
    }), 'deleted record still readable');
    forkSql($conn, 'ROLLBACK');
    ++$checks;

    // Any new native LO must be exactly the historical test fixture above.
    $nativeAfter = (int)pg_fetch_result(
        forkSql($conn, 'SELECT count(*) FROM pg_largeobject_metadata'),
        0,
        0
    );
    requireFork($nativeAfter === $baseline + 1, 'SPI duplicated bytes in native PostgreSQL LOs');
    requireFork((int)pg_fetch_result(forkSql(
        $conn,
        "SELECT count(*) FROM information_schema.columns WHERE table_schema='ecidade_nextcloud' AND data_type='bytea'"
    ), 0, 0) === 0, 'SQL journal contains bytea columns');
    ++$checks;
    echo 'PASS integrated legacy facade -> SPI v1 -> PostgreSQL journal / shared worker, fork='
        . $fork . ' PHP=' . PHP_VERSION . ' checks=' . $checks . "\n";
} finally {
    @unlink($input);
    @unlink($output);
    @pg_close($reader);
    @pg_close($conn);
}
