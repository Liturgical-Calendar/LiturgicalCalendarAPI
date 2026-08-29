<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers;

use LiturgicalCalendar\Api\Enum\ChangeOperation;
use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Handlers\Auth\ChangeRequestHandler;
use LiturgicalCalendar\Api\Http\Exception\NotFoundException;
use LiturgicalCalendar\Api\Http\Exception\UnauthorizedException;
use LiturgicalCalendar\Api\Http\Exception\ValidationException;
use LiturgicalCalendar\Api\Repositories\SourceDataChangeRequestRepository;
use LiturgicalCalendar\Api\Services\ChangeResource;
use LiturgicalCalendar\Tests\Repositories\RepositoryTestCase;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(ChangeRequestHandler::class)]
final class AuthChangeRequestHandlerTest extends RepositoryTestCase
{
    private SourceDataChangeRequestRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new SourceDataChangeRequestRepository(self::$pdo);
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
        );
    }

    private function request(string $method, string $path, string $sub): ServerRequest
    {
        return ( new ServerRequest($method, $path) )
            ->withAttribute('oidc_user', ['sub' => $sub, 'email' => 'alice@example.test', 'email_verified' => true])
            ->withHeader('Accept', 'application/json');
    }

    public function testListReturnsOnlyTheCallersOwnBatches(): void
    {
        $mine = $this->submitFor('user-1', 'USA');
        $this->submitFor('user-2', 'ITALY');

        $handler  = new ChangeRequestHandler([], $this->repo);
        $response = $handler->handle($this->request('GET', '/auth/change-requests', 'user-1'));

        $body = json_decode((string) $response->getBody(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(1, $body['change_requests']);
        self::assertSame($mine, $body['change_requests'][0]['batch_id']);
        self::assertSame(1, $body['total']);
    }

    public function testAnUnauthenticatedCallerIsRejected(): void
    {
        // Handlers throw ApiException subclasses; ErrorHandlingMiddleware converts
        // them to HTTP responses in the real pipeline. Calling handle() directly
        // here (bypassing the pipeline) means the exception must be asserted
        // directly rather than read off a returned response — see every other
        // handler test in this suite (e.g. AccessRequestHandlerTest::
        // testMissingOidcUserIsUnauthorized) for the same pattern.
        $handler = new ChangeRequestHandler([], $this->repo);
        $request = ( new ServerRequest('GET', '/auth/change-requests') )->withHeader('Accept', 'application/json');

        $this->expectException(UnauthorizedException::class);

        $handler->handle($request);
    }

    public function testUnrecognisedStatusIsRejectedRatherThanSilentlyListingEverything(): void
    {
        // If this ever regressed into a silent unfiltered listing, a caller who
        // thinks they narrowed the list by status would act on one that isn't
        // narrowed at all. Assert on the message too, not just the exception
        // type — a message that degraded to something generic would still let
        // that regression slip through a type-only assertion.
        $this->submitFor('user-1', 'USA');

        $handler = new ChangeRequestHandler([], $this->repo);
        $request = $this->request('GET', '/auth/change-requests', 'user-1')
            ->withQueryParams(['status' => 'bogus']);

        try {
            $handler->handle($request);
            self::fail('Expected a ValidationException');
        } catch (ValidationException $e) {
            self::assertSame(
                'Invalid status "bogus". Valid values: submitted, approved, rejected, withdrawn',
                $e->getMessage()
            );
        }
    }

    public function testWithdrawingOwnBatchSucceeds(): void
    {
        $batchId = $this->submitFor('user-1', 'USA');

        $handler  = new ChangeRequestHandler(['change-requests', $batchId, 'withdraw'], $this->repo);
        $response = $handler->handle($this->request('POST', '/auth/change-requests/' . $batchId . '/withdraw', 'user-1'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('withdrawn', $this->repo->getBatch($batchId)[0]['review_status']);
    }

    /**
     * These two tests exist side by side deliberately, to make one contrast legible:
     *
     * - Splitting outcomes AMONG WELL-FORMED batch ids is the thing that must never
     *   happen: "no such batch" and "exists but isn't yours" both have to collapse
     *   to the same 404, because a 403 (or any other tell) would disclose the
     *   batch's existence to a caller who has no right to know it. withdrawBatch()
     *   already guarantees this in SQL by scoping on submitted_by_sub in the WHERE
     *   clause rather than fetching-then-checking — see the class docblock.
     * - A malformed batch id is a DIFFERENT axis entirely: whether a string is
     *   UUID-shaped is decidable from the input alone, before any query runs, so a
     *   distinct 400 here discloses nothing about the database — the caller could
     *   have checked the shape themselves without ever calling us. Collapsing it
     *   into the same 404 as "not yours" is not required and was a deliberate
     *   choice to keep, matching AccessRequestAdminHandler's identical `{id}` guard.
     */
    public function testWithdrawingWithAMalformedBatchIdIsAValidationError(): void
    {
        $handler = new ChangeRequestHandler(['change-requests', 'not-a-uuid', 'withdraw'], $this->repo);

        try {
            $handler->handle($this->request('POST', '/auth/change-requests/not-a-uuid/withdraw', 'user-1'));
            self::fail('Expected a ValidationException');
        } catch (ValidationException $e) {
            // The message names a format problem, not an existence one — it must
            // never read like "not found" or "not yours", which would blur this
            // back into the axis that has to stay collapsed (see docblock above).
            self::assertSame('Invalid batch ID format', $e->getMessage());
        }
    }

    public function testWithdrawingSomeoneElsesBatchIsNotFound(): void
    {
        // A 403 here would confirm to the caller that a batch they cannot touch
        // exists at all — withdrawBatch() returning 0 rows (not theirs, or already
        // decided) must always surface as 404, never 403. See the class docblock.
        $batchId = $this->submitFor('user-2', 'ITALY');

        $handler = new ChangeRequestHandler(['change-requests', $batchId, 'withdraw'], $this->repo);

        try {
            $handler->handle($this->request('POST', '/auth/change-requests/' . $batchId . '/withdraw', 'user-1'));
            self::fail('Expected a NotFoundException');
        } catch (NotFoundException $e) {
            self::assertSame(404, $e->getStatus());
        }

        self::assertSame('submitted', $this->repo->getBatch($batchId)[0]['review_status']);
    }
}
