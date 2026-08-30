<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use LiturgicalCalendar\Api\Enum\ChangeOperation;
use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Handlers\Admin\ChangeRequestAdminHandler;
use LiturgicalCalendar\Api\Http\Exception\ConflictException;
use LiturgicalCalendar\Api\Http\Exception\NotFoundException;
use LiturgicalCalendar\Api\Repositories\SourceDataChangeRequestRepository;
use LiturgicalCalendar\Api\Router;
use LiturgicalCalendar\Api\Services\ChangeResource;
use LiturgicalCalendar\Api\Services\OpenFgaClient;
use LiturgicalCalendar\Api\Services\SourceData\SourceDataPublishNotifier;
use LiturgicalCalendar\Tests\Repositories\RepositoryTestCase;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use GuzzleHttp\Exception\ConnectException;
use LiturgicalCalendar\Api\Enum\ChangeReviewStatus;
use LiturgicalCalendar\Api\Http\Exception\UnauthorizedException;
use LiturgicalCalendar\Api\Http\Exception\UnprocessableContentException;
use LiturgicalCalendar\Api\Http\Exception\ValidationException;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Extends RepositoryTestCase (not AbstractHandlerTestCase) because
 * `sourcedata_change_requests` is truncated only by RepositoryTestCase::TABLES —
 * AuthChangeRequestHandlerTest (Task 12) established the same precedent for the
 * same reason.
 */
#[CoversClass(ChangeRequestAdminHandler::class)]
final class ChangeRequestAdminHandlerTest extends RepositoryTestCase
{
    private SourceDataChangeRequestRepository $repo;

    private string $savedApiFilePath = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new SourceDataChangeRequestRepository(self::$pdo);

        // The #918 approval gate imports schemas through LitSchema::path(), which prefixes
        // Router::$apiFilePath. Pin it to the project root the way AbstractHandlerTestCase
        // does, and put it back afterwards so no other class inherits this one's value.
        $this->savedApiFilePath = isset(Router::$apiFilePath) ? Router::$apiFilePath : '';
        Router::$apiFilePath    = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR;
    }

    protected function tearDown(): void
    {
        Router::$apiFilePath = $this->savedApiFilePath;
        parent::tearDown();
    }

    /**
     * @param array<int, GuzzleResponse> $fgaResponses
     * @param ?SourceDataPublishNotifier $notifier      Injected the same way the repository and FGA
     *                                                   client already are, so tests can substitute a
     *                                                   recording subclass instead of touching Redis.
     */
    private function handler(array $pathParts, array $fgaResponses, ?SourceDataPublishNotifier $notifier = null): ChangeRequestAdminHandler
    {
        $guzzle = new GuzzleClient(['handler' => HandlerStack::create(new MockHandler($fgaResponses))]);
        $psr17  = new Psr17Factory();
        $client = new OpenFgaClient(
            apiUrl: 'http://openfga.test',
            storeId: 'test-store',
            modelId: 'test-model',
            httpClient: $guzzle,
            requestFactory: $psr17,
            streamFactory: $psr17,
            apiToken: 'test-token'
        );

        return new ChangeRequestAdminHandler($pathParts, $this->repo, $client, $notifier);
    }

    private static function allowed(bool $allowed): GuzzleResponse
    {
        return new GuzzleResponse(200, [], json_encode(['allowed' => $allowed]));
    }

    /**
     * A national-calendar locale file, valid against `LitCalTranslation.json`.
     *
     * The path is a real one — {@see \LiturgicalCalendar\Api\Enum\JsonData::NATIONAL_CALENDAR_I18N_FILE}
     * — and the content really does satisfy the schema that governs it, so approving one of
     * these batches goes through the #918 re-validation gate rather than round it. A synthetic
     * path would resolve to no schema and silently skip the very check most of these tests
     * transit.
     */
    private function submitFor(string $sub, string $nation): string
    {
        return $this->submitContentFor(
            $sub,
            $nation,
            'jsondata/sourcedata/rite/roman/calendars/nations/' . $nation . '/i18n/en.json',
            ChangeOperation::UPDATE,
            '{"StFrancisAssisi":"Saint Francis of Assisi"}'
        );
    }

    /**
     * As {@see submitFor()}, but with the staged file spelled out — for the tests that care
     * what the row actually holds.
     */
    private function submitContentFor(
        string $sub,
        string $nation,
        string $path,
        ChangeOperation $operation,
        ?string $content
    ): string {
        return $this->repo->submitBatch(
            ChangeResource::nationalCalendar(Rite::ROMAN, $nation),
            [
                [
                    'path'      => $path,
                    'operation' => $operation,
                    'content'   => $content,
                ],
            ],
            $sub,
            'Alice',
            'alice@example.test',
            true
        )['batch_id'];
    }

    private function request(string $method, string $path, string $sub): ServerRequest
    {
        return ( new ServerRequest($method, $path) )
            ->withAttribute('oidc_user', ['sub' => $sub, 'roles' => ['calendar_editor']])
            ->withHeader('Accept', 'application/json');
    }

    public function testAResourceAdminSeesOnlyTheirOwnResources(): void
    {
        $usa = $this->submitFor('user-1', 'USA');
        $this->submitFor('user-2', 'ITALY');

        // listAll() orders newest-first, so ITALY (submitted second) is probed
        // before USA. Two batches probed; the admin administers only USA.
        $handler  = $this->handler([], [self::allowed(false), self::allowed(true)]);
        $response = $handler->handle($this->request('GET', '/admin/change-requests', 'admin-1'));

        $body = json_decode((string) $response->getBody(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(1, $body['change_requests']);
        self::assertSame($usa, $body['change_requests'][0]['batch_id']);
        self::assertSame(1, $body['count']);
        // total is the PRE-filter SQL count, not the post-filter count.
        self::assertSame(2, $body['total']);
        self::assertFalse($body['has_more']);
    }

    /**
     * A single SQL page (limit=1) that filters down to ZERO surviving rows must still
     * report has_more=true when the SQL paginator has more pages behind it — the
     * failure Ruling 1 exists to prevent: a client that stops paging on an empty page
     * would otherwise silently stop seeing reviewable batches on later pages.
     */
    public function testAnEmptyFilteredPageStillReportsHasMoreWhenMorePagesRemain(): void
    {
        $this->submitFor('user-1', 'USA');
        $usa2 = $this->submitFor('user-1', 'CANADA');

        // limit=1: the SQL page holds only the newest batch (CANADA). The admin
        // administers neither, so the filtered page is empty.
        $handler  = $this->handler([], [self::allowed(false)]);
        $response = $handler->handle($this->request('GET', '/admin/change-requests?limit=1', 'admin-1'));

        $body = json_decode((string) $response->getBody(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(0, $body['change_requests']);
        self::assertSame(0, $body['count']);
        self::assertSame(2, $body['total']);
        self::assertSame(1, $body['limit']);
        // sqlPageCount (1) + offset (0) = 1 < total (2) => more pages remain.
        self::assertTrue($body['has_more']);
        self::assertNotEmpty($usa2);
    }

    public function testApprovingABatchStampsTheAdmin(): void
    {
        $batchId = $this->submitFor('user-1', 'USA');

        $handler  = $this->handler(['change-requests', $batchId, 'approve'], [self::allowed(true)]);
        $response = $handler->handle($this->request('POST', '/admin/change-requests/' . $batchId . '/approve', 'admin-1'));

        self::assertSame(200, $response->getStatusCode());

        $row = $this->repo->getBatch($batchId)[0];
        self::assertSame('approved', $row['review_status']);
        self::assertSame('admin-1', $row['approved_by_sub']);
    }

    /**
     * A resource admin who does not administer the batch's resource gets 404, never
     * 403 — a 403 would confirm to them that the batch exists at all. This deviates
     * from the task brief's literal "403" wording (and this test's brief-supplied
     * name), per the dispatch ruling that this must match
     * ChangeRequestHandler::withdraw()'s 404-only behaviour.
     */
    public function testApprovingAResourceTheCallerDoesNotAdministerIsNotFound(): void
    {
        $batchId = $this->submitFor('user-1', 'USA');

        $handler = $this->handler(['change-requests', $batchId, 'approve'], [self::allowed(false)]);

        $this->expectException(NotFoundException::class);
        $handler->handle($this->request('POST', '/admin/change-requests/' . $batchId . '/approve', 'admin-2'));
    }

    public function testRejectingRecordsTheReason(): void
    {
        $batchId = $this->submitFor('user-1', 'USA');

        $handler = $this->handler(['change-requests', $batchId, 'reject'], [self::allowed(true)]);
        $request = $this->request('POST', '/admin/change-requests/' . $batchId . '/reject', 'admin-1')
            ->withHeader('Content-Type', 'application/json');
        $request->getBody()->write(json_encode(['reason' => 'Wrong feast rank']));
        $request->getBody()->rewind();

        $response = $handler->handle($request);

        self::assertSame(200, $response->getStatusCode());

        $row = $this->repo->getBatch($batchId)[0];
        self::assertSame('rejected', $row['review_status']);
        self::assertSame('Wrong feast rank', $row['rejected_reason']);
    }

    public function testApprovingAnAlreadyDecidedBatchConflicts(): void
    {
        $batchId = $this->submitFor('user-1', 'USA');
        $this->repo->approveBatch($batchId, 'admin-1');

        $handler = $this->handler(['change-requests', $batchId, 'approve'], [self::allowed(true)]);

        $this->expectException(ConflictException::class);
        try {
            $handler->handle($this->request('POST', '/admin/change-requests/' . $batchId . '/approve', 'admin-2'));
        } finally {
            self::assertSame('admin-1', $this->repo->getBatch($batchId)[0]['approved_by_sub']);
        }
    }

    public function testAnUnknownBatchIsNotFound(): void
    {
        $handler = $this->handler(['change-requests', '00000000-0000-0000-0000-000000000000', 'approve'], []);

        $this->expectException(NotFoundException::class);
        $handler->handle($this->request('POST', '/admin/change-requests/00000000-0000-0000-0000-000000000000/approve', 'admin-1'));
    }

    public function testAGlobalAdminBypassesTheFgaCheckEntirely(): void
    {
        $batchId = $this->submitFor('user-1', 'USA');

        // No FGA responses queued at all — a global admin must never dial OpenFGA.
        $handler = $this->handler(['change-requests', $batchId, 'approve'], []);
        $request = ( new ServerRequest('POST', '/admin/change-requests/' . $batchId . '/approve') )
            ->withAttribute('oidc_user', ['sub' => 'admin-1', 'roles' => ['admin']])
            ->withHeader('Accept', 'application/json');

        $response = $handler->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('approved', $this->repo->getBatch($batchId)[0]['review_status']);
    }

    public function testAnUnauthenticatedCallerIsRejected(): void
    {
        $request = ( new ServerRequest('GET', '/admin/change-requests') )
            ->withHeader('Accept', 'application/json');

        $this->expectException(UnauthorizedException::class);
        $this->handler([], [])->handle($request);
    }

    public function testATokenWithoutASubjectIsRejected(): void
    {
        $request = ( new ServerRequest('GET', '/admin/change-requests') )
            ->withAttribute('oidc_user', ['roles' => ['calendar_editor']])
            ->withHeader('Accept', 'application/json');

        $this->expectException(UnauthorizedException::class);
        $this->handler([], [])->handle($request);
    }

    public function testAnUnrecognisedStatusFilterIsRejected(): void
    {
        // Silently ignoring the filter would hand the caller a list they believe is
        // narrower than it is, so this must fail rather than fall back to "everything".
        $request = $this->request('GET', '/admin/change-requests', 'admin-1')
            ->withQueryParams(['status' => 'not-a-status']);

        $this->expectException(ValidationException::class);
        $this->handler([], [])->handle($request);
    }

    public function testEveryValidStatusFilterIsAccepted(): void
    {
        foreach (ChangeReviewStatus::cases() as $case) {
            $request = $this->request('GET', '/admin/change-requests', 'admin-1')
                ->withQueryParams(['status' => $case->value]);

            $response = $this->handler([], [self::allowed(true)])->handle($request);

            self::assertSame(200, $response->getStatusCode(), $case->value . ' should be accepted');
        }
    }

    public function testAMalformedBatchIdIsRejectedBeforeAnyLookup(): void
    {
        $request = $this->request('POST', '/admin/change-requests/not-a-uuid/approve', 'admin-1');

        $this->expectException(ValidationException::class);
        $this->handler(['change-requests', 'not-a-uuid', 'approve'], [])->handle($request);
    }

    public function testRejectingAnAlreadyDecidedBatchConflicts(): void
    {
        $batchId = $this->submitFor('editor-1', 'US');
        $this->repo->approveBatch($batchId, 'admin-1');

        $request = $this->request('POST', '/admin/change-requests/' . $batchId . '/reject', 'admin-1');

        $this->expectException(ConflictException::class);
        $this->handler(['change-requests', $batchId, 'reject'], [self::allowed(true)])->handle($request);
    }

    public function testAnUnreachableFgaFailsClosedOnApprove(): void
    {
        // A transport failure must never read as "allowed": an admin who cannot be
        // checked is an admin who cannot approve.
        $batchId = $this->submitFor('editor-1', 'US');

        $request = $this->request('POST', '/admin/change-requests/' . $batchId . '/approve', 'admin-1');
        $handler = $this->handler(['change-requests', $batchId, 'approve'], [new ConnectException('unreachable', new Psr17Factory()->createRequest('POST', 'http://openfga.test'))]);

        try {
            $handler->handle($request);
            self::fail('an unreachable OpenFGA must not permit approval');
        } catch (NotFoundException) {
            // Fails closed, and as 404 rather than 403 so it cannot confirm the batch exists.
        }

        $rows = $this->repo->getBatch($batchId);
        self::assertSame(ChangeReviewStatus::SUBMITTED->value, $rows[0]['review_status'], 'batch must remain undecided');
    }

    public function testApproveNotifiesTheStreamAfterTheStatusUpdate(): void
    {
        $batchId  = $this->submitFor('editor-1', 'USA');
        $notifier = new RecordingPublishNotifier();

        $handler = $this->handler(['change-requests', $batchId, 'approve'], [self::allowed(true)], $notifier);
        $handler->handle($this->request('POST', '/admin/change-requests/' . $batchId . '/approve', 'admin-1'));

        self::assertSame([$batchId], $notifier->notified);
    }

    public function testRejectDoesNotNotifyTheStream(): void
    {
        $batchId  = $this->submitFor('editor-1', 'USA');
        $notifier = new RecordingPublishNotifier();

        $handler = $this->handler(['change-requests', $batchId, 'reject'], [self::allowed(true)], $notifier);
        $handler->handle($this->request('POST', '/admin/change-requests/' . $batchId . '/reject', 'admin-1'));

        self::assertSame([], $notifier->notified, 'a rejected batch is never publishable');
    }

    /**
     * The happy half of #918: content that still satisfies the schema governing its path is
     * approved, and the notifier is told, exactly as before the gate existed.
     */
    public function testApprovingABatchWhoseContentStillValidatesSucceeds(): void
    {
        $batchId  = $this->submitFor('editor-1', 'USA');
        $notifier = new RecordingPublishNotifier();

        $handler  = $this->handler(['change-requests', $batchId, 'approve'], [self::allowed(true)], $notifier);
        $response = $handler->handle($this->request('POST', '/admin/change-requests/' . $batchId . '/approve', 'admin-1'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(ChangeReviewStatus::APPROVED->value, $this->repo->getBatch($batchId)[0]['review_status']);
        self::assertSame([$batchId], $notifier->notified);
    }

    /**
     * The defect half of #918. `LitCalTranslation.json` requires every value to be a string;
     * a row holding a number therefore no longer validates. Standing in for real schema drift
     * — the row was accepted once and the schema has since tightened — because the outcome the
     * gate has to produce is identical either way: content in the queue that the schema in
     * force now rejects.
     */
    public function testApprovingABatchWhoseContentNoLongerValidatesIsRefused(): void
    {
        $path    = 'jsondata/sourcedata/rite/roman/calendars/nations/USA/i18n/en.json';
        $batchId = $this->submitContentFor('editor-1', 'USA', $path, ChangeOperation::UPDATE, '{"StFrancisAssisi":42}');

        $notifier = new RecordingPublishNotifier();
        $handler  = $this->handler(['change-requests', $batchId, 'approve'], [self::allowed(true)], $notifier);

        try {
            $handler->handle($this->request('POST', '/admin/change-requests/' . $batchId . '/approve', 'admin-1'));
            self::fail('a batch that no longer validates must not be approvable');
        } catch (UnprocessableContentException $e) {
            self::assertSame(422, $e->getStatus());
            // Actionable: which file, and which schema refused it.
            self::assertStringContainsString($path, $e->getMessage());
            self::assertStringContainsString('LitCalTranslation.json', $e->getMessage());
        }

        // Not silently approved, and not silently dropped: the batch is intact and still
        // awaiting review, so its submitter can withdraw and re-submit it.
        $rows = $this->repo->getBatch($batchId);
        self::assertCount(1, $rows);
        self::assertSame(ChangeReviewStatus::SUBMITTED->value, $rows[0]['review_status']);
        self::assertNull($rows[0]['approved_by_sub']);
        self::assertSame([], $notifier->notified, 'nothing may be announced for a refused approval');
    }

    /**
     * A row whose content is not JSON at all is a violation too — it would reach the pull
     * request as an unparseable file.
     */
    public function testApprovingABatchWhoseContentIsNotJsonIsRefused(): void
    {
        $path    = 'jsondata/sourcedata/rite/roman/calendars/nations/USA/i18n/en.json';
        $batchId = $this->submitContentFor('editor-1', 'USA', $path, ChangeOperation::UPDATE, '{not json');

        $handler = $this->handler(['change-requests', $batchId, 'approve'], [self::allowed(true)]);

        $this->expectException(UnprocessableContentException::class);
        $this->expectExceptionMessageMatches('/not valid JSON/');
        $handler->handle($this->request('POST', '/admin/change-requests/' . $batchId . '/approve', 'admin-1'));
    }

    /**
     * A DELETE row carries no content, so there is nothing for a schema to have an opinion
     * about. Critically, the path here IS one the resolver recognises — an empty i18n file
     * would fail `LitCalTranslation.json`'s `minProperties` — so the batch only approves
     * because the gate keys on "are there bytes", not on the path.
     *
     * This is the ordinary locale-drop case, not a resource deletion: `RegionalDataHandler`
     * stages exactly this DELETE for a locale removed from `metadata.locales` on a calendar
     * that still exists, which is why `operation = 'delete'` must never be read as anything
     * more than "no bytes proposed".
     */
    public function testADeleteRowIsApprovedWithoutBeingValidated(): void
    {
        $batchId = $this->submitContentFor(
            'editor-1',
            'USA',
            'jsondata/sourcedata/rite/roman/calendars/nations/USA/i18n/de.json',
            ChangeOperation::DELETE,
            null
        );

        $handler  = $this->handler(['change-requests', $batchId, 'approve'], [self::allowed(true)]);
        $response = $handler->handle($this->request('POST', '/admin/change-requests/' . $batchId . '/approve', 'admin-1'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(ChangeReviewStatus::APPROVED->value, $this->repo->getBatch($batchId)[0]['review_status']);
    }

    /**
     * A path outside every family {@see \LiturgicalCalendar\Api\Services\SourceData\SourceDataSchemaResolver}
     * knows resolves to no schema, and "no schema claims these bytes" is not "these bytes are
     * wrong". Refusing here would jam the reviewer's queue on a batch nothing has found fault
     * with, for a reason no administrator could act on.
     */
    public function testAPathNoSchemaGovernsIsApprovedRatherThanRefused(): void
    {
        $batchId = $this->submitContentFor(
            'editor-1',
            'USA',
            'jsondata/sourcedata/rite/roman/missals/propriumdetempore/propriumdetempore.json',
            ChangeOperation::UPDATE,
            '{"anything":"at all"}'
        );

        $handler  = $this->handler(['change-requests', $batchId, 'approve'], [self::allowed(true)]);
        $response = $handler->handle($this->request('POST', '/admin/change-requests/' . $batchId . '/approve', 'admin-1'));

        self::assertSame(200, $response->getStatusCode());
    }

    /**
     * "Someone else already decided this" stays a 409, even when the decided batch's content
     * would not pass the schemas in force now. The transition is not going to happen, so
     * re-litigating its content would replace the true answer with a misleading one.
     */
    public function testAnAlreadyDecidedBatchStillConflictsRatherThanFailingValidation(): void
    {
        $batchId = $this->submitContentFor(
            'editor-1',
            'USA',
            'jsondata/sourcedata/rite/roman/calendars/nations/USA/i18n/en.json',
            ChangeOperation::UPDATE,
            '{"StFrancisAssisi":42}'
        );
        $this->repo->approveBatch($batchId, 'admin-1');

        $handler = $this->handler(['change-requests', $batchId, 'approve'], [self::allowed(true)]);

        $this->expectException(ConflictException::class);
        $handler->handle($this->request('POST', '/admin/change-requests/' . $batchId . '/approve', 'admin-2'));
    }

    /**
     * Rejection is unaffected: a batch that can no longer be approved must still be
     * dismissible, or an invalid batch would be stuck in the queue forever.
     */
    public function testABatchThatNoLongerValidatesCanStillBeRejected(): void
    {
        $batchId = $this->submitContentFor(
            'editor-1',
            'USA',
            'jsondata/sourcedata/rite/roman/calendars/nations/USA/i18n/en.json',
            ChangeOperation::UPDATE,
            '{"StFrancisAssisi":42}'
        );

        $handler  = $this->handler(['change-requests', $batchId, 'reject'], [self::allowed(true)]);
        $response = $handler->handle($this->request('POST', '/admin/change-requests/' . $batchId . '/reject', 'admin-1'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(ChangeReviewStatus::REJECTED->value, $this->repo->getBatch($batchId)[0]['review_status']);
    }
}
