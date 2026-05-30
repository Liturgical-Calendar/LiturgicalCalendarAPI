<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Repositories;

use LiturgicalCalendar\Api\Repositories\AccessRequestRepository;
use LiturgicalCalendar\Api\Repositories\UserNotificationRepository;
use LiturgicalCalendar\Tests\Handlers\AbstractHandlerTestCase;

final class UserNotificationRepositoryTest extends AbstractHandlerTestCase
{
    protected static bool $requiresDatabase = true;

    private UserNotificationRepository $repo;
    private AccessRequestRepository $accessReqRepo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo          = new UserNotificationRepository(self::$pdo);
        $this->accessReqRepo = new AccessRequestRepository(self::$pdo);
    }

    public function testFetchInboxReturnsEmptyShapeWhenUserHasNoRequests(): void
    {
        $result = $this->repo->fetchInbox('zitadel-user-empty');

        self::assertSame([], $result['items']);
        self::assertSame(0, $result['total']);
        self::assertSame(0, $result['unread_count']);
        self::assertSame('1970-01-01T00:00:00+00:00', $result['last_seen_at']);
    }

    public function testFetchInboxExcludesPendingRequests(): void
    {
        $this->accessReqRepo->create(
            'zitadel-user-a',
            'a@example.test',
            'User A',
            'calendar_editor',
            [['object_type' => 'national_calendar', 'object_id' => 'IT', 'relation' => 'editor']]
        );
        // Pending — no review yet.

        $result = $this->repo->fetchInbox('zitadel-user-a');

        self::assertSame([], $result['items']);
        self::assertSame(0, $result['total']);
        self::assertSame(0, $result['unread_count']);
    }

    public function testFetchInboxReturnsReviewedItemsAllUnreadWhenNoBookmark(): void
    {
        $id1 = $this->accessReqRepo->create(
            'zitadel-user-b',
            'b@example.test',
            'User B',
            'calendar_editor',
            [['object_type' => 'national_calendar', 'object_id' => 'IT', 'relation' => 'editor']]
        );
        $id2 = $this->accessReqRepo->create(
            'zitadel-user-b',
            'b@example.test',
            'User B',
            'developer',
            [['object_type' => 'test_definition', 'object_id' => 'foo', 'relation' => 'editor']]
        );
        $this->accessReqRepo->approve($id1, 'admin-x', 'welcome');
        $this->accessReqRepo->reject($id2, 'admin-x', 'denied');

        $result = $this->repo->fetchInbox('zitadel-user-b');

        self::assertCount(2, $result['items']);
        self::assertSame(2, $result['total']);
        self::assertSame(2, $result['unread_count']);
        foreach ($result['items'] as $item) {
            self::assertTrue($item['unread']);
            self::assertSame('access_request_reviewed', $item['type']);
            self::assertIsArray($item['permissions']);
        }
    }

    public function testFetchInboxIsolatesUsers(): void
    {
        $idA = $this->accessReqRepo->create(
            'zitadel-user-c',
            'c@example.test',
            'C',
            'calendar_editor',
            [['object_type' => 'national_calendar', 'object_id' => 'IT', 'relation' => 'editor']]
        );
        $idB = $this->accessReqRepo->create(
            'zitadel-user-d',
            'd@example.test',
            'D',
            'calendar_editor',
            [['object_type' => 'national_calendar', 'object_id' => 'FR', 'relation' => 'editor']]
        );
        $this->accessReqRepo->approve($idA, 'admin-x', null);
        $this->accessReqRepo->approve($idB, 'admin-x', null);

        $resultC = $this->repo->fetchInbox('zitadel-user-c');
        self::assertCount(1, $resultC['items']);
        self::assertSame(1, $resultC['total']);
    }

    public function testFetchInboxRespectsLimit(): void
    {
        for ($i = 0; $i < 55; $i++) {
            $id = $this->accessReqRepo->create(
                'zitadel-user-e',
                "e{$i}@example.test",
                "E{$i}",
                'developer',
                [['object_type' => 'test_definition', 'object_id' => "obj-{$i}", 'relation' => 'editor']]
            );
            $this->accessReqRepo->approve($id, 'admin-x', null);
        }

        $result = $this->repo->fetchInbox('zitadel-user-e', limit: 50);

        self::assertCount(50, $result['items']);
        self::assertSame(55, $result['total']);
        self::assertSame(55, $result['unread_count']);
    }

    public function testFetchInboxOrdersByReviewedAtDesc(): void
    {
        $id1 = $this->accessReqRepo->create(
            'zitadel-user-f',
            'f@example.test',
            'F',
            'calendar_editor',
            [['object_type' => 'national_calendar', 'object_id' => 'IT', 'relation' => 'editor']]
        );
        $this->accessReqRepo->approve($id1, 'admin-x', null);

        usleep(1_100_000);

        $id2 = $this->accessReqRepo->create(
            'zitadel-user-f',
            'f@example.test',
            'F',
            'developer',
            [['object_type' => 'test_definition', 'object_id' => 't', 'relation' => 'editor']]
        );
        $this->accessReqRepo->approve($id2, 'admin-x', null);

        $result = $this->repo->fetchInbox('zitadel-user-f');

        self::assertSame($id2, $result['items'][0]['request_id']);
        self::assertSame($id1, $result['items'][1]['request_id']);
    }

    public function testMarkSeenInsertsRowOnFirstCall(): void
    {
        $seenAt = $this->repo->markSeen('zitadel-user-g');

        self::assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/',
            $seenAt
        );

        $stmt = self::$pdo->prepare(
            'SELECT user_id FROM user_notification_state WHERE user_id = :u'
        );
        $stmt->execute(['u' => 'zitadel-user-g']);
        self::assertSame('zitadel-user-g', $stmt->fetchColumn());
    }

    public function testMarkSeenAdvancesOnSecondCall(): void
    {
        $first = $this->repo->markSeen('zitadel-user-h');
        usleep(1_100_000);
        $second = $this->repo->markSeen('zitadel-user-h');

        self::assertGreaterThan($first, $second);
    }

    public function testMarkSeenThenFetchInboxMarksItemsRead(): void
    {
        $id = $this->accessReqRepo->create(
            'zitadel-user-i',
            'i@example.test',
            'I',
            'calendar_editor',
            [['object_type' => 'national_calendar', 'object_id' => 'IT', 'relation' => 'editor']]
        );
        $this->accessReqRepo->approve($id, 'admin-x', null);

        usleep(1_100_000);
        $this->repo->markSeen('zitadel-user-i');

        $result = $this->repo->fetchInbox('zitadel-user-i');
        self::assertCount(1, $result['items']);
        self::assertFalse($result['items'][0]['unread']);
        self::assertSame(0, $result['unread_count']);
        self::assertSame(1, $result['total']);
    }

    public function testFetchInboxDecodesPermissionsJsonb(): void
    {
        $id = $this->accessReqRepo->create(
            'zitadel-user-j',
            'j@example.test',
            'J',
            'calendar_editor',
            [['object_type' => 'national_calendar', 'object_id' => 'IT', 'relation' => 'editor']]
        );
        $this->accessReqRepo->approve($id, 'admin-x', null);

        $result = $this->repo->fetchInbox('zitadel-user-j');

        // assertEqualsCanonicalizing rather than assertSame because Postgres
        // JSONB does not preserve key order — only the (de)normalized value
        // shape is guaranteed.
        self::assertEqualsCanonicalizing(
            [['object_type' => 'national_calendar', 'object_id' => 'IT', 'relation' => 'editor']],
            $result['items'][0]['permissions']
        );
    }

    public function testFetchInboxReturnsNullReviewNotes(): void
    {
        $id = $this->accessReqRepo->create(
            'zitadel-user-k',
            'k@example.test',
            'K',
            'calendar_editor',
            [['object_type' => 'national_calendar', 'object_id' => 'IT', 'relation' => 'editor']]
        );
        $this->accessReqRepo->approve($id, 'admin-x', null); // null notes

        $result = $this->repo->fetchInbox('zitadel-user-k');

        self::assertCount(1, $result['items']);
        self::assertNull($result['items'][0]['review_notes']);
    }

    public function testFetchInboxIncludesRevokedStatus(): void
    {
        // The full reviewed-status matrix is approved | rejected | revoked.
        // Verify all three appear in the inbox and are counted toward total
        // and unread_count. Uses three distinct roles to avoid the partial
        // unique index on (zitadel_user_id, requested_role) WHERE status =
        // 'pending'.
        $idApproved = $this->accessReqRepo->create(
            'zitadel-user-revoked',
            'r@example.test',
            'R',
            'calendar_editor',
            [['object_type' => 'national_calendar', 'object_id' => 'IT', 'relation' => 'editor']]
        );
        $idRejected = $this->accessReqRepo->create(
            'zitadel-user-revoked',
            'r@example.test',
            'R',
            'developer',
            [['object_type' => 'test_definition', 'object_id' => 'foo', 'relation' => 'editor']]
        );
        $idRevoked  = $this->accessReqRepo->create(
            'zitadel-user-revoked',
            'r@example.test',
            'R',
            'test_editor',
            [['object_type' => 'test_definition', 'object_id' => 'bar', 'relation' => 'editor']]
        );
        $this->accessReqRepo->approve($idApproved, 'admin-x', null);
        $this->accessReqRepo->reject($idRejected, 'admin-x', null);
        // revoke() requires the row to be approved first (its WHERE clause).
        $this->accessReqRepo->approve($idRevoked, 'admin-x', null);
        $this->accessReqRepo->revoke($idRevoked, 'admin-x', null);

        $result = $this->repo->fetchInbox('zitadel-user-revoked');

        self::assertSame(3, $result['total']);
        self::assertSame(3, $result['unread_count']);
        $statuses = array_column($result['items'], 'status');
        sort($statuses);
        self::assertSame(['approved', 'rejected', 'revoked'], $statuses);
    }
}
