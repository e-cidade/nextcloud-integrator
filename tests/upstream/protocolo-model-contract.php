<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * Pinned original ProcessoDocumento business methods, not a reimplementation.
 * DAO and PostgreSQL schema are synthetic; Nextcloud is a remote test double.
 * No municipal ERP, dataset, filesystem or production host may be contacted.
 */
$onCi = getenv('GITHUB_ACTIONS') === 'true' && getenv('PGHOST') === '127.0.0.1';
$inContainer = getenv('ECIDADE_SYNTHETIC_TEST') === '1'
    && getenv('PGHOST') === 'mvp-db-ci0001' && is_file('/.dockerenv');
if (PHP_SAPI !== 'cli' || getenv('ECIDADE_SYNTHETIC_FORK_CONTRACT') !== '1'
    || (!$onCi && !$inContainer) || getenv('PGDATABASE') !== 'ecidade_synthetic_test'
    || getenv('PGUSER') !== 'mvp_test' || !getenv('PGPASSWORD')
    || !extension_loaded('pgsql')) {
    fwrite(STDERR, "Refusing non-synthetic protocol document fixture\n");
    exit(2);
}
$fork = isset($argv[2]) ? $argv[2] : 'dbseller';
$pinnedModelHashes = [
    'dbseller' => '251ac8e5087c1e29280189eccd89ab452e54ca00',
    'contass' => '2eb3782e84400d40bae177d598445e26140ddf93',
    'cpd' => 'b2b62a1cfc49bc402f5a10a4b063219ec7f230e6',
];
if (!isset($pinnedModelHashes[$fork])) {
    throw new RuntimeException('Unknown pinned protocol fork');
}
$source = isset($argv[1]) ? $argv[1] : '';
if (basename($source) !== 'ProcessoDocumento.model.php' || !is_file($source)) {
    throw new RuntimeException('Pinned public protocol model is required');
}
$original = file_get_contents($source);
$blob = sha1('blob ' . strlen($original) . "\0" . $original);
if ($blob !== $pinnedModelHashes[$fork]) {
    throw new RuntimeException('Public protocol model differs from pinned revision');
}
$source = realpath($source);
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

class SyntheticProtocolPolicy implements DocumentAccessPolicy
{
    public $revoked = false;
    public $fork = 'dbseller';
    public function allows(AdapterContext $context, $operation, $legacyId)
    {
        return !$this->revoked && $context->backend() === $this->fork
            && $context->actor() === 'synthetic-protocol-actor'
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
function _M($key, $args = null)
{
    return $key;
}
function db_getsession($name)
{
    if ($name !== 'DB_coddepto') {
        throw new RuntimeException('Unexpected synthetic session field');
    }
    return 7;
}

function modification($source)
{
    if ($source !== 'std/DBLargeObject.php') {
        throw new RuntimeException('Unverified original model dependency');
    }
    return dirname(dirname(__DIR__)) . '/modules/' . getenv('ECIDADE_ADAPTER') . '/DBLargeObject.php';
}
function protoSql($sql, array $params = [])
{
    global $conn, $fork;
    $sql = str_replace('synthetic_protocolo', 'synthetic_protocolo_' . $fork, $sql);
    $result = count($params) ? @pg_query_params($conn, $sql, $params) : @pg_query($conn, $sql);
    if ($result === false) {
        throw new RuntimeException('Synthetic protocol SQL failed');
    }
    return $result;
}
function protoAssert($ok, $why)
{
    if (!$ok) {
        throw new RuntimeException('Pinned protocol business contract failed: ' . $why);
    }
}
function protoDenied($callback)
{
    try {
        call_user_func($callback);
    } catch (Exception $error) {
        return true;
    }
    return false;
}
function protoWriteContent($bytes, $oid, $file)
{
    if (getenv('ECIDADE_ADAPTER') === 'contass') {
        file_put_contents($file, $bytes);
        return DBLargeObject::escrita($file, $oid);
    }
    return DBLargeObject::writeContent($bytes, $oid);
}

class db_utils
{
    public static function inTransaction($connection = null)
    {
        $connection = $connection ? $connection : $GLOBALS['conn'];
        return pg_transaction_status($connection) === PGSQL_TRANSACTION_INTRANS;
    }
    public static function getDao($name)
    {
        if ($name !== 'protprocessodocumento') {
            throw new RuntimeException('Unexpected DAO in protocol fixture');
        }
        return new cl_protprocessodocumento();
    }
    public static function fieldsMemory($result, $index)
    {
        return pg_fetch_object($result, $index);
    }
}

/**
 * Minimal SQL DAO seam for the ORIGINAL protocol model.
 * DAO methods write actual synthetic SQL records within ERP transactions.
 * The business model itself is loaded unchanged from pinned public source.
 */
class cl_protprocessodocumento
{
    public $erro_status = '1';
    public $p01_sequencial;
    public $p01_protprocesso;
    public $p01_descricao;
    public $p01_documento;
    public $p01_nomedocumento;
    public $p01_data;
    public $p01_usuario;
    public $p01_procandamint;
    public $p01_estorage;
    public $p01_ordem;
    public $p01_assinado;
    public $p01_assinado_por;
    public $p01_documento_hash;
    public $p01_depart;
    public $p01_nivelacesso;

    public function incluir($unused)
    {
        $sql = 'INSERT INTO synthetic_protocolo.documents '
            . '(p01_protprocesso,p01_descricao,p01_documento,p01_nomedocumento,'
            . 'p01_data,p01_usuario,p01_procandamint,p01_estorage,'
            . 'p01_ordem,p01_assinado,p01_assinado_por,p01_documento_hash,'
            . 'p01_depart,p01_nivelacesso) '
            . 'VALUES ($1,$2,$3::oid,$4,$5,$6,$7,$8::boolean,$9,$10,$11,$12,$13,$14) '
            . 'RETURNING p01_sequencial';
        $result = protoSql($sql, [
            $this->p01_protprocesso, $this->p01_descricao, $this->p01_documento,
            $this->p01_nomedocumento, $this->p01_data, $this->p01_usuario,
            $this->p01_procandamint,
            $this->p01_estorage === null ? 'false' : (is_bool($this->p01_estorage)
                ? ($this->p01_estorage ? 't' : 'f') : $this->p01_estorage),
            $this->p01_ordem, $this->p01_assinado, $this->p01_assinado_por,
            $this->p01_documento_hash, $this->p01_depart, $this->p01_nivelacesso,
        ]);
        $this->p01_sequencial = (int)pg_fetch_result($result, 0, 0);
    }

    public function alterar($id)
    {
        protoSql(
            'UPDATE synthetic_protocolo.documents '
            . 'SET p01_descricao=$1,p01_nomedocumento=COALESCE($2,p01_nomedocumento),'
            . 'p01_documento=COALESCE($3::oid,p01_documento),'
            . 'p01_assinado=COALESCE($4,p01_assinado),'
            . 'p01_assinado_por=COALESCE($5,p01_assinado_por),'
            . 'p01_ordem=COALESCE($6,p01_ordem),'
            . 'p01_documento_hash=COALESCE($7,p01_documento_hash),'
            . 'p01_nivelacesso=COALESCE($9,p01_nivelacesso) '
            . 'WHERE p01_sequencial=$8',
            [$this->p01_descricao,$this->p01_nomedocumento,$this->p01_documento,
                $this->p01_assinado,$this->p01_assinado_por,$this->p01_ordem,
                $this->p01_documento_hash,$id,$this->p01_nivelacesso]
        );
    }

    public function excluir($id)
    {
        protoSql('DELETE FROM synthetic_protocolo.documents WHERE p01_sequencial=$1', [$id]);
    }

    public function sql_query_file($id)
    {
        return $this->sql_query_documento_usuario($id);
    }

    public function sql_query_documento_usuario($id)
    {
        return 'SELECT * FROM synthetic_protocolo.documents WHERE p01_sequencial=' . (int)$id;
    }

    public function sql_record($sql)
    {
        $result = protoSql($sql);
        $this->erro_status = pg_num_rows($result) ? '1' : '0';
        return $result;
    }
}

$connection = 'host=' . getenv('PGHOST') . ' port=5432 dbname=ecidade_synthetic_test'
    . ' user=mvp_test password=' . getenv('PGPASSWORD') . ' connect_timeout=5';
$conn = pg_connect($connection, PGSQL_CONNECT_FORCE_NEW);
$reader = pg_connect($connection, PGSQL_CONNECT_FORCE_NEW);
if (!$conn || !$reader) {
    throw new RuntimeException('Synthetic protocol database unavailable');
}
$workspace = rtrim(sys_get_temp_dir(), '/') . '/synthetic-protocol-' . getmypid();
if (!mkdir($workspace, 0700) || !mkdir($workspace . '/tmp', 0700)) {
    throw new RuntimeException('Synthetic protocol workspace unavailable');
}
$oldCwd = getcwd();
chdir($workspace);
// Contass includes the facade directly (no modification() hook). Recreate
// that override in a disposable synthetic filesystem, never in a fork tree.
if ($fork === 'contass') {
    if (!mkdir($workspace . '/std', 0700)) {
        throw new RuntimeException('Cannot prepare synthetic Contass facade path');
    }
    file_put_contents(
        $workspace . '/std/DBLargeObject.php',
        '<?php require_once ' . var_export($root . '/modules/contass/DBLargeObject.php', true) . ';'
    );
}

$checks = 0;
try {
    $existingSchema = pg_fetch_result(protoSql(
        "SELECT count(*) FROM information_schema.tables WHERE table_schema='ecidade_nextcloud'"
    ), 0, 0);
    if ((int)$existingSchema === 0) {
        protoSql(file_get_contents($root . '/database/001_opt_in_mvp_schema.sql'));
    } else {
        protoAssert((int)$existingSchema === 2, 'Incomplete synthetic journal');
    }
    protoSql('CREATE SCHEMA synthetic_protocolo');
    protoSql('CREATE TABLE synthetic_protocolo.documents ('
        . 'p01_sequencial serial PRIMARY KEY, p01_protprocesso bigint NOT NULL, '
        . 'p01_descricao text NOT NULL, p01_documento oid NOT NULL, '
        . 'p01_nomedocumento text NOT NULL, p01_data date, '
        . 'p01_usuario integer, p01_procandamint integer, '
        . 'p01_estorage boolean NOT NULL DEFAULT false, p01_ordem integer, '
        . 'p01_assinado boolean, p01_assinado_por text, '
        . 'p01_documento_hash text, p01_depart integer, p01_nivelacesso integer)');
    $baseline = (int)pg_fetch_result(
        protoSql('SELECT count(*) FROM pg_largeobject_metadata'),
        0,
        0
    );
    protoAssert($baseline <= 1, 'Unexpected native LO baseline in disposable test database');
    ++$checks;

    $scope = new Scope('installation-a', 'municipio-a');
    $ledger = new PgDocumentLedger($conn);
    $readerLedger = new PgDocumentLedger($reader);
    $liveHttps = getenv('ECIDADE_SYNTHETIC_HTTPS_TEST') === '1';
    if ($liveHttps && (!$inContainer || !is_file('/synthetic-certs/cert.pem')
        || !getenv('ECIDADE_MVP_PENDING_READ_KEY')
        || getenv('MVP_SYNTHETIC_PASSWORD') !== getenv('PGPASSWORD'))) {
        throw new RuntimeException('Refusing unverified Nextcloud HTTPS test configuration');
    }
    $remote = $liveHttps ? new NextcloudMvpClient($scope, [
        'url' => 'https://synthetic-nextcloud/ocs/v2.php/apps/ecidadelab/api/v2',
        'user' => 'synthetic-admin',
        'token' => getenv('MVP_SYNTHETIC_PASSWORD'),
        'ca_file' => '/synthetic-certs/cert.pem',
        'pending_read_key' => getenv('ECIDADE_MVP_PENDING_READ_KEY'),
    ]) : new FakeRemoteDocuments();
    $coordinator = new DocumentCoordinator($ledger, $remote);
    $worker = new DocumentReconciler($readerLedger, $remote, $scope);
    $policy = new SyntheticProtocolPolicy();
    $policy->fork = $fork;
    $context = new AdapterContext(
        $scope,
        $fork,
        'large-object-facade',
        'synthetic-protocol-actor'
    );
    $manifest = [
        'id' => $fork,'production_ready' => false,
        'storage_capabilities' => [[
            'surface' => 'large-object-facade', 'usage' => 'persistent-document',
            'spi' => 1,
            'operations' => ['reserve','create','read','update','delete','inspect'],
        ]],
    ];
    $spi = new AdapterServiceV1($coordinator, $ledger, $policy, $manifest);
    LargeObjectBridge::bind($spi, $context, $ledger);

    // Actual pinned business model, loaded unchanged; only its SQL DAO is
    // replaced by an in-memory test seam using genuine PostgreSQL statements.
    require_once $source;
    protoAssert(class_exists('ProcessoDocumento'), 'Original protocol model not loaded');
    ++$checks;

    $bytes = "synthetic-protocol-document\0" . str_repeat('X', 16384);
    $input = $workspace . '/attachment.pdf';
    file_put_contents($input, $bytes);
    $model = new ProcessoDocumento();
    $model->setProcessoProtocolo(new processoProtocolo(850034));
    $model->setDescricao('Synthetic protocol annex');
    if ($fork !== 'contass') {
        $model->setUsuario(new UsuarioSistema(1001));
        $model->setOrdem(1);
    }
    $model->setCaminhoArquivo($input);
    if ($fork === 'dbseller') {
        // This input is a PostgreSQL wire representation, not a boolean.
        // The original model must not skip OID allocation for nonempty 'f'.
        $model->setStorage('f');
    }

    protoSql('BEGIN');
    protoAssert((bool)$model->salvar(), 'Original protocol incluir failed');
    $docId = $model->getCodigo();
    $business = protoSql('SELECT p01_documento::text FROM synthetic_protocolo.documents '
        . 'WHERE p01_sequencial=' . (int)$docId);
    $oid = (int)pg_fetch_result($business, 0, 0);
    protoAssert($docId > 0 && $oid >= 3000000000, 'Invalid protocol business ID or logical OID');
    protoAssert(
        $model->getOID() === $oid,
        'Original model failed to retain the allocated OID in the business object'
    );
    protoAssert(
        (int)pg_fetch_result($business, 0, 0) === $oid,
        'Original model did not persist logical OID into business record'
    );
    ++$checks;

    protoAssert(protoDenied(function () use ($readerLedger, $remote, $scope, $oid) {
        (new DocumentCoordinator($readerLedger, $remote))->read($scope, $oid);
    }), 'Uncommitted business document visible to concurrent connection');
    protoAssert(
        $readerLedger->claimNext($scope) === null,
        'Uncommitted protocol upload visible to worker'
    );
    ++$checks;

    protoSql('COMMIT');
    protoSql('BEGIN');
    $loaded = new ProcessoDocumento($docId);
    $downloadPath = $loaded->download();
    protoAssert(
        substr($downloadPath, 0, 4) === 'tmp/'
        && file_get_contents($downloadPath) === $bytes,
        'Original protocol download mismatch immediately after COMMIT'
    );
    unlink($downloadPath);
    // Only the unfixed CPD model should attempt the historical fallback.
    // The pinned DBSeller regression must not access E-Storage for OID bytes.
    $storageAttempts = \App\Domain\Configuracao\Helpers\StorageHelper::$attempts;
    protoAssert(
        $fork === 'cpd' ? $storageAttempts > 0 : $storageAttempts === 0,
        'Unexpected E-Storage fallback for the pinned Protocolo model'
    );
    protoSql('COMMIT');
    protoAssert($worker->runOnce(), 'Shared worker did not finalize protocol annex');
    ++$checks;

    // Original metadata update preserves the business reference and bytes.
    protoSql('BEGIN');
    $loaded->setDescricao('Updated synthetic protocol annex metadata');
    protoAssert((bool)$loaded->salvar(), 'Original business metadata update failed');
    protoSql('COMMIT');
    $updatedDescription = pg_fetch_result(protoSql(
        'SELECT p01_descricao FROM synthetic_protocolo.documents '
        . 'WHERE p01_sequencial=' . (int)$docId
    ), 0, 0);
    protoAssert(
        $updatedDescription === 'Updated synthetic protocol annex metadata',
        'Original business update did not persist metadata'
    );
    protoAssert(
        $spi->read($context, $oid) === $bytes,
        'Metadata edit unexpectedly modified immutable document bytes'
    );
    ++$checks;

    // Content replacement through the very same original facade creates
    // immutable version 2 without changing the protocol's persisted OID.
    $replacement = "synthetic-protocol-updated\0" . str_repeat('Y', 4096);
    protoSql('BEGIN');
    protoWriteContent($replacement, $oid, $input);
    $pendingPath = $loaded->download();
    protoAssert(
        file_get_contents($pendingPath) === $replacement,
        'Original protocol model could not export its own provisional update'
    );
    unlink($pendingPath);
    protoSql('ROLLBACK');
    protoAssert(
        $spi->read($context, $oid) === $bytes,
        'Rolled-back protocol replacement exposed provisional bytes'
    );
    ++$checks;

    protoSql('BEGIN');
    protoWriteContent($replacement, $oid, $input);
    protoSql('COMMIT');
    protoAssert(
        $spi->read($context, $oid) === $replacement,
        'Committed replacement not readable before worker'
    );
    protoAssert($worker->runOnce(), 'Second immutable version did not finalize');
    protoSql('BEGIN');
    $finalPath = $loaded->download();
    protoAssert(
        file_get_contents($finalPath) === $replacement,
        'Original protocol model could not export its finalized update'
    );
    unlink($finalPath);
    protoSql('COMMIT');
    protoAssert((int)pg_fetch_result(protoSql(
        'SELECT p01_documento::text FROM synthetic_protocolo.documents '
        . 'WHERE p01_sequencial=' . (int)$docId
    ), 0, 0) === $oid, 'Business document identity changed after update');
    ++$checks;

    $policy->revoked = true;
    protoSql('BEGIN');
    protoAssert(protoDenied(function () use ($loaded) {
        $loaded->download();
    }), 'Revoked protocol actor could export a document');
    protoSql('ROLLBACK');
    $policy->revoked = false;
    ++$checks;

    // CPD checks isStorage() when deciding whether to unlink the legacy OID.
    // Raw PostgreSQL boolean text 'f' is truthy in that original method,
    // so demonstrate the bypass and then make the *fixture* explicitly typed.
    // This does not fix the vendor's original code or certify its deploy.
    if ($fork === 'cpd') {
        protoAssert(
            $loaded->isStorage() === true,
            'CPD raw PostgreSQL boolean coercion hazard was not reproduced'
        );
        protoSql('BEGIN');
        protoAssert($loaded->excluir() === true, 'CPD original delete did not run');
        protoAssert(
            $spi->read($context, $oid) === $replacement,
            'Unexpected tombstone: CPD original still has boolean storage bypass'
        );
        protoSql('ROLLBACK');
        $loaded->setStorage(false); // Explicit synthetic DAO normalization.
        protoAssert(
            $loaded->isStorage() === false,
            'CPD synthetic boolean normalization was ineffective'
        );
    }

    // Original business deletion must remove the SQL record and tombstone
    // its logical OID in the same transaction; rollback restores both.
    protoSql('BEGIN');
    protoAssert($loaded->excluir() === true, 'Original protocol exclusion failed');
    protoSql('ROLLBACK');
    protoAssert((int)pg_fetch_result(protoSql(
        'SELECT count(*) FROM synthetic_protocolo.documents WHERE p01_sequencial=' . (int)$docId
    ), 0, 0) === 1, 'Rolled-back business deletion persisted');
    protoAssert(
        $spi->read($context, $oid) === $replacement,
        'Rollback incorrectly tombstoned a published protocol document'
    );
    ++$checks;

    protoSql('BEGIN');
    protoAssert($loaded->excluir() === true, 'Final protocol deletion failed');
    protoSql('COMMIT');
    protoAssert((int)pg_fetch_result(protoSql(
        'SELECT count(*) FROM synthetic_protocolo.documents WHERE p01_sequencial=' . (int)$docId
    ), 0, 0) === 0, 'Protocol business record was not removed');
    protoAssert(protoDenied(function () use ($spi, $context, $oid) {
        $spi->read($context, $oid);
    }), 'Deleted protocol reference still readable');
    ++$checks;

    // A new original model insert discarded by ERP rollback has no published
    // business row or SQL journal reference. The staged remote is an orphan.
    $uncommitted = new ProcessoDocumento();
    $uncommitted->setProcessoProtocolo(new processoProtocolo(850035));
    $uncommitted->setDescricao('Rolled-back protocol annex');
    if ($fork !== 'contass') {
        $uncommitted->setUsuario(new UsuarioSistema(1001));
    }
    $uncommitted->setCaminhoArquivo($input);
    protoSql('BEGIN');
    protoAssert((bool)$uncommitted->salvar(), 'Rollback candidate not staged');
    $rolledId = (int)pg_fetch_result(protoSql(
        'SELECT p01_documento::text FROM synthetic_protocolo.documents '
        . 'WHERE p01_sequencial=' . (int)$uncommitted->getCodigo()
    ), 0, 0);
    protoSql('ROLLBACK');
    protoAssert(protoDenied(function () use ($spi, $context, $rolledId) {
        $spi->read($context, $rolledId);
    }), 'Rollback exposed a synthetic protocol document');
    protoAssert(
        $readerLedger->claimNext($scope) === null,
        'Rollback left a committed protocol outbox job'
    );
    ++$checks;

    $nativeAfter = (int)pg_fetch_result(protoSql(
        'SELECT count(*) FROM pg_largeobject_metadata'
    ), 0, 0);
    protoAssert(
        $nativeAfter === $baseline,
        'Original protocol methods created duplicate native PostgreSQL LOs'
    );
    protoAssert((int)pg_fetch_result(protoSql(
        'SELECT count(*) FROM information_schema.columns '
        . "WHERE table_schema IN ('ecidade_nextcloud','synthetic_protocolo') "
        . "AND data_type='bytea'"
    ), 0, 0) === 0, 'Protocol or journal duplicated bytes in bytea');
    protoAssert((int)pg_fetch_result(protoSql(
        'SELECT count(*) FROM ecidade_nextcloud.operations '
        . 'WHERE legacy_id=' . (int)$oid . "::oid AND operation_type='write'"
    ), 0, 0) === 2, 'Immutable protocol revisions missing from journal');
    if (!$liveHttps) {
        protoAssert(count($remote->objects) >= 3, 'Expected immutable history and staged orphan');
    }
    protoAssert(
        count(glob($workspace . '/tmp/*')) === 0,
        'Original protocol temporary download was not released'
    );
    ++$checks;
    echo 'PASS pinned ' . $fork . ' ProcessoDocumento actual business methods -> SPI v1'
        . ($liveHttps ? ' -> Nextcloud HTTPS' : ' -> synthetic remote')
        . ' -> synthetic SQL outbox -> shared worker, PHP ' . PHP_VERSION
        . ' checks=' . $checks . "\n";
} finally {
    chdir($oldCwd);
    foreach (glob($workspace . '/tmp/*') as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    if (is_file($workspace . '/attachment.pdf')) {
        unlink($workspace . '/attachment.pdf');
    }
    @rmdir($workspace . '/tmp');
    if ($fork === 'contass') {
        @unlink($workspace . '/std/DBLargeObject.php');
        @rmdir($workspace . '/std');
    }
    @rmdir($workspace);
    pg_close($reader);
    pg_close($conn);
}
