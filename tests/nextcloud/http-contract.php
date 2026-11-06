<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * HTTP contract for Nextcloud 35, using ONLY ephemeral synthetic service users.
 * Must be run inside the disposable Nextcloud test container, not on a host.
 * No production URLs, connections, credentials or document data.
 */

if (PHP_SAPI !== 'cli'
    || getenv('ECIDADE_SYNTHETIC_HTTP_TEST') !== '1'
    || getenv('NEXTCLOUD_ADMIN_USER') !== 'synthetic-admin'
    || !is_file('/.dockerenv')
    || !is_file('/var/www/html/config/config.php')) {
    fwrite(STDERR, "Refusing synthetic HTTP tests outside a disposable container.\n");
    exit(2);
}

$password = getenv('MVP_SYNTHETIC_PASSWORD');
if ($password === false || strlen($password) < 12) {
    throw new RuntimeException('Missing disposable test-only password');
}
$api = 'http://127.0.0.1/ocs/v2.php/apps/ecidadelab/api/v2';
$stateFile = '/tmp/ecidade-synthetic-http-state.json';
$phase = $argv[1] ?? 'functional';
$checks = 0;

function check(bool $result, string $message): void
{
    global $checks;
    $checks++;
    if (!$result) {
        throw new RuntimeException('Synthetic HTTP contract failed: ' . $message);
    }
}

function callApi(
    string $user,
    string $installation,
    string $tenant,
    string $method,
    string $path,
    ?string $bytes = null,
    array $headers = []
): array {
    global $api, $password;
    if (!in_array($user, ['synthetic-admin', 'synthetic-service-b'], true)
        || !preg_match('#^/(documents/[a-f0-9]{64}/versions/[a-f0-9]{64}(/(content|finalize|pending-content))?|staged\?before=[0-9]+&limit=[0-9]+)$#D', $path)) {
        throw new InvalidArgumentException('Invalid synthetic test target');
    }
    $handle = curl_init($api . $path);
    $params = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_USERPWD => $user . ':' . $password,
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_HTTPHEADER => array_merge([
            'OCS-APIRequest: true',
            'X-Ecidade-Installation: ' . $installation,
            'X-Ecidade-Tenant: ' . $tenant,
            'Accept: application/json',
            'Content-Type: application/octet-stream',
            'Expect:',
        ], $headers),
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 45,
    ];
    if ($bytes !== null) {
        $params[CURLOPT_POSTFIELDS] = $bytes;
    } elseif ($method === 'POST') {
        $params[CURLOPT_POSTFIELDS] = '';
    }
    curl_setopt_array($handle, $params);
    $response = curl_exec($handle);
    $status = (int)curl_getinfo($handle, CURLINFO_HTTP_CODE);
    // cURL handles are released automatically on PHP 8.0+.
    if (!is_string($response)) {
        throw new RuntimeException('Synthetic HTTP transport failed (no target disclosed)');
    }
    return [$status, $response, json_decode($response, true)];
}

function pathFor(string $document, string $operation): string
{
    return '/documents/' . $document . '/versions/' . $operation;
}

function acceptStatus(int $actual, array $expected, string $check): void
{
    check(in_array($actual, $expected, true), $check . ': HTTP ' . $actual);
}

try {
    if ($phase === 'disabled') {
        [$status] = callApi(
            'synthetic-admin',
            'installation-a',
            'municipio-a',
            'GET',
            '/staged?before=1&limit=1'
        );
        // Fail if the app route was never registered. The disposable
        // HTTP worker is reloaded after app activation by the workflow.
        acceptStatus($status, [403], 'Opt-in gate must refuse disabled API');
        echo 'PASS disabled-by-default HTTP gate (' . $checks . " checks)\n";
        exit(0);
    }

    if ($phase === 'revoked') {
        $state = json_decode((string)file_get_contents($stateFile), true);
        check(is_array($state) && isset($state['document'],$state['operation'],
            $state['other_document'],$state['other_operation']), 'Valid synthetic state');
        [$status] = callApi(
            'synthetic-service-b',
            'installation-b',
            'municipio-b',
            'GET',
            pathFor($state['other_document'], $state['other_operation'])
        );
        acceptStatus($status, [401,403], 'Disabled service account cannot authenticate');
        [$status, $bytes] = callApi(
            'synthetic-admin',
            'installation-a',
            'municipio-a',
            'GET',
            pathFor($state['document'], $state['operation']) . '/content'
        );
        acceptStatus($status, [200], 'Revoking other tenant does not affect authorized documents');
        check(hash_equals($state['bytes_sha256'], hash('sha256', $bytes)), 'Authorized document retains checksum');
        echo 'PASS synthetic principal revocation HTTP test (' . $checks . " checks)\n";
        exit(0);
    }

    if ($phase === 'restart') {
        $state = json_decode((string)file_get_contents($stateFile), true);
        check(is_array($state) && isset($state['document'],$state['operation'],$state['bytes_sha256']), 'Valid persisted synthetic test state');
        [$status, $bytes] = callApi(
            'synthetic-admin',
            'installation-a',
            'municipio-a',
            'GET',
            pathFor($state['document'], $state['operation']) . '/content'
        );
        acceptStatus($status, [200], 'Committed document is available after service restart');
        check(hash_equals($state['bytes_sha256'], hash('sha256', $bytes)), 'Integrity after restart');
        echo 'PASS synthetic document persists through Nextcloud container restart (' . $checks . " checks)\n";
        exit(0);
    }

    if ($phase !== 'functional') {
        throw new InvalidArgumentException('Unrecognized synthetic test phase');
    }

    $doc = bin2hex(random_bytes(32));
    $op = bin2hex(random_bytes(32));
    $bytes = '%PDF-1.7 synthetic data only' . "\n" . str_repeat('A', 4096);
    $base = pathFor($doc, $op);

    [$status, $body, $meta] = callApi(
        'synthetic-admin',
        'installation-a',
        'municipio-a',
        'POST',
        $base,
        $bytes
    );
    if ($status !== 201) {
        $message = is_array($meta) ? ($meta['ocs']['meta']['message'] ?? $meta['message'] ?? '') : '';
        $reason = is_scalar($message) ? substr((string)$message, 0, 160) : '';
        throw new RuntimeException('Stage immutable document: HTTP ' . $status . ($reason !== '' ? ' (' . $reason . ')' : ''));
    }
    acceptStatus($status, [201], 'Stage immutable document');
    check(is_array($meta), 'Stage returns JSON metadata');
    check(($meta['state'] ?? '') === 'staged', 'Initial state is staged');
    check(($meta['checksum'] ?? '') === hash('sha256', $bytes), 'Stage checksum');
    check(($meta['size'] ?? -1) === strlen($bytes), 'Stage size');
    check(($meta['id'] ?? 0) > 0, 'Stage returns a Nextcloud ID');

    [$status, , $inspected] = callApi(
        'synthetic-admin',
        'installation-a',
        'municipio-a',
        'GET',
        $base
    );
    acceptStatus($status, [200], 'Inspect staged version');
    check(($inspected['state'] ?? '') === 'staged', 'Staged version not published');

    [$status] = callApi(
        'synthetic-admin',
        'installation-a',
        'municipio-a',
        'GET',
        $base . '/content'
    );
    acceptStatus($status, [403], 'Staged content must be inaccessible');

    // Ordinary authenticated service requests still cannot read staged bytes.
    [$status] = callApi(
        'synthetic-admin',
        'installation-a',
        'municipio-a',
        'GET',
        $base . '/pending-content'
    );
    acceptStatus($status, [403], 'Pending read requires independent authorization');

    [$status] = callApi(
        'synthetic-admin',
        'installation-a',
        'municipio-a',
        'GET',
        $base . '/pending-content',
        null,
        ['X-Ecidade-Pending-Time: ' . time(),
            'X-Ecidade-Pending-Signature: ' . str_repeat('0', 64)]
    );
    acceptStatus($status, [403], 'Forged pending read proof rejected');

    [$status, , $finalized] = callApi(
        'synthetic-admin',
        'installation-a',
        'municipio-a',
        'POST',
        $base . '/finalize'
    );
    acceptStatus($status, [200], 'Finalize staging');
    check(($finalized['state'] ?? '') === 'committed', 'Committed state');

    [$status, $downloaded] = callApi(
        'synthetic-admin',
        'installation-a',
        'municipio-a',
        'GET',
        $base . '/content'
    );
    acceptStatus($status, [200], 'Download committed content');
    check(hash_equals(hash('sha256', $bytes), hash('sha256', $downloaded)), 'Committed content unchanged');

    [$status, , $secondFinalize] = callApi(
        'synthetic-admin',
        'installation-a',
        'municipio-a',
        'POST',
        $base . '/finalize'
    );
    acceptStatus($status, [200], 'Idempotent re-finalization');
    check(($secondFinalize['id'] ?? null) === ($meta['id'] ?? null), 'No second file during retry');

    [$status, , $repeated] = callApi(
        'synthetic-admin',
        'installation-a',
        'municipio-a',
        'POST',
        $base,
        $bytes
    );
    acceptStatus($status, [201], 'Repeat stage with same bytes');
    check(($repeated['id'] ?? null) === ($meta['id'] ?? null), 'Immutable deduplication by operation key');

    [$status] = callApi(
        'synthetic-admin',
        'installation-a',
        'municipio-a',
        'POST',
        $base,
        $bytes . 'changed'
    );
    acceptStatus($status, [400], 'Reject same idempotency key with different content');

    [$status] = callApi(
        'synthetic-admin',
        'installation-a',
        'municipio-b',
        'GET',
        $base . '/content'
    );
    acceptStatus($status, [403], 'Header cannot escalate account into another municipality');

    [$status] = callApi(
        'synthetic-service-b',
        'installation-b',
        'municipio-b',
        'GET',
        $base . '/content'
    );
    acceptStatus($status, [404], 'Other authenticated municipality cannot read document');

    [$status] = callApi(
        'synthetic-service-b',
        'installation-a',
        'municipio-a',
        'GET',
        $base . '/content'
    );
    acceptStatus($status, [403], 'Other municipality cannot impersonate scope using headers');

    $doc2 = bin2hex(random_bytes(32));
    $op2 = bin2hex(random_bytes(32));
    [$status, , $meta2] = callApi(
        'synthetic-service-b',
        'installation-b',
        'municipio-b',
        'POST',
        pathFor($doc2, $op2),
        'synthetic-B-data'
    );
    acceptStatus($status, [201], 'Second municipality stages its own file');
    check(($meta2['state'] ?? '') === 'staged', 'Tenant B stage');
    [$status] = callApi(
        'synthetic-admin',
        'installation-a',
        'municipio-a',
        'GET',
        pathFor($doc2, $op2)
    );
    acceptStatus($status, [404], 'Cross-tenant inventory access fails');

    [$status] = callApi(
        'synthetic-service-b',
        'installation-b',
        'municipio-b',
        'DELETE',
        pathFor($doc2, $op2)
    );
    acceptStatus($status, [403], 'Fresh staged document cannot be deleted prematurely');

    [$status, , $items] = callApi(
        'synthetic-admin',
        'installation-a',
        'municipio-a',
        'GET',
        '/staged?before=1&limit=100'
    );
    acceptStatus($status, [200], 'Scoped orphan inventory');
    check(is_array($items) && $items === [], 'No old staged orphans');
    [$status] = callApi(
        'synthetic-admin',
        'installation-a',
        'municipio-a',
        'GET',
        '/staged?before=' . time() . '&limit=100'
    );
    acceptStatus($status, [400], 'Reject unsafe orphan listing range');

    file_put_contents($stateFile, json_encode([
        'document' => $doc,
        'operation' => $op,
        'other_document' => $doc2,
        'other_operation' => $op2,
        'bytes_sha256' => hash('sha256', $bytes),
    ], JSON_THROW_ON_ERROR));

    echo 'PASS real Nextcloud 35 HTTP lifecycle, cross-tenant authorization and integrity (' . $checks . " checks)\n";
} catch (Throwable $failure) {
    fwrite(STDERR, 'FAIL disposable HTTP contract: ' . $failure->getMessage() . "\n");
    exit(1);
}
