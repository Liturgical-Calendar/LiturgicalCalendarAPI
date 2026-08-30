<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Repositories;

use LiturgicalCalendar\Api\Enum\ChangeOperation;
use LiturgicalCalendar\Api\Enum\ChangePublicationStatus;
use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Repositories\AccessRequestRepository;
use LiturgicalCalendar\Api\Repositories\SourceDataChangeRequestRepository;
use LiturgicalCalendar\Api\Repositories\UserNotificationRepository;
use LiturgicalCalendar\Api\Services\ChangeResource;
use LiturgicalCalendar\Tests\Handlers\AbstractHandlerTestCase;

final class UserNotificationRepositoryTest extends AbstractHandlerTestCase
{
    protected static bool $requiresDatabase = true;

    private UserNotificationRepository $repo;
    private AccessRequestRepository $accessReqRepo;
    private SourceDataChangeRequestRepository $changeReqRepo;

    protected function setUp(): void
    {
        parent::setUp();

        // AbstractHandlerTestCase::TABLES does not include sourcedata_change_requests
        // (only RepositoryTestCase::TABLES does — see SourceDataChangeRequestSupersedeRegressionTest's
        // setUp() for the same precedent), so truncate it here explicitly.
        self::$pdo?->exec('TRUNCATE TABLE sourcedata_change_requests RESTART IDENTITY CASCADE');

        $this->repo          = new UserNotificationRepository(self::$pdo);
        $this->accessReqRepo = new AccessRequestRepository(self::$pdo);
        $this->changeReqRepo = new SourceDataChangeRequestRepository(self::$pdo);
    }

    /**
     * Submit a real batch through SourceDataChangeRequestRepository, so that the review-decision
     * half of the inbox is exercised against rows `decideBatch()` actually wrote — the point being
     * that `review_decision` is populated by the production write path and not by the fixture.
     *
     * @param int $files How many paths the batch covers, to prove the inbox collapses to one item.
     */
    private function submittedBatch(string $sub, string $resourceId = 'US', int $files = 1): string
    {
        $paths = [];
        for ($i = 0; $i < $files; $i++) {
            $paths[] = [
                'path'      => "jsondata/sourcedata/rite/roman/calendars/nations/{$resourceId}/file{$i}.json",
                'operation' => ChangeOperation::CREATE,
                'content'   => '{"litcal":[]}',
            ];
        }

        return $this->changeReqRepo->submitBatch(
            ChangeResource::nationalCalendar(Rite::ROMAN, $resourceId),
            $paths,
            $sub,
            'Submitter',
            'submitter@example.test',
            true
        )['batch_id'];
    }

    /** Shift a decided batch's review cursor, so ordering and the seen bookmark are testable. */
    private function backdateDecision(string $batchId, string $interval): void
    {
        $stmt = self::$pdo->prepare(
            'UPDATE sourcedata_change_requests SET approved_at = NOW() + :offset::interval WHERE batch_id = :batch_id'
        );
        $stmt->execute(['offset' => $interval, 'batch_id' => $batchId]);
    }

    /**
     * Insert a settled (merged or closed) source-data change-request batch directly, bypassing
     * SourceDataChangeRequestRepository::submitBatch() — driving the whole publish path to produce
     * one inbox row would test the publisher, not the inbox.
     *
     * @param string $publicationStatus 'merged' or 'closed'.
     * @param string $offset            A Postgres interval literal relative to NOW(), e.g. '-1 hour'.
     */
    private function settledBatch(
        string $sub,
        string $publicationStatus,
        string $offset,
        int $files = 1,
        ?int $prNumber = 101
    ): string {
        /** @var string $batchId */
        $batchId = self::$pdo->query('SELECT gen_random_uuid()::text')->fetchColumn();

        $stmt = self::$pdo->prepare(
            <<<'SQL'
                INSERT INTO sourcedata_change_requests
                    (batch_id, resource_type, resource_id, path, operation, content,
                     submitted_by_sub, submitted_by_name, submitted_by_email, submitted_by_email_verified,
                     review_status, publication_status, pr_number, publication_settled_at)
                VALUES
                    (:batch_id, :resource_type, :resource_id, :path, 'create', :content,
                     :sub, :name, :email, TRUE,
                     'approved', :publication_status, :pr_number, NOW() + :offset::interval)
                SQL
        );

        for ($i = 0; $i < $files; $i++) {
            $stmt->execute([
                'batch_id'           => $batchId,
                'resource_type'      => 'national_calendar',
                'resource_id'        => 'roman/US',
                'path'               => "jsondata/sourcedata/rite/roman/calendars/nations/US/file{$i}.json",
                'content'            => '{"litcal":[]}',
                'sub'                => $sub,
                'name'               => 'Submitter',
                'email'              => 'submitter@example.test',
                'publication_status' => $publicationStatus,
                'pr_number'          => $prNumber,
                'offset'             => $offset,
            ]);
        }

        return $batchId;
    }

    /** Insert an OPEN batch (publication_settled_at left NULL) — not news yet. */
    private function openBatch(string $sub): string
    {
        /** @var string $batchId */
        $batchId = self::$pdo->query('SELECT gen_random_uuid()::text')->fetchColumn();

        $stmt = self::$pdo->prepare(
            <<<'SQL'
                INSERT INTO sourcedata_change_requests
                    (batch_id, resource_type, resource_id, path, operation, content,
                     submitted_by_sub, submitted_by_name, submitted_by_email, submitted_by_email_verified,
                     review_status, publication_status, pr_number, publication_settled_at)
                VALUES
                    (:batch_id, 'national_calendar', 'roman/US', :path, 'create', '{"litcal":[]}',
                     :sub, 'Submitter', 'submitter@example.test', TRUE,
                     'approved', 'open', 101, NULL)
                SQL
        );
        $stmt->execute([
            'batch_id' => $batchId,
            'path'     => 'jsondata/sourcedata/rite/roman/calendars/nations/US/US.json',
            'sub'      => $sub,
        ]);

        return $batchId;
    }

    /** Insert a reviewed access_requests row with reviewed_at offset from NOW() by a Postgres interval literal. */
    private function reviewedAccessRequest(string $sub, string $offset): string
    {
        $stmt = self::$pdo->prepare(
            <<<'SQL'
                INSERT INTO access_requests
                    (zitadel_user_id, user_email, user_name, requested_role, permissions,
                     status, reviewed_by, reviewed_at)
                VALUES
                    (:sub, :email, :name, :role, '[]',
                     'approved', 'admin-x', NOW() + :offset::interval)
                RETURNING id
                SQL
        );
        $stmt->execute([
            'sub'    => $sub,
            'email'  => $sub . '@example.test',
            'name'   => $sub,
            'role'   => 'calendar_editor',
            'offset' => $offset,
        ]);

        return (string) $stmt->fetchColumn();
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

    public function testFetchInboxClampsLimitAboveCap(): void
    {
        // Seed two items so the clamp's effect (cap at 50) is the only thing
        // that could constrain the result; with 2 items and a caller-requested
        // 999, we expect 2 returned (not an error, not the raw 999).
        $id1 = $this->accessReqRepo->create(
            'zitadel-user-clamp-hi',
            'ch@example.test',
            'CH',
            'calendar_editor',
            [['object_type' => 'national_calendar', 'object_id' => 'IT', 'relation' => 'editor']]
        );
        $id2 = $this->accessReqRepo->create(
            'zitadel-user-clamp-hi',
            'ch@example.test',
            'CH',
            'developer',
            [['object_type' => 'test_definition', 'object_id' => 'foo', 'relation' => 'editor']]
        );
        $this->accessReqRepo->approve($id1, 'admin-x', null);
        $this->accessReqRepo->approve($id2, 'admin-x', null);

        $result = $this->repo->fetchInbox('zitadel-user-clamp-hi', limit: 999);

        self::assertCount(2, $result['items']);
        self::assertSame(2, $result['total']);
    }

    public function testFetchInboxClampsNonPositiveLimitToOne(): void
    {
        // A caller-requested limit of 0 or negative is nonsensical; the
        // repository clamps to 1 (defense in depth) rather than running a
        // `LIMIT 0` query that returns no items.
        $id1 = $this->accessReqRepo->create(
            'zitadel-user-clamp-lo',
            'cl@example.test',
            'CL',
            'calendar_editor',
            [['object_type' => 'national_calendar', 'object_id' => 'IT', 'relation' => 'editor']]
        );
        $id2 = $this->accessReqRepo->create(
            'zitadel-user-clamp-lo',
            'cl@example.test',
            'CL',
            'developer',
            [['object_type' => 'test_definition', 'object_id' => 'foo', 'relation' => 'editor']]
        );
        $this->accessReqRepo->approve($id1, 'admin-x', null);
        $this->accessReqRepo->approve($id2, 'admin-x', null);

        $result = $this->repo->fetchInbox('zitadel-user-clamp-lo', limit: 0);

        self::assertCount(1, $result['items']);
        // total is the window-function count over the FULL filtered set,
        // so it still sees both items even though only 1 is paged in.
        self::assertSame(2, $result['total']);
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

    public function testInboxCarriesSettledChangeRequests(): void
    {
        $batchId = $this->settledBatch('user-1', 'merged', '-1 hour');

        $inbox = $this->repo->fetchInbox('user-1');

        self::assertSame(1, $inbox['total']);
        self::assertSame('change_request_published', $inbox['items'][0]['type']);
        self::assertSame($batchId, $inbox['items'][0]['batch_id']);
        self::assertSame('merged', $inbox['items'][0]['publication_status']);
        self::assertTrue($inbox['items'][0]['unread']);
    }

    public function testAnUnsettledBatchIsNotNews(): void
    {
        $this->openBatch('user-1');

        self::assertSame(0, $this->repo->fetchInbox('user-1')['total']);
    }

    public function testOneItemPerBatchNotPerFile(): void
    {
        $this->settledBatch('user-1', 'merged', '-1 hour', files: 3);

        self::assertCount(1, $this->repo->fetchInbox('user-1')['items']);
    }

    public function testTheTwoSourcesInterleaveByTimestampAndShareTheTotals(): void
    {
        $this->reviewedAccessRequest('user-1', '-2 hours');
        $this->settledBatch('user-1', 'merged', '-1 hour');
        $this->reviewedAccessRequest('user-1', '-3 hours');

        $inbox = $this->repo->fetchInbox('user-1');

        self::assertSame(3, $inbox['total']);
        self::assertSame(3, $inbox['unread_count']);
        self::assertSame(
            ['change_request_published', 'access_request_reviewed', 'access_request_reviewed'],
            array_column($inbox['items'], 'type'),
            'newest first, across both sources'
        );
    }

    public function testTheSeenBookmarkMarksChangeRequestsRead(): void
    {
        $this->settledBatch('user-1', 'merged', '-2 hours');
        $this->repo->markSeen('user-1');
        $this->settledBatch('user-1', 'closed', '+0 seconds');

        $inbox = $this->repo->fetchInbox('user-1');

        self::assertSame(2, $inbox['total']);
        self::assertSame(1, $inbox['unread_count']);
    }

    public function testAnotherUsersBatchIsInvisible(): void
    {
        $this->settledBatch('user-2', 'merged', '-1 hour');

        self::assertSame(0, $this->repo->fetchInbox('user-1')['total']);
    }

    public function testChangeRequestItemCarriesPrNumberAndSettledAtAsRfc3339(): void
    {
        $this->settledBatch('user-1', 'merged', '-1 hour', prNumber: 4321);

        $item = $this->repo->fetchInbox('user-1')['items'][0];

        self::assertSame(4321, $item['pr_number']);
        self::assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/',
            $item['settled_at']
        );
    }

    // ---------------------------------------------------------------- review decisions (#925)

    /**
     * The bug this half exists for. A rejected batch never publishes, so before
     * `change_request_reviewed` existed its submitter got no notification of any kind — the
     * proposal simply stopped.
     */
    public function testARejectedBatchNotifiesItsSubmitter(): void
    {
        $batchId = $this->submittedBatch('user-1');
        self::assertGreaterThan(0, $this->changeReqRepo->rejectBatch($batchId, 'reviewer-1', 'Wrong feast rank'));

        $inbox = $this->repo->fetchInbox('user-1');

        self::assertSame(1, $inbox['total']);
        self::assertSame(1, $inbox['unread_count']);
        $item = $inbox['items'][0];
        self::assertSame('change_request_reviewed', $item['type']);
        self::assertSame($batchId, $item['batch_id']);
        self::assertSame('rejected', $item['review_status']);
        self::assertSame('Wrong feast rank', $item['rejected_reason']);
        self::assertSame('national_calendar', $item['resource_type']);
        self::assertSame('roman/US', $item['resource_id']);
        self::assertTrue($item['unread']);
        self::assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/',
            $item['reviewed_at']
        );
    }

    /**
     * An approval is news at the moment it is made, not only when GitHub eventually settles it:
     * publication can be a queue wait, a grace window and a human pull-request review away.
     */
    public function testAnApprovedBatchNotifiesItsSubmitterBeforeItPublishes(): void
    {
        $batchId = $this->submittedBatch('user-1');
        $this->changeReqRepo->approveBatch($batchId, 'reviewer-1');

        $inbox = $this->repo->fetchInbox('user-1');

        self::assertSame(1, $inbox['total']);
        $item = $inbox['items'][0];
        self::assertSame('change_request_reviewed', $item['type']);
        self::assertSame('approved', $item['review_status']);
        self::assertNull($item['rejected_reason']);
    }

    /**
     * A resource admin's own write is auto-approved inside the same request, and the write
     * response already answered `disposition: "approved"`. Telling them afterwards what they just
     * did is noise, so a decision whose decider IS the submitter produces nothing.
     */
    public function testADecisionAnEditorMadeOnTheirOwnBatchIsNotNews(): void
    {
        $batchId = $this->submittedBatch('user-1');
        $this->changeReqRepo->approveBatch($batchId, 'user-1');

        self::assertSame(0, $this->repo->fetchInbox('user-1')['total']);
    }

    public function testAnUndecidedBatchIsNotNews(): void
    {
        $this->submittedBatch('user-1');

        self::assertSame(0, $this->repo->fetchInbox('user-1')['total']);
    }

    /**
     * An editor who changed a calendar and its i18n files proposed ONE thing, and one decision was
     * made about it — the same collapse `change_request_published` performs.
     */
    public function testOneReviewItemPerBatchNotPerFile(): void
    {
        $batchId = $this->submittedBatch('user-1', files: 3);
        $this->changeReqRepo->rejectBatch($batchId, 'reviewer-1', 'no');

        $inbox = $this->repo->fetchInbox('user-1');

        self::assertCount(1, $inbox['items']);
        self::assertSame(1, $inbox['total']);
    }

    /**
     * The reason `review_decision` exists at all.
     *
     * `markBatchClosedUnmerged()` writes `review_status = 'rejected'` when a published batch's pull
     * request closes without merging — on a batch a human APPROVED. An inbox keyed on
     * `review_status` would tell this submitter their proposal was refused, and date the refusal to
     * the moment they were approved. The decision item must keep saying `approved`; the close is
     * reported by the publication item, at the time it actually happened.
     */
    public function testAClosedUnmergedPullRequestDoesNotRewriteTheReviewDecision(): void
    {
        $batchId = $this->submittedBatch('user-1');
        $this->changeReqRepo->approveBatch($batchId, 'reviewer-1');
        $this->changeReqRepo->markBatchPublicationStatus($batchId, ChangePublicationStatus::OPEN);
        self::assertGreaterThan(
            0,
            $this->changeReqRepo->markBatchClosedUnmerged($batchId, 'Closed without merging')
        );

        // Sanity: the row really does now claim to be rejected.
        $stmt = self::$pdo->prepare('SELECT review_status, review_decision FROM sourcedata_change_requests WHERE batch_id = :b LIMIT 1');
        $stmt->execute(['b' => $batchId]);
        /** @var array<string,string> $row */
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        self::assertSame('rejected', $row['review_status']);
        self::assertSame('approved', $row['review_decision']);

        $inbox = $this->repo->fetchInbox('user-1');

        self::assertSame(2, $inbox['total'], 'the decision and the publication are both news');
        $byType = [];
        foreach ($inbox['items'] as $item) {
            $byType[$item['type']] = $item;
        }
        self::assertArrayHasKey('change_request_reviewed', $byType);
        self::assertArrayHasKey('change_request_published', $byType);
        self::assertSame('approved', $byType['change_request_reviewed']['review_status']);
        self::assertSame('closed', $byType['change_request_published']['publication_status']);

        // markBatchClosedUnmerged() also writes its own close text into `rejected_reason`. An
        // APPROVAL must not surface it: `rejected_reason` on this item is the reason for THIS
        // decision, and an approval has none.
        self::assertNull($byType['change_request_reviewed']['rejected_reason']);
    }

    public function testAnotherUsersDecisionIsInvisible(): void
    {
        $batchId = $this->submittedBatch('user-2');
        $this->changeReqRepo->rejectBatch($batchId, 'reviewer-1', 'no');

        self::assertSame(0, $this->repo->fetchInbox('user-1')['total']);
    }

    public function testTheSeenBookmarkMarksAReviewDecisionRead(): void
    {
        $older = $this->submittedBatch('user-1', 'US');
        $this->changeReqRepo->rejectBatch($older, 'reviewer-1', 'no');
        $this->backdateDecision($older, '-2 hours');

        $this->repo->markSeen('user-1');

        $newer = $this->submittedBatch('user-1', 'CA');
        $this->changeReqRepo->approveBatch($newer, 'reviewer-1');

        $inbox = $this->repo->fetchInbox('user-1');

        self::assertSame(2, $inbox['total']);
        self::assertSame(1, $inbox['unread_count']);
        self::assertSame($newer, $inbox['items'][0]['batch_id'], 'newest decision first');
    }

    /**
     * The cursor is `approved_at`, which the decision writes once — NOT `updated_at`, which moves
     * on every claim, release, reclaim and publication-status write. A batch whose row is touched
     * after the user has read its decision must not silently become unread again.
     */
    public function testALaterRowTouchDoesNotResurrectAReadDecision(): void
    {
        $batchId = $this->submittedBatch('user-1');
        $this->changeReqRepo->approveBatch($batchId, 'reviewer-1');
        $this->backdateDecision($batchId, '-2 hours');

        $this->repo->markSeen('user-1');

        // Moves updated_at to NOW(), and leaves approved_at alone.
        $this->changeReqRepo->markBatchPublicationStatus($batchId, ChangePublicationStatus::QUEUED);

        $inbox = $this->repo->fetchInbox('user-1');

        self::assertSame(1, $inbox['total']);
        self::assertSame(0, $inbox['unread_count']);
        self::assertFalse($inbox['items'][0]['unread']);
    }

    public function testChangeRequestTotalReflectsAllSettledBatchesNotJustThePage(): void
    {
        // Mirrors testFetchInboxRespectsLimit for the access-request half: more settled batches
        // than the page limit must still report their TRUE total/unread_count, not the count of
        // the returned (already-capped) page. Distinct per-batch offsets avoid any same-instant
        // ordering ambiguity between batches.
        for ($i = 0; $i < 55; $i++) {
            $this->settledBatch('user-1', 'merged', "-{$i} minutes");
        }

        $result = $this->repo->fetchInbox('user-1', limit: 50);

        self::assertCount(50, $result['items']);
        self::assertSame(55, $result['total']);
        self::assertSame(55, $result['unread_count']);
    }
}
