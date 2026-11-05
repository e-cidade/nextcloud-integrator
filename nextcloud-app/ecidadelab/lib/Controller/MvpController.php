<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\EcidadeLab\Controller;

use OCA\EcidadeLab\Service\ScopedDocumentStorage;
use OCA\EcidadeLab\Service\ScopePolicy;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCSController;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Opt-in MVP API. All operations deny by default until an administrator
 * binds authenticated service users to exactly one installation and tenant.
 *
 * No direct access to the e-Cidade DB and no production deployment here.
 */
final class MvpController extends OCSController
{
    private const MAX_BYTES = 8388608;

    public function __construct(
        string $appName,
        IRequest $request,
        private IUserSession $userSession,
        private IConfig $config,
        private ScopedDocumentStorage $storage
    ) {
        parent::__construct($appName, $request);
    }

    private function scope(): array
    {
        if (!$this->config->getSystemValueBool('ecidadelab_mvp_enabled', false)) {
            throw new OCSForbiddenException('Experimental MVP API disabled');
        }
        $user = $this->userSession->getUser();
        $uid = $user === null ? null : $user->getUID();

        // Admin-controlled mapping (example only):
        // 'ecidadelab_principals' => [
        //   'synthetic-service-a' => [
        //       'installation'=>'installation-a', 'tenant'=>'municipio-a'
        //   ]
        // ]
        $principals = $this->config->getSystemValue('ecidadelab_principals', []);
        try {
            return ScopePolicy::resolve(
                $uid,
                $principals,
                $this->request->getHeader('X-Ecidade-Installation'),
                $this->request->getHeader('X-Ecidade-Tenant')
            );
        } catch (\RuntimeException $denied) {
            // Do not leak whether a tenant, installation or UID exists.
            throw new OCSForbiddenException('Document access forbidden');
        }
    }

    private function keys(string $documentKey, string $operationKey): void
    {
        try {
            ScopePolicy::opaqueKey($documentKey);
            ScopePolicy::opaqueKey($operationKey);
        } catch (\InvalidArgumentException $denied) {
            throw new OCSBadRequestException('Invalid document reference');
        }
    }

    private function body(): string
    {
        $length = $this->request->getHeader('Content-Length');
        if (is_numeric($length) && (float)$length > self::MAX_BYTES) {
            throw new OCSBadRequestException('Document exceeds experimental size limit');
        }
        $body = file_get_contents('php://input', false, null, 0, self::MAX_BYTES + 1);
        if (!is_string($body) || strlen($body) > self::MAX_BYTES) {
            throw new OCSBadRequestException('Document exceeds experimental size limit');
        }
        return $body;
    }

    private function json(array $value, int $status = Http::STATUS_OK): DataDisplayResponse
    {
        return new DataDisplayResponse(
            json_encode($value, JSON_THROW_ON_ERROR),
            $status,
            ['Content-Type' => 'application/json', 'Cache-Control' => 'private, no-store']
        );
    }

    #[NoAdminRequired]
    public function stage(string $documentKey, string $operationKey): DataDisplayResponse
    {
        $this->keys($documentKey, $operationKey);
        $scope = $this->scope();
        $meta = $this->storage->stage($scope, $documentKey, $operationKey, $this->body());
        return $this->json($meta, Http::STATUS_CREATED);
    }

    #[NoAdminRequired]
    public function inspect(string $documentKey, string $operationKey): DataDisplayResponse
    {
        $this->keys($documentKey, $operationKey);
        return $this->json($this->storage->inspect(
            $this->scope(),
            $documentKey,
            $operationKey
        ));
    }

    #[NoAdminRequired]
    public function finalize(string $documentKey, string $operationKey): DataDisplayResponse
    {
        $this->keys($documentKey, $operationKey);
        return $this->json($this->storage->finalize(
            $this->scope(),
            $documentKey,
            $operationKey
        ));
    }

    #[NoAdminRequired]
    public function download(string $documentKey, string $operationKey): DataDisplayResponse
    {
        $this->keys($documentKey, $operationKey);
        $bytes = $this->storage->download($this->scope(), $documentKey, $operationKey);
        return new DataDisplayResponse(
            $bytes,
            Http::STATUS_OK,
            ['Content-Type' => 'application/octet-stream','Cache-Control' => 'private, no-store']
        );
    }

    #[NoAdminRequired]
    public function abort(string $documentKey, string $operationKey): DataDisplayResponse
    {
        $this->keys($documentKey, $operationKey);
        $this->storage->abort($this->scope(), $documentKey, $operationKey);
        return new DataDisplayResponse('', Http::STATUS_NO_CONTENT);
    }

    #[NoAdminRequired]
    public function staged(): DataDisplayResponse
    {
        $before = $this->request->getParam('before', '');
        $limit = $this->request->getParam('limit', '100');
        if (!is_scalar($before) || !is_scalar($limit)
            || (string)(int)$before !== (string)$before
            || (string)(int)$limit !== (string)$limit) {
            throw new OCSBadRequestException('Invalid staged inventory parameters');
        }
        return $this->json($this->storage->listStaged(
            $this->scope(),
            (int)$before,
            (int)$limit
        ));
    }
}
