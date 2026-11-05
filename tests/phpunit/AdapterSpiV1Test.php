<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

require_once __DIR__ . '/MvpDocumentCoordinatorTest.php';

use ECidade\Nextcloud\Application\AdapterServiceV1;
use ECidade\Nextcloud\Application\DocumentCoordinator;
use ECidade\Nextcloud\Application\DocumentReconciler;
use ECidade\Nextcloud\Contract\DocumentAccessPolicy;
use ECidade\Nextcloud\Domain\AdapterContext;
use ECidade\Nextcloud\Domain\AdapterFailure;
use ECidade\Nextcloud\Domain\OperationKey;
use ECidade\Nextcloud\Domain\Scope;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SyntheticBoundPolicy implements DocumentAccessPolicy
{
    public array $permissions = ['reserve', 'create', 'read', 'update', 'delete', 'inspect'];

    public function allows(AdapterContext $context, $operation, $legacyId)
    {
        $attrs = $context->attributes();
        return $context->scope()->equals(new Scope('installation-a', 'municipio-a'))
            && $context->actor() === 'actor-allowed'
            && in_array($operation, $this->permissions, true)
            && (!isset($attrs['revoked']) || $attrs['revoked'] !== true);
    }
}

/** No municipal deployments, SQL server or network connections. */
final class AdapterSpiV1Test extends TestCase
{
    private FakeMvpDocumentLedger $ledger;
    private FakeMvpRemoteDocuments $remote;
    private SyntheticBoundPolicy $policy;
    private AdapterServiceV1 $spi;
    private AdapterContext $context;

    public static function forks(): array
    {
        return [['dbseller'], ['contass'], ['cpd']];
    }

    public static function invalidSurfaces(): array
    {
        return [
            ['cache', 'UNSUPPORTED'],
            ['temporary', 'UNSUPPORTED'],
            ['export', 'UNSUPPORTED'],
            ['unmapped', 'UNSUPPORTED'],
        ];
    }

    private static function manifest(string $id): array
    {
        return [
            'id' => $id,
            'production_ready' => false,
            'storage_capabilities' => [
                [
                    'surface' => 'large-object-facade',
                    'usage' => 'persistent-document',
                    'spi' => 1,
                    'operations' => ['reserve', 'create', 'read', 'update', 'delete', 'inspect'],
                ],
                [
                    'surface' => 'e-storage',
                    'usage' => 'persistent-document',
                    'spi' => 1,
                    'operations' => ['reserve', 'create', 'read', 'update', 'delete', 'inspect'],
                ],
                ['surface' => 'cache', 'usage' => 'cache', 'spi' => 1, 'operations' => ['read']],
                ['surface' => 'temporary', 'usage' => 'temporary', 'spi' => 1, 'operations' => ['create']],
                ['surface' => 'export', 'usage' => 'export', 'spi' => 1, 'operations' => ['read']],
            ],
        ];
    }

    protected function setUp(): void
    {
        $this->ledger = new FakeMvpDocumentLedger();
        $this->remote = new FakeMvpRemoteDocuments();
        $this->policy = new SyntheticBoundPolicy();
        $this->spi = new AdapterServiceV1(
            new DocumentCoordinator($this->ledger, $this->remote),
            $this->ledger,
            $this->policy,
            self::manifest('dbseller')
        );
        $this->context = $this->contextFor('dbseller');
    }

    private function contextFor(string $backend, string $surface = 'large-object-facade', string $actor = 'actor-allowed', array $attributes = []): AdapterContext
    {
        return new AdapterContext(new Scope('installation-a', 'municipio-a'), $backend, $surface, $actor, $attributes);
    }

    #[DataProvider('forks')]
    public function testOneSpiLifecycleForEveryFork(string $fork): void
    {
        $spi = new AdapterServiceV1(
            new DocumentCoordinator($this->ledger, $this->remote),
            $this->ledger,
            $this->policy,
            self::manifest($fork)
        );
        $context = $this->contextFor($fork);
        $key1 = OperationKey::generate();
        $key2 = OperationKey::generate();
        $this->ledger->begin();
        $reference = $spi->reserve($context, 'municipal-test-1');
        $duplicate = $spi->reserve($context, 'municipal-test-1');
        self::assertSame($reference['legacy_id'], $duplicate['legacy_id']);
        $created = $spi->create($context, $reference['legacy_id'], 'synthetic-v1', $key1);
        self::assertSame('staged', $created['state']);
        self::assertSame('synthetic-v1', $spi->read($context, $reference['legacy_id']));
        self::assertSame($created, $spi->create($context, $reference['legacy_id'], 'synthetic-v1', $key1));
        $this->ledger->commit();
        self::assertSame('synthetic-v1', $spi->read($context, $reference['legacy_id']));
        $worker = new DocumentReconciler($this->ledger, $this->remote, $context->scope());
        self::assertTrue($worker->runOnce());
        $this->ledger->begin();
        $updated = $spi->update($context, $reference['legacy_id'], 'synthetic-v2', $key2);
        self::assertSame(2, $updated['revision']);
        self::assertSame('synthetic-v2', $spi->read($context, $reference['legacy_id']));
        $this->ledger->rollback();
        self::assertSame('synthetic-v1', $spi->read($context, $reference['legacy_id']));
        $this->ledger->begin();
        self::assertTrue($spi->delete($context, $reference['legacy_id']));
        $this->ledger->commit();
        self::assertTrue($spi->inspect($context, $reference['legacy_id'])['deleted']);
        self::assertCount(2, $this->remote->versions); // no SQL document bytes
    }

    #[DataProvider('invalidSurfaces')]
    public function testNonPersistentSurfacesAreNotRedirected(string $surface, string $reason): void
    {
        $this->expectException(AdapterFailure::class);
        $this->expectExceptionMessage($reason);
        $this->spi->read($this->contextFor('dbseller', $surface), 3000000000);
    }

    public function testUndeclaredCapabilityIsFailClosed(): void
    {
        $this->expectException(AdapterFailure::class);
        $this->expectExceptionMessage('UNSUPPORTED');
        $this->spi->reserve($this->contextFor('dbseller', 'bytea'), 'legacy');
    }

    public function testBackendBindingRejectsHistoricalOrOtherSurface(): void
    {
        $this->ledger->begin();
        $ref = $this->ledger->reserve($this->context->scope(), 'historical-reference');
        $this->expectException(AdapterFailure::class);
        $this->expectExceptionMessage('NOT_FOUND');
        $this->spi->read($this->context, $ref['legacy_id']);
    }

    public function testCallerCannotSwapBackend(): void
    {
        $this->expectException(AdapterFailure::class);
        $this->expectExceptionMessage('DENIED');
        $this->spi->reserve($this->contextFor('contass'), 'synthetic');
    }

    public function testAuthorizationRejectsRevokedActor(): void
    {
        $this->expectException(AdapterFailure::class);
        $this->expectExceptionMessage('DENIED');
        $this->spi->reserve($this->contextFor('dbseller', 'large-object-facade', 'actor-allowed', ['revoked' => true]), 'synthetic');
    }

    public function testUnmappedEstorageAclAndParentAreDenied(): void
    {
        $this->expectException(AdapterFailure::class);
        $this->expectExceptionMessage('DENIED');
        $this->spi->reserve($this->contextFor('dbseller', 'e-storage'), 'synthetic');
    }

    public function testMappedEstorageStillRequiresTrustedPolicy(): void
    {
        $context = $this->contextFor('dbseller', 'e-storage', 'actor-forbidden', [
            'allowed' => ['actor-forbidden'], 'file_father' => 'parent-123',
        ]);
        $this->expectException(AdapterFailure::class);
        $this->expectExceptionMessage('DENIED');
        $this->spi->reserve($context, 'synthetic');
    }

    public function testMissingBusinessTransactionFailsBeforeRemoteIo(): void
    {
        $this->expectException(AdapterFailure::class);
        $this->expectExceptionMessage('TRANSACTION_REQUIRED');
        $this->spi->reserve($this->context, 'synthetic');
    }

    public function testIdempotencyConflictDoesNotOverwriteBytes(): void
    {
        $this->ledger->begin();
        $ref = $this->spi->reserve($this->context, 'synthetic');
        $key = OperationKey::generate();
        $this->spi->create($this->context, $ref['legacy_id'], 'original', $key);
        try {
            $this->spi->create($this->context, $ref['legacy_id'], 'different', $key);
            self::fail('Different bytes with same key must fail');
        } catch (AdapterFailure $error) {
            self::assertSame(AdapterFailure::CONFLICT, $error->reason());
        }
        self::assertSame('original', $this->spi->read($this->context, $ref['legacy_id']));
        self::assertCount(1, $this->remote->versions);
    }

    public function testDifferentPrincipalIsForbidden(): void
    {
        $this->expectException(AdapterFailure::class);
        $this->expectExceptionMessage('DENIED');
        $this->spi->reserve($this->contextFor('dbseller', 'large-object-facade', 'other-actor'), 'synthetic');
    }
}
