<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * Original vendor PHP 5.6 HTTP login + Protocolo RPC + vendor demo PG16
 * + shared Nextcloud 35 HTTPS document lifecycle. No production access.
 */
if (PHP_SAPI !== 'cli' || getenv('ECIDADE_ERP_DISPOSABLE_CI') !== '1'
    || getenv('ECIDADE_ERP_NEXTCLOUD_E2E') !== '1'
    || getenv('DB_HOST') !== 'erp-db'
    || getenv('DB_DATABASE') !== 'ecidade'
    || !is_file('/.dockerenv') || !extension_loaded('curl')
    || !extension_loaded('pgsql')
    || !is_file('/tmp/ecidade-nextcloud-ca.pem')
    || strlen((string) getenv('MVP_SYNTHETIC_PASSWORD')) < 32
    || strlen((string) getenv('MVP_SYNTHETIC_PENDING_KEY')) < 32) {
    fwrite(STDERR, "Refusing authentic ERP E2E outside isolated CI\n");
    exit(2);
}
function erpAssert($condition, $label)
{
    if (!$condition) {
        throw new RuntimeException('Original ERP + Nextcloud E2E: ' . $label);
    }
}
function erpQuery($connection, $sql, array $params = [])
{
    static $statement = 0;
    $statement++;
    $result = @pg_query_params($connection, $sql, $params);
    if ($result === false) {
        // Do not output SQL, demo records, identifiers or PostgreSQL DETAIL.
        // A statement ordinal plus a fixed failure class is enough to
        // diagnose a mismatch with the vendor's public demonstration schema.
        $error = (string) @pg_last_error($connection);
        $category = 'other';
        foreach ([
            'does not exist' => 'missing-schema-object',
            'violates foreign key' => 'foreign-key',
            'violates not-null' => 'not-null',
            'violates unique' => 'unique',
            'value too long' => 'field-length',
            'invalid input syntax' => 'invalid-value',
            'permission denied' => 'permission',
            'current transaction is aborted' => 'transaction-aborted',
        ] as $pattern => $label) {
            if (stripos($error, $pattern) !== false) {
                $category = $label;
                break;
            }
        }
        throw new RuntimeException(
            'Original ERP + Nextcloud E2E: demo SQL statement ' . $statement
            . ' failed (' . $category . ')'
        );
    }
    return $result;
}
function erpHttp($url, $json, $cookieJar)
{
    $handle = curl_init($url);
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_USERAGENT => 'e-Cidade CI original Protocolo integration test',
    ];
    if ($cookieJar !== null) {
        $options[CURLOPT_COOKIEJAR] = $cookieJar;
        $options[CURLOPT_COOKIEFILE] = $cookieJar;
    }
    if ($json !== null) {
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = http_build_query(['json' => json_encode($json)]);
        $options[CURLOPT_HTTPHEADER] = ['Content-Type: application/x-www-form-urlencoded'];
    }
    curl_setopt_array($handle, $options);
    $body = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
    curl_close($handle);
    erpAssert(is_string($body), 'Original ERP HTTP transport failed');
    return [$status, $body];
}
function erpRpc($params, $cookieJar, $stage)
{
    list($status, $body) = erpHttp('http://127.0.0.1/prot4_processodocumento.RPC.php', $params, $cookieJar);
    erpAssert($status === 200, $stage . ' returned unexpected HTTP ' . $status);
    $result = json_decode($body, true);
    erpAssert(
        is_array($result) && isset($result['iStatus']) && (int) $result['iStatus'] === 1,
        $stage . ' was rejected by original business code'
    );
    return $result;
}
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

$cookie = tempnam('/tmp', 'erp-auth-');
$source = '/var/www/html/tmp/ci-erp-nc-' . substr(sha1($cookie . microtime(true)), 0, 20) . '.pdf';
$download = null;
$process = null;
$db = false;
try {
    list($homeStatus, ) = erpHttp('http://127.0.0.1/login.php', null, $cookie);
    erpAssert($homeStatus === 200, 'Original login.php unavailable');
    $auth = base64_encode('DB_login=dbseller&DB_senha=' . md5('dbseller'));
    list($loginStatus, $loginBody) = erpHttp('http://127.0.0.1/abrir.php?sAuth=' . rawurlencode($auth), null, $cookie);
    erpAssert(
        $loginStatus === 200 && strpos($loginBody, 'Abrindo E-cidade') !== false,
        'Original PHP 5.6 vendor login failed'
    );

    // Simulate only the module/window choice after REAL ERP authentication.
    // This fixture verifies the selected department in the vendor database.
    list($contextStatus, $contextBody) = erpHttp(
        'http://127.0.0.1/.ci-erp-session-context.php',
        null,
        $cookie
    );
    erpAssert(
        $contextStatus === 200 && strpos($contextBody, 'Authorized vendor Protocolo session context: PASS') !== false,
        'Authenticated vendor HTTP session cannot select an existing real department'
    );

    $db = @pg_connect(
        'host=' . getenv('DB_HOST') . ' port=' . getenv('DB_PORT')
        . ' dbname=' . getenv('DB_DATABASE')
        . ' user=' . getenv('DB_USERNAME') . ' password=' . getenv('DB_PASSWORD')
        . ' connect_timeout=5',
        PGSQL_CONNECT_FORCE_NEW
    );
    erpAssert($db !== false, 'Original ERP database not reachable');
    erpAssert(
        @pg_query($db, 'SELECT public.fc_set_pg_search_path()') !== false,
        'Original ERP PostgreSQL search path unavailable'
    );

    $identity = pg_fetch_assoc(erpQuery(
        $db,
        'SELECT u.id_usuario, dep.coddepto, dep.instit FROM db_usuarios u '
        . 'INNER JOIN db_depusu du ON du.id_usuario=u.id_usuario '
        . 'INNER JOIN db_depart dep ON dep.coddepto=du.coddepto '
        . 'INNER JOIN db_config cfg ON cfg.codigo=dep.instit '
        . "WHERE u.login='dbseller' AND u.usuarioativo=1 ORDER BY dep.coddepto LIMIT 1"
    ));
    erpAssert($identity !== false, 'Demo lacks an authorized user and real department');

    $before = (int) pg_fetch_result(erpQuery(
        $db,
        'SELECT count(*) FROM pg_catalog.pg_largeobject_metadata'
    ), 0, 0);
    // Synthetic process only, preserving all original vendor business tables.
    $created = erpQuery(
        $db,
        'INSERT INTO protprocesso '
        . '(p58_codproc,p58_codigo,p58_dtproc,p58_id_usuario,p58_numcgm,'
        . 'p58_requer,p58_coddepto,p58_interno,p58_publico,p58_instit,'
        . 'p58_numero,p58_ano,p58_tipoprocesso,p58_orgao,p58_processopai,p58_volume) '
        . "SELECT nextval('protocolo.protprocesso_p58_codproc_seq'), "
        . 'tp.p51_codigo,CURRENT_DATE,$1,cgm.z01_numcgm,'
        . "'CI isolated ERP to Nextcloud Protocolo',$2,false,false,$3, "
        . "'CI-NC-' || txid_current()::text, "
        . 'EXTRACT(YEAR FROM CURRENT_DATE)::integer,t.p109_sequencial,0,0,0 '
        . 'FROM tipoproc tp CROSS JOIN cgm CROSS JOIN tipoprocesso t LIMIT 1 '
        . 'RETURNING p58_codproc',
        [(int) $identity['id_usuario'], (int) $identity['coddepto'], (int) $identity['instit']]
    );
    erpAssert(pg_num_rows($created) === 1, 'Vendor demo lacks Protocolo fixture prerequisites');
    $process = (int) pg_fetch_result($created, 0, 0);
    erpAssert($process > 0, 'Synthetic original Protocolo process could not be created');

    $bytes = "%PDF-1.4\n% authentic CI original ERP document\n"
        . str_repeat("E-Cidade-Nextcloud-real-route\x00", 256) . "\n%%EOF\n";
    erpAssert(file_put_contents($source, $bytes) === strlen($bytes), 'Unable to stage ephemeral demo attachment');
    chmod($source, 0644);
    $base = ['iCodigoProcesso' => $process];

    // An unauthenticated request must not expose original business data.
    list($anonymousStatus, $anonymousBody) = erpHttp(
        'http://127.0.0.1/prot4_processodocumento.RPC.php',
        $base + ['exec' => 'carregarDocumentos'],
        null
    );
    $anonymous = json_decode($anonymousBody, true);
    erpAssert(
        !($anonymousStatus === 200 && is_array($anonymous)
        && isset($anonymous['iStatus']) && (int) $anonymous['iStatus'] === 1),
        'Protocolo endpoint accepted an unauthenticated request'
    );

    erpRpc($base + [
        'exec' => 'salvarDocumento',
        'iCodigoDocumento' => 0,
        'sDescricaoDocumento' => 'CI original vendor municipal document',
        'sCaminhoArquivo' => 'tmp/' . basename($source),
    ], $cookie, 'Create real Protocolo attachment');

    $document = pg_fetch_assoc(erpQuery(
        $db,
        'SELECT p01_sequencial,p01_documento::text AS oid,p01_descricao,p01_estorage '
        . 'FROM protprocessodocumento WHERE p01_protprocesso=$1',
        [$process]
    ));
    erpAssert($document !== false, 'Original generated DAO did not persist the document reference');
    $documentId = (int) $document['p01_sequencial'];
    $oid = (int) $document['oid'];
    erpAssert($documentId > 0 && $oid >= 3000000000
        && $document['p01_estorage'] === 'f', 'Original Protocolo did not use logical Nextcloud OID');
    $row = pg_fetch_assoc(erpQuery(
        $db,
        'SELECT d.document_key, o.checksum, o.byte_count, o.remote_file_id '
        . 'FROM ecidade_nextcloud.documents d '
        . 'JOIN ecidade_nextcloud.operations o ON o.legacy_id=d.legacy_id '
        . "WHERE d.legacy_id=$1::oid AND o.operation_type='write'",
        [$oid]
    ));
    erpAssert(
        $row !== false && $row['checksum'] === hash('sha256', $bytes)
        && (int) $row['byte_count'] === strlen($bytes)
        && (int) $row['remote_file_id'] > 0,
        'ERP transaction did not persist matching metadata-only Nextcloud journal'
    );

    $downloadResult = erpRpc(
        $base + ['exec' => 'download', 'iCodigoDocumento' => $documentId],
        $cookie,
        'Download original Protocolo attachment'
    );
    erpAssert(isset($downloadResult['sCaminhoDownloadArquivo']), 'Original RPC returned no document download');
    $download = realpath('/var/www/html/' . $downloadResult['sCaminhoDownloadArquivo']);
    $tmpRoot = realpath('/var/www/html/tmp') . DIRECTORY_SEPARATOR;
    erpAssert(
        is_string($download) && strpos($download, $tmpRoot) === 0
        && hash_file('sha256', $download) === hash('sha256', $bytes),
        'Original RPC read-after-COMMIT differs from Nextcloud bytes'
    );

    $listing = erpRpc(
        $base + ['exec' => 'carregarDocumentos'],
        $cookie,
        'List original Protocolo documents'
    );
    erpAssert(
        isset($listing['aDocumentosVinculados'])
        && is_array($listing['aDocumentosVinculados'])
        && count($listing['aDocumentosVinculados']) === 1
        && (int) $listing['aDocumentosVinculados'][0]['iCodigoDocumento'] === $documentId,
        'Original Protocolo list omitted the new document'
    );

    erpRpc($base + [
        'exec' => 'salvarDocumento', 'iCodigoDocumento' => $documentId,
        'sDescricaoDocumento' => 'CI original ERP document metadata update',
    ], $cookie, 'Update original Protocolo description');
    $updated = pg_fetch_assoc(erpQuery(
        $db,
        'SELECT p01_descricao,p01_documento::text AS oid '
        . 'FROM protprocessodocumento WHERE p01_sequencial=$1',
        [$documentId]
    ));
    erpAssert(
        $updated !== false
        && $updated['p01_descricao'] === 'CI original ERP document metadata update'
        && (int) $updated['oid'] === $oid,
        'Original business update changed the immutable document reference'
    );

    $scope = new \ECidade\Nextcloud\Domain\Scope('installation-a', 'municipio-a');
    $remote = new \ECidade\Nextcloud\Transport\NextcloudMvpClient($scope, [
        'url' => 'https://synthetic-nextcloud/ocs/v2.php/apps/ecidadelab/api/v2',
        'user' => 'synthetic-admin',
        'token' => getenv('MVP_SYNTHETIC_PASSWORD'),
        'ca_file' => '/tmp/ecidade-nextcloud-ca.pem',
        'pending_read_key' => getenv('MVP_SYNTHETIC_PENDING_KEY'),
    ]);
    $ledger = new \ECidade\Nextcloud\Persistence\PgDocumentLedger($db);
    $coordinator = new \ECidade\Nextcloud\Application\DocumentCoordinator($ledger, $remote);
    erpAssert(
        $coordinator->read($scope, $oid) === $bytes,
        'Nextcloud did not retain the exact original ERP document bytes'
    );
    $worker = new \ECidade\Nextcloud\Application\DocumentReconciler($ledger, $remote, $scope);
    erpAssert($worker->runOnce(), 'Outbox from the original ERP transaction was not reconciled');
    erpAssert(
        $coordinator->read($scope, $oid) === $bytes,
        'Original ERP document could not be read after Nextcloud reconciliation'
    );

    erpRpc(
        $base + ['exec' => 'excluirDocumento', 'aDocumentosExclusao' => [$documentId]],
        $cookie,
        'Delete original Protocolo document'
    );
    $left = (int) pg_fetch_result(erpQuery(
        $db,
        'SELECT count(*) FROM protprocessodocumento WHERE p01_protprocesso=$1',
        [$process]
    ), 0, 0);
    erpAssert($left === 0, 'Original Protocolo generated DAO did not remove its document row');
    $denied = false;
    try {
        $coordinator->read($scope, $oid);
    } catch (Exception $ignored) {
        $denied = true;
    }
    erpAssert($denied, 'Deleted original ERP document remains logically readable');

    $after = (int) pg_fetch_result(erpQuery(
        $db,
        'SELECT count(*) FROM pg_catalog.pg_largeobject_metadata'
    ), 0, 0);
    erpAssert($after === $before, 'Original ERP document bytes were duplicated in PostgreSQL Large Objects');
    $bytea = (int) pg_fetch_result(erpQuery(
        $db,
        'SELECT count(*) FROM information_schema.columns '
        . "WHERE table_name='protprocessodocumento' AND data_type='bytea'"
    ), 0, 0);
    erpAssert($bytea === 0, 'Protocolo document bytes persisted in a bytea column');
    echo "PASS original DBSeller PHP 5.6 login/Protocolo RPC and full Nextcloud 35 HTTPS CRUD, transaction/outbox, no PostgreSQL document-byte duplication\n";
} catch (Exception $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
} finally {
    if ($download !== null && is_file($download)) {
        @unlink($download);
    }
    if (is_file($source)) {
        @unlink($source);
    }
    if (is_file($cookie)) {
        @unlink($cookie);
    }
    if ($db !== false && $process !== null) {
        @pg_query_params($db, 'DELETE FROM protprocesso WHERE p58_codproc=$1', [$process]);
    }
    if ($db !== false) {
        @pg_close($db);
    }
}
