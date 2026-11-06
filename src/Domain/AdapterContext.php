<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace ECidade\Nextcloud\Domain;

/**
 * Immutable business identity supplied by a trusted ERP integration.
 * The injected policy MUST verify the authenticated actor and ACL mapping;
 * these attributes are not independently authoritative.
 */
final class AdapterContext
{
    private $scope;
    private $backend;
    private $surface;
    private $actor;
    private $attributes;

    public function __construct(Scope $scope, $backend, $surface, $actor, array $attributes = [])
    {
        foreach (['backend' => $backend, 'surface' => $surface, 'actor' => $actor] as $name => $value) {
            if (!is_string($value) || !preg_match('/^[a-z][a-z0-9_-]{1,63}$/D', $value)) {
                throw new \InvalidArgumentException('Invalid adapter ' . $name);
            }
        }
        $this->scope = $scope;
        $this->backend = $backend;
        $this->surface = $surface;
        $this->actor = $actor;
        $this->attributes = $attributes;
    }

    public function scope()
    {
        return $this->scope;
    }

    public function backend()
    {
        return $this->backend;
    }

    public function surface()
    {
        return $this->surface;
    }

    public function actor()
    {
        return $this->actor;
    }

    public function attributes()
    {
        return $this->attributes;
    }
}
