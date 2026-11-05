<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
/**
 * End-to-end PHP 5.6 DBSeller runtime test.
 * Boots the REAL application's bootstrap.php and modification() machinery,
 * then uses the Docker-file-overridden DBLargeObject class against Nextcloud.
 * Lab schema only. NEVER use production data.
 */
chdir('/var/www/html');
$_SERVER['DOCUMENT_ROOT'] = '/var/www/html';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require_once '/var/www/html/bootstrap.php';
require_once modification('std/DBLargeObject.php');

$expectedSharedClass = realpath(getenv('ECIDADE_INTEGRATOR_ROOT') . '/src/StandardLargeObjectFacade.php');
$loadedClass = realpath((new ReflectionClass('DBLargeObject'))->getFileName());
if (!$expectedSharedClass || $loadedClass !== $expectedSharedClass) {
    throw new RuntimeException('Unexpected document class: shared modular override was not loaded');
}

$conn = pg_connect(sprintf(
    'host=%s port=%s dbname=%s user=%s password=%s connect_timeout=8',
    getenv('DB_HOST'),
    getenv('DB_PORT'),
    getenv('DB_DATABASE'),
    getenv('DB_USERNAME'),
    getenv('DB_PASSWORD')
));
if (!$conn) {
    throw new RuntimeException('DBSeller sample database unavailable');
}

function dbq($sql, $args = [])
{
    global $conn;
    $q = count($args) ? pg_query_params($conn, $sql, $args) : pg_query($conn, $sql);
    if (!$q) {
        throw new RuntimeException('Demo SQL statement failed');
    }
    return $q;
}
function largeObjectCount()
{
    return (int)pg_fetch_result(dbq('SELECT count(*) FROM pg_largeobject_metadata'), 0, 0);
}
function payload()
{
    return 'ecidade-dbseller-real-bootstrap-test:' . str_repeat('A', 4096);
}
function updatedPayload()
{
    return 'ecidade-dbseller-updated-document:' . str_repeat('B', 8192);
}
function getDocumentMetadata($id)
{
    $h = curl_init('https://nginx/ocs/v2.php/apps/ecidadelab/api/v1/documents/' . (int)$id);
    curl_setopt_array($h, [
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_USERPWD => 'bench:' . getenv('ECIDADE_NC_TOKEN'),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER => ['Host: localhost','OCS-APIRequest: true',
            'X-Ecidade-Tenant: municipio-a'],
        CURLOPT_CAINFO => '/opt/ecidade-compat/nextcloud.crt',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    if (defined('CURLOPT_NOPROXY')) {
        curl_setopt($h, CURLOPT_NOPROXY, '*');
    }
    $bytes = curl_exec($h);
    $status = (int)curl_getinfo($h, CURLINFO_HTTP_CODE);
    $error = curl_error($h);
    curl_close($h);
    if ($bytes === false) {
        throw new RuntimeException('Nextcloud metadata lookup failed: '.$error);
    }
    return [$status, json_decode($bytes, true)];
}
function ensureDemoTable()
{
    dbq('CREATE SCHEMA IF NOT EXISTS ecidade_nc_poc');
    dbq('CREATE TABLE IF NOT EXISTS ecidade_nc_poc.documents (
            business_id bigint PRIMARY KEY,
            document_oid oid NOT NULL,
            caption text NOT NULL
        )');
}

$action = isset($argv[1]) ? $argv[1] : '';
$businessId = 850034;
switch ($action) {
    case 'write':
        ensureDemoTable();
        $existing = dbq('SELECT 1 FROM ecidade_nc_poc.documents WHERE business_id = $1', [$businessId]);
        if (pg_num_rows($existing)) {
            throw new RuntimeException('A previous lab document remains. Cleanup before write.');
        }
        $before = largeObjectCount();
        dbq('BEGIN');
        $oid = DBLargeObject::criaOID(true);
        $n = DBLargeObject::writeContent(payload(), $oid);
        if ($n !== strlen(payload())) {
            throw new RuntimeException('Incomplete document upload');
        }
        dbq('INSERT INTO ecidade_nc_poc.documents (business_id, document_oid, caption)
            VALUES ($1, $2::oid, $3)', [$businessId, $oid, 'Documentos do e-Cidade - Teste']);
        dbq('COMMIT');
        $meta = getDocumentMetadata($oid);
        if ($meta[0] !== 200 || $meta[1]['checksum'] !== hash('sha256', payload())) {
            throw new RuntimeException('Document metadata/checksum missing on Nextcloud');
        }
        if (largeObjectCount() !== $before) {
            throw new RuntimeException('Unexpected PostgreSQL LO creation');
        }
        echo json_encode(['action' => 'write','business_id' => $businessId,
            'logical_oid' => $oid,'nextcloud_file_id' => $meta[1]['id'],
            'metadata_stored_in_nextcloud' => true,'bytes_persisted_in_ecidade_pg' => false,
            'postgre_lo_before' => $before,'postgres_lo_after' => largeObjectCount()]),PHP_EOL;
        break;
    case 'read':
        $row = dbq('SELECT document_oid::text FROM ecidade_nc_poc.documents WHERE business_id = $1', [$businessId]);
        if (!pg_num_rows($row)) {
            throw new RuntimeException('Demo business row not found');
        }
        $oid = (int)pg_fetch_result($row, 0, 0);
        $before = largeObjectCount();
        dbq('BEGIN');
        $temp = tempnam(sys_get_temp_dir(), 'ecidade-nc-poc-');
        if (!DBLargeObject::leitura($oid, $temp)) {
            throw new RuntimeException('Legacy read failed');
        }
        dbq('COMMIT');
        $read = file_get_contents($temp);
        unlink($temp);
        if ($read !== payload()) {
            throw new RuntimeException('SHA-256 or bytes mismatch');
        }
        dbq('BEGIN');
        $native = @pg_lo_open($GLOBALS['conn'], $oid, 'r');
        dbq('ROLLBACK');
        if ($native !== false) {
            throw new RuntimeException('Native PostgreSQL LO was unexpectedly readable');
        }
        if (largeObjectCount() !== $before) {
            throw new RuntimeException('PostgreSQL LO count changed');
        }
        echo json_encode(['action' => 'read','business_id' => $businessId,
            'logical_oid' => $oid,'sha256_valid' => true,'content_read_from_nextcloud' => true,
            'direct_pg_lo_open_succeeded' => false,'postgres_lo_count' => $before]),PHP_EOL;
        break;
    case 'update':
        $row = dbq('SELECT document_oid::text FROM ecidade_nc_poc.documents WHERE business_id = $1', [$businessId]);
        if (!pg_num_rows($row)) {
            throw new RuntimeException('Cannot update without original document');
        }
        $oid = (int)pg_fetch_result($row, 0, 0);
        $before = largeObjectCount();
        dbq('BEGIN');
        $n = DBLargeObject::writeContent(updatedPayload(), $oid);
        if ($n !== strlen(updatedPayload())) {
            throw new RuntimeException('Incomplete update');
        }
        dbq('COMMIT');
        $meta = getDocumentMetadata($oid);
        if ($meta[0] !== 200
            || $meta[1]['checksum'] !== hash('sha256', updatedPayload())
            || (int)$meta[1]['revision'] !== 2
            || (int)$meta[1]['id'] !== $oid) {
            throw new RuntimeException('Update did not preserve ID and advance revision');
        }
        if (largeObjectCount() !== $before) {
            throw new RuntimeException('Unexpected native LO on update');
        }
        echo json_encode(['action' => 'update','same_document_id' => true,
            'revision_advanced' => true,'checksum_valid' => true,
            'no_new_pg_large_objects' => true]),PHP_EOL;
        break;
    case 'read-updated':
        $row = dbq('SELECT document_oid::text FROM ecidade_nc_poc.documents WHERE business_id = $1', [$businessId]);
        if (!pg_num_rows($row)) {
            throw new RuntimeException('Cannot read updated document');
        }
        $oid = (int)pg_fetch_result($row, 0, 0);
        $before = largeObjectCount();
        dbq('BEGIN');
        $temp = tempnam(sys_get_temp_dir(), 'ecidade-nc-updated-');
        if (!DBLargeObject::leitura($oid, $temp)) {
            throw new RuntimeException('Updated read failed');
        }
        dbq('COMMIT');
        $bytes = file_get_contents($temp);
        unlink($temp);
        if ($bytes !== updatedPayload()) {
            throw new RuntimeException('Updated content mismatch');
        }
        if (largeObjectCount() !== $before) {
            throw new RuntimeException('Unexpected native LO during read');
        }
        echo json_encode(['action' => 'read-updated','sha256_valid' => true,
            'content_read_from_nextcloud' => true,'no_new_pg_large_objects' => true]),PHP_EOL;
        break;
    case 'rollback':
        $before = largeObjectCount();
        dbq('BEGIN');
        $oid = DBLargeObject::criaOID(true);
        DBLargeObject::writeContent('transient e-Cidade test content', $oid);
        dbq('ROLLBACK');
        $meta = getDocumentMetadata($oid);
        if ($meta[0] !== 200) {
            throw new RuntimeException('Rollback case did not expose expected orphan');
        }
        dbq('BEGIN');
        DBLargeObject::exclusao($oid);
        dbq('COMMIT');
        if (largeObjectCount() !== $before) {
            throw new RuntimeException('PostgreSQL LO changed');
        }
        echo json_encode(['action' => 'rollback','native_transaction_rolled_back' => true,
            'nextcloud_orphan_observed' => true,'compensation_applied' => true,
            'atomicity_not_automatic' => true]),PHP_EOL;
        break;
    case 'cleanup':
        $row = dbq('SELECT document_oid::text FROM ecidade_nc_poc.documents WHERE business_id = $1', [$businessId]);
        if (!pg_num_rows($row)) {
            throw new RuntimeException('Lab business record not found');
        }
        $oid = (int)pg_fetch_result($row, 0, 0);
        $before = largeObjectCount();
        dbq('BEGIN');
        DBLargeObject::exclusao($oid);
        dbq('DELETE FROM ecidade_nc_poc.documents WHERE business_id = $1', [$businessId]);
        dbq('COMMIT');
        if (largeObjectCount() !== $before) {
            throw new RuntimeException('Unexpected PG LO modification');
        }
        echo json_encode(['action' => 'cleanup','legacy_business_relation_removed' => true,
            'nextcloud_document_deleted' => true,'postgres_lo_count' => $before]),PHP_EOL;
        break;
    default:
        throw new InvalidArgumentException('Allowed actions: write, read, update, read-updated, rollback, cleanup');
}
