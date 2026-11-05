<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * Exact real transport + SQL journal contract against synthetic services.
 * Uses disposable PostgreSQL and Nextcloud under a self-signed TEST CA.
 * The client rejects insecure HTTP, untrusted certificates and other scopes.
 *
 * This never loads, changes, or contacts any deployed e-Cidade installation.
 */

if (PHP_SAPI !== 'cli'
    || getenv('ECIDADE_SYNTHETIC_TEST') !== '1'
    || getenv('ECIDADE_SYNTHETIC_HTTPS_TEST') !== '1'
    || getenv('PGHOST') !== 'mvp-db-ci0001'
    || getenv('PGDATABASE') !== 'ecidade_synthetic_test'
    || getenv('PGUSER') !== 'mvp_test'
    || !is_file('/.dockerenv')
    || !is_file('/synthetic-certs/cert.pem')
    || !extension_loaded('pgsql')
    || !extension_loaded('curl')) {
    fwrite(STDERR, "Refusing to run outside the isolated synthetic PHP/PostgreSQL/HTTPS stack.\n");
    exit(2);
}
$password = getenv('MVP_SYNTHETIC_PASSWORD');
if (!is_string($password) || strlen($password) < 12
    || $password !== getenv('PGPASSWORD')) {
    throw new RuntimeException('Invalid disposable test-only credentials');
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'ECidade\\Nextcloud\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file = dirname(__DIR__, 2) . '/src/' . $relative . '.php';
    if (!is_file($file)) {
        throw new RuntimeException('Missing SDK class in synthetic runner');
    }
    require_once $file;
});

use ECidade\Nextcloud\Application\AdapterServiceV1;
use ECidade\Nextcloud\Application\DocumentCoordinator;
use ECidade\Nextcloud\Application\DocumentReconciler;
use ECidade\Nextcloud\Contract\DocumentAccessPolicy;
use ECidade\Nextcloud\Domain\AdapterContext;
use ECidade\Nextcloud\Domain\Scope;
use ECidade\Nextcloud\LargeObjectBridge;
use ECidade\Nextcloud\Persistence\PgDocumentLedger;
use ECidade\Nextcloud\Transport\NextcloudMvpClient;

$checks = 0;
function ensure(bool $condition, string $message): void
{
    global $checks;
    $checks++;
    if (!$condition) {
        throw new RuntimeException('Isolated SQL-to-HTTPS contract failed: ' . $message);
    }
}
function sql($connection, string $statement)
{
    $result = pg_query($connection, $statement);
    if ($result === false) {
        throw new RuntimeException('Synthetic SQL statement failed');
    }
    return $result;
}
function assertDenied(callable $operation): bool
{
    try {
        $operation();
    } catch (RuntimeException $exception) {
        return true;
    }
    return false;
}
function connectSyntheticPg(): PgSql\Connection
{
    $connection = pg_connect(
        'host=mvp-db-ci0001 port=5432 dbname=ecidade_synthetic_test '
        . 'user=mvp_test password=' . getenv('PGPASSWORD') . ' connect_timeout=5',
        PGSQL_CONNECT_FORCE_NEW
    );
    if (!$connection instanceof PgSql\Connection) {
        throw new RuntimeException('Disposable PostgreSQL is unavailable');
    }
    return $connection;
}
function scopedClient(Scope $scope, string $user, string $url): NextcloudMvpClient
{
    return new NextcloudMvpClient($scope, [
        'url' => $url,
        'user' => $user,
        'token' => (string)getenv('MVP_SYNTHETIC_PASSWORD'),
        'ca_file' => '/synthetic-certs/cert.pem',
        'pending_read_key' => (string)getenv('ECIDADE_MVP_PENDING_READ_KEY'),
    ]);
}

/** Trusted synthetic ERP session, deliberately independent of HTTP credentials. */
final class SyntheticHttpFacadePolicy implements DocumentAccessPolicy
{
    public $revoked = false;

    public function allows(AdapterContext $context, $operation, $legacyId)
    {
        return !$this->revoked && $context->actor() === 'synthetic-actor'
            && $context->scope()->equals(new Scope('installation-a', 'municipio-a'));
    }
}

$scopeA = new Scope('installation-a', 'municipio-a');
$scopeB = new Scope('installation-b', 'municipio-b');
$url = 'https://synthetic-nextcloud/ocs/v2.php/apps/ecidadelab/api/v2';
$db = connectSyntheticPg();
$workerDb = connectSyntheticPg();
$ledger = new PgDocumentLedger($db);
$workerLedger = new PgDocumentLedger($workerDb);
$clientA = scopedClient($scopeA, 'synthetic-admin', $url);
$clientB = scopedClient($scopeB, 'synthetic-service-b', $url);
$coordinatorA = new DocumentCoordinator($ledger, $clientA);
$coordinatorB = new DocumentCoordinator($ledger, $clientB);
$workerA = new DocumentReconciler($workerLedger, $clientA, $scopeA);
$workerB = new DocumentReconciler($workerLedger, $clientB, $scopeB);

try {
    $baseline = (int)pg_fetch_result(
        sql($db, 'SELECT count(*) FROM pg_largeobject_metadata'),
        0,
        0
    );
    ensure($baseline === 0, 'Disposable PG starts without native large objects');

    // Ref and intent share a business SQL transaction; Nextcloud receives
    // immutable staged bytes over verified HTTPS (never direct DB blobs).
    sql($db, 'BEGIN');
    $ref = $coordinatorA->reserve($scopeA, 'synthetic-business-1');
    $id = $ref['legacy_id'];
    $bytes = "synthetic-data-v1\0" . str_repeat('Z', 4096);
    $intent = $coordinatorA->write($scopeA, $id, $bytes);
    ensure(
        $intent['state'] === 'staged' && $intent['revision'] === 1,
        'Initial version staged via real HTTPS'
    );
    ensure(
        $coordinatorA->read($scopeA, $id) === $bytes,
        'Owner transaction can read its own staged HTTPS version'
    );
    ensure(assertDenied(static function () use ($workerLedger, $clientA, $scopeA, $id): void {
        (new DocumentCoordinator($workerLedger, $clientA))->read($scopeA, $id);
    }), 'Concurrent SQL reader cannot see owner staged version');
    ensure(
        $workerLedger->claimNext($scopeA) === null,
        'Independent SQL worker cannot see uncommitted outbox'
    );
    sql($db, 'COMMIT');

    ensure(
        $coordinatorA->read($scopeA, $id) === $bytes,
        'Committed SQL version available before asynchronous worker'
    );

    ensure($workerA->runOnce(), 'Reconciler finalized real Nextcloud version');
    ensure(
        $coordinatorA->read($scopeA, $id) === $bytes,
        'Committed bytes retrieved exactly through HTTPS and SQL reference'
    );

    // Revision update stays immutable: previous committed document readable
    // until the worker finalizes the next revision.
    sql($db, 'BEGIN');
    $v2 = "synthetic-data-v2\0" . str_repeat('Y', 3072);
    $intent2 = $coordinatorA->write($scopeA, $id, $v2);
    sql($db, 'COMMIT');
    ensure($intent2['revision'] === 2, 'Version advanced without changing reference');
    ensure(
        $coordinatorA->read($scopeA, $id) === $v2,
        'Committed SQL replacement readable before worker'
    );
    ensure($workerA->runOnce(), 'Immutable second version finalized');
    ensure(
        $coordinatorA->read($scopeA, $id) === $v2,
        'Current version points to confirmed Nextcloud bytes'
    );

    // SQL rollback does not create a published reference, even if a staged
    // file remains on the remote side for conservative later reconciliation.
    sql($db, 'BEGIN');
    $rolled = $coordinatorA->reserve($scopeA, 'synthetic-rolled-back');
    $staged = $coordinatorA->write($scopeA, $rolled['legacy_id'], 'synthetic-orphan');
    sql($db, 'ROLLBACK');
    ensure(assertDenied(static function () use ($coordinatorA, $scopeA, $rolled): void {
        $coordinatorA->read($scopeA, $rolled['legacy_id']);
    }), 'Rolled-back business reference was visible');
    ensure(assertDenied(static function () use ($clientA, $scopeA, $rolled, $staged): void {
        $clientA->download($scopeA, $rolled['document_key'], $staged['operation_key']);
    }), 'Rolled-back remote staged bytes were readable');

    // The same logical document and retry ID are kept after a crash that
    // happened after remote finalize but before SQL outbox completion.
    sql($db, 'BEGIN');
    $late = $coordinatorA->reserve($scopeA, 'synthetic-post-finalize-crash');
    $coordinatorA->write($scopeA, $late['legacy_id'], 'confirmed-before-crash');
    sql($db, 'COMMIT');
    $claimed = $workerLedger->claimNext($scopeA);
    ensure(is_array($claimed), 'Committed crash case was claimed');
    $clientA->finalize($scopeA, $claimed['document_key'], $claimed['operation_key']);
    sql($workerDb, 'UPDATE ecidade_nextcloud.operations '
        . "SET claimed_at=now()-interval '11 minutes' WHERE id=" . (int)$claimed['id']);
    ensure($workerA->runOnce(), 'Idempotent retry after remote success');
    ensure(
        $coordinatorA->read($scopeA, $late['legacy_id']) === 'confirmed-before-crash',
        'Recovered immutable version remains readable'
    );

    // Another principal in a separate tenant may create their own document,
    // but may never access the first tenant's SQL reference or remote bytes.
    sql($db, 'BEGIN');
    $other = $coordinatorB->reserve($scopeB, 'synthetic-business-B');
    $coordinatorB->write($scopeB, $other['legacy_id'], 'synthetic-B-only');
    sql($db, 'COMMIT');
    ensure($workerA->runOnce() === false, 'Worker A selected tenant B operation');
    ensure($workerB->runOnce(), 'Worker B finalized own document');
    ensure(
        $coordinatorB->read($scopeB, $other['legacy_id']) === 'synthetic-B-only',
        'Tenant B own document readable'
    );
    ensure(assertDenied(static function () use ($coordinatorA, $scopeA, $other): void {
        $coordinatorA->read($scopeA, $other['legacy_id']);
    }), 'Tenant A read tenant B SQL reference');
    ensure(assertDenied(static function () use ($clientB, $scopeB, $ref, $intent): void {
        $clientB->download($scopeB, $ref['document_key'], $intent['operation_key']);
    }), 'Tenant B obtained tenant A immutable bytes');

    // Failure of certificate validation MUST fail closed without creating
    // a fallback Large Object in PostgreSQL.
    $badClient = new NextcloudMvpClient($scopeA, [
        'url' => $url,'user' => 'synthetic-admin',
        'token' => (string)getenv('MVP_SYNTHETIC_PASSWORD'),
        'ca_file' => '/sdk/tests/nextcloud/http-contract.php',
    ]);
    ensure(assertDenied(static function () use ($badClient, $scopeA): void {
        $badClient->stage($scopeA, str_repeat('a', 64), str_repeat('b', 64), 'synthetic');
    }), 'Untrusted synthetic CA unexpectedly permitted upload');

    // Force one remote outage using a TEST-only nonexistent hostname,
    // and prove the SQL outbox remains recoverable on a later call.
    sql($db, 'BEGIN');
    $outage = $coordinatorA->reserve($scopeA, 'synthetic-network-outage');
    $coordinatorA->write($scopeA, $outage['legacy_id'], 'recover-later');
    sql($db, 'COMMIT');
    $unavailable = new NextcloudMvpClient($scopeA, [
        'url' => 'https://unreachable.invalid/ocs/v2.php/apps/ecidadelab/api/v2',
        'user' => 'synthetic-admin',
        'token' => (string)getenv('MVP_SYNTHETIC_PASSWORD'),
        'ca_file' => '/synthetic-certs/cert.pem',
    ]);
    $brokenWorker = new DocumentReconciler($workerLedger, $unavailable, $scopeA);
    ensure($brokenWorker->runOnce() === false, 'Expected network outage was not handled');
    sql($db, 'UPDATE ecidade_nextcloud.operations SET next_attempt_at=now() '
        . "WHERE status='staged' AND error_code='REMOTE_UNAVAILABLE'");
    ensure($workerA->runOnce(), 'Worker failed to reconcile after restored network');
    ensure(
        $coordinatorA->read($scopeA, $outage['legacy_id']) === 'recover-later',
        'Previously committed SQL document lost after retry'
    );


    // Complete legacy facade -> SPI -> journal -> verified HTTPS flow.
    // This is a disposable integration fixture, not a municipal ERP bootstrap.
    putenv('ECIDADE_ADAPTER=dbseller');
    putenv('ECIDADE_INTEGRATOR_ROOT=' . dirname(__DIR__, 2));
    require_once dirname(__DIR__, 2) . '/modules/dbseller/DBLargeObject.php';
    $facadePolicy = new SyntheticHttpFacadePolicy();
    $facadeContext = new AdapterContext(
        $scopeA,
        'dbseller',
        'large-object-facade',
        'synthetic-actor'
    );
    $facadeSpi = new AdapterServiceV1(
        $coordinatorA,
        $ledger,
        $facadePolicy,
        [
            'id' => 'dbseller', 'production_ready' => false,
            'storage_capabilities' => [[
                'surface' => 'large-object-facade', 'usage' => 'persistent-document',
                'spi' => 1,
                'operations' => ['reserve', 'create', 'read', 'update', 'delete', 'inspect'],
            ]],
        ]
    );
    $GLOBALS['conn'] = $db;
    LargeObjectBridge::bind($facadeSpi, $facadeContext, $ledger);
    $legacyOid = DBLargeObject::criaOID(false);
    ensure(
        is_int($legacyOid) && $legacyOid >= 3000000000,
        'Legacy facade must receive a logical SQL OID'
    );
    ensure(
        pg_transaction_status($db) === PGSQL_TRANSACTION_IDLE,
        'Legacy facade implicit create must commit'
    );
    ensure(assertDenied(static function () use ($legacyOid): void {
        DBLargeObject::writeContent('no-transaction', $legacyOid);
    }), 'Legacy facade must reject writes without business transaction');

    $facadeV1 = "synthetic-facade-https-v1\0" . str_repeat('Q', 1024);
    $facadeV2 = "synthetic-facade-https-v2\0" . str_repeat('R', 1024);
    $facadeTarget = tempnam(sys_get_temp_dir(), 'spi-https-');
    try {
        sql($db, 'BEGIN');
        ensure(
            DBLargeObject::writeContent($facadeV1, $legacyOid) === strlen($facadeV1),
            'Facade to SPI HTTPS write returned wrong byte count'
        );
        ensure(
            DBLargeObject::leitura($legacyOid, $facadeTarget) === true
            && file_get_contents($facadeTarget) === $facadeV1,
            'Facade cannot read staged HTTPS content in owner transaction'
        );
        $linkedRemoteId = (int)pg_fetch_result(sql(
            $db,
            'SELECT remote_file_id FROM ecidade_nextcloud.operations '
            . 'WHERE legacy_id=' . (int)$legacyOid . '::oid AND operation_type=\'write\''
        ), 0, 0);
        ensure($linkedRemoteId !== $legacyOid, 'Nextcloud file ID leaked as legacy OID');
        ensure(assertDenied(static function () use ($workerLedger, $clientA, $scopeA, $legacyOid): void {
            (new DocumentCoordinator($workerLedger, $clientA))->read($scopeA, $legacyOid);
        }), 'Concurrent request could read uncommitted facade bytes');
        sql($db, 'COMMIT');

        sql($db, 'BEGIN');
        ensure(
            DBLargeObject::leitura($legacyOid, $facadeTarget)
            && file_get_contents($facadeTarget) === $facadeV1,
            'Facade cannot read committed SQL journal before worker'
        );
        sql($db, 'COMMIT');
        ensure($workerA->runOnce(), 'Facade write was not finalized by common worker');

        sql($db, 'BEGIN');
        DBLargeObject::writeContent($facadeV2, $legacyOid);
        ensure(
            DBLargeObject::leitura($legacyOid, $facadeTarget)
            && file_get_contents($facadeTarget) === $facadeV2,
            'Facade cannot read own HTTPS update'
        );
        sql($db, 'ROLLBACK');
        sql($db, 'BEGIN');
        ensure(
            DBLargeObject::leitura($legacyOid, $facadeTarget)
            && file_get_contents($facadeTarget) === $facadeV1,
            'Facade rollback exposed abandoned HTTPS version'
        );
        sql($db, 'COMMIT');

        $facadePolicy->revoked = true;
        sql($db, 'BEGIN');
        ensure(assertDenied(static function () use ($legacyOid, $facadeTarget): void {
            DBLargeObject::leitura($legacyOid, $facadeTarget);
        }), 'Revoked ERP actor could read staged or committed content');
        sql($db, 'ROLLBACK');
        $facadePolicy->revoked = false;

        sql($db, 'BEGIN');
        ensure(DBLargeObject::exclusao($legacyOid), 'Legacy facade delete return mismatch');
        sql($db, 'ROLLBACK');
        sql($db, 'BEGIN');
        ensure(
            DBLargeObject::leitura($legacyOid, $facadeTarget)
            && file_get_contents($facadeTarget) === $facadeV1,
            'Rollback of facade deletion lost existing document'
        );
        ensure(DBLargeObject::exclusao($legacyOid), 'Legacy facade delete failed');
        sql($db, 'COMMIT');
        sql($db, 'BEGIN');
        ensure(assertDenied(static function () use ($legacyOid, $facadeTarget): void {
            DBLargeObject::leitura($legacyOid, $facadeTarget);
        }), 'Facade deletion was not visible after commit');
        sql($db, 'ROLLBACK');
    } finally {
        @unlink($facadeTarget);
    }

    // Pure metadata journal: not one new PostgreSQL Large Object was created.
    $after = (int)pg_fetch_result(
        sql($db, 'SELECT count(*) FROM pg_largeobject_metadata'),
        0,
        0
    );
    ensure($after === $baseline, 'File bytes unexpectedly duplicated in PostgreSQL');
    $byteaCols = (int)pg_fetch_result(sql(
        $db,
        'SELECT count(*) FROM information_schema.columns '
        . "WHERE table_schema='ecidade_nextcloud' AND data_type='bytea'"
    ), 0, 0);
    ensure($byteaCols === 0, 'Unexpected bytea columns in SQL metadata journal');

    echo 'PASS synthetic PG11 + verified HTTPS + Nextcloud 35 end-to-end ('
        . $checks . " checks), independent refs and no binary duplication\n";
} catch (Throwable $failure) {
    fwrite(STDERR, 'FAIL synthetic PG/HTTPS contract: ' . $failure->getMessage() . "\n");
    exit(1);
} finally {
    pg_close($workerDb);
    pg_close($db);
}
