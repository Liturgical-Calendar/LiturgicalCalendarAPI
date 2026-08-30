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
use LiturgicalCalendar\Api\Services\ChangeResource;
use LiturgicalCalendar\Api\Services\OpenFgaClient;
use LiturgicalCalendar\Api\Services\SourceData\SourceDataPublishNotifier;
use LiturgicalCalendar\Tests\Repositories\RepositoryTestCase;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use GuzzleHttp\Exception\ConnectException;
use LiturgicalCalendar\Api\Enum\ChangeReviewStatus;
use LiturgicalCalendar\Api\Http\Exception\UnauthorizedException;
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

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new SourceDataChangeRequestRepository(self::$pdo);
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

    private function submitFor(string $sub, string $nation): string
    {
        return $this->repo->submitBatch(
            ChangeResource::nationalCalendar(Rite::ROMAN, $nation),
            [
                [
                    'path'      => 'jsondata/sourcedata/rite/roman/calendars/nation/' . $nation . '.json',
                    'operation' => ChangeOperation::UPDATE,
                    'content'   => '{"litcal":[]}',
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
}
