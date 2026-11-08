<?php
// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
/**
 * HTTP test boundary for the UNMODIFIED DBSeller prot4_processodocumento RPC.
 *
 * Only disposable PostgreSQL + Nextcloud containers. Original business route,
 * original DAO and original model are loaded unchanged. Framework bootstrap,
 * login/session and municipal data are intentionally synthetic; never call
 * this a full ERP-authenticated end-to-end test or production integration.
 */
if (getenv('ECIDADE_SYNTHETIC_ERP_HTTP_TEST') !== '1'
    || getenv('ECIDADE_SYNTHETIC_TEST') !== '1'
    || getenv('ECIDADE_SYNTHETIC_HTTPS_TEST') !== '1'
    || getenv('PGHOST') !== 'mvp-db-ci0001'
    || getenv('PGDATABASE') !== 'ecidade_synthetic_test'
    || getenv('PGUSER') !== 'mvp_test'
    || getenv('MVP_SYNTHETIC_PASSWORD') !== getenv('PGPASSWORD')
    || !is_file('/.dockerenv') || !is_file('/synthetic-certs/cert.pem')
    || !extension_loaded('pgsql') || !extension_loaded('curl')) {
    http_response_code(503);
    exit;
}
if ($_SERVER['REQUEST_URI'] === '/health') {
    header('Content-Type: text/plain');
    echo 'ok';
    return;
}
if ($_SERVER['REQUEST_URI'] !== '/protocol' || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(404);
    exit;
}
$secret = getenv('MVP_SYNTHETIC_PASSWORD');
if (!is_string($secret) || strlen($secret) < 32
    || !isset($_SERVER['HTTP_X_ECIDADE_LAB_TOKEN'])
    || !hash_equals($secret, $_SERVER['HTTP_X_ECIDADE_LAB_TOKEN'])) {
    http_response_code(403);
    exit;
}
if (!isset($_POST['json']) || !is_string($_POST['json'])
    || strlen($_POST['json']) > 2048) {
    http_response_code(400);
    exit;
}
$payload = json_decode($_POST['json'], true);
$workspace = '/tmp/dbseller-http-synthetic';
$source = '/sdk/.build/dbseller-candidate/ecidade/';
if (!is_array($payload)
    || !isset($payload['exec']) || !is_string($payload['exec'])
    || !in_array($payload['exec'], ['salvarDocumento', 'carregarDocumentos', 'download', 'excluirDocumento'], true)
    || !isset($payload['iCodigoProcesso']) || $payload['iCodigoProcesso'] !== 900001
    || (isset($payload['storage']) && $payload['storage'] !== false)
    || (isset($payload['sCaminhoArquivo'])
        && $payload['sCaminhoArquivo'] !== $workspace . '/synthetic.pdf')) {
    http_response_code(400);
    exit;
}
if ($payload['exec'] === 'salvarDocumento'
    && (!isset($payload['iCodigoDocumento'])
        || !is_int($payload['iCodigoDocumento'])
        || $payload['iCodigoDocumento'] < 0
        || !isset($payload['sDescricaoDocumento'])
        || !is_string($payload['sDescricaoDocumento'])
        || strlen($payload['sDescricaoDocumento']) > 90)) {
    http_response_code(400);
    exit;
}
if (in_array($payload['exec'], ['download', 'excluirDocumento'], true)
    && (($payload['exec'] === 'download'
        && (!isset($payload['iCodigoDocumento'])
            || !is_int($payload['iCodigoDocumento']) || $payload['iCodigoDocumento'] < 1))
        || ($payload['exec'] === 'excluirDocumento'
            && (!isset($payload['aDocumentosExclusao'])
                || !is_array($payload['aDocumentosExclusao'])
                || count($payload['aDocumentosExclusao']) !== 1
                || !is_int($payload['aDocumentosExclusao'][0]))))) {
    http_response_code(400);
    exit;
}
function syntheticSource($file, $expected)
{
    $real = realpath($file);
    if ($real !== $file || !is_file($real) || is_link($real)
        || !preg_match('/^[a-f0-9]{40}$/D', $expected)) {
        throw new RuntimeException('Untrusted original DBSeller source');
    }
    $raw = file_get_contents($real);
    if (!hash_equals($expected, sha1('blob ' . strlen($raw) . "\0" . $raw))) {
        throw new RuntimeException('DBSeller candidate source fingerprint changed');
    }
    return $real;
}
function syntheticSql($sql)
{
    $result = @pg_query($GLOBALS['conn'], $sql);
    if ($result === false) {
        throw new RuntimeException('Original RPC synthetic PostgreSQL statement failed');
    }
    return $result;
}
function db_query($sql)
{
    return syntheticSql($sql);
}
function db_inicio_transacao()
{
    syntheticSql('BEGIN');
}
function db_fim_transacao($rollback)
{
    syntheticSql($rollback ? 'ROLLBACK' : 'COMMIT');
}
function db_getsession($name, $default = null)
{
    if ($name === 'DB_desativar_account') {
        return true;
    }
    if ($name === 'DB_coddepto') {
        return 11;
    }
    if ($name === 'DB_id_usuario') {
        return 1001;
    }
    throw new RuntimeException('Unsupported synthetic ERP session attribute');
}
function _M($key, $args = null)
{
    return $key;
}
function modification($source)
{
    if ($source === 'std/DBLargeObject.php') {
        return '/sdk/modules/dbseller/DBLargeObject.php';
    }
    // Do not load the real database connection/session using a false
    // synthetic identity. Those layers are outside this contract.
    return '/sdk/tests/upstream/dbseller-http-noop.php';
}
class DBException extends Exception {}
class BusinessException extends Exception {}
class rotulo
{
    public function __construct($name)
    {
        if ($name !== 'protprocessodocumento') {
            throw new RuntimeException('Unexpected original generated DAO table');
        }
    }
}
class db_utils
{
    public static function inTransaction($connection = null)
    {
        return pg_transaction_status($connection ?: $GLOBALS['conn']) === PGSQL_TRANSACTION_INTRANS;
    }
    public static function getDao($name)
    {
        if ($name !== 'protprocessodocumento') {
            throw new RuntimeException('Unexpected generated DAO lookup');
        }
        return new cl_protprocessodocumento();
    }
    public static function fieldsMemory($result, $index)
    {
        return pg_fetch_object($result, $index);
    }
}
class db_stdClass
{
    public static function normalizeStringJsonEscapeString($input)
    {
        return $input;
    }
}
class services_json
{
    public function decode($json)
    {
        return json_decode($json);
    }
    public function encode($value)
    {
        return json_encode($value);
    }
}
class UsuarioSistema
{
    private $id;
    public function __construct($id) { $this->id = $id; }
    public function getIdUsuario() { return $this->id; }
    public function getNome() { return 'Synthetic Actor'; }
}
class SyntheticDepartamento
{
    public function getCodigo() { return 11; }
    public function getNomeDepartamento() { return 'Synthetic Department'; }
}
class processoProtocolo
{
    private $id;
    public function __construct($id) { $this->id = $id; }
    public function getCodProcesso() { return $this->id; }
    public function getDepartamentoAtual() { return new SyntheticDepartamento(); }
    public function getDocumentos()
    {
        $rows = syntheticSql('SELECT p01_sequencial FROM protprocessodocumento WHERE p01_protprocesso=900001');
        $result = [];
        while ($row = pg_fetch_assoc($rows)) {
            $result[] = new ProcessoDocumento((int)$row['p01_sequencial']);
        }
        return $result;
    }
}
class SyntheticRpcPolicy implements \ECidade\Nextcloud\Contract\DocumentAccessPolicy
{
    public function allows(\ECidade\Nextcloud\Domain\AdapterContext $context, $operation, $legacyId)
    {
        return $context->actor() === 'synthetic-protocol-actor'
            && $context->backend() === 'dbseller'
            && $context->surface() === 'large-object-facade'
            && $context->scope()->equals(
                new \ECidade\Nextcloud\Domain\Scope('installation-a', 'municipio-a')
            );
    }
}

try {
    $route = syntheticSource(
        $source . 'prot4_processodocumento.RPC.php',
        getenv('ECIDADE_DBSELLER_CANDIDATE_ROUTE_BLOB')
    );
    $model = syntheticSource(
        $source . 'model/protocolo/ProcessoDocumento.model.php',
        getenv('ECIDADE_DBSELLER_CANDIDATE_MODEL_BLOB')
    );
    $dao = syntheticSource(
        $source . 'classes/db_protprocessodocumento_classe.php',
        getenv('ECIDADE_DBSELLER_CANDIDATE_DAO_BLOB')
    );
    if (!is_dir($workspace) && !mkdir($workspace, 0700)) {
        throw new RuntimeException('Synthetic RPC directory unavailable');
    }
    if (!is_dir($workspace . '/tmp') && !mkdir($workspace . '/tmp', 0700)) {
        throw new RuntimeException('Synthetic RPC staging unavailable');
    }
    $bytes = "original-dbseller-http-only\0" . str_repeat('R', 8192);
    file_put_contents($workspace . '/synthetic.pdf', $bytes);
    chdir($workspace);
    $_SERVER['PHP_SELF'] = '/prot4_processodocumento.RPC.php';
    $_SESSION = ['DB_id_usuario' => 1001, 'DB_coddepto' => 11];
    $GLOBALS['conn'] = pg_connect(
        'host=mvp-db-ci0001 port=5432 dbname=ecidade_synthetic_test user=mvp_test password='
        . getenv('PGPASSWORD') . ' connect_timeout=5',
        PGSQL_CONNECT_FORCE_NEW
    );
    if (!$GLOBALS['conn']) {
        throw new RuntimeException('Synthetic RPC database unavailable');
    }
    syntheticSql('SET search_path TO generated_http_synthetic,public');
    putenv('ECIDADE_ADAPTER=dbseller');
    putenv('ECIDADE_INTEGRATOR_ROOT=/sdk');
    spl_autoload_register(function ($class) {
        $prefix = 'ECidade\\Nextcloud\\';
        if (strpos($class, $prefix) !== 0) {
            return;
        }
        $path = '/sdk/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($path)) {
            require_once $path;
        }
    });
    require_once '/sdk/tests/fixtures/SyntheticProtocolStorageHelper.php';
    require_once $dao;
    require_once $model;
    $scope = new \ECidade\Nextcloud\Domain\Scope('installation-a', 'municipio-a');
    $ledger = new \ECidade\Nextcloud\Persistence\PgDocumentLedger($GLOBALS['conn']);
    $remote = new \ECidade\Nextcloud\Transport\NextcloudMvpClient($scope, [
        'url' => 'https://synthetic-nextcloud/ocs/v2.php/apps/ecidadelab/api/v2',
        'user' => 'synthetic-admin',
        'token' => getenv('MVP_SYNTHETIC_PASSWORD'),
        'ca_file' => '/synthetic-certs/cert.pem',
        'pending_read_key' => getenv('ECIDADE_MVP_PENDING_READ_KEY'),
    ]);
    $coordinator = new \ECidade\Nextcloud\Application\DocumentCoordinator($ledger, $remote);
    $spi = new \ECidade\Nextcloud\Application\AdapterServiceV1(
        $coordinator, $ledger, new SyntheticRpcPolicy(), [
            'id' => 'dbseller',
            'production_ready' => false,
            'storage_capabilities' => [[
                'surface' => 'large-object-facade', 'usage' => 'persistent-document',
                'spi' => 1,
                'operations' => ['reserve', 'create', 'read', 'update', 'delete', 'inspect'],
            ]],
        ]
    );
    \ECidade\Nextcloud\LargeObjectBridge::bind(
        $spi,
        new \ECidade\Nextcloud\Domain\AdapterContext(
            $scope, 'dbseller', 'large-object-facade', 'synthetic-protocol-actor'
        ),
        $ledger
    );
    ob_start();
    require $route;  // Original RPC executes its unmodified switch and SQL transactions.
    $body = ob_get_clean();
    $response = json_decode($body, true);
    if (!is_array($response) || !isset($response['iStatus'])
        || \App\Domain\Configuracao\Helpers\StorageHelper::$attempts !== 0) {
        throw new RuntimeException('Original RPC produced invalid response or E-Storage fallback');
    }
    if ($payload['exec'] === 'download' && (int)$response['iStatus'] === 1) {
        $path = isset($response['sCaminhoDownloadArquivo'])
            ? $response['sCaminhoDownloadArquivo'] : '';
        $resolved = realpath($path);
        if ($resolved === false || strpos($resolved, $workspace . '/tmp/') !== 0) {
            throw new RuntimeException('Original RPC returned an unsafe document path');
        }
        $response['sha256'] = hash_file('sha256', $resolved);
        unlink($resolved);
        unset($response['sCaminhoDownloadArquivo']);
    }
    header('Content-Type: application/json');
    echo json_encode($response);
} catch (Exception $error) {
    if (ob_get_level()) {
        ob_end_clean();
    }
    // Never publish raw framework, SQL, file path, or document errors via HTTP.
    http_response_code(500);
    header('Content-Type: application/json');
    echo '{"error":"SYNTHETIC_RPC_CONTRACT_FAILED"}';
}
