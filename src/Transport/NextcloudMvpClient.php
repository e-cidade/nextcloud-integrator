<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace ECidade\Nextcloud\Transport;

use ECidade\Nextcloud\Contract\RemoteDocuments;
use ECidade\Nextcloud\Domain\OperationKey;
use ECidade\Nextcloud\Domain\Scope;

/**
 * HTTPS transport for the opt-in Nextcloud MVP v2 API.
 * No host-specific paths/addresses, no credentials in logs, no PG access.
 *
 * Supports legacy PHP 5.6; the remote app requires Nextcloud 35/PHP 8.3.
 */
final class NextcloudMvpClient implements RemoteDocuments
{
    private $scope;
    private $url;
    private $username;
    private $token;
    private $caFile;
    private $handle;
    private $pendingReadKey;

    public function __construct(Scope $scope, array $options)
    {
        foreach (['url', 'user', 'token', 'ca_file'] as $key) {
            if (!isset($options[$key]) || !is_string($options[$key])
                || $options[$key] === '' || preg_match('/[\x00-\x1f\x7f]/', $options[$key])) {
                throw new \InvalidArgumentException('Invalid remote transport configuration');
            }
        }
        if (strpos($options['url'], 'https://') !== 0
            || !is_file($options['ca_file'])
            || strpos($options['url'], '@') !== false
            || strpos($options['url'], '#') !== false) {
            throw new \InvalidArgumentException('HTTPS and a trusted certificate are required');
        }
        // Do not allow arbitrary path suffixes such as user-controlled URLs.
        if (!preg_match('#/ocs/v2\.php/apps/ecidadelab/api/v2/?$#D', $options['url'])) {
            throw new \InvalidArgumentException('Unexpected Nextcloud MVP API URL');
        }
        $this->scope = $scope;
        $this->url = rtrim($options['url'], '/');
        $this->username = $options['user'];
        $this->token = $options['token'];
        $this->caFile = $options['ca_file'];
        // Pending reads need a separate server-side secret, not an app password.
        $this->pendingReadKey = isset($options['pending_read_key'])
            ? $options['pending_read_key'] : null;
    }

    public static function fromEnvironment()
    {
        if (getenv('ECIDADE_MVP_EXPERIMENTAL') !== '1') {
            throw new \RuntimeException('MVP transport disabled outside explicitly approved tests');
        }
        $scope = new Scope(
            getenv('ECIDADE_MVP_INSTALLATION'),
            getenv('ECIDADE_MVP_TENANT')
        );
        return new self($scope, [
            'url' => getenv('ECIDADE_MVP_NEXTCLOUD_URL'),
            'user' => getenv('ECIDADE_MVP_USER'),
            'token' => getenv('ECIDADE_MVP_TOKEN'),
            'ca_file' => getenv('ECIDADE_MVP_CA'),
            'pending_read_key' => getenv('ECIDADE_MVP_PENDING_READ_KEY'),
        ]);
    }

    private function assertScope(Scope $scope)
    {
        if (!$scope->equals($this->scope)) {
            throw new \RuntimeException('Remote document scope mismatch');
        }
    }

    private function request($method, $path, $bytes, array $expected, array $extraHeaders = [])
    {
        if (!preg_match('#^/documents/[a-f0-9]{64}/versions/[a-f0-9]{64}(?:/(?:finalize|content|pending-content))?$#D', $path)
            && !preg_match('#^/staged\?before=[0-9]{1,11}&limit=[0-9]{1,3}$#D', $path)) {
            throw new \InvalidArgumentException('Invalid remote document API path');
        }
        if ($this->handle === null) {
            $this->handle = curl_init();
        }
        curl_reset($this->handle);
        $headers = [
            'OCS-APIRequest: true',
            'Accept: application/json',
            'Content-Type: application/octet-stream',
            'X-Ecidade-Installation: ' . $this->scope->installation(),
            'X-Ecidade-Tenant: ' . $this->scope->tenant(),
            'Cache-Control: no-store',
            'Expect:'
        ];
        $headers = array_merge($headers, $extraHeaders);
        $options = [
            CURLOPT_URL => $this->url . $path,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_USERPWD => $this->username . ':' . $this->token,
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CAINFO => $this->caFile,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];
        curl_setopt_array($this->handle, $options);
        if ($bytes !== null) {
            curl_setopt($this->handle, CURLOPT_POSTFIELDS, $bytes);
        } elseif ($method === 'POST') {
            curl_setopt($this->handle, CURLOPT_POSTFIELDS, '');
        }
        $raw = curl_exec($this->handle);
        $status = (int)curl_getinfo($this->handle, CURLINFO_HTTP_CODE);
        if ($raw === false) {
            // Never include cURL's raw error; it can disclose internal hostnames.
            throw new \RuntimeException('Remote Nextcloud transport unavailable');
        }
        if (!in_array($status, $expected, true)) {
            // Never log response body; it may contain infrastructure details.
            throw new \RuntimeException('Remote document operation refused (HTTP '
                . $status . ')');
        }
        return (string)$raw;
    }

    private function versionPath($documentKey, $operationKey)
    {
        return '/documents/' . OperationKey::validate($documentKey)
            . '/versions/' . OperationKey::validate($operationKey);
    }

    private function decode($raw)
    {
        $value = json_decode($raw, true);
        if (!is_array($value)) {
            throw new \RuntimeException('Remote returned an invalid document response');
        }
        return $value;
    }

    public function stage(Scope $scope, $documentKey, $operationKey, $bytes)
    {
        $this->assertScope($scope);
        if (!is_string($bytes) || strlen($bytes) > 8388608) {
            throw new \InvalidArgumentException('Document exceeds experimental size limit');
        }
        $value = $this->decode($this->request(
            'POST',
            $this->versionPath($documentKey, $operationKey),
            $bytes,
            [201]
        ));
        if (!isset($value['id'],$value['state'],$value['checksum'],$value['size'])
            || $value['state'] !== 'staged'
            || !hash_equals(hash('sha256', $bytes), $value['checksum'])
            || (int)$value['size'] !== strlen($bytes) || (int)$value['id'] < 1) {
            throw new \RuntimeException('Remote stage integrity verification failed');
        }
        return $value;
    }

    public function finalize(Scope $scope, $documentKey, $operationKey)
    {
        $this->assertScope($scope);
        $value = $this->decode($this->request(
            'POST',
            $this->versionPath($documentKey, $operationKey) . '/finalize',
            null,
            [200]
        ));
        if (!isset($value['checksum'],$value['state'],$value['size'])
            || $value['state'] !== 'committed') {
            throw new \RuntimeException('Remote finalization acknowledgement invalid');
        }
        return $value;
    }

    public function download(Scope $scope, $documentKey, $operationKey)
    {
        $this->assertScope($scope);
        $raw = $this->request(
            'GET',
            $this->versionPath($documentKey, $operationKey) . '/content',
            null,
            [200]
        );
        if (strlen($raw) > 8388608) {
            throw new \RuntimeException('Remote response exceeds experimental size limit');
        }
        return $raw;
    }

    /**
     * Authenticated privileged read, constructed only after SQL-visible
     * journal lookup. The server validates a separate short-lived MAC.
     */
    public function downloadPending(Scope $scope, $documentKey, $operationKey)
    {
        $this->assertScope($scope);
        $documentKey = OperationKey::validate($documentKey);
        $operationKey = OperationKey::validate($operationKey);
        if (!is_string($this->pendingReadKey) || strlen($this->pendingReadKey) < 32) {
            throw new \RuntimeException('Pending read capability not configured');
        }
        $timestamp = time();
        $payload = implode("\n", [
            'pending-read-v1', $scope->installation(), $scope->tenant(),
            $documentKey, $operationKey, (string)$timestamp
        ]);
        $signature = hash_hmac('sha256', $payload, $this->pendingReadKey);
        $raw = $this->request(
            'GET',
            $this->versionPath($documentKey, $operationKey) . '/pending-content',
            null,
            [200],
            ['X-Ecidade-Pending-Time: ' . $timestamp,
                'X-Ecidade-Pending-Signature: ' . $signature]
        );
        if (strlen($raw) > 8388608) {
            throw new \RuntimeException('Remote response exceeds experimental size limit');
        }
        return $raw;
    }

    public function abort(Scope $scope, $documentKey, $operationKey)
    {
        $this->assertScope($scope);
        $this->request(
            'DELETE',
            $this->versionPath($documentKey, $operationKey),
            null,
            [204]
        );
        return true;
    }

    public function listStaged(Scope $scope, $beforeUnixTimestamp, $limit)
    {
        $this->assertScope($scope);
        if (!is_int($beforeUnixTimestamp) || $beforeUnixTimestamp > time() - 86400
            || !is_int($limit) || $limit < 1 || $limit > 100) {
            throw new \InvalidArgumentException('Unsafe staged inventory request');
        }
        $raw = $this->request(
            'GET',
            '/staged?before=' . $beforeUnixTimestamp . '&limit=' . $limit,
            null,
            [200]
        );
        $items = $this->decode($raw);
        if (count($items) > $limit) {
            throw new \RuntimeException('Remote inventory exceeded requested limit');
        }
        foreach ($items as $item) {
            if (!is_array($item) || !isset($item['document_key'],$item['operation_key'])) {
                throw new \RuntimeException('Incomplete remote staged inventory');
            }
            OperationKey::validate($item['document_key']);
            OperationKey::validate($item['operation_key']);
        }
        return $items;
    }
}
