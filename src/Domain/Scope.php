<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace ECidade\Nextcloud\Domain;

/**
 * An immutable authority boundary. The installation and tenant MUST come
 * from authenticated server configuration, never from an arbitrary file ID.
 */
final class Scope
{
    private $installation;
    private $tenant;

    public function __construct($installation, $tenant)
    {
        foreach (['installation' => $installation, 'tenant' => $tenant] as $label => $value) {
            if (!is_string($value) || !preg_match('/^[a-z][a-z0-9_-]{2,63}$/D', $value)) {
                throw new \InvalidArgumentException('Invalid ' . $label . ' identifier');
            }
        }
        $this->installation = $installation;
        $this->tenant = $tenant;
    }

    public function installation()
    {
        return $this->installation;
    }

    public function tenant()
    {
        return $this->tenant;
    }

    public function equals(Scope $other)
    {
        return $this->installation === $other->installation
            && $this->tenant === $other->tenant;
    }

    public function key()
    {
        return hash('sha256', $this->installation . "\0" . $this->tenant);
    }
}
