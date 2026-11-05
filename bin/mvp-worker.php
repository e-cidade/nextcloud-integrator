#!/usr/bin/env php
<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * Explicit, bounded reconciler for an opt-in installation/municipality.
 * No ERP bootstrap, schema migrations, secrets in output, or auto-start.
 */
if (PHP_SAPI !== 'cli' || getenv('ECIDADE_MVP_EXPERIMENTAL') !== '1') {
    fwrite(STDERR, "MVP worker disabled; explicit CLI opt-in required.\n");
    exit(2);
}
require_once dirname(__DIR__) . '/src/Application/WorkerBatchOptions.php';
try {
    if ($argc !== 2) {
        throw new \InvalidArgumentException('Missing bounded batch argument');
    }
    $max = \ECidade\Nextcloud\Application\WorkerBatchOptions::parseLimit($argv[1]);
} catch (\InvalidArgumentException $error) {
    fwrite(STDERR, "Usage: ECIDADE_MVP_EXPERIMENTAL=1 php bin/mvp-worker.php --max=1..100\n");
    exit(2);
}
if (!extension_loaded('pgsql') || !extension_loaded('curl')) {
    fwrite(STDERR, "Required PostgreSQL and cURL extensions are unavailable.\n");
    exit(2);
}
foreach (['PGHOST', 'PGPORT', 'PGDATABASE', 'PGUSER', 'PGPASSWORD'] as $name) {
    $value = getenv($name);
    if (!is_string($value) || $value === ''
        || preg_match('/[\x00-\x20\x7f\x27\\\\]/', $value)) {
        fwrite(STDERR, "Missing or invalid worker PostgreSQL configuration.\n");
        exit(2);
    }
}
if (!preg_match('/^[1-9][0-9]{0,4}$/D', getenv('PGPORT'))) {
    fwrite(STDERR, "Invalid PostgreSQL port.\n");
    exit(2);
}
$root = dirname(__DIR__);
spl_autoload_register(function ($class) use ($root) {
    $prefix = 'ECidade\\Nextcloud\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }
    $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file = $root . '/src/' . $relative . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});
$db = null;
try {
    $scope = new \ECidade\Nextcloud\Domain\Scope(
        getenv('ECIDADE_MVP_INSTALLATION'),
        getenv('ECIDADE_MVP_TENANT')
    );
    // All connection parameters are escaped as libpq key/value values.
    // The worker requires a dedicated DB role granted ONLY its metadata schema.
    $parts = [];
    foreach (['PGHOST' => 'host', 'PGPORT' => 'port', 'PGDATABASE' => 'dbname',
        'PGUSER' => 'user', 'PGPASSWORD' => 'password'] as $env => $parameter) {
        $parts[] = $parameter . '=' . getenv($env);
    }
    $db = @pg_connect(implode(' ', $parts), PGSQL_CONNECT_FORCE_NEW);
    if ($db === false) {
        throw new \RuntimeException('Worker database unavailable');
    }
    $ledger = new \ECidade\Nextcloud\Persistence\PgDocumentLedger($db);
    $remote = \ECidade\Nextcloud\Transport\NextcloudMvpClient::fromEnvironment();
    $worker = new \ECidade\Nextcloud\Application\DocumentReconciler($ledger, $remote, $scope);
    $processed = 0;
    for ($i = 0; $i < $max; $i++) {
        if (!$worker->runOnce()) {
            break;
        }
        $processed++;
    }
    echo 'Reconciled ', $processed, " operations; remaining work is not implied to be empty.\n";
} catch (\Exception $error) {
    // Do not print error messages from drivers (may contain credentials / hostnames).
    fwrite(STDERR, "MVP worker failed closed; inspect scoped journal status.\n");
    exit(1);
} finally {
    if ($db !== null && $db !== false) {
        pg_close($db);
    }
}
