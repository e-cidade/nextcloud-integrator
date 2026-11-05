<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
/**
 * Source-contract integration smoke: one Nextcloud instance, separate PHP
 * processes, common HTTP client, fork-specific method facade.
 *
 * IMPORTANT: Contass and CPD test HERE is a facade-contract test against a
 * synthetic PG11 database, NOT a fully booted Contass or CPD ERP.
 */
$root = dirname(__DIR__);
if (!getenv('ECIDADE_INTEGRATOR_ROOT')) {
    putenv('ECIDADE_INTEGRATOR_ROOT='.$root);
}
require_once $root.'/src/AdapterRegistry.php';
$adapter = getenv('ECIDADE_ADAPTER');
$r = new \ECidade\Nextcloud\AdapterRegistry($root);
$r->checkSelection($adapter);
require_once $root.'/src/DocumentClient.php';
require_once $root.'/src/LargeObjectBridge.php';
require_once $root.'/modules/'.$adapter.'/DBLargeObject.php';
$expected = ['criaOID','escrita','leitura','exclusao'];
if ($adapter === 'contass') {
    $expected[] = 'gerarArquivoOid';
} else {
    $expected[] = 'writeContent';
}
$ref = new \ReflectionClass('DBLargeObject');
foreach ($expected as $method) {
    if (!$ref->hasMethod($method)) {
        throw new \RuntimeException('Missing facade method: '.$method);
    }
}
$connection = sprintf(
    'host=%s port=%s dbname=%s user=%s password=%s connect_timeout=10',
    getenv('DB_HOST'),
    getenv('DB_PORT'),
    getenv('DB_DATABASE'),
    getenv('DB_USERNAME'),
    getenv('DB_PASSWORD')
);
$conn = pg_connect($connection);
if (!$conn) {
    throw new \RuntimeException('Cannot connect to isolated legacy database');
}
function pgExec($sql)
{
    global $conn;
    if (!pg_query($conn, $sql)) {
        throw new \RuntimeException('Test PG command failed');
    }
}
function loCount()
{
    global $conn;
    $result = pg_query($conn, 'SELECT count(*) FROM pg_largeobject_metadata');
    if (!$result) {
        throw new \RuntimeException('No PG metadata');
    }
    return (int)pg_fetch_result($result, 0, 0);
}
function syntheticDocument()
{
    return "SYNTHETIC e-Cidade document\n".str_repeat('LABDATA1234', 437);
}
$action = isset($argv[1]) ? $argv[1] : '';
$previous = loCount();
if ($action === 'write') {
    $source = tempnam(sys_get_temp_dir(), 'ecidade-adapter-');
    file_put_contents($source, syntheticDocument());
    pgExec('BEGIN');
    if ($adapter === 'contass') {
        $oid = DBLargeObject::gerarArquivoOid($source, true);
    } else {
        $oid = DBLargeObject::criaOID(true);
        $n = DBLargeObject::writeContent(syntheticDocument(), $oid);
        if ($n !== strlen(syntheticDocument())) {
            throw new \RuntimeException('Incorrect number of written bytes');
        }
    }
    pgExec('COMMIT');
    unlink($source);
    $meta = \ECidade\Nextcloud\DocumentClient::fromEnvironment()->inspect($oid);
    if ($meta['checksum'] !== hash('sha256', syntheticDocument())
        || $meta['state'] !== 'committed') {
        throw new \RuntimeException('Nextcloud metadata mismatch');
    }
    if (loCount() !== $previous) {
        throw new \RuntimeException('New PostgreSQL LO found');
    }
    echo 'ID='.$oid."\n";
    echo 'PASS_WRITE adapter='.$adapter.' php='.PHP_VERSION.' postgres_LO='.$previous."\n";
} elseif ($action === 'read') {
    $oid = isset($argv[2]) ? (int)$argv[2] : 0;
    if ($oid < 1) {
        throw new \InvalidArgumentException('Missing OID');
    }
    $target = tempnam(sys_get_temp_dir(), 'ecidade-read-');
    pgExec('BEGIN');
    if (!DBLargeObject::leitura($oid, $target)) {
        throw new \RuntimeException('Read failed');
    }
    pgExec('COMMIT');
    $read = file_get_contents($target);
    unlink($target);
    if ($read !== syntheticDocument()) {
        throw new \RuntimeException('Document contents differ');
    }
    pgExec('BEGIN');
    $native = @pg_lo_open($conn, $oid, 'r');
    pgExec('ROLLBACK');
    if ($native !== false) {
        throw new \RuntimeException('Unexpected native PG LO for remote file ID');
    }
    if (loCount() !== $previous) {
        throw new \RuntimeException('New PG LO found');
    }
    echo 'PASS_READ adapter='.$adapter.' sha256='.hash('sha256', $read)."\n";
} elseif ($action === 'delete') {
    $oid = isset($argv[2]) ? (int)$argv[2] : 0;
    if ($oid < 1) {
        throw new \InvalidArgumentException('Missing OID');
    }
    pgExec('BEGIN');
    if (!DBLargeObject::exclusao($oid)) {
        throw new \RuntimeException('Delete failed');
    }
    pgExec('COMMIT');
    try {
        \ECidade\Nextcloud\DocumentClient::fromEnvironment()->inspect($oid);
        throw new \RuntimeException('Deleted document unexpectedly accessible');
    } catch (\RuntimeException $error) {
        if (strpos($error->getMessage(), 'HTTP 404') === false) {
            throw $error;
        }
    }
    if (loCount() !== $previous) {
        throw new \RuntimeException('New PG LO found');
    }
    echo 'PASS_DELETE adapter='.$adapter.' postgres_LO='.$previous."\n";
} else {
    throw new \InvalidArgumentException('Use write | read <oid> | delete <oid>');
}
