<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace ECidade\Nextcloud;

/**
 * Shared PHP 5.6+ client. Uses the existing lab Nextcloud API.
 * Lab endpoint is not production hardened.
 */
class DocumentClient
{
    private $options;
    private $handle;
    public function __construct(array $options)
    {
        foreach (['url','user','token','tenant','ca_file','name','business'] as $field) {
            if (empty($options[$field])) {
                throw new \RuntimeException('Missing configuration: '.$field);
            }
        }
        if (strpos($options['url'], 'https://') !== 0 || !is_file($options['ca_file'])) {
            throw new \RuntimeException('HTTPS and CA certificate required');
        }
        if (!preg_match('/^[a-z0-9-]{3,40}$/D', $options['tenant'])) {
            throw new \RuntimeException('Invalid tenant');
        }
        if (strlen($options['name']) > 120 || strpos($options['name'], '/') !== false || strpos($options['name'], '\\') !== false) {
            throw new \RuntimeException('Invalid filename');
        }
        if (strlen($options['business']) > 90) {
            throw new \RuntimeException('Business reference is too long');
        }
        foreach (['name','business'] as $key) {
            if (preg_match('/[\x00-\x1f\x7f]/', $options[$key])) {
                throw new \RuntimeException('Control characters forbidden in document metadata');
            }
        }
        $this->options = $options;
    }
    public static function fromEnvironment()
    {
        if (getenv('ECIDADE_POC_CLIENT') !== '1') {
            throw new \RuntimeException('Experimental transport disabled outside explicitly marked lab');
        }
        return new self([
            'url' => getenv('ECIDADE_NC_BASE_URL'),
            'user' => getenv('ECIDADE_NC_USER'), 'token' => getenv('ECIDADE_NC_TOKEN'),
            'tenant' => getenv('ECIDADE_NC_TENANT'), 'ca_file' => getenv('ECIDADE_NC_CA'),
            'name' => getenv('ECIDADE_NC_FILENAME'), 'business' => getenv('ECIDADE_NC_BUSINESS'),
            'http_host' => getenv('ECIDADE_NC_HTTP_HOST')
        ]);
    }
    private function request($verb, $path, $payload, $status)
    {
        if (!preg_match('#^/(?:reserve|[0-9]+(?:/content)?)$#D', $path)) {
            throw new \InvalidArgumentException('Invalid document path');
        }
        if ($this->handle === null) {
            $this->handle = curl_init();
        }
        curl_reset($this->handle);
        $o = $this->options;
        $headers = [
            'OCS-APIRequest: true','X-Ecidade-Tenant: '.$o['tenant'],
            'X-Ecidade-Filename: '.$o['name'],'X-Ecidade-Business: '.$o['business'],
            'Content-Type: application/octet-stream','Expect:'
        ];
        if (!empty($o['http_host'])) {
            if (!preg_match('/^[a-z0-9.-]+(?::[0-9]+)?$/iD', $o['http_host'])) {
                throw new \RuntimeException('Invalid HTTP Host override');
            }
            $headers[] = 'Host: '.$o['http_host'];
        }
        $opts = [
            CURLOPT_URL => rtrim($o['url'], '/').$path,
            CURLOPT_CUSTOMREQUEST => $verb,CURLOPT_USERPWD => $o['user'].':'.$o['token'],
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,CURLOPT_CONNECTTIMEOUT => 8,CURLOPT_TIMEOUT => 120,
            CURLOPT_HTTPHEADER => $headers,CURLOPT_CAINFO => $o['ca_file'],
            CURLOPT_SSL_VERIFYPEER => true,CURLOPT_SSL_VERIFYHOST => 2
        ];
        if (defined('CURLOPT_NOPROXY')) {
            $opts[CURLOPT_NOPROXY] = '*';
        }
        curl_setopt_array($this->handle, $opts);
        if ($payload !== null) {
            curl_setopt($this->handle, CURLOPT_POSTFIELDS, $payload);
        } elseif ($verb === 'POST') {
            curl_setopt($this->handle, CURLOPT_POSTFIELDS, '');
        }
        $raw = curl_exec($this->handle);
        $code = (int)curl_getinfo($this->handle, CURLINFO_HTTP_CODE);
        if ($raw === false) {
            throw new \RuntimeException('Nextcloud transport unavailable: '.curl_error($this->handle));
        }
        // Never disclose response contents on error: they may contain user data.
        if ($code !== $status) {
            throw new \RuntimeException('Nextcloud refused document operation (HTTP '.$code.')');
        }
        return (string)$raw;
    }
    private function metadata($verb, $path, $body, $status)
    {
        $data = json_decode($this->request($verb, $path, $body, $status), true);
        if (!is_array($data) || !isset($data['id']) || !is_numeric($data['id'])) {
            throw new \RuntimeException('Nextcloud returned invalid metadata');
        }
        return $data;
    }
    private function id($id)
    {
        if (!is_numeric($id) || (int)$id < 1 || (string)(int)$id !== (string)$id) {
            throw new \InvalidArgumentException('Invalid document ID');
        }
        return (int)$id;
    }
    public function reserve()
    {
        return $this->metadata('POST', '/reserve', '', 201);
    }
    public function write($id, $bytes)
    {
        if (!is_string($bytes)) {
            throw new \InvalidArgumentException('Content must be bytes');
        }
        $m = $this->metadata('PUT', '/'.$this->id($id).'/content', $bytes, 200);
        if (!isset($m['checksum'],$m['state']) || $m['state'] !== 'committed'
            || $m['checksum'] !== hash('sha256', $bytes)) {
            throw new \RuntimeException('Nextcloud checksum or state mismatch');
        }
        return $m;
    }
    public function read($id)
    {
        return $this->request('GET', '/'.$this->id($id).'/content', null, 200);
    }
    public function inspect($id)
    {
        return $this->metadata('GET', '/'.$this->id($id), null, 200);
    }
    public function remove($id)
    {
        $this->request('DELETE', '/'.$this->id($id), null, 204);
        return true;
    }
}
