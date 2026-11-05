<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use ECidade\Nextcloud\AdapterRegistry;
use PHPUnit\Framework\TestCase;

final class AdapterRegistryTest extends TestCase
{
    private AdapterRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new AdapterRegistry(dirname(__DIR__, 2));
    }

    public function testKnownForksAreListed(): void
    {
        self::assertSame(['contass', 'cpd', 'dbseller'], $this->registry->ids());
    }

    public function testPublicAdapterManifestCannotClaimProductionReadiness(): void
    {
        foreach ($this->registry->ids() as $id) {
            $manifest = $this->registry->checkSelection($id);
            self::assertFalse($manifest['production_ready'], $id);
            self::assertNotEmpty($manifest['hooks'], $id);
            self::assertSame(2, $manifest['schema'], $id);
        }
    }

    public function testRejectsUnknownModule(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unknown adapter');
        $this->registry->find('unsupported-fork');
    }

    public function testRejectsTraversal(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->registry->find('../dbseller');
    }

    public function testRejectsWhitespaceInModuleId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->registry->find('foo bar');
    }

    public function testKnownModulesHaveImmutableSources(): void
    {
        foreach ($this->registry->ids() as $id) {
            $manifest = $this->registry->find($id);
            foreach ($manifest['hooks'] as $hook) {
                if ($hook['type'] !== 'php-file-override') {
                    continue;
                }
                self::assertMatchesRegularExpression(
                    '/^[a-f0-9]{40}$/',
                    $hook['source']['git_blob']
                );
                self::assertFileExists(
                    dirname(__DIR__, 2) . '/modules/' . $id . '/' . $hook['replacement']
                );
            }
        }
    }
}
