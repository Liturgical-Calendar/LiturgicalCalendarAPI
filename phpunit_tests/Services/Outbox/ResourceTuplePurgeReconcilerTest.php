<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services\Outbox;

use LiturgicalCalendar\Api\Services\OpenFgaClient;
use LiturgicalCalendar\Api\Services\ResourceExistenceCheckerInterface;
use LiturgicalCalendar\Api\Services\ResourceTuplePurgeServiceInterface;
use LiturgicalCalendar\Api\Services\Outbox\ResourceTuplePurgeReconciler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ResourceTuplePurgeReconciler::class)]
final class ResourceTuplePurgeReconcilerTest extends TestCase
{
    public function testPurgesOnlyDeletedResourcesWithOperationalTuplesIgnoringAdmin(): void
    {
        $client = $this->createStub(OpenFgaClient::class);
        $client->method('readTuples')->willReturn([
            'tuples'                  => [
                // deleted resource (ZZ) with operational tuple -> should purge
                ['user' => 'user:a', 'relation' => 'editor', 'object' => 'national_calendar:ZZ'],
                // deleted resource (ZZ) admin tuple -> must be ignored (no purge trigger by itself)
                ['user' => 'user:b', 'relation' => 'admin', 'object' => 'national_calendar:ZZ'],
                // GRC always exists -> never purge
                ['user' => 'user:c', 'relation' => 'editor', 'object' => 'general_roman_calendar:temporale'],
            ],
            'next_continuation_token' => '',
        ]);

        $checker = $this->createStub(ResourceExistenceCheckerInterface::class);
        $checker->method('isResourceType')->willReturn(true);
        $checker->method('exists')->willReturnCallback(
            fn (string $t, string $id): bool => !( $t === 'national_calendar' && $id === 'ZZ' )
        );

        $purge = $this->createMock(ResourceTuplePurgeServiceInterface::class);
        $purge->expects($this->once())
            ->method('purgeForObject')
            ->with('national_calendar:ZZ')
            ->willReturn(1);

        $reconciler = new ResourceTuplePurgeReconciler($client, $checker, $purge);
        $result     = $reconciler->sweep();

        $this->assertSame(1, $result['purgedObjects']);
    }

    public function testNoOperationalTuplesYieldsZeroPurgedObjects(): void
    {
        $client = $this->createStub(OpenFgaClient::class);
        $client->method('readTuples')->willReturn([
            'tuples'                  => [
                // admin-only tuple on a deleted resource -> must NOT trigger purge
                ['user' => 'user:b', 'relation' => 'admin', 'object' => 'national_calendar:ZZ'],
            ],
            'next_continuation_token' => '',
        ]);

        $checker = $this->createStub(ResourceExistenceCheckerInterface::class);
        $checker->method('isResourceType')->willReturn(true);
        $checker->method('exists')->willReturn(false); // simulate deleted resource

        $purge = $this->createMock(ResourceTuplePurgeServiceInterface::class);
        $purge->expects($this->never())->method('purgeForObject');

        $reconciler = new ResourceTuplePurgeReconciler($client, $checker, $purge);
        $result     = $reconciler->sweep();

        $this->assertSame(0, $result['purgedObjects']);
        $this->assertSame(0, $result['enqueued']);
        $this->assertSame(1, $result['scanned']);
    }

    public function testNonResourceTypeObjectsAreSkipped(): void
    {
        $client = $this->createStub(OpenFgaClient::class);
        $client->method('readTuples')->willReturn([
            'tuples'                  => [
                ['user' => 'user:a', 'relation' => 'editor', 'object' => 'user:someuser'],
            ],
            'next_continuation_token' => '',
        ]);

        $checker = $this->createMock(ResourceExistenceCheckerInterface::class);
        $checker->method('isResourceType')->willReturn(false); // 'user' is not a resource type
        $checker->expects($this->never())->method('exists');

        $purge = $this->createMock(ResourceTuplePurgeServiceInterface::class);
        $purge->expects($this->never())->method('purgeForObject');

        $reconciler = new ResourceTuplePurgeReconciler($client, $checker, $purge);
        $result     = $reconciler->sweep();

        $this->assertSame(0, $result['purgedObjects']);
    }

    public function testSummaryCountsAreAccurate(): void
    {
        $client = $this->createStub(OpenFgaClient::class);
        $client->method('readTuples')->willReturn([
            'tuples'                  => [
                ['user' => 'user:a', 'relation' => 'editor', 'object' => 'national_calendar:ZZ'],
                ['user' => 'user:b', 'relation' => 'viewer', 'object' => 'national_calendar:ZZ'],
                ['user' => 'user:c', 'relation' => 'editor', 'object' => 'national_calendar:IT'],
            ],
            'next_continuation_token' => '',
        ]);

        $checker = $this->createStub(ResourceExistenceCheckerInterface::class);
        $checker->method('isResourceType')->willReturn(true);
        $checker->method('exists')->willReturnCallback(
            fn (string $t, string $id): bool => !( $t === 'national_calendar' && $id === 'ZZ' )
        );

        $purge = $this->createMock(ResourceTuplePurgeServiceInterface::class);
        $purge->expects($this->once())
            ->method('purgeForObject')
            ->with('national_calendar:ZZ')
            ->willReturn(2);

        $reconciler = new ResourceTuplePurgeReconciler($client, $checker, $purge);
        $result     = $reconciler->sweep();

        $this->assertSame(3, $result['scanned']);
        $this->assertSame(1, $result['purgedObjects']);
        $this->assertSame(2, $result['enqueued']);
    }
}
