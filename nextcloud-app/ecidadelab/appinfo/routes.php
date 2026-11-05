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
  ],
];
