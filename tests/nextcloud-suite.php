<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
/**
 * Process-isolated integration suite, written in PHP.
 *
 * Usage:
 *   php tests/nextcloud-suite.php facade  (Contass/CPD contract)
 *   php tests/nextcloud-suite.php legacy  (DBSeller real bootstrap)
 *
 * Runs in the existing Docker lab network. No Docker socket required.
 * It creates only synthetic documents in Nextcloud and preserves PG LO count.
 */
$mode = isset($argv[1]) ? $argv[1] : '';
if ($mode !== 'facade' && $mode !== 'legacy') {
    fwrite(STDERR, "Usage: php tests/nextcloud-suite.php facade|legacy\n");
    exit(2);
}
$adapter = getenv('ECIDADE_ADAPTER');
if (!in_array($adapter, ['dbseller','contass','cpd'], true)) {
    fwrite(STDERR, "Integration requires explicitly selected adapter\n");
    exit(2);
}
if (getenv('ECIDADE_POC_CLIENT') !== '1') {
    fwrite(STDERR, "Refusing to run outside the isolated test environment\n");
    exit(2);
}
if ($mode === 'legacy' && $adapter !== 'dbseller') {
    fwrite(STDERR, "Legacy bootstrap suite is specific to DBSeller\n");
    exit(2);
}

// The legacy e-Cidade error handler can swallow a failing CLI test and exit 0.
// Disable that *handler in the test process only* so assertions actually fail.
if ($mode === 'legacy') {
    putenv('TEST=1');
}

function invokePhp($script, array $arguments)
{
    $parts = [escapeshellarg(PHP_BINARY),escapeshellarg($script)];
    foreach ($arguments as $arg) {
        $parts[] = escapeshellarg((string)$arg);
    }
    $descriptors = [
        0 => ['pipe','r'],1 => ['pipe','w'],2 => ['pipe','w']
    ];
    $process = proc_open(implode(' ', $parts), $descriptors, $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot spawn isolated PHP process');
    }
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    if ($exit !== 0) {
        // The test output contains synthetic data only; avoid dumping raw documents.
        throw new RuntimeException('Child PHP test failed: '.implode(' ', $arguments).
            ' (exit '.$exit.') '.substr($err, 0, 600));
    }
    if (trim($out) !== '') {
        echo trim($out),PHP_EOL;
    }
    return $out;
}

function expectLegacyAction($stdout, $action)
{
    $data = json_decode(trim($stdout), true);
    if (!is_array($data) || !isset($data['action']) || $data['action'] !== $action) {
        throw new RuntimeException('Legacy action '. $action.
            ' did not produce verifiable JSON; refusing a false PASS');
    }
    return $data;
}

function pgConnectForSuite()
{
    $connection = sprintf(
        'host=%s port=%s dbname=%s user=%s password=%s connect_timeout=10',
        getenv('DB_HOST'),
        getenv('DB_PORT'),
        getenv('DB_DATABASE'),
        getenv('DB_USERNAME'),
        getenv('DB_PASSWORD')
    );
    $db = pg_connect($connection);
    if (!$db) {
        throw new RuntimeException('Isolated PostgreSQL inaccessible');
    }
    return $db;
}
function loCountForSuite($db)
{
    $r = pg_query($db, 'SELECT count(*) FROM pg_largeobject_metadata');
    if (!$r) {
        throw new RuntimeException('Cannot inspect PG LO count');
    }
    return (int)pg_fetch_result($r, 0, 0);
}

if (!extension_loaded('pgsql')) {
    fwrite(STDERR, "pgsql extension required\n");
    exit(2);
}
$db = pgConnectForSuite();
$before = loCountForSuite($db);
$success = false;
$oid = null;
try {
    if ($mode === 'legacy') {
        $source = '/opt/ecidade-integrator/poc/fixtures/real-dbseller-flow.php';
        $write = expectLegacyAction(invokePhp($source, ['write']), 'write');
        if (empty($write['metadata_stored_in_nextcloud'])
            || !isset($write['postgre_lo_before'],$write['postgres_lo_after'])
            || $write['postgre_lo_before'] !== $write['postgres_lo_after']) {
            throw new RuntimeException('Invalid DBSeller write evidence');
        }
        $row = pg_query($db, 'SELECT count(*) FROM ecidade_nc_poc.documents');
        if (!$row || (int)pg_fetch_result($row, 0, 0) !== 1) {
            throw new RuntimeException('DBSeller did not persist its test business link');
        }
        $read = expectLegacyAction(invokePhp($source, ['read']), 'read');
        if (empty($read['sha256_valid']) || empty($read['content_read_from_nextcloud'])) {
            throw new RuntimeException('DBSeller read did not verify integrity');
        }
        $update = expectLegacyAction(invokePhp($source, ['update']), 'update');
        if (empty($update['same_document_id'])
            || empty($update['revision_advanced'])
            || empty($update['checksum_valid'])
            || empty($update['no_new_pg_large_objects'])) {
            throw new RuntimeException('DBSeller update was not verified');
        }
        $updatedRead = expectLegacyAction(invokePhp($source, ['read-updated']), 'read-updated');
        if (empty($updatedRead['sha256_valid']) || empty($updatedRead['content_read_from_nextcloud'])) {
            throw new RuntimeException('DBSeller updated read integrity failed');
        }
        $rollback = expectLegacyAction(invokePhp($source, ['rollback']), 'rollback');
        if (empty($rollback['nextcloud_orphan_observed'])
            || empty($rollback['compensation_applied'])) {
            throw new RuntimeException('DBSeller rollback evidence invalid');
        }
        $cleanup = expectLegacyAction(invokePhp($source, ['cleanup']), 'cleanup');
        if (empty($cleanup['nextcloud_document_deleted'])) {
            throw new RuntimeException('DBSeller cleanup evidence invalid');
        }
        $negative = json_decode(trim(invokePhp('/opt/ecidade-integrator/poc/fixtures/failure-tests.php', [])), true);
        if (!is_array($negative) || count($negative) !== 3) {
            throw new RuntimeException('Legacy negative tests missing');
        }
        foreach ($negative as $scenario) {
            if (empty($scenario['failed_closed'])) {
                throw new RuntimeException('Legacy error scenario was not rejected');
            }
        }
        $row = pg_query($db, 'SELECT count(*) FROM ecidade_nc_poc.documents');
        if (!$row || (int)pg_fetch_result($row, 0, 0) !== 0) {
            throw new RuntimeException('Synthetic legacy business records remain');
        }
    } else {
        $source = dirname(__FILE__).'/runtime-contract.php';
        $output = invokePhp($source, ['write']);
        if (!preg_match('/^ID=([0-9]+)$/m', $output, $match)) {
            throw new RuntimeException('No created document identifier');
        }
        $oid = (int)$match[1];
        invokePhp($source, ['read',$oid]);
        invokePhp($source, ['delete',$oid]);
        $oid = null;
    }
    if (loCountForSuite($db) !== $before) {
        throw new RuntimeException('Native PostgreSQL Large Object count changed');
    }
    $success = true;
    echo 'PASS '.$adapter.' '.$mode.' PHP integration suite. Native PG LO count unchanged: '.$before.PHP_EOL;
} catch (Exception $e) {
    fwrite(STDERR, 'FAIL '.$adapter.' '.$mode.': '.$e->getMessage().PHP_EOL);
    // Best-effort synthetic test cleanup. The PRODUCT still lacks a
    // durable distributed-transaction reconciler.
    if ($oid !== null && $mode === 'facade') {
        try {
            invokePhp($source, ['delete',$oid]);
        } catch (Exception $cleanup) {
        }
    }
} finally {
    pg_close($db);
}
if (!$success) {
    exit(1);
}
