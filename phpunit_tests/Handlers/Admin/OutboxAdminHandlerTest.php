<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers\Admin;

use LiturgicalCalendar\Api\Handlers\Admin\OutboxAdminHandler;
use LiturgicalCalendar\Api\Repositories\OutboxRepository;
use LiturgicalCalendar\Api\Services\Outbox\OutboxOperation;
use LiturgicalCalendar\Api\Services\Outbox\OutboxStatus;
use LiturgicalCalendar\Tests\Handlers\AbstractHandlerTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Integration tests for OutboxAdminHandler.
 *
 * Exercises the two sub-routes:
 *   GET  /admin/outbox?status=...&summary=...&limit=...&offset=...
 *   POST /admin/outbox/{id}/retry
 *
 * All four tests require Postgres (openfga_outbox table).
 * Auth is satisfied by attaching an oidc_user attribute directly on the
 * PSR-7 request (same pattern as PermissionAdminHandlerTest / AbstractHandlerTestCase).
 */
#[CoversClass(OutboxAdminHandler::class)]
final class OutboxAdminHandlerTest extends AbstractHandlerTestCase
{
    protected static bool $requiresDatabase = true;

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Insert a single outbox row directly via SQL and return its ID.
     * Bypasses OutboxRepository::insertBatch (which enforces idempotency_key
     * uniqueness) so tests can seed arbitrary statuses quickly.
     */
    private function insertOutboxRow(
        string $status = 'pending',
        string $operation = 'write_tuple',
        ?string $lastError = null,
        ?string $accessRequestId = null
    ): int {
        $metadata = ['admin_user' => 'user:test-admin'];
        if ($accessRequestId !== null) {
            $metadata['access_request_id'] = $accessRequestId;
        }

        $stmt = self::$pdo->prepare(<<<'SQL'
            INSERT INTO openfga_outbox
                (operation, fga_user, fga_relation, fga_object, status, last_error, metadata)
            VALUES
                (:operation, 'user:alice', 'editor', 'national_calendar:IT', :status::outbox_status, :last_error, :metadata::jsonb)
            RETURNING id
        SQL);
        $stmt->execute([
            ':operation'  => $operation,
            ':status'     => $status,
            ':last_error' => $lastError,
            ':metadata'   => json_encode($metadata, JSON_THROW_ON_ERROR),
        ]);
        return (int) $stmt->fetchColumn();
    }

    private function makeHandler(): OutboxAdminHandler
    {
        return new OutboxAdminHandler(new OutboxRepository(self::$pdo));
    }

    // -----------------------------------------------------------------------
    // Test 1: GET list with status filter
    // -----------------------------------------------------------------------

    public function testGetWithStatusFilterReturnsMatchingRows(): void
    {
        // Seed rows in different statuses
        $this->insertOutboxRow('pending');
        $this->insertOutboxRow('pending');
        $this->insertOutboxRow('retrying');
        $failedId1 = $this->insertOutboxRow('failed_terminal', 'write_tuple', 'FGA error', null);
        $failedId2 = $this->insertOutboxRow('failed_terminal', 'delete_tuple', 'timeout', null);
        $this->insertOutboxRow('succeeded');

        $response = $this->makeHandler()->handle(
            $this->withOidcUser(
                $this->requestFor('GET', '/admin/outbox?status=failed_terminal')
            )
        );

        self::assertSame(200, $response->getStatusCode());
        $body = $this->decodeJsonBody($response);

        // Envelope shape
        self::assertArrayHasKey('items', $body);
        self::assertArrayHasKey('total', $body);
        self::assertArrayHasKey('count', $body);
        self::assertArrayHasKey('limit', $body);
        self::assertArrayHasKey('offset', $body);
        self::assertArrayHasKey('has_more', $body);

        // Only failed_terminal rows come back
        self::assertSame(2, $body['total']);
        self::assertSame(2, $body['count']);
        self::assertFalse($body['has_more']);

        self::assertIsArray($body['items']);
        $returnedIds = array_column($body['items'], 'id');
        sort($returnedIds);
        self::assertContains($failedId1, $returnedIds);
        self::assertContains($failedId2, $returnedIds);

        // Each row has the expected fields
        $firstItem = $body['items'][0];
        self::assertArrayHasKey('id', $firstItem);
        self::assertArrayHasKey('operation', $firstItem);
        self::assertArrayHasKey('fga_user', $firstItem);
        self::assertArrayHasKey('fga_relation', $firstItem);
        self::assertArrayHasKey('fga_object', $firstItem);
        self::assertArrayHasKey('status', $firstItem);
        self::assertArrayHasKey('attempts', $firstItem);
        self::assertArrayHasKey('last_error', $firstItem);
        self::assertArrayHasKey('metadata', $firstItem);
        self::assertArrayHasKey('created_at', $firstItem);

        // All returned rows must be in failed_terminal status
        foreach ($body['items'] as $item) {
            self::assertSame('failed_terminal', $item['status']);
        }
    }

    // -----------------------------------------------------------------------
    // Test 2: GET summary returns counts per status
    // -----------------------------------------------------------------------

    public function testGetSummaryReturnsCountsPerStatus(): void
    {
        // Seed: 2 pending, 1 retrying, 3 succeeded, 1 failed_terminal
        $this->insertOutboxRow('pending');
        $this->insertOutboxRow('pending');
        $this->insertOutboxRow('retrying');
        $this->insertOutboxRow('succeeded');
        $this->insertOutboxRow('succeeded');
        $this->insertOutboxRow('succeeded');
        $this->insertOutboxRow('failed_terminal', 'write_tuple', 'error');

        $response = $this->makeHandler()->handle(
            $this->withOidcUser(
                $this->requestFor('GET', '/admin/outbox?summary=1')
            )
        );

        self::assertSame(200, $response->getStatusCode());
        $body = $this->decodeJsonBody($response);

        self::assertArrayHasKey('counts', $body);
        self::assertIsArray($body['counts']);

        $counts = $body['counts'];
        self::assertArrayHasKey('pending', $counts);
        self::assertArrayHasKey('retrying', $counts);
        self::assertArrayHasKey('succeeded', $counts);
        self::assertArrayHasKey('failed_terminal', $counts);

        self::assertSame(2, $counts['pending']);
        self::assertSame(1, $counts['retrying']);
        self::assertSame(3, $counts['succeeded']);
        self::assertSame(1, $counts['failed_terminal']);

        // oldest_pending_age_seconds must be present and >= 0
        self::assertArrayHasKey('oldest_pending_age_seconds', $body);
        self::assertIsInt($body['oldest_pending_age_seconds']);
        self::assertGreaterThanOrEqual(0, $body['oldest_pending_age_seconds']);
    }

    // -----------------------------------------------------------------------
    // Test 3: POST retry resets failed_terminal to pending
    // -----------------------------------------------------------------------

    public function testPostRetryResetsFailedTerminalToPending(): void
    {
        $id = $this->insertOutboxRow('failed_terminal', 'write_tuple', 'FGA unreachable');

        $response = $this->makeHandler()->handle(
            $this->withOidcUser(
                $this->requestFor('POST', "/admin/outbox/{$id}/retry")
            )
        );

        self::assertSame(200, $response->getStatusCode());
        $body = $this->decodeJsonBody($response);
        self::assertArrayHasKey('success', $body);
        self::assertTrue($body['success']);

        // Verify via repository that the row is back to pending with reset fields
        $repo = new OutboxRepository(self::$pdo);
        $row  = $repo->getById($id);
        self::assertNotNull($row);
        self::assertSame(OutboxStatus::PENDING, $row->status);
        self::assertSame(0, $row->attempts);
        self::assertNull($row->lastError);
    }

    // -----------------------------------------------------------------------
    // Test 4: POST retry returns 409 for non-terminal row
    // -----------------------------------------------------------------------

    public function testPostRetryReturns409ForNonTerminalRow(): void
    {
        $id = $this->insertOutboxRow('pending');

        $response = $this->makeHandler()->handle(
            $this->withOidcUser(
                $this->requestFor('POST', "/admin/outbox/{$id}/retry")
            )
        );

        self::assertSame(409, $response->getStatusCode());
        $body = $this->decodeJsonBody($response);
        self::assertArrayHasKey('error', $body);
        self::assertStringContainsStringIgnoringCase('failed_terminal', (string) $body['error']);
    }

    // -----------------------------------------------------------------------
    // Test 5: POST retry returns 404 for missing row
    // -----------------------------------------------------------------------

    public function testPostRetryReturns404ForMissingRow(): void
    {
        $response = $this->makeHandler()->handle(
            $this->withOidcUser(
                $this->requestFor('POST', '/admin/outbox/999999/retry')
            )
        );

        self::assertSame(404, $response->getStatusCode());
        $body = $this->decodeJsonBody($response);
        self::assertArrayHasKey('error', $body);
        self::assertStringContainsStringIgnoringCase('not found', (string) $body['error']);
    }
}
