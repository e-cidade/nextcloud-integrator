<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
// Executed exclusively in the isolated DBSeller PHP 5.6 container.
chdir('/var/www/html');
$_SERVER['DOCUMENT_ROOT'] = '/var/www/html';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['HTTP_HOST'] = 'localhost';
require_once '/var/www/html/bootstrap.php';
require_once modification('std/DBLargeObject.php');

$conn = pg_connect(sprintf(
    'host=%s port=%s dbname=%s user=%s password=%s',
    getenv('DB_HOST'),
    getenv('DB_PORT'),
    getenv('DB_DATABASE'),
    getenv('DB_USERNAME'),
    getenv('DB_PASSWORD')
));
if (!$conn) {
    throw new RuntimeException('No lab database connection');
}
$baseline = (int)pg_fetch_result(pg_query($conn, 'SELECT count(*) FROM pg_largeobject_metadata'), 0, 0);
$originalToken = getenv('ECIDADE_NC_TOKEN');
$originalTenant = getenv('ECIDADE_NC_TENANT');
$originalBase = getenv('ECIDADE_NC_BASE_URL');

$scenarios = [
    ['name' => 'invalid_token', 'env' => 'ECIDADE_NC_TOKEN', 'value' => 'invalid-lab-token'],
    ['name' => 'cross_tenant', 'env' => 'ECIDADE_NC_TENANT', 'value' => 'municipio-b'],
    ['name' => 'nextcloud_unavailable', 'env' => 'ECIDADE_NC_BASE_URL', 'value' => 'https://nginx:19999/ocs/v2.php/apps/ecidadelab/api/v1/documents'],
];
$results = [];
foreach ($scenarios as $case) {
    putenv($case['env'].'='.$case['value']);
    if (!pg_query($conn, 'BEGIN')) {
        throw new RuntimeException('Cannot start lab DB transaction');
    }
    $rejected = false;
    $reason = '';
    try {
        $newId = DBLargeObject::criaOID(true);
        if ($newId) {
            throw new RuntimeException('UNSAFE: document reservation succeeded despite invalid conditions');
        }
    } catch (Exception $e) {
        $rejected = true;
        $reason = get_class($e);
    }
    pg_query($conn, 'ROLLBACK');
    putenv('ECIDADE_NC_TOKEN='.$originalToken);
    putenv('ECIDADE_NC_TENANT='.$originalTenant);
    putenv('ECIDADE_NC_BASE_URL='.$originalBase);
    $after = (int)pg_fetch_result(pg_query($conn, 'SELECT count(*) FROM pg_largeobject_metadata'), 0, 0);
    if (!$rejected || $after !== $baseline) {
        throw new RuntimeException('Failed safety check: '.$case['name']);
    }
    $results[] = ['scenario' => $case['name'],'failed_closed' => true,
        'no_new_postgres_large_objects' => true,'error_type' => $reason];
}
echo json_encode($results),PHP_EOL;
