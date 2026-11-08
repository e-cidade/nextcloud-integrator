<?php
// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
/**
 * Exercise unchanged original DBSeller municipal RPC across real HTTP,
 * original model/DAO, shared SQL transaction, SPI, and Nextcloud HTTPS.
 * Authentication/session/bootstrap are explicit synthetic test seams.
 */
if (PHP_SAPI !== 'cli' || getenv('ECIDADE_SYNTHETIC_ERP_HTTP_TEST') !== '1'
    || getenv('ECIDADE_SYNTHETIC_TEST') !== '1'
    || getenv('ECIDADE_SYNTHETIC_HTTPS_TEST') !== '1'
    || getenv('PGHOST') !== 'mvp-db-ci0001'
    || getenv('PGDATABASE') !== 'ecidade_synthetic_test'
    || !is_file('/.dockerenv') || !extension_loaded('curl')) {
    fwrite(STDERR, "Refusing non-disposable DBSeller HTTP contract\n");
    exit(2);
}
function verifyRpc($ok, $description)
{
    if (!$ok) {
        throw new RuntimeException('DBSeller original HTTP RPC regression: ' . $description);
    }
}
function rpc($args, $authenticated = true, $status = 200)
{
    $c = curl_init('http://dbseller-route-ci:8080/protocol');
    $headers = ['Content-Type: application/x-www-form-urlencoded'];
    if ($authenticated) {
        $headers[] = 'X-Ecidade-Lab-Token: ' . getenv('MVP_SYNTHETIC_PASSWORD');
    }
    curl_setopt_array($c, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query(['json' => json_encode($args)]),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 40,
    ]);
    $body = curl_exec($c);
    $actual = curl_getinfo($c, CURLINFO_HTTP_CODE);
    curl_close($c);
    verifyRpc($body !== false && $actual === $status, 'Unexpected HTTP status');
    if ($status !== 200) {
        return null;
    }
    $response = json_decode($body, true);
    verifyRpc(is_array($response) && isset($response['iStatus'])
        && (int)$response['iStatus'] === 1, 'Original business RPC rejected synthetic action');
    return $response;
}
$db = pg_connect(
    'host=mvp-db-ci0001 port=5432 dbname=ecidade_synthetic_test user=mvp_test password='
    . getenv('PGPASSWORD') . ' connect_timeout=5',
    PGSQL_CONNECT_FORCE_NEW
);
verifyRpc($db !== false, 'Synthetic PostgreSQL unavailable');
function rsql($query)
{
    $result = @pg_query($GLOBALS['db'], $query);
    if ($result === false) {
        throw new RuntimeException('DBSeller HTTP synthetic SQL failed');
    }
    return $result;
}
$GLOBALS['db'] = $db;
$schema = 'generated_http_synthetic';
rsql('CREATE SCHEMA ' . $schema);
rsql('CREATE SEQUENCE ' . $schema . '.protprocessodocumento_p01_sequencial_seq');
rsql('CREATE TABLE ' . $schema . '.protprocessodocumento ('
    . 'p01_sequencial int PRIMARY KEY, p01_protprocesso bigint NOT NULL, '
    . 'p01_descricao text NOT NULL, p01_documento oid, '
    . 'p01_nomedocumento text NOT NULL, p01_data date, '
    . 'p01_usuario bigint, p01_procandamint bigint, '
    . 'p01_estorage boolean NOT NULL DEFAULT false, p01_ordem int, '
    . 'p01_assinado boolean, p01_assinado_por bigint, '
    . 'p01_documento_hash text, p01_c70codlan bigint, '
    . 'p01_upload_name text)');
rsql('CREATE TABLE ' . $schema . '.db_usuarios(id_usuario bigint PRIMARY KEY)');
rsql('INSERT INTO ' . $schema . '.db_usuarios VALUES (1001)');
$before = (int)pg_fetch_result(rsql('SELECT count(*) FROM pg_largeobject_metadata'), 0, 0);
$base = ['iCodigoProcesso' => 900001];
rpc($base + ['exec' => 'download', 'iCodigoDocumento' => 1], false, 403);
rpc($base + ['exec' => 'salvarDocumento', 'iCodigoDocumento' => 0,
    'sDescricaoDocumento' => 'Blocked E-Storage test', 'storage' => true], true, 400);

$created = rpc($base + [
    'exec' => 'salvarDocumento',
    'iCodigoDocumento' => 0,
    'sDescricaoDocumento' => 'Synthetic municipal protocol attachment',
    'sCaminhoArquivo' => '/tmp/dbseller-http-synthetic/synthetic.pdf',
]);
verifyRpc(isset($created['sMensagem']), 'Original create returned no message');
$rows = rsql('SELECT p01_sequencial, p01_documento::text AS oid, '
    . "p01_estorage, p01_descricao FROM $schema.protprocessodocumento");
verifyRpc(pg_num_rows($rows) === 1, 'Original business create did not persist one row');
$row = pg_fetch_assoc($rows);
$id = (int)$row['p01_sequencial'];
$oid = (int)$row['oid'];
verifyRpc($id > 0 && $oid >= 3000000000 && $row['p01_estorage'] === 'f',
    'Original business OID/storage flag invalid');
$sha = hash('sha256', "original-dbseller-http-only\0" . str_repeat('R', 8192));
$download = rpc($base + ['exec' => 'download', 'iCodigoDocumento' => $id]);
verifyRpc(isset($download['sha256']) && $download['sha256'] === $sha,
    'Original route read-after-COMMIT bytes differ from Nextcloud version');

$listed = rpc($base + ['exec' => 'carregarDocumentos']);
verifyRpc(isset($listed['aDocumentosVinculados'])
    && count($listed['aDocumentosVinculados']) === 1
    && (int)$listed['aDocumentosVinculados'][0]['iCodigoDocumento'] === $id,
    'Original protocol document list did not include stored attachment');

rpc($base + [
    'exec' => 'salvarDocumento', 'iCodigoDocumento' => $id,
    'sDescricaoDocumento' => 'Updated synthetic municipal description',
]);
$updated = pg_fetch_assoc(rsql('SELECT p01_descricao, p01_documento::text AS oid FROM '
    . $schema . '.protprocessodocumento WHERE p01_sequencial=' . $id));
verifyRpc($updated['p01_descricao'] === 'Updated synthetic municipal description'
    && (int)$updated['oid'] === $oid, 'Original metadata update changed business identity');

spl_autoload_register(function ($class) {
    $prefix = 'ECidade\\Nextcloud\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }
    $file = '/sdk/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});
$scope = new \ECidade\Nextcloud\Domain\Scope('installation-a', 'municipio-a');
$remote = new \ECidade\Nextcloud\Transport\NextcloudMvpClient($scope, [
    'url' => 'https://synthetic-nextcloud/ocs/v2.php/apps/ecidadelab/api/v2',
    'user' => 'synthetic-admin',
    'token' => getenv('MVP_SYNTHETIC_PASSWORD'),
    'ca_file' => '/synthetic-certs/cert.pem',
    'pending_read_key' => getenv('ECIDADE_MVP_PENDING_READ_KEY'),
]);
$ledger = new \ECidade\Nextcloud\Persistence\PgDocumentLedger($db);
$coordinator = new \ECidade\Nextcloud\Application\DocumentCoordinator($ledger, $remote);
verifyRpc($coordinator->read($scope, $oid)
    === "original-dbseller-http-only\0" . str_repeat('R', 8192),
    'Original HTTP route did not produce shared SQL/Nextcloud document bytes');
$worker = new \ECidade\Nextcloud\Application\DocumentReconciler($ledger, $remote, $scope);
verifyRpc($worker->runOnce(), 'Original HTTP route outbox was not reconciled');
$second = rpc($base + ['exec' => 'download', 'iCodigoDocumento' => $id]);
verifyRpc($second['sha256'] === $sha, 'Original HTTP route lost bytes after worker reconciliation');

rpc($base + ['exec' => 'excluirDocumento', 'aDocumentosExclusao' => [$id]]);
verifyRpc((int)pg_fetch_result(rsql('SELECT count(*) FROM ' . $schema
    . '.protprocessodocumento'), 0, 0) === 0, 'Original RPC failed to delete business row');
$denied = false;
try {
    $coordinator->read($scope, $oid);
} catch (Exception $error) {
    $denied = true;
}
verifyRpc($denied, 'Original RPC deletion left readable Nextcloud logical reference');
verifyRpc((int)pg_fetch_result(rsql('SELECT count(*) FROM pg_largeobject_metadata'), 0, 0)
    === $before, 'Original HTTP route created PostgreSQL native document bytes');
verifyRpc((int)pg_fetch_result(rsql(
    "SELECT count(*) FROM information_schema.columns WHERE table_schema='$schema' AND data_type='bytea'"
), 0, 0) === 0, 'Original HTTP schema contains persistent bytea');
echo "PASS unchanged DBSeller Protocolo RPC over HTTP: create, read, list, metadata update, delete; shared Nextcloud HTTPS, PostgreSQL isolation and fail-closed negative cases\n";
