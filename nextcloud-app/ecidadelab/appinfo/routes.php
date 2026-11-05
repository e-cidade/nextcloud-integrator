<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);
return [
  'ocs' => [
    ['name' => 'documents#create', 'url' => '/api/v1/documents', 'verb' => 'POST'],
    ['name' => 'documents#reserve', 'url' => '/api/v1/documents/reserve', 'verb' => 'POST'],
    ['name' => 'documents#inspect', 'url' => '/api/v1/documents/{id}', 'verb' => 'GET'],
    ['name' => 'documents#download', 'url' => '/api/v1/documents/{id}/content', 'verb' => 'GET'],
    ['name' => 'documents#replace', 'url' => '/api/v1/documents/{id}/content', 'verb' => 'PUT'],
    ['name' => 'documents#remove', 'url' => '/api/v1/documents/{id}', 'verb' => 'DELETE'],
    // MVP v2: disabled by default and bound to authenticated service identities.
    ['name' => 'mvp#stage', 'url' => '/api/v2/documents/{documentKey}/versions/{operationKey}', 'verb' => 'POST'],
    ['name' => 'mvp#inspect', 'url' => '/api/v2/documents/{documentKey}/versions/{operationKey}', 'verb' => 'GET'],
    ['name' => 'mvp#finalize', 'url' => '/api/v2/documents/{documentKey}/versions/{operationKey}/finalize', 'verb' => 'POST'],
    ['name' => 'mvp#download', 'url' => '/api/v2/documents/{documentKey}/versions/{operationKey}/content', 'verb' => 'GET'],
    ['name' => 'mvp#abort', 'url' => '/api/v2/documents/{documentKey}/versions/{operationKey}', 'verb' => 'DELETE'],
    ['name' => 'mvp#staged', 'url' => '/api/v2/staged', 'verb' => 'GET'],
  ],
];
