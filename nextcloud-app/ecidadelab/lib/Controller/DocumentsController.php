<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace OCA\EcidadeLab\Controller;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\AppFramework\OCSController;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\FilesMetadata\IFilesMetadataManager;
use OCP\IRequest;
use OCP\IUserSession;

/** Experimental ONLY. Not safe for production tenant isolation / distributed transactions. */
class DocumentsController extends OCSController
{
    public function __construct(
        string $appName,
        IRequest $request,
        private IRootFolder $rootFolder,
        private IUserSession $userSession,
        private IFilesMetadataManager $metadataManager
    ) {
        parent::__construct($appName, $request);
    }

    private function tenant(): string
    {
        $value = $this->request->getHeader('X-Ecidade-Tenant');
        // Hardcoded to a single test municipality; use credentials bound to tenant in production.
        if ($value !== 'municipio-a') {
            throw new OCSForbiddenException('Unknown or unauthorized lab tenant');
        }
        return $value;
    }

    private function folder(string $tenant)
    {
        $user = $this->userSession->getUser();
        if ($user === null || $user->getUID() !== 'bench') {
            throw new OCSForbiddenException('Test account required');
        }
        $home = $this->rootFolder->getUserFolder($user->getUID());
        foreach (['ECidadeLab', 'ECidadeLab/Documents', "ECidadeLab/Documents/$tenant"] as $path) {
            if (!$home->nodeExists($path)) {
                $home->newFolder($path);
            }
        }
        return $home->get("ECidadeLab/Documents/$tenant");
    }

    private function file(int $id): File
    {
        if ($id < 1) {
            throw new OCSBadRequestException('Invalid file ID');
        }
        $tenant = $this->tenant();
        $node = $this->folder($tenant)->getFirstNodeById($id);
        if (!$node instanceof File) {
            throw new OCSNotFoundException('Document does not exist');
        }
        $meta = $this->metadataManager->getMetadata($id);
        if (!$meta->hasKey('ecidade-tenant') || $meta->getString('ecidade-tenant') !== $tenant) {
            throw new OCSForbiddenException('Document not authorized');
        }
        return $node;
    }

    private function body(): string
    {
        $body = file_get_contents('php://input');
        if (!is_string($body) || strlen($body) > 8388608) {
            throw new OCSBadRequestException('Limit: 8 MiB in this POC');
        }
        return $body;
    }

    private function setMetadata(
        File $file,
        string $tenant,
        string $name,
        string $business,
        string $bytes,
        string $state,
        int $revision
    ): void {
        $meta = $this->metadataManager->getMetadata($file->getId(), true);
        $meta->setString('ecidade-tenant', $tenant);
        $meta->setString('ecidade-name', $name);
        $meta->setString('ecidade-business', $business);
        $meta->setString('ecidade-checksum', hash('sha256', $bytes));
        $meta->setString('ecidade-state', $state);
        $meta->setInt('ecidade-size', strlen($bytes));
        $meta->setInt('ecidade-revision', $revision);
        $this->metadataManager->saveMetadata($meta);
    }

    private function details(File $file): array
    {
        $m = $this->metadataManager->getMetadata($file->getId());
        return [
            'id' => $file->getId(),
            'tenant' => $m->getString('ecidade-tenant'),
            'name' => $m->getString('ecidade-name'),
            'business' => $m->getString('ecidade-business'),
            'checksum' => $m->getString('ecidade-checksum'),
            'size' => $m->getInt('ecidade-size'),
            'state' => $m->getString('ecidade-state'),
            'revision' => $m->getInt('ecidade-revision'),
        ];
    }

    private function json(array $payload, int $status = Http::STATUS_OK): DataDisplayResponse
    {
        return new DataDisplayResponse(
            json_encode($payload, JSON_THROW_ON_ERROR),
            $status,
            ['Content-Type' => 'application/json']
        );
    }

    private function createFile(bool $reserve): DataDisplayResponse
    {
        $tenant = $this->tenant();
        $name = $this->request->getHeader('X-Ecidade-Filename');
        $business = $this->request->getHeader('X-Ecidade-Business');
        if (!is_string($name) || strlen($name) < 1 || strlen($name) > 120
            || str_contains($name, '/') || str_contains($name, '\\')
            || !is_string($business) || strlen($business) > 90) {
            throw new OCSBadRequestException('Invalid document metadata');
        }
        $content = $reserve ? '' : $this->body();
        $file = $this->folder($tenant)->newFile(
            'document-' . bin2hex(random_bytes(12)) . '.bin',
            $content
        );
        try {
            $this->setMetadata(
                $file,
                $tenant,
                $name,
                $business,
                $content,
                $reserve ? 'reserved' : 'committed',
                $reserve ? 0 : 1
            );
        } catch (\Throwable $e) {
            $file->delete();
            throw $e;
        }
        return $this->json($this->details($file), Http::STATUS_CREATED);
    }

    #[NoAdminRequired]
    public function create(): DataDisplayResponse
    {
        return $this->createFile(false);
    }
    #[NoAdminRequired]
    public function reserve(): DataDisplayResponse
    {
        return $this->createFile(true);
    }

    #[NoAdminRequired]
    public function inspect(int $id): DataDisplayResponse
    {
        return $this->json($this->details($this->file($id)));
    }

    #[NoAdminRequired]
    public function download(int $id): DataDisplayResponse
    {
        $file = $this->file($id);
        $meta = $this->details($file);
        if ($meta['state'] !== 'committed') {
            throw new OCSForbiddenException('Document has not been committed');
        }
        $bytes = $file->getContent();
        if (hash('sha256', $bytes) !== $meta['checksum']) {
            throw new \RuntimeException('Document checksum mismatch');
        }
        return new DataDisplayResponse(
            $bytes,
            Http::STATUS_OK,
            ['Content-Type' => 'application/octet-stream']
        );
    }

    #[NoAdminRequired]
    public function replace(int $id): DataDisplayResponse
    {
        $file = $this->file($id);
        $previous = $this->details($file);
        $bytes = $this->body();
        $file->putContent($bytes);
        $this->setMetadata(
            $file,
            $previous['tenant'],
            $previous['name'],
            $previous['business'],
            $bytes,
            'committed',
            $previous['revision'] + 1
        );
        return $this->json($this->details($file));
    }

    #[NoAdminRequired]
    public function remove(int $id): DataDisplayResponse
    {
        $this->file($id)->delete();
        return new DataDisplayResponse('', Http::STATUS_NO_CONTENT);
    }
}
