<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * Isolated PostgreSQL ledger integration. NEVER invoke against a real ERP DB.
 *
 * Hard gate: ECIDADE_SYNTHETIC_TEST=1, a disposable mvp-db-* container
 * hostname, and exact synthetic database/user names. No client data.
 */
if (getenv('ECIDADE_SYNTHETIC_TEST') !== '1'
    || !preg_match('/^mvp-db-[a-z0-9]{6,24}$/D', (string)getenv('PGHOST'))
    || getenv('PGDATABASE') !== 'ecidade_synthetic_test'
    || getenv('PGUSER') !== 'mvp_test'
    || !getenv('PGPASSWORD')) {
    fwrite(STDERR, "Refusing to access a non-disposable synthetic PostgreSQL database.\n");
    exit(2);
}
if (!extension_loaded('pgsql')) {
    fwrite(STDERR, "PostgreSQL extension is required in the disposable test runner.\n");
    exit(2);
}

$root = dirname(__DIR__);
spl_autoload_register(function ($class) use ($root) {
    $prefix = 'ECidade\\Nextcloud\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }
    $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file = $root . '/src/' . $relative . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});
require_once $root . '/tests/fixtures/FakeRemoteDocuments.php';

use ECidade\Nextcloud\Application\DocumentCoordinator;
use ECidade\Nextcloud\Application\DocumentReconciler;
use ECidade\Nextcloud\Domain\Scope;
use ECidade\Nextcloud\Persistence\PgDocumentLedger;

function ensure($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
function statement($db, $sql)
{
    $result = pg_query($db, $sql);
    if (!$result) {
        throw new RuntimeException('Synthetic SQL fixture failed');
    }
    return $result;
}
function rowCount($db, $sql)
{
    return (int)pg_fetch_result(statement($db, $sql), 0, 0);
}
function denied($callback)
{
    try {
        call_user_func($callback);
        return false;
    } catch (RuntimeException $expected) {
        return true;
    }
}
function dbConnection()
{
    $c = sprintf(
        'host=%s port=5432 dbname=%s user=%s password=%s connect_timeout=5',
        getenv('PGHOST'),
        getenv('PGDATABASE'),
        getenv('PGUSER'),
        getenv('PGPASSWORD')
    );
    $db = pg_connect($c, PGSQL_CONNECT_FORCE_NEW);
    if (!$db) {
        throw new RuntimeException('Disposable synthetic DB unavailable');
    }
    return $db;
}
$db = dbConnection();
$otherDb = dbConnection();
$scopeA = new Scope('installation-a', 'municipio-a');
$scopeB = new Scope('installation-b', 'municipio-b');
$ledger = new PgDocumentLedger($db);
$workerLedger = new PgDocumentLedger($otherDb);
$remote = new FakeRemoteDocuments();
$coordinator = new DocumentCoordinator($ledger, $remote);
$reconciler = new DocumentReconciler($workerLedger, $remote, $scopeA);
$reconcilerB = new DocumentReconciler($workerLedger, $remote, $scopeB);
try {
    // Schema must exist in the disposable DB before this script.
    ensure(rowCount(
        $db,
        "SELECT count(*) FROM information_schema.tables WHERE table_schema='ecidade_nextcloud'"
    ) === 2, 'Schema fixture not loaded');

    // Prove collision avoidance against a REAL PostgreSQL LO catalog row,
    // created intentionally here in a fresh database with synthetic content.
    statement($db, 'SELECT lo_create(3000000000::oid)');
    $baseline = rowCount($db, 'SELECT count(*) FROM pg_largeobject_metadata');
    ensure($baseline === 1, 'Unexpected baseline of synthetic native LOs');

    statement($db, 'BEGIN');
    $reserved = $coordinator->reserve($scopeA, 'synthetic-case-a');
    $id = $reserved['legacy_id'];
    ensure($id !== 3000000000 && $id > 3000000000, 'Legacy OID collided with native LO');
    $first = $coordinator->write($scopeA, $id, 'synthetic-pdf-v1');
    ensure($first['revision'] === 1, 'First revision expected');
    ensure(
        $coordinator->read($scopeA, $id) === 'synthetic-pdf-v1',
        'Same transaction must read its own staged bytes'
    );
    // A second PostgreSQL connection MUST NOT see the uncommitted outbox.
    ensure(denied(function () use ($workerLedger, $remote, $scopeA, $id) {
        (new DocumentCoordinator($workerLedger, $remote))->read($scopeA, $id);
    }), 'Concurrent SQL reader obtained uncommitted staged bytes');
    ensure($workerLedger->claimNext($scopeA) === null, 'Uncommitted SQL journal was visible to worker');
    statement($db, 'COMMIT');

    ensure(
        $coordinator->read($scopeA, $id) === 'synthetic-pdf-v1',
        'Committed journal must be readable before asynchronous reconciliation'
    );
    ensure($reconciler->runOnce(), 'Committed outbox could not be finalized');
    ensure($coordinator->read($scopeA, $id) === 'synthetic-pdf-v1', 'Committed read mismatch');
    ensure(denied(function () use ($coordinator, $scopeB, $id) {
        $coordinator->read($scopeB, $id);
    }), 'Cross-tenant document read was allowed');

    // Immutable revision update: do not overwrite the committed old version.
    statement($db, 'BEGIN');
    $second = $coordinator->write($scopeA, $id, 'synthetic-pdf-v2');
    ensure($second['revision'] === 2, 'Expected a second revision');
    statement($db, 'COMMIT');
    ensure(
        $coordinator->read($scopeA, $id) === 'synthetic-pdf-v2',
        'Latest SQL-visible pending revision must be readable immediately'
    );
    ensure($reconciler->runOnce(), 'Updated immutable revision could not be finalized');
    ensure($coordinator->read($scopeA, $id) === 'synthetic-pdf-v2', 'Updated read mismatch');
    ensure(count($remote->objects) === 2, 'Original immutable remote revision was overwritten');

    // SQL rollback leaves no visible journal row, only a remote staged orphan.
    statement($db, 'BEGIN');
    $rolled = $coordinator->reserve($scopeA, 'synthetic-to-rollback');
    $coordinator->write($scopeA, $rolled['legacy_id'], 'will-rollback');
    statement($db, 'ROLLBACK');
    ensure(denied(function () use ($coordinator, $scopeA, $rolled) {
        $coordinator->read($scopeA, $rolled['legacy_id']);
    }), 'Rolled-back business reference survived');
    ensure(
        $reconciler->discardOrphans($scopeA, time() - 86400, 100) === 1,
        'Rolled-back staged orphan was not cleaned up safely'
    );
    ensure(count($remote->objects) === 2, 'Committed versions were accidentally deleted');

    // Simulate a crash after a worker claims a committed outbox row.
    statement($db, 'BEGIN');
    $crashed = $coordinator->reserve($scopeA, 'synthetic-crash-case');
    $coordinator->write($scopeA, $crashed['legacy_id'], 'crash-safe');
    statement($db, 'COMMIT');
    $leased = $workerLedger->claimNext($scopeA);
    ensure(is_array($leased), 'Worker did not claim the pending operation');
    ensure(denied(function () use ($workerLedger, $scopeB, $leased) {
        $workerLedger->finish($scopeB, $leased['id']);
    }), 'Other-municipality worker finalized an operation');
    ensure(denied(function () use ($workerLedger, $scopeB, $leased) {
        $workerLedger->retry($scopeB, $leased['id'], 'REMOTE_UNAVAILABLE');
    }), 'Other-municipality worker retried an operation');
    statement($otherDb, "UPDATE ecidade_nextcloud.operations SET claimed_at=now()-interval '11 minutes' "
        . 'WHERE id=' . (int)$leased['id']);
    ensure($reconciler->runOnce(), 'Expired worker lease was not recoverable');
    ensure(
        $coordinator->read($scopeA, $crashed['legacy_id']) === 'crash-safe',
        'Recovered committed bytes mismatch'
    );

    // Transient remote outage must retain SQL journal, not lose the reference.
    statement($db, 'BEGIN');
    $outage = $coordinator->reserve($scopeB, 'synthetic-outage');
    $coordinator->write($scopeB, $outage['legacy_id'], 'retry-test');
    statement($db, 'COMMIT');
    ensure(
        $workerLedger->claimNext($scopeA) === null,
        'A worker for municipality A claimed municipality B data'
    );
    $remote->failNextFinalize = true;
    ensure(!$reconcilerB->runOnce(), 'Expected transient reconciliation failure');
    $failed = rowCount(
        $db,
        "SELECT count(*) FROM ecidade_nextcloud.operations WHERE status='staged' "
        . "AND error_code='REMOTE_UNAVAILABLE'"
    );
    ensure($failed === 1, 'Failed remote operation was not persisted for retry');
    statement($db, 'UPDATE ecidade_nextcloud.operations SET next_attempt_at=now() '
        . "WHERE status='staged' AND error_code='REMOTE_UNAVAILABLE'");
    ensure($reconcilerB->runOnce(), 'Retry after simulated network outage failed');
    ensure(
        $coordinator->read($scopeB, $outage['legacy_id']) === 'retry-test',
        'Retried document read mismatch'
    );

    // Deleted SQL reference is tombstoned after commit. Bytes remain retained.
    statement($db, 'BEGIN');
    $coordinator->delete($scopeA, $id);
    statement($db, 'ROLLBACK');
    ensure(
        $coordinator->read($scopeA, $id) === 'synthetic-pdf-v2',
        'SQL rollback of tombstone lost a committed version'
    );
    statement($db, 'BEGIN');
    $coordinator->delete($scopeA, $id);
    statement($db, 'COMMIT');
    ensure(denied(function () use ($coordinator, $scopeA, $id) {
        $coordinator->read($scopeA, $id);
    }), 'Tombstoned version was publicly readable');
    ensure(count($remote->objects) === 4, 'Delete prematurely destroyed remote immutable data');

    // A worker can crash after the remote operation is committed but before
    // updating the SQL outbox. A second worker must accept idempotent finalize.
    statement($db, 'BEGIN');
    $late = $coordinator->reserve($scopeA, 'synthetic-post-finalize-crash');
    $coordinator->write($scopeA, $late['legacy_id'], 'recovered-after-remote');
    statement($db, 'COMMIT');
    $claim = $workerLedger->claimNext($scopeA);
    ensure(is_array($claim), 'No outbox row for post-finalize crash test');
    $remote->finalize($scopeA, $claim['document_key'], $claim['operation_key']);
    statement($otherDb, "UPDATE ecidade_nextcloud.operations SET claimed_at=now()-interval '11 minutes' "
        . 'WHERE id=' . (int)$claim['id']);
    ensure($reconciler->runOnce(), 'Idempotent finalize after worker crash failed');
    ensure(
        $coordinator->read($scopeA, $late['legacy_id']) === 'recovered-after-remote',
        'Post-finalize crash recovery lost committed bytes'
    );

    // An invalid write must not create remote staged data.
    $outsideBytesCount = count($remote->objects);
    ensure(denied(function () use ($coordinator, $scopeA, $id) {
        $coordinator->write($scopeA, $id, 'without-sql');
    }), 'Write without existing SQL business transaction was permitted');
    ensure(
        count($remote->objects) === $outsideBytesCount,
        'Rejected write created remote orphan'
    );

    // No file-content column, and no new native PG Large Object.
    ensure(
        rowCount($db, 'SELECT count(*) FROM pg_largeobject_metadata') === $baseline,
        'The metadata journal created a native PG Large Object'
    );
    ensure(
        rowCount($db, 'SELECT count(*) FROM information_schema.columns '
        . "WHERE table_schema='ecidade_nextcloud' AND data_type='bytea'") === 0,
        'Document bytes were added to PostgreSQL journal'
    );
    echo 'PASS isolated PostgreSQL ledger: scoped OIDs, staged invisibility, '
        . 'immutable revisions, SQL rollback, crash recovery, network retry, '
        . "tombstone, no binary duplication\n";
} catch (Exception $error) {
    fwrite(STDERR, 'FAIL synthetic PostgreSQL MVP contract: ' . $error->getMessage() . "\n");
    exit(1);
} finally {
    pg_close($otherDb);
    pg_close($db);
}
