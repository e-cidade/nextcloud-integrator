<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * Exercise the original generated DAO and Protocolo model through the shared
 * SPI, with an isolated fake remote or verified-HTTPS disposable Nextcloud.
 * Legacy is a regression characterization; fixed is an unmerged PR preview.
 * The ERP bootstrap, business schema, session and HTTP route remain untested.
 */
$onCi = getenv('GITHUB_ACTIONS') === 'true' && getenv('PGHOST') === '127.0.0.1';
$inContainer = getenv('ECIDADE_SYNTHETIC_TEST') === '1'
    && getenv('PGHOST') === 'mvp-db-ci0001' && is_file('/.dockerenv');
$liveHttps = getenv('ECIDADE_SYNTHETIC_HTTPS_TEST') === '1';
if (PHP_SAPI !== 'cli' || getenv('ECIDADE_SYNTHETIC_FORK_CONTRACT') !== '1'
    || (!$onCi && !$inContainer)
    || getenv('PGDATABASE') !== 'ecidade_synthetic_test'
    || getenv('PGUSER') !== 'mvp_test' || !getenv('PGPASSWORD')
    || !extension_loaded('pgsql')) {
    fwrite(STDERR, "Refusing non-synthetic generated-DAO fixture\n");
    exit(2);
}
if ($liveHttps && (!$inContainer || !is_file('/synthetic-certs/cert.pem')
    || !getenv('ECIDADE_MVP_PENDING_READ_KEY')
    || getenv('MVP_SYNTHETIC_PASSWORD') !== getenv('PGPASSWORD')
    || !extension_loaded('curl'))) {
    fwrite(STDERR, "Refusing unverified original DAO HTTPS fixture\n");
    exit(2);
}
function daoPinned($file, $expectedName, $expectedBlob)
{
    if (basename($file) !== $expectedName || !is_file($file)) {
        throw new RuntimeException('Missing pinned original public PHP source');
    }
    $bytes = file_get_contents($file);
    $blob = sha1('blob ' . strlen($bytes) . "\0" . $bytes);
    if ($blob !== $expectedBlob) {
        throw new RuntimeException('Unexpected generated DAO or model revision');
    }
    return realpath($file);
}
$fork = isset($argv[3]) ? $argv[3] : 'dbseller';
$variant = isset($argv[4]) ? $argv[4] : 'legacy';
if ($liveHttps && $fork !== 'dbseller') {
    fwrite(STDERR, "Refusing cross-fork original DAO HTTPS fixture\n");
    exit(2);
}
$modelBlobs = [
    'dbseller' => [
        'legacy' => '4935e0514c8b206853f071d60612647503cedb99',
        'fixed' => '82454dc863697abaf9c754e5cd9079d0d9a4c2ce',
    ],
    'cpd' => [
        'legacy' => 'b2b62a1cfc49bc402f5a10a4b063219ec7f230e6',
        'fixed' => 'fcc315394d4e87ea7769ec27803f8ae89f3f9af2',
    ],
];
$daoBlobs = [
    'dbseller' => 'b1f52999d0f0e630e9dfcac7a04fa93a161e136a',
    'cpd' => '9e130f3a86377bc17b8e7833c6b54a5d5d782939',
];
if (!isset($modelBlobs[$fork][$variant])) {
    throw new RuntimeException('Unsupported pinned original DAO test variant');
}
$modelPath = daoPinned(
    isset($argv[1]) ? $argv[1] : '',
    'ProcessoDocumento.model.php',
    $modelBlobs[$fork][$variant]
);
$daoPath = daoPinned(
    isset($argv[2]) ? $argv[2] : '',
    'db_protprocessodocumento_classe.php',
    $daoBlobs[$fork]
);
$root = dirname(dirname(__DIR__));
putenv('ECIDADE_ADAPTER=' . $fork);
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
require_once $root . '/tests/fixtures/SyntheticProtocolStorageHelper.php';
date_default_timezone_set('UTC');

use ECidade\Nextcloud\Application\AdapterServiceV1;
use ECidade\Nextcloud\Application\DocumentCoordinator;
use ECidade\Nextcloud\Application\DocumentReconciler;
use ECidade\Nextcloud\Contract\DocumentAccessPolicy;
use ECidade\Nextcloud\Domain\AdapterContext;
use ECidade\Nextcloud\Domain\Scope;
use ECidade\Nextcloud\LargeObjectBridge;
use ECidade\Nextcloud\Persistence\PgDocumentLedger;
use ECidade\Nextcloud\Transport\NextcloudMvpClient;

class SyntheticGeneratedDaoPolicy implements DocumentAccessPolicy
{
    public $fork = 'dbseller';
    public $revoked = false;
    public function allows(AdapterContext $context, $operation, $legacyId)
    {
        return !$this->revoked && $context->actor() === 'synthetic-protocol-actor'
            && $context->backend() === $this->fork
            && $context->scope()->equals(new Scope('installation-a', 'municipio-a'));
    }
}
class DBException extends Exception
{
}
class BusinessException extends Exception
{
}
class processoProtocolo
{
    private $id;
    public function __construct($id)
    {
        $this->id = $id;
    }
    public function getCodProcesso()
    {
        return $this->id;
    }
}
class UsuarioSistema
{
    private $id;
    public function __construct($id)
    {
        $this->id = $id;
    }
    public function getIdUsuario()
    {
        return $this->id;
    }
}
class rotulo
{
    public function __construct($table)
    {
        if ($table !== 'protprocessodocumento') {
            throw new RuntimeException('Unexpected generated DAO');
        }
    }
}
function _M($key, $args = null)
{
    return $key;
}
function modification($source)
{
    if ($source !== 'std/DBLargeObject.php') {
        throw new RuntimeException('Unexpected original source dependency');
    }
    return dirname(dirname(__DIR__)) . '/modules/' . getenv('ECIDADE_ADAPTER') . '/DBLargeObject.php';
}
function db_getsession($name, $default = null)
{
    if ($name === 'DB_desativar_account') {
        // No real audit/session tables exist in a synthetic database.
        return true;
    }
    throw new RuntimeException('Unexpected municipal session lookup');
}
function db_query($sql)
{
    $result = @pg_query($GLOBALS['conn'], $sql);
    if ($result === false) {
        throw new RuntimeException('Original DAO issued invalid synthetic SQL');
    }
    return $result;
}
class db_utils
{
    public static function inTransaction($connection = null)
    {
        return pg_transaction_status($connection ? $connection : $GLOBALS['conn'])
            === PGSQL_TRANSACTION_INTRANS;
    }
    public static function getDao($name)
    {
        if ($name !== 'protprocessodocumento') {
            throw new RuntimeException('Unexpected original DAO requested');
        }
        return new cl_protprocessodocumento();
    }
    public static function fieldsMemory($result, $index)
    {
        return pg_fetch_object($result, $index);
    }
}
function assertGenerated($ok, $message)
{
    if (!$ok) {
        throw new RuntimeException('Original generated-DAO characterization: ' . $message);
    }
}

$connection = 'host=' . getenv('PGHOST')
    . ' port=5432 dbname=ecidade_synthetic_test'
    . ' user=mvp_test password=' . getenv('PGPASSWORD') . ' connect_timeout=5';
$conn = pg_connect($connection, PGSQL_CONNECT_FORCE_NEW);
$reader = pg_connect($connection, PGSQL_CONNECT_FORCE_NEW);
if (!$conn || !$reader) {
    throw new RuntimeException('Synthetic PostgreSQL not available');
}
$workspace = rtrim(sys_get_temp_dir(), '/') . '/original-dao-' . getmypid();
if (!mkdir($workspace, 0700) || !mkdir($workspace . '/tmp', 0700)) {
    throw new RuntimeException('Cannot create isolated synthetic workspace');
}
$oldCwd = getcwd();
chdir($workspace);

try {
    // Original DAO SQL is not rewritten. Only fixed synthetic table names,
    // sequence and a read-only account-logging seam are provided.
    $syntheticSchema = 'generated_dao_synthetic_' . $fork . '_' . $variant;
    db_query('CREATE SCHEMA ' . $syntheticSchema);
    db_query('SET search_path TO ' . $syntheticSchema . ',public');
    db_query('CREATE SEQUENCE protprocessodocumento_p01_sequencial_seq');
    db_query('CREATE TABLE protprocessodocumento ('
        . 'p01_sequencial int PRIMARY KEY, p01_protprocesso bigint NOT NULL, '
        . 'p01_descricao text NOT NULL, p01_documento oid, '
        . 'p01_nomedocumento text NOT NULL, p01_data date, '
        . 'p01_usuario bigint, p01_procandamint bigint, '
        . 'p01_estorage boolean NOT NULL DEFAULT false, p01_ordem int, '
        . 'p01_assinado boolean, p01_assinado_por bigint, '
        . 'p01_documento_hash text, p01_c70codlan bigint, '
        . 'p01_upload_name text)');
    db_query('CREATE TABLE db_usuarios (id_usuario bigint PRIMARY KEY)');
    db_query('INSERT INTO db_usuarios(id_usuario) VALUES (1001)');
    $schemaCount = pg_fetch_result(db_query(
        "SELECT count(*) FROM information_schema.tables WHERE table_schema='ecidade_nextcloud'"
    ), 0, 0);
    if ((int)$schemaCount === 0) {
        db_query(file_get_contents($root . '/database/001_opt_in_mvp_schema.sql'));
    } else {
        assertGenerated((int)$schemaCount === 2, 'Incomplete shared ledger');
    }
    $baseline = (int)pg_fetch_result(db_query(
        'SELECT count(*) FROM pg_largeobject_metadata'
    ), 0, 0);

    $_SERVER['PHP_SELF'] = 'synthetic-protocol.php';
    require_once $daoPath;
    require_once $modelPath;
    assertGenerated(class_exists('cl_protprocessodocumento')
        && class_exists('ProcessoDocumento'), 'Original source classes missing');

    $scope = new Scope('installation-a', 'municipio-a');
    $ledger = new PgDocumentLedger($conn);
    $readerLedger = new PgDocumentLedger($reader);
    $remote = $liveHttps ? new NextcloudMvpClient($scope, [
        'url' => 'https://synthetic-nextcloud/ocs/v2.php/apps/ecidadelab/api/v2',
        'user' => 'synthetic-admin',
        'token' => getenv('MVP_SYNTHETIC_PASSWORD'),
        'ca_file' => '/synthetic-certs/cert.pem',
        'pending_read_key' => getenv('ECIDADE_MVP_PENDING_READ_KEY'),
    ]) : new FakeRemoteDocuments();
    $coordinator = new DocumentCoordinator($ledger, $remote);
    $worker = new DocumentReconciler($readerLedger, $remote, $scope);
    $context = new AdapterContext(
        $scope,
        $fork,
        'large-object-facade',
        'synthetic-protocol-actor'
    );
    $policy = new SyntheticGeneratedDaoPolicy();
    $policy->fork = $fork;
    $spi = new AdapterServiceV1(
        $coordinator,
        $ledger,
        $policy,
        [
            'id' => $fork,'production_ready' => false,
            'storage_capabilities' => [[
                'surface' => 'large-object-facade', 'usage' => 'persistent-document',
                'spi' => 1,
                'operations' => ['reserve','create','read','update','delete','inspect'],
            ]],
        ]
    );
    LargeObjectBridge::bind($spi, $context, $ledger);

    $bytes = "public-source-original-dao-fixture\0" . str_repeat('B', 8192);
    $upload = $workspace . '/synthetic.pdf';
    file_put_contents($upload, $bytes);
    $model = new ProcessoDocumento();
    $model->setProcessoProtocolo(new processoProtocolo(900001));
    $model->setDescricao('Generated DAO synthetic attachment');
    $model->setUsuario(new UsuarioSistema(1001));
    $model->setCaminhoArquivo($upload);
    $model->setOrdem(1);

    db_query('BEGIN');
    assertGenerated((bool)$model->salvar(), 'Original model/DAO insert failed');
    $businessId = (int)$model->getCodigo();
    $oid = (int)$model->getOID();
    assertGenerated(
        $businessId > 0 && $oid >= 3000000000,
        'Legacy business ID and logical OID invalid'
    );
    $sqlRow = db_query('SELECT p01_documento::text AS oid, '
        . 'CASE WHEN p01_estorage THEN 1 ELSE 0 END AS estorage '
        . 'FROM protprocessodocumento WHERE p01_sequencial=' . $businessId);
    assertGenerated(
        (int)pg_fetch_result($sqlRow, 0, 'oid') === $oid,
        'Original generated DAO lost business OID reference'
    );
    $expectedStorageFlag = $variant === 'legacy' ? 1 : 0;
    assertGenerated(
        (int)pg_fetch_result($sqlRow, 0, 'estorage') === $expectedStorageFlag,
        'Generated DAO wrote an unexpected E-Storage flag for OID bytes'
    );
    // The original model, business row and SPI outbox must share SQL visibility.
    $sameTransactionPath = $model->download();
    assertGenerated(file_get_contents($sameTransactionPath) === $bytes,
        'Original model cannot read uncommitted bytes in its own transaction');
    unlink($sameTransactionPath);
    assertGenerated((int)pg_fetch_result(pg_query($reader,
        'SELECT count(*) FROM ' . $syntheticSchema . '.protprocessodocumento'
    ), 0, 0) === 0, 'Original DAO exposed an uncommitted business row');
    assertGenerated((int)pg_fetch_result(pg_query($reader,
        'SELECT count(*) FROM ecidade_nextcloud.operations WHERE legacy_id=' . $oid
        . "::oid AND operation_type='write'"
    ), 0, 0) === 0, 'Original DAO exposed an uncommitted document outbox');
    db_query('COMMIT');
    $beforeWorker = new ProcessoDocumento($businessId);
    db_query('BEGIN');
    $pendingPath = $beforeWorker->download();
    assertGenerated(file_get_contents($pendingPath) === $bytes,
        'Original DAO could not read committed bytes before asynchronous worker');
    unlink($pendingPath);
    db_query('COMMIT');
    assertGenerated($worker->runOnce(), 'Shared worker failed to finalize original DAO upload');
    assertGenerated(hash('sha256', $spi->read($context, $oid)) === hash('sha256', $bytes),
        'Nextcloud reconciliation modified original document checksum');

    // Compare the unchanged public source with a pinned proposed vendor fix.
    // Both versions must still retrieve identical bytes from the SPI bridge.
    $loaded = new ProcessoDocumento($businessId);
    assertGenerated(
        $loaded->isStorage() === ($variant === 'legacy'),
        'Original DAO storage flag not faithfully reconstructed on reload'
    );
    db_query('BEGIN');
    $path = $loaded->download();
    assertGenerated(
        file_get_contents($path) === $bytes,
        'Original fallback read did not return unchanged bytes'
    );
    unlink($path);
    assertGenerated(
        ($variant === 'legacy' && \App\Domain\Configuracao\Helpers\StorageHelper::$attempts > 0)
        || ($variant === 'fixed' && \App\Domain\Configuracao\Helpers\StorageHelper::$attempts === 0),
        'E-Storage called unexpectedly for OID content or legacy bug not reproduced'
    );
    db_query('COMMIT');

    // The proposed upstream source must distinguish every known historical
    // PostgreSQL boolean representation. The actual model method is invoked.
    if ($variant === 'fixed') {
        $historical = [
            ['t', true], ['true', true], [' TRUE ', true], ['1', true],
            [true, true], [1, true], ['f', false], ['false', false],
            ['0', false], ['', false], [false, false], [0, false],
            [null, false], ['on', false],
        ];
        foreach ($historical as $case) {
            $loaded->setStorage($case[0]);
            assertGenerated($loaded->isStorage() === $case[1],
                'Proposed upstream fix misclassified historical storage value');
        }
        $loaded->setStorage('t');
        $attempts = \App\Domain\Configuracao\Helpers\StorageHelper::$attempts;
        db_query('BEGIN');
        $truePath = $loaded->download();
        assertGenerated(file_get_contents($truePath) === $bytes,
            'Legacy fallback after E-Storage error lost original bytes');
        unlink($truePath);
        db_query('COMMIT');
        assertGenerated(\App\Domain\Configuracao\Helpers\StorageHelper::$attempts
            === $attempts + 1, 'True E-Storage flag did not invoke original helper');
        $loaded->setStorage('f');
    }

    // For an existing document, original salvar() changes SQL metadata only.
    db_query('BEGIN');
    $loaded->setDescricao('Updated original generated DAO metadata');
    assertGenerated((bool)$loaded->salvar(), 'Original DAO metadata update failed');
    db_query('COMMIT');
    assertGenerated(pg_fetch_result(db_query(
        'SELECT p01_descricao FROM protprocessodocumento WHERE p01_sequencial=' . $businessId
    ), 0, 0) === 'Updated original generated DAO metadata',
        'Original DAO did not persist metadata update');

    $policy->revoked = true;
    db_query('BEGIN');
    $denied = false;
    try {
        $loaded->download();
    } catch (Exception $error) {
        $denied = true;
    }
    db_query('ROLLBACK');
    assertGenerated($denied, 'Revoked actor could read original business document');
    $policy->revoked = false;

    $foreignDenied = false;
    try {
        (new DocumentCoordinator($readerLedger, $remote))->read(
            new Scope('installation-b', 'municipio-b'), $oid
        );
    } catch (Exception $error) {
        $foreignDenied = true;
    }
    assertGenerated($foreignDenied, 'Original business OID leaked across installations');

    // Byte updates use the same original DBLargeObject API, not salvar().
    $replacement = "original-dao-v2\0" . str_repeat('C', 4096);
    db_query('BEGIN');
    assertGenerated(DBLargeObject::writeContent($replacement, $oid) === strlen($replacement),
        'Original legacy facade rejected staged byte update');
    $provisional = $loaded->download();
    assertGenerated(file_get_contents($provisional) === $replacement,
        'Original model did not expose its own provisional bytes');
    unlink($provisional);
    db_query('ROLLBACK');
    assertGenerated($spi->read($context, $oid) === $bytes,
        'Aborted original DAO update was exposed after ROLLBACK');

    db_query('BEGIN');
    assertGenerated(DBLargeObject::writeContent($replacement, $oid) === strlen($replacement),
        'Original legacy facade rejected committed byte update');
    db_query('COMMIT');
    assertGenerated($spi->read($context, $oid) === $replacement,
        'Original DAO version two unreadable immediately after COMMIT');
    // A fresh instance of the existing worker must recover the SQL outbox.
    $restartedWorker = new DocumentReconciler(new PgDocumentLedger($reader), $remote, $scope);
    assertGenerated($restartedWorker->runOnce(), 'Shared worker failed after reconstruction');
    db_query('BEGIN');
    $updatedPath = $loaded->download();
    assertGenerated(file_get_contents($updatedPath) === $replacement,
        'Original business download did not retrieve immutable version two');
    unlink($updatedPath);
    db_query('COMMIT');
    assertGenerated((int)pg_fetch_result(db_query(
        'SELECT p01_documento::text FROM protprocessodocumento '
        . 'WHERE p01_sequencial=' . $businessId
    ), 0, 0) === $oid, 'Original DAO changed business OID after version update');
    assertGenerated((int)pg_fetch_result(db_query(
        'SELECT count(*) FROM ecidade_nextcloud.operations '
        . 'WHERE legacy_id=' . $oid . "::oid AND operation_type='write'"
    ), 0, 0) === 2, 'Original DAO did not retain two immutable revisions');
    assertGenerated(pg_fetch_result(db_query(
        'SELECT checksum FROM ecidade_nextcloud.operations '
        . 'WHERE legacy_id=' . $oid . "::oid AND operation_type='write' ORDER BY id DESC LIMIT 1"
    ), 0, 0) === hash('sha256', $replacement),
        'Immutable version two checksum differs from original bytes');

    db_query('BEGIN');
    assertGenerated($loaded->excluir() === true, 'Original DAO rollback delete failed');
    db_query('ROLLBACK');
    assertGenerated((int)pg_fetch_result(db_query(
        'SELECT count(*) FROM protprocessodocumento WHERE p01_sequencial=' . $businessId
    ), 0, 0) === 1, 'ROLLBACK deleted original business record');
    assertGenerated($spi->read($context, $oid) === $replacement,
        'ROLLBACK tombstoned original document bytes');

    // CPD and DBSeller characterization still intentionally differ.
    // CPD legacy skips its OID tombstone if the wrongly stored E-Storage
    // flag is true; DBSeller always calls the tombstone. Both fixed sources
    // must delete the document and its metadata in one SQL transaction.
    $expectOrphan = $fork === 'cpd' && $variant === 'legacy';
    db_query('BEGIN');
    assertGenerated($loaded->excluir() === true, 'Original DAO delete failed');
    assertGenerated((int)pg_fetch_result(db_query(
        'SELECT count(*) FROM protprocessodocumento WHERE p01_sequencial=' . $businessId
    ), 0, 0) === 0, 'Business record was not deleted');
    $visible = true;
    try {
        $spi->read($context, $oid);
    } catch (Exception $error) {
        $visible = false;
    }
    assertGenerated(
        $visible === $expectOrphan,
        'Original model did not honor expected OID tombstone behavior'
    );
    db_query('COMMIT');
    $visible = true;
    try {
        $spi->read($context, $oid);
    } catch (Exception $error) {
        $visible = false;
    }
    assertGenerated(
        $visible === $expectOrphan,
        'Committed delete differed from the original business model behavior'
    );
    assertGenerated((int)pg_fetch_result(db_query(
        'SELECT count(*) FROM pg_largeobject_metadata'
    ), 0, 0) === $baseline, 'Generated DAO wrote an additional PostgreSQL Large Object');
    assertGenerated((int)pg_fetch_result(db_query(
        'SELECT count(*) FROM information_schema.columns '
        . "WHERE table_schema IN ('" . $syntheticSchema . "','ecidade_nextcloud') "
        . "AND data_type='bytea'"
    ), 0, 0) === 0, 'Generated DAO or shared journal duplicated bytes in bytea');
    assertGenerated(count(glob($workspace . '/tmp/*')) === 0,
        'Original business download left persistent files in temporary staging');

    echo 'PASS original ' . $fork . ' Protocolo model and generated DAO (' . $variant . ')'
        . ($variant === 'legacy' ? ': demonstrated E-Storage flag regression' : ': OID classification corrected')
        . ($expectOrphan ? ' AND deletion orphan in CPD' : ', OID tombstone verified')
        . ($liveHttps ? ', verified Nextcloud HTTPS' : ', fake remote')
        . ', SQL rollback, ACL and immutable revisions, PHP ' . PHP_VERSION . "\n";
} finally {
    chdir($oldCwd);
    foreach (glob($workspace . '/tmp/*') as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    @unlink($workspace . '/synthetic.pdf');
    @rmdir($workspace . '/tmp');
    @rmdir($workspace);
    @pg_close($reader);
    @pg_close($conn);
}
