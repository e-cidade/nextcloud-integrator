<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use ECidade\Nextcloud\Application\DocumentCoordinator;
use ECidade\Nextcloud\Application\DocumentReconciler;
use ECidade\Nextcloud\Contract\DocumentLedger;
use ECidade\Nextcloud\Contract\RemoteDocuments;
use ECidade\Nextcloud\Domain\OperationKey;
use ECidade\Nextcloud\Domain\Scope;
use PHPUnit\Framework\TestCase;

/** Fakes are exclusively synthetic; no Nextcloud or PostgreSQL is contacted. */
final class FakeMvpDocumentLedger implements DocumentLedger
{
    public array $documents = [];
    public array $ops = [];
    public bool $transaction = false;
    private array $snapshot = [];
    private int $nextId = 3000000000;

    public function begin(): void
    {
        if ($this->transaction) {
            throw new RuntimeException('Already in a transaction');
        }
        $this->snapshot = [$this->documents, $this->ops];
        $this->transaction = true;
    }

    public function commit(): void
    {
        $this->needTransaction();
        $this->transaction = false;
    }

    public function rollback(): void
    {
        $this->needTransaction();
        [$this->documents,$this->ops] = $this->snapshot;
        $this->transaction = false;
    }

    public function assertBusinessTransaction()
    {
        $this->needTransaction();
    }

    private function needTransaction(): void
    {
        if (!$this->transaction) {
            throw new RuntimeException('Business transaction required');
        }
    }

    private function document(Scope $scope, $id): array
    {
        if (!isset($this->documents[$id]) || !$this->documents[$id]['scope']->equals($scope)) {
            throw new RuntimeException('Document not found');
        }
        return $this->documents[$id];
    }

    public function reserve(Scope $scope, $businessKey)
    {
        $this->needTransaction();
        $id = $this->nextId++;
        $key = OperationKey::generate();
        $this->documents[$id] = [
            'scope' => $scope,'document_key' => $key,'business_key' => $businessKey,
            'current_revision' => 0,'pending_revision' => null,'deleted_at' => null
        ];
        return ['legacy_id' => $id,'document_key' => $key];
    }

    public function find(Scope $scope, $legacyId)
    {
        return $this->document($scope, $legacyId);
    }

    public function recordStaged(Scope $scope, $legacyId, $operationKey, $checksum, $size, $remoteId)
    {
        $this->needTransaction();
        $doc = $this->document($scope, $legacyId);
        if ($doc['pending_revision'] !== null || $doc['deleted_at'] !== null) {
            throw new RuntimeException('Cannot stage another revision');
        }
        $rev = $doc['current_revision'] + 1;
        $this->documents[$legacyId]['pending_revision'] = $rev;
        $this->ops[$operationKey] = [
            'id' => $operationKey,'scope' => $scope,'operation_key' => $operationKey,
            'legacy_id' => $legacyId,'document_key' => $doc['document_key'],
            'checksum' => $checksum,'byte_count' => $size,'remote_file_id' => $remoteId,
            'revision' => $rev,'status' => 'staged','operation_type' => 'write'
        ];
        return ['operation_key' => $operationKey,'revision' => $rev,'state' => 'staged',
            'document_key' => $doc['document_key']];
    }

    public function tombstone(Scope $scope, $legacyId)
    {
        $this->needTransaction();
        $doc = $this->document($scope, $legacyId);
        if ($doc['pending_revision'] !== null) {
            throw new RuntimeException('Pending write');
        }
        $this->documents[$legacyId]['deleted_at'] = 'synthetic-tombstone';
        return true;
    }

    public function claimNext(Scope $scope)
    {
        if ($this->transaction) {
            return null;
        } // Fake emulates uncommitted-row invisibility.
        foreach ($this->ops as $id => $op) {
            if ($op['status'] === 'staged' && $op['scope']->equals($scope)) {
                $this->ops[$id]['status'] = 'processing';
                return $this->ops[$id];
            }
        }
        return null;
    }

    public function finish(Scope $scope, $operationId)
    {
        $op = $this->ops[$operationId];
        if (!$op['scope']->equals($scope)) {
            throw new RuntimeException('Reconciliation scope mismatch');
        }
        if ($op['status'] !== 'processing') {
            throw new RuntimeException('Not claimed');
        }
        $this->documents[$op['legacy_id']]['current_revision'] = $op['revision'];
        $this->documents[$op['legacy_id']]['pending_revision'] = null;
        $this->ops[$operationId]['status'] = 'finalized';
        return true;
    }

    public function retry(Scope $scope, $operationId, $errorCode)
    {
        if (!$this->ops[$operationId]['scope']->equals($scope)) {
            throw new RuntimeException('Reconciliation scope mismatch');
        }
        $this->ops[$operationId]['status'] = 'staged';
        $this->ops[$operationId]['error_code'] = $errorCode;
    }

    public function operationExists(Scope $scope, $operationKey)
    {
        return isset($this->ops[$operationKey]) && $this->ops[$operationKey]['scope']->equals($scope);
    }

    public function latestCommittedVersion(Scope $scope, $legacyId)
    {
        $doc = $this->document($scope, $legacyId);
        if ($doc['deleted_at'] !== null || $doc['current_revision'] < 1) {
            throw new RuntimeException('Document not published');
        }
        foreach ($this->ops as $op) {
            if ($op['legacy_id'] === $legacyId && $op['status'] === 'finalized'
                && $op['revision'] === $doc['current_revision']) {
                return $op;
            }
        }
        throw new RuntimeException('Missing committed version');
    }
}

final class FakeMvpRemoteDocuments implements RemoteDocuments
{
    public array $versions = [];
    public bool $failNextFinalize = false;
    public bool $corruptFinalizedMetadata = false;
    public bool $corruptDownloadedBytes = false;

    private function path(Scope $scope, $documentKey, $operationKey): string
    {
        return $scope->key() . ':' . $documentKey . ':' . $operationKey;
    }

    public function stage(Scope $scope, $documentKey, $operationKey, $bytes)
    {
        $path = $this->path($scope, $documentKey, $operationKey);
        if (isset($this->versions[$path])) {
            if ($this->versions[$path]['bytes'] !== $bytes) {
                throw new RuntimeException('Idempotency mismatch');
            }
            return $this->details($path);
        }
        $this->versions[$path] = [
            'scope' => $scope,'document_key' => $documentKey,'operation_key' => $operationKey,
            'id' => count($this->versions) + 101,'bytes' => $bytes,'state' => 'staged',
            'created_at' => time() - 90000
        ];
        return $this->details($path);
    }

    private function details($path): array
    {
        $version = $this->versions[$path];
        return [
            'id' => $version['id'],'state' => $version['state'],
            'size' => strlen($version['bytes']),'checksum' => hash('sha256', $version['bytes'])
        ];
    }

    public function finalize(Scope $scope, $documentKey, $operationKey)
    {
        if ($this->failNextFinalize) {
            $this->failNextFinalize = false;
            throw new RuntimeException('Synthetic network outage');
        }
        $path = $this->path($scope, $documentKey, $operationKey);
        if (!isset($this->versions[$path])) {
            throw new RuntimeException('Version missing');
        }
        $this->versions[$path]['state'] = 'committed';
        $info = $this->details($path);
        if ($this->corruptFinalizedMetadata) {
            $info['checksum'] = str_repeat('f', 64);
        }
        return $info;
    }

    public function download(Scope $scope, $documentKey, $operationKey)
    {
        $path = $this->path($scope, $documentKey, $operationKey);
        if (!isset($this->versions[$path]) || $this->versions[$path]['state'] !== 'committed') {
            throw new RuntimeException('Document version is not published');
        }
        return $this->corruptDownloadedBytes
            ? $this->versions[$path]['bytes'] . 'tampered'
            : $this->versions[$path]['bytes'];
    }

    public function abort(Scope $scope, $documentKey, $operationKey)
    {
        $path = $this->path($scope, $documentKey, $operationKey);
        if (!isset($this->versions[$path])) {
            return true;
        }
        if ($this->versions[$path]['state'] === 'committed') {
            throw new RuntimeException('Committed versions cannot be aborted');
        }
        unset($this->versions[$path]);
        return true;
    }

    public function listStaged(Scope $scope, $beforeUnixTimestamp, $limit)
    {
        $result = [];
        foreach ($this->versions as $version) {
            if ($version['scope']->equals($scope) && $version['state'] === 'staged'
                && $version['created_at'] < $beforeUnixTimestamp) {
                $result[] = ['operation_key' => $version['operation_key'],
                    'document_key' => $version['document_key']];
                if (count($result) >= $limit) {
                    break;
                }
            }
        }
        return $result;
    }
}

final class MvpDocumentCoordinatorTest extends TestCase
{
    private Scope $scope;
    private FakeMvpDocumentLedger $ledger;
    private FakeMvpRemoteDocuments $remote;
    private DocumentCoordinator $coordinator;
    private DocumentReconciler $reconciler;

    protected function setUp(): void
    {
        $this->scope = new Scope('installation-a', 'municipio-a');
        $this->ledger = new FakeMvpDocumentLedger();
        $this->remote = new FakeMvpRemoteDocuments();
        $this->coordinator = new DocumentCoordinator($this->ledger, $this->remote);
        $this->reconciler = new DocumentReconciler($this->ledger, $this->remote, $this->scope);
    }

    private function reserveAndStage(string $bytes): int
    {
        $this->ledger->begin();
        $ref = $this->coordinator->reserve($this->scope, 'synthetic-case');
        $this->coordinator->write($this->scope, $ref['legacy_id'], $bytes);
        $this->ledger->commit();
        return $ref['legacy_id'];
    }

    public function testMustUseBusinessTransaction(): void
    {
        $this->expectException(RuntimeException::class);
        $this->coordinator->reserve($this->scope, 'synthetic-case');
    }

    public function testWriteOutsideBusinessTransactionCannotCreateRemoteOrphan(): void
    {
        $this->ledger->begin();
        $ref = $this->coordinator->reserve($this->scope, 'synthetic');
        $this->ledger->commit();

        try {
            $this->coordinator->write($this->scope, $ref['legacy_id'], 'bytes');
            self::fail('Writing without SQL transaction must be refused');
        } catch (RuntimeException $expected) {
            self::assertStringContainsString('transaction', $expected->getMessage());
        }
        self::assertCount(0, $this->remote->versions);
    }

    public function testOrphanCollectorPreservesSqlJournaledPendingVersion(): void
    {
        $id = $this->reserveAndStage('pending');
        self::assertSame(0, $this->reconciler->discardOrphans(
            $this->scope,
            time() - 86400,
            100
        ));
        self::assertCount(1, $this->remote->versions);
        self::assertTrue($this->reconciler->runOnce());
        self::assertSame('pending', $this->coordinator->read($this->scope, $id));
    }

    public function testDocumentIsInvisibleUntilSqlCommitAndReconciliation(): void
    {
        $this->ledger->begin();
        $doc = $this->coordinator->reserve($this->scope, 'synthetic-case');
        $op = $this->coordinator->write($this->scope, $doc['legacy_id'], 'bytes-v1');
        self::assertSame('staged', $op['state']);
        self::assertFalse($this->reconciler->runOnce());
        $this->ledger->commit();
        try {
            $this->coordinator->read($this->scope, $doc['legacy_id']);
            self::fail('Staged bytes were visible before reconciliation');
        } catch (RuntimeException $expected) {
            self::assertStringContainsString('not published', $expected->getMessage());
        }
        self::assertTrue($this->reconciler->runOnce());
        self::assertSame('bytes-v1', $this->coordinator->read($this->scope, $doc['legacy_id']));
        self::assertFalse($this->reconciler->runOnce());
    }

    public function testWorkerCannotClaimDifferentMunicipalityOperations(): void
    {
        $otherScope = new Scope('installation-b', 'municipio-b');
        $this->ledger->begin();
        $otherRef = $this->coordinator->reserve($otherScope, 'synthetic-other');
        $this->coordinator->write($otherScope, $otherRef['legacy_id'], 'other-only');
        $this->ledger->commit();

        self::assertFalse($this->reconciler->runOnce());
        self::assertSame('staged', array_values($this->ledger->ops)[0]['status']);
        $otherWorker = new DocumentReconciler($this->ledger, $this->remote, $otherScope);
        self::assertTrue($otherWorker->runOnce());
        self::assertSame('other-only', $this->coordinator->read(
            $otherScope,
            $otherRef['legacy_id']
        ));
    }

    public function testWorkerRejectsCrossTenantOrphanCleanup(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('different scope');
        $this->reconciler->discardOrphans(new Scope(
            'installation-b',
            'municipio-b'
        ), time() - 86400, 1);
    }

    public function testSqlRollbackLeavesOnlyStagedOrphanWhichCanBeDiscarded(): void
    {
        $this->ledger->begin();
        $ref = $this->coordinator->reserve($this->scope, 'synthetic');
        $this->coordinator->write($this->scope, $ref['legacy_id'], 'uncommitted');
        $this->ledger->rollback();
        self::assertCount(0, $this->ledger->documents);
        self::assertCount(1, $this->remote->versions);
        self::assertSame(1, $this->reconciler->discardOrphans(
            $this->scope,
            time() - 86400,
            100
        ));
        self::assertCount(0, $this->remote->versions);
    }

    public function testTransientRemoteFailureRetainsOutboxAndEventuallySucceeds(): void
    {
        $id = $this->reserveAndStage('persistent');
        $this->remote->failNextFinalize = true;
        self::assertFalse($this->reconciler->runOnce());
        self::assertSame('REMOTE_UNAVAILABLE', array_values($this->ledger->ops)[0]['error_code']);
        self::assertTrue($this->reconciler->runOnce());
        self::assertSame('persistent', $this->coordinator->read($this->scope, $id));
    }

    public function testIntegrityFailureIsNeverPublished(): void
    {
        $id = $this->reserveAndStage('expected');
        $this->remote->corruptFinalizedMetadata = true;
        self::assertFalse($this->reconciler->runOnce());
        try {
            $this->coordinator->read($this->scope, $id);
            self::fail('Mismatched checksum was published');
        } catch (RuntimeException $expected) {
            self::assertStringContainsString('not published', $expected->getMessage());
        }
    }

    public function testDownloadChecksumMismatchNeverReturnsWrongBytes(): void
    {
        $id = $this->reserveAndStage('expected');
        self::assertTrue($this->reconciler->runOnce());
        $this->remote->corruptDownloadedBytes = true;
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('integrity failed');
        $this->coordinator->read($this->scope, $id);
    }

    public function testImmutableUpdatesRetainOldVersionsAndAdvanceRevision(): void
    {
        $id = $this->reserveAndStage('v1');
        self::assertTrue($this->reconciler->runOnce());
        $this->ledger->begin();
        $second = $this->coordinator->write($this->scope, $id, 'v2');
        $this->ledger->commit();
        self::assertSame(2, $second['revision']);
        // During an update the last committed version remains readable.
        self::assertSame('v1', $this->coordinator->read($this->scope, $id));
        self::assertTrue($this->reconciler->runOnce());
        self::assertSame('v2', $this->coordinator->read($this->scope, $id));
        self::assertCount(2, $this->remote->versions);
        foreach ($this->remote->versions as $v) {
            self::assertSame('committed', $v['state']);
        }
    }

    public function testCrossTenantReadFailsWithoutLeakingDocument(): void
    {
        $id = $this->reserveAndStage('secret-synthetic');
        self::assertTrue($this->reconciler->runOnce());
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Document not found');
        $this->coordinator->read(new Scope('installation-b', 'municipio-b'), $id);
    }

    public function testRollbackOfDeleteDoesNotDestroyCommittedBytes(): void
    {
        $id = $this->reserveAndStage('keep-me');
        $this->reconciler->runOnce();
        $this->ledger->begin();
        $this->coordinator->delete($this->scope, $id);
        $this->ledger->rollback();
        self::assertSame('keep-me', $this->coordinator->read($this->scope, $id));
        self::assertCount(1, $this->remote->versions);
    }

    public function testCommittedTombstoneBlocksReadButRetainsRemoteVersion(): void
    {
        $id = $this->reserveAndStage('retained');
        $this->reconciler->runOnce();
        $this->ledger->begin();
        self::assertTrue($this->coordinator->delete($this->scope, $id));
        $this->ledger->commit();
        try {
            $this->coordinator->read($this->scope, $id);
            self::fail('Deleted document should not be available');
        } catch (RuntimeException $expected) {
            self::assertStringContainsString('not published', $expected->getMessage());
        }
        self::assertCount(1, $this->remote->versions);
    }

    public function testOrphanCleanupIsConservativeAndCannotTouchCommittedVersions(): void
    {
        $id = $this->reserveAndStage('committed');
        $this->reconciler->runOnce();
        self::assertSame(0, $this->reconciler->discardOrphans($this->scope, time() - 86400));
        self::assertCount(1, $this->remote->versions);
    }

    public function testOrphanCleanupRequiresMinimumTwentyFourHourAge(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->reconciler->discardOrphans($this->scope, time() - 60);
    }

    public function testOperationKeysAreUnpredictableAndValidated(): void
    {
        $a = OperationKey::generate();
        $b = OperationKey::generate();
        self::assertNotSame($a, $b);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $a);
        self::assertSame($b, OperationKey::validate($b));
    }

    public function testInvalidInstallationNameIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Scope('../internal', 'municipio-a');
    }
}
