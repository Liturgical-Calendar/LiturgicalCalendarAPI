<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers\Admin;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use LiturgicalCalendar\Api\Handlers\Admin\AccessRequestAdminHandler;
use LiturgicalCalendar\Api\Http\Exception\MethodNotAllowedException;
use LiturgicalCalendar\Api\Http\Exception\NotFoundException;
use LiturgicalCalendar\Api\Http\Exception\UnauthorizedException;
use LiturgicalCalendar\Api\Http\Exception\ValidationException;
use LiturgicalCalendar\Api\Repositories\AccessRequestRepository;
use LiturgicalCalendar\Api\Repositories\OutboxRepository;
use LiturgicalCalendar\Api\Services\OpenFgaClient;
use LiturgicalCalendar\Api\Services\Outbox\OutboxNotifier;
use LiturgicalCalendar\Api\Services\Outbox\OutboxOperation;
use LiturgicalCalendar\Api\Services\Outbox\OutboxProcessor;
use LiturgicalCalendar\Api\Services\Outbox\OutboxStatus;
use LiturgicalCalendar\Tests\Handlers\AbstractHandlerTestCase;
use LiturgicalCalendar\Tests\Support\EnvIsolationTrait;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass(AccessRequestAdminHandler::class)]
final class AccessRequestAdminHandlerTest extends AbstractHandlerTestCase
{
    use EnvIsolationTrait;

    protected static bool $requiresDatabase = true;

    /**
     * Extract and narrow the `requests` field of a decoded response body.
     *
     * `decodeJsonBody()` returns `array<string, mixed>`, so direct access
     * to `$body['requests']` and any further descent is `mixed` —
     * `assertCount`, `assertArrayHasKey`, and offset access all reject
     * that. This helper does the runtime narrowing once per call site so
     * the downstream test code stays readable.
     *
     * @param array<string, mixed> $body
     * @return list<array<int|string, mixed>>
     */
    private static function requestsFrom(array $body): array
    {
        self::assertArrayHasKey('requests', $body);
        self::assertIsArray($body['requests']);
        $out = [];
        foreach ($body['requests'] as $row) {
            self::assertIsArray($row);
            $out[] = $row;
        }
        return $out;
    }

    /**
     * Extract and narrow an array-typed top-level body field. Used for
     * the various list-shaped fields the admin handler returns
     * (`tuples_created`, `tuples_deleted`, `fga_errors`, etc.) which
     * arrive typed `mixed` through `decodeJsonBody`.
     *
     * @param array<string, mixed> $body
     * @return array<int|string, mixed>
     */
    private static function arrayFieldFrom(array $body, string $key): array
    {
        self::assertArrayHasKey($key, $body);
        self::assertIsArray($body[$key]);
        return $body[$key];
    }

    /**
     * Extract and narrow a string-typed top-level body field. Used for
     * `message` (and other narrative fields) before assertStringContainsString.
     *
     * @param array<string, mixed> $body
     */
    private static function stringFieldFrom(array $body, string $key): string
    {
        self::assertArrayHasKey($key, $body);
        self::assertIsString($body[$key]);
        return $body[$key];
    }

    public function testOptionsPreflightSucceeds(): void
    {
        $response = ( new AccessRequestAdminHandler() )->handle(
            $this->requestFor('OPTIONS', '/admin/access-requests', [
                'Origin'                        => 'https://app.example.test',
                'Access-Control-Request-Method' => 'GET',
            ])
        );
        self::assertSame(204, $response->getStatusCode());
    }

    public function testPutIsMethodNotAllowed(): void
    {
        $this->expectException(MethodNotAllowedException::class);
        ( new AccessRequestAdminHandler() )->handle(
            $this->withOidcUser($this->requestFor('PUT', '/admin/access-requests'))
        );
    }

    public function testMissingOidcUserIsUnauthorized(): void
    {
        $this->expectException(UnauthorizedException::class);
        ( new AccessRequestAdminHandler() )->handle($this->requestFor('GET', '/admin/access-requests'));
    }

    public function testEmptySubIsUnauthorized(): void
    {
        $request = $this->requestFor('GET', '/admin/access-requests')
            ->withAttribute('oidc_user', ['sub' => '   ', 'roles' => ['admin']]);

        $this->expectException(UnauthorizedException::class);
        $this->expectExceptionMessage('Invalid authentication token');
        ( new AccessRequestAdminHandler() )->handle($request);
    }

    public function testListReturnsEmptyForFreshDatabase(): void
    {
        $response = ( new AccessRequestAdminHandler() )->handle(
            $this->withOidcUser($this->requestFor('GET', '/admin/access-requests'))
        );

        self::assertSame(200, $response->getStatusCode());
        $body = $this->decodeJsonBody($response);
        self::assertSame(0, $body['count']);
        self::assertSame([], $body['requests']);
    }

    public function testListWithStatusFilterReturnsOnlyMatchingRequests(): void
    {
        $repo = new AccessRequestRepository(self::$pdo);
        $repo->create('user-a', 'a@x.test', null, 'developer', []);
        $b = $repo->create('user-b', 'b@x.test', null, 'developer', []);
        $repo->approve($b, 'admin');

        $response = ( new AccessRequestAdminHandler() )->handle(
            $this->withOidcUser($this->requestFor('GET', '/admin/access-requests?status=approved'))
        );

        $body = $this->decodeJsonBody($response);
        self::assertSame(1, $body['count']);
    }

    public function testListWithBadStatusFilterIsValidationError(): void
    {
        $this->expectException(ValidationException::class);
        ( new AccessRequestAdminHandler() )->handle(
            $this->withOidcUser($this->requestFor('GET', '/admin/access-requests?status=weird'))
        );
    }

    public function testPostWithIncompletePathIsValidationError(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid request path');
        ( new AccessRequestAdminHandler() )->handle(
            $this->withOidcUser($this->requestFor('POST', '/admin/access-requests'))
        );
    }

    public function testPostWithBadUuidIsValidationError(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid request ID format');
        ( new AccessRequestAdminHandler() )->handle(
            $this->withOidcUser($this->requestFor('POST', '/admin/access-requests/not-a-uuid/approve', [], []))
        );
    }

    public function testApproveUnknownRequestIsNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        ( new AccessRequestAdminHandler() )->handle(
            $this->withOidcUser(
                $this->requestFor('POST', '/admin/access-requests/00000000-0000-0000-0000-000000000000/approve', [], [])
            )
        );
    }

    public function testRejectNonPendingIsValidationError(): void
    {
        $repo = new AccessRequestRepository(self::$pdo);
        $id   = $repo->create('user-a', 'a@x.test', null, 'developer', []);
        $repo->approve($id, 'someone'); // status is now 'approved'

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Cannot reject');

        ( new AccessRequestAdminHandler() )->handle(
            $this->withOidcUser($this->requestFor('POST', '/admin/access-requests/' . $id . '/reject', [], []))
        );
    }

    public function testApproveHappyPathWithoutFgaOrZitadel(): void
    {
        $repo = new AccessRequestRepository(self::$pdo);
        $id   = $repo->create('user-a', 'a@x.test', null, 'developer', [
            ['object_type' => 'national_calendar', 'object_id' => 'IT', 'relation' => 'editor'],
        ]);

        // Clear OpenFGA + Zitadel envs so both isConfigured() gates return
        // false during the handler call. The bootstrap's safeLoad of
        // .env.local would otherwise leak dev-stack credentials into this
        // test on developer machines (see #619).
        $response = $this->withoutEnv(
            array_merge(self::ZITADEL_ENV_VARS, self::OPENFGA_ENV_VARS),
            fn() => ( new AccessRequestAdminHandler() )->handle(
                $this->withOidcUser($this->requestFor('POST', '/admin/access-requests/' . $id . '/approve', [], ['notes' => 'ok']))
            )
        );

        self::assertSame(200, $response->getStatusCode());
        $body = $this->decodeJsonBody($response);
        self::assertTrue($body['success']);
        self::assertFalse($body['role_assigned']);
        self::assertSame([], $body['tuples_created']);
        self::assertNull($body['zitadel_error']);

        $row = $repo->getById($id);
        self::assertNotNull($row);
        self::assertSame('approved', $row['status']);

        // FGA-unavailable + non-empty permissions: outbox rows are still
        // persisted so the backstop can drain them when FGA comes back.
        // Mirror of testRevokeHappyPathWithoutFgaOrZitadel.
        self::assertSame(1, $body['outbox_pending']);
        self::assertSame(0, $body['outbox_failed']);
        self::assertCount(1, $body['outbox_ids']);
        self::assertCount(1, $body['fga_errors']);
        self::assertSame('pending', $body['fga_errors'][0]['status']);
    }

    public function testRejectHappyPath(): void
    {
        $repo = new AccessRequestRepository(self::$pdo);
        $id   = $repo->create('user-a', 'a@x.test', null, 'developer', []);

        $response = ( new AccessRequestAdminHandler() )->handle(
            $this->withOidcUser($this->requestFor('POST', '/admin/access-requests/' . $id . '/reject', [], ['notes' => 'no']))
        );

        $body = $this->decodeJsonBody($response);
        self::assertTrue($body['success']);

        $row = $repo->getById($id);
        self::assertNotNull($row);
        self::assertSame('rejected', $row['status']);
    }

    public function testRevokeHappyPathWithoutFgaOrZitadel(): void
    {
        $repo = new AccessRequestRepository(self::$pdo);
        $id   = $repo->create('user-a', 'a@x.test', null, 'developer', [
            ['object_type' => 'national_calendar', 'object_id' => 'IT', 'relation' => 'editor'],
        ]);
        $repo->approve($id, 'admin');

        // When FGA is unavailable the handler MUST still create outbox rows
        // for the delete operations — they're durable in PG and the cron
        // backstop will drain them once FGA comes back. The earlier
        // contract (drop the work) silently left stale tuples behind.
        $response = $this->withoutEnv(
            array_merge(self::ZITADEL_ENV_VARS, self::OPENFGA_ENV_VARS),
            fn() => ( new AccessRequestAdminHandler() )->handle(
                $this->withOidcUser($this->requestFor('POST', '/admin/access-requests/' . $id . '/revoke', [], []))
            )
        );

        self::assertSame(200, $response->getStatusCode());
        $body = $this->decodeJsonBody($response);
        self::assertTrue($body['success']);
        self::assertFalse($body['role_removed']);
        self::assertNull($body['zitadel_error']);
        // DB revoke happened atomically with the outbox insert.
        $row = $repo->getById($id);
        self::assertNotNull($row);
        self::assertSame('revoked', $row['status']);

        // The delete is queued for the backstop. tuples_deleted stays empty
        // (sync attempt was skipped because FGA is unavailable); the row
        // shows up as outbox_pending and outbox_ids carries its ID so the
        // admin can follow up via /admin/outbox.
        self::assertSame([], $body['tuples_deleted']);
        self::assertSame(1, $body['outbox_pending']);
        self::assertSame(0, $body['outbox_failed']);
        self::assertCount(1, $body['outbox_ids']);
        self::assertCount(1, $body['fga_errors']);
        self::assertSame('pending', $body['fga_errors'][0]['status']);
    }

    public function testUnknownActionIsValidationError(): void
    {
        $repo = new AccessRequestRepository(self::$pdo);
        $id   = $repo->create('user-a', 'a@x.test', null, 'developer', []);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid action');

        ( new AccessRequestAdminHandler() )->handle(
            $this->withOidcUser($this->requestFor('POST', '/admin/access-requests/' . $id . '/teleport', [], []))
        );
    }

    // --- Pagination (issue #572) -----------------------------------------

    public function testListRequestsWithoutPaginationDefaults(): void
    {
        $repo = new AccessRequestRepository(self::$pdo);
        $repo->create('user-a', 'a@x.test', null, 'developer', []);
        $repo->create('user-b', 'b@x.test', null, 'developer', []);

        $response = ( new AccessRequestAdminHandler() )->handle(
            $this->withOidcUser($this->requestFor('GET', '/admin/access-requests'))
        );

        self::assertSame(200, $response->getStatusCode());
        $body     = $this->decodeJsonBody($response);
        $requests = self::requestsFrom($body);
        self::assertCount(2, $requests);
        self::assertSame(2, $body['count']);
        self::assertSame(2, $body['total']);
        self::assertSame(100, $body['limit']);
        self::assertSame(0, $body['offset']);
        self::assertFalse($body['has_more']);
    }

    public function testListRequestsWithLimitAndOffsetReturnsSlice(): void
    {
        $repo = new AccessRequestRepository(self::$pdo);
        $repo->create('user-a', 'a@x.test', null, 'developer', []);
        usleep(2000);
        $repo->create('user-b', 'b@x.test', null, 'developer', []);
        usleep(2000);
        $repo->create('user-c', 'c@x.test', null, 'developer', []);

        $response = ( new AccessRequestAdminHandler() )->handle(
            $this->withOidcUser($this->requestFor('GET', '/admin/access-requests?limit=1&offset=1'))
        );

        $body     = $this->decodeJsonBody($response);
        $requests = self::requestsFrom($body);
        self::assertCount(1, $requests);
        self::assertSame(1, $body['count']);
        self::assertSame(3, $body['total']);
        self::assertSame(1, $body['limit']);
        self::assertSame(1, $body['offset']);
        self::assertTrue($body['has_more']); // 1 + 1 = 2 < 3
    }

    public function testListRequestsCombinesStatusFilterAndPagination(): void
    {
        $repo = new AccessRequestRepository(self::$pdo);
        $repo->create('user-a', 'a@x.test', null, 'developer', []); // pending
        usleep(2000);
        $repo->create('user-b', 'b@x.test', null, 'developer', []); // pending
        usleep(2000);
        $approved = $repo->create('user-c', 'c@x.test', null, 'developer', []);
        $repo->approve($approved, 'someone'); // not pending — must not appear

        $response = ( new AccessRequestAdminHandler() )->handle(
            $this->withOidcUser($this->requestFor('GET', '/admin/access-requests?status=pending&limit=1&offset=0'))
        );

        $body     = $this->decodeJsonBody($response);
        $requests = self::requestsFrom($body);
        self::assertCount(1, $requests);
        self::assertSame(1, $body['count']);
        // total counts only matching (pending) rows, ignoring limit/offset.
        self::assertSame(2, $body['total']);
        self::assertTrue($body['has_more']); // 0 + 1 = 1 < 2
        self::assertSame('pending', $requests[0]['status']);
    }

    /** @return iterable<string, array{0:string,1:string}> */
    public static function adminInvalidLimitProvider(): iterable
    {
        yield 'zero'        => ['limit=0', 'between 1 and 500'];
        yield 'too-large'   => ['limit=501', 'between 1 and 500'];
        yield 'non-numeric' => ['limit=abc', 'must be a positive integer'];
        yield 'negative'    => ['limit=-1', 'must be a positive integer'];
    }

    #[DataProvider('adminInvalidLimitProvider')]
    public function testListRequestsRejectsInvalidLimit(string $query, string $messageFragment): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage($messageFragment);

        ( new AccessRequestAdminHandler() )->handle(
            $this->withOidcUser($this->requestFor('GET', '/admin/access-requests?' . $query))
        );
    }

    /** @return iterable<string, array{0:string}> */
    public static function adminInvalidOffsetProvider(): iterable
    {
        yield 'negative'    => ['offset=-1'];
        yield 'non-numeric' => ['offset=abc'];
    }

    #[DataProvider('adminInvalidOffsetProvider')]
    public function testListRequestsRejectsInvalidOffset(string $query): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('offset must be a non-negative integer');

        ( new AccessRequestAdminHandler() )->handle(
            $this->withOidcUser($this->requestFor('GET', '/admin/access-requests?' . $query))
        );
    }

    // --- Public/admin schema split (issue #566) --------------------------

    public function testListRequestsKeepsAdminOnlyFields(): void
    {
        // Mirror of testListOwnRequestsStripsAdminOnlyFields on the public
        // handler: seed a row with all admin-only columns populated, then
        // list as admin and assert each field is present in the response.
        $repo = new AccessRequestRepository(self::$pdo);
        $id   = $repo->create('user-a', 'a@x.test', null, 'developer', []);
        $repo->approve($id, 'admin-bob', 'ok');
        $repo->updateZitadelSyncStatus($id, 'failed', 'token expired');

        $response = ( new AccessRequestAdminHandler() )->handle(
            $this->withOidcUser($this->requestFor('GET', '/admin/access-requests'))
        );

        $body     = $this->decodeJsonBody($response);
        $requests = self::requestsFrom($body);
        self::assertCount(1, $requests);
        $row = $requests[0];

        self::assertArrayHasKey('reviewed_by', $row);
        self::assertSame('admin-bob', $row['reviewed_by']);
        self::assertArrayHasKey('zitadel_sync_status', $row);
        self::assertSame('failed', $row['zitadel_sync_status']);
        self::assertArrayHasKey('zitadel_sync_error', $row);
        self::assertSame('token expired', $row['zitadel_sync_error']);
    }

    // --- Fail-fast on OpenFGA tuple errors (issue #567) ------------------

    /**
     * Build a real OpenFgaClient backed by a Guzzle MockHandler that
     * replays the queued HTTP responses. We construct the handler with
     * this client injected, set the OPENFGA_* env vars so
     * `OpenFgaClient::isConfigured()` returns true (otherwise the tuple-
     * write loop is skipped), and undo both at the end of the test.
     *
     * @param callable(OpenFgaClient): \Psr\Http\Message\ResponseInterface $fn
     */
    private function withMockOpenFgaClient(MockHandler $mock, callable $fn): \Psr\Http\Message\ResponseInterface
    {
        $stack  = HandlerStack::create($mock);
        $guzzle = new GuzzleClient(['handler' => $stack]);
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

        $savedEnv = [];
        foreach (['OPENFGA_API_URL', 'OPENFGA_STORE_ID', 'OPENFGA_MODEL_ID'] as $name) {
            $savedEnv[$name] = getenv($name);
            putenv("{$name}=stub");
            $_ENV[$name] = 'stub';
        }

        try {
            return $fn($client);
        } finally {
            foreach ($savedEnv as $name => $original) {
                if ($original === false) {
                    putenv($name);
                    unset($_ENV[$name]);
                } else {
                    putenv("{$name}={$original}");
                    $_ENV[$name] = $original;
                }
            }
        }
    }

    public function testApproveSucceedsWhenAllTupleWritesSucceed(): void
    {
        $repo = new AccessRequestRepository(self::$pdo);
        $id   = $repo->create('user-a', 'a@x.test', null, 'developer', [
            ['object_type' => 'national_calendar', 'object_id' => 'IT', 'relation' => 'editor'],
            ['object_type' => 'diocesan_calendar', 'object_id' => 'romamo_it', 'relation' => 'viewer'],
        ]);

        $mock = new MockHandler([
            new GuzzleResponse(200, [], '{}'), // tuple 1 write OK
            new GuzzleResponse(200, [], '{}'), // tuple 2 write OK
        ]);

        $outboxRepo = new OutboxRepository(self::$pdo);
        $notifier   = new OutboxNotifier(null, 'litcal:reconcile-stream');

        $response = $this->withoutEnv(self::ZITADEL_ENV_VARS, fn() => $this->withMockOpenFgaClient(
            $mock,
            function (OpenFgaClient $client) use ($id, $outboxRepo, $notifier): \Psr\Http\Message\ResponseInterface {
                $processor = new OutboxProcessor($outboxRepo, $client);
                $handler   = new AccessRequestAdminHandler($client);
                $handler->setOutboxDependencies($outboxRepo, $notifier, $processor);
                return $handler->handle(
                    $this->withOidcUser($this->requestFor(
                        'POST',
                        '/admin/access-requests/' . $id . '/approve',
                        [],
                        ['notes' => 'ok']
                    ))
                );
            }
        ));

        self::assertSame(200, $response->getStatusCode());
        $body = $this->decodeJsonBody($response);
        self::assertTrue($body['success']);
        self::assertCount(2, self::arrayFieldFrom($body, 'tuples_created'));
        self::assertSame([], $body['fga_errors']);
        self::assertSame(0, $body['outbox_pending']);
        self::assertSame(0, $body['outbox_failed']);
        self::assertCount(2, self::arrayFieldFrom($body, 'outbox_ids'));

        $row = $repo->getById($id);
        self::assertNotNull($row);
        self::assertSame('approved', $row['status']);
    }

    public function testApproveTreatsDuplicateTupleAsBenign(): void
    {
        // Idempotent re-approval: one tuple already exists. The outbox processor
        // classifies TupleAlreadyExistsException as BENIGN_SUCCESS, so the row
        // ends up in 'succeeded' state. The DB is mutated and success is true.
        $repo = new AccessRequestRepository(self::$pdo);
        $id   = $repo->create('user-a', 'a@x.test', null, 'developer', [
            ['object_type' => 'national_calendar', 'object_id' => 'IT', 'relation' => 'editor'],
        ]);

        $mock = new MockHandler([
            new GuzzleResponse(400, [], (string) json_encode([
                'code'    => 'cannot_allow_duplicate_tuple',
                'message' => 'cannot write duplicate tuple',
            ])),
        ]);

        $outboxRepo = new OutboxRepository(self::$pdo);
        $notifier   = new OutboxNotifier(null, 'litcal:reconcile-stream');

        $response = $this->withoutEnv(self::ZITADEL_ENV_VARS, fn() => $this->withMockOpenFgaClient(
            $mock,
            function (OpenFgaClient $client) use ($id, $outboxRepo, $notifier): \Psr\Http\Message\ResponseInterface {
                $processor = new OutboxProcessor($outboxRepo, $client);
                $handler   = new AccessRequestAdminHandler($client);
                $handler->setOutboxDependencies($outboxRepo, $notifier, $processor);
                return $handler->handle(
                    $this->withOidcUser($this->requestFor(
                        'POST',
                        '/admin/access-requests/' . $id . '/approve',
                        [],
                        ['notes' => 'retry']
                    ))
                );
            }
        ));

        $body = $this->decodeJsonBody($response);
        self::assertTrue($body['success']);
        // BENIGN_SUCCESS → goes into tuples_created (row is succeeded in outbox).
        self::assertCount(1, self::arrayFieldFrom($body, 'tuples_created'));
        self::assertSame([], $body['fga_errors']);
        self::assertSame(0, $body['outbox_pending']);
        self::assertSame(0, $body['outbox_failed']);

        $row = $repo->getById($id);
        self::assertNotNull($row);
        self::assertSame('approved', $row['status']);
    }

    public function testApproveCommitsDbAndSurfacesTerminalOutboxFailureOnRealFgaError(): void
    {
        // Genuine terminal OpenFGA failure (e.g. validation_error 400 with a
        // known-terminal error code). Under the outbox pattern:
        // - The DB IS committed (approved) — success: true.
        // - The outbox row ends up in 'failed_terminal' state.
        // - outbox_failed == 1 and fga_errors carries the structured error.
        // - The admin can retry the outbox row via the outbox retry endpoint.
        $repo = new AccessRequestRepository(self::$pdo);
        $id   = $repo->create('user-a', 'a@x.test', null, 'developer', [
            ['object_type' => 'national_calendar', 'object_id' => 'IT', 'relation' => 'editor'],
        ]);

        $mock = new MockHandler([
            new GuzzleResponse(400, [], (string) json_encode([
                'code'    => 'validation_error',
                'message' => 'invalid relation for type',
            ])),
        ]);

        $outboxRepo = new OutboxRepository(self::$pdo);
        $notifier   = new OutboxNotifier(null, 'litcal:reconcile-stream');

        $response = $this->withoutEnv(self::ZITADEL_ENV_VARS, fn() => $this->withMockOpenFgaClient(
            $mock,
            function (OpenFgaClient $client) use ($id, $outboxRepo, $notifier): \Psr\Http\Message\ResponseInterface {
                $processor = new OutboxProcessor($outboxRepo, $client);
                $handler   = new AccessRequestAdminHandler($client);
                $handler->setOutboxDependencies($outboxRepo, $notifier, $processor);
                return $handler->handle(
                    $this->withOidcUser($this->requestFor(
                        'POST',
                        '/admin/access-requests/' . $id . '/approve',
                        [],
                        ['notes' => 'try']
                    ))
                );
            }
        ));

        $body = $this->decodeJsonBody($response);
        // DB committed → success: true (deliberate semantic shift from #628).
        self::assertTrue($body['success']);
        self::assertSame(0, $body['outbox_pending']);
        self::assertSame(1, $body['outbox_failed']);
        self::assertCount(1, self::arrayFieldFrom($body, 'fga_errors'));
        self::assertStringContainsString('failed terminally', self::stringFieldFrom($body, 'message'));

        // DB row IS approved — the outbox captures the failure, not the DB transaction.
        $row = $repo->getById($id);
        self::assertNotNull($row);
        self::assertSame('approved', $row['status']);

        // Verify the outbox row itself is in failed_terminal state.
        $outboxIds = self::arrayFieldFrom($body, 'outbox_ids');
        self::assertCount(1, $outboxIds);
        $outboxRow = $outboxRepo->getById((int) $outboxIds[0]);
        self::assertNotNull($outboxRow);
        self::assertSame(OutboxStatus::FAILED_TERMINAL, $outboxRow->status);
    }

    public function testRevokeSucceedsWhenAllTupleDeletesSucceed(): void
    {
        $repo = new AccessRequestRepository(self::$pdo);
        $id   = $repo->create('user-a', 'a@x.test', null, 'developer', [
            ['object_type' => 'national_calendar', 'object_id' => 'IT', 'relation' => 'editor'],
        ]);
        $repo->approve($id, 'admin-bob');

        $mock = new MockHandler([
            new GuzzleResponse(200, [], '{}'), // delete OK
        ]);

        $outboxRepo = new OutboxRepository(self::$pdo);
        $notifier   = new OutboxNotifier(null, 'litcal:reconcile-stream');

        $response = $this->withoutEnv(self::ZITADEL_ENV_VARS, fn() => $this->withMockOpenFgaClient(
            $mock,
            function (OpenFgaClient $client) use ($id, $outboxRepo, $notifier): \Psr\Http\Message\ResponseInterface {
                $processor = new OutboxProcessor($outboxRepo, $client);
                $handler   = new AccessRequestAdminHandler($client);
                $handler->setOutboxDependencies($outboxRepo, $notifier, $processor);
                return $handler->handle(
                    $this->withOidcUser($this->requestFor(
                        'POST',
                        '/admin/access-requests/' . $id . '/revoke',
                        [],
                        []
                    ))
                );
            }
        ));

        $body = $this->decodeJsonBody($response);
        self::assertTrue($body['success']);
        self::assertCount(1, self::arrayFieldFrom($body, 'tuples_deleted'));
        self::assertSame([], $body['fga_errors']);
        self::assertSame(0, $body['outbox_pending']);
        self::assertSame(0, $body['outbox_failed']);
        self::assertCount(1, self::arrayFieldFrom($body, 'outbox_ids'));

        $row = $repo->getById($id);
        self::assertNotNull($row);
        self::assertSame('revoked', $row['status']);
    }

    public function testRevokeTreatsMissingTupleAsBenign(): void
    {
        // TupleNotFoundException (cannot_allow_unknown_tuple_to_be_deleted) is
        // classified as BENIGN_SUCCESS by OutboxClassifier — the row lands in
        // 'succeeded' state and appears in tuples_deleted (idempotent re-revoke).
        $repo = new AccessRequestRepository(self::$pdo);
        $id   = $repo->create('user-a', 'a@x.test', null, 'developer', [
            ['object_type' => 'national_calendar', 'object_id' => 'IT', 'relation' => 'editor'],
        ]);
        $repo->approve($id, 'admin-bob');

        $mock = new MockHandler([
            new GuzzleResponse(400, [], (string) json_encode([
                'code'    => 'cannot_allow_unknown_tuple_to_be_deleted',
                'message' => 'cannot delete unknown tuple',
            ])),
        ]);

        $outboxRepo = new OutboxRepository(self::$pdo);
        $notifier   = new OutboxNotifier(null, 'litcal:reconcile-stream');

        $response = $this->withoutEnv(self::ZITADEL_ENV_VARS, fn() => $this->withMockOpenFgaClient(
            $mock,
            function (OpenFgaClient $client) use ($id, $outboxRepo, $notifier): \Psr\Http\Message\ResponseInterface {
                $processor = new OutboxProcessor($outboxRepo, $client);
                $handler   = new AccessRequestAdminHandler($client);
                $handler->setOutboxDependencies($outboxRepo, $notifier, $processor);
                return $handler->handle(
                    $this->withOidcUser($this->requestFor(
                        'POST',
                        '/admin/access-requests/' . $id . '/revoke',
                        [],
                        []
                    ))
                );
            }
        ));

        $body = $this->decodeJsonBody($response);
        self::assertTrue($body['success']);
        // BENIGN_SUCCESS → counted in tuples_deleted (idempotent — tuple already gone).
        self::assertCount(1, self::arrayFieldFrom($body, 'tuples_deleted'));
        self::assertSame([], $body['fga_errors']);
        self::assertSame(0, $body['outbox_pending']);
        self::assertSame(0, $body['outbox_failed']);

        $row = $repo->getById($id);
        self::assertNotNull($row);
        self::assertSame('revoked', $row['status']);
    }

    public function testRevokeCommitsDbAndSurfacesTransientOutboxFailureOnFgaError(): void
    {
        // A 500 (transient / retryable) from OpenFGA. Under the outbox pattern:
        // - The DB IS committed (revoked) — success: true.
        // - The outbox row ends up in 'retrying' state.
        // - outbox_pending == 1 and fga_errors carries the structured error.
        // - The cron backstop or async consumer will retry the deletion.
        $repo = new AccessRequestRepository(self::$pdo);
        $id   = $repo->create('user-a', 'a@x.test', null, 'developer', [
            ['object_type' => 'national_calendar', 'object_id' => 'IT', 'relation' => 'editor'],
        ]);
        $repo->approve($id, 'admin-bob');

        $mock = new MockHandler([
            new GuzzleResponse(500, [], (string) json_encode([
                'code'    => 'internal_error',
                'message' => 'internal server error',
            ])),
        ]);

        $outboxRepo = new OutboxRepository(self::$pdo);
        $notifier   = new OutboxNotifier(null, 'litcal:reconcile-stream');

        $response = $this->withoutEnv(self::ZITADEL_ENV_VARS, fn() => $this->withMockOpenFgaClient(
            $mock,
            function (OpenFgaClient $client) use ($id, $outboxRepo, $notifier): \Psr\Http\Message\ResponseInterface {
                $processor = new OutboxProcessor($outboxRepo, $client);
                $handler   = new AccessRequestAdminHandler($client);
                $handler->setOutboxDependencies($outboxRepo, $notifier, $processor);
                return $handler->handle(
                    $this->withOidcUser($this->requestFor(
                        'POST',
                        '/admin/access-requests/' . $id . '/revoke',
                        [],
                        []
                    ))
                );
            }
        ));

        $body = $this->decodeJsonBody($response);
        // DB committed → success: true (outbox pattern — mirrors approveRequest semantics).
        self::assertTrue($body['success']);
        self::assertSame(1, $body['outbox_pending']);
        self::assertSame(0, $body['outbox_failed']);
        self::assertCount(1, self::arrayFieldFrom($body, 'fga_errors'));

        // DB row IS revoked — the outbox captures the failure, not the DB transaction.
        $row = $repo->getById($id);
        self::assertNotNull($row);
        self::assertSame('revoked', $row['status']);

        // Verify the outbox row itself is in retrying state.
        $outboxIds = self::arrayFieldFrom($body, 'outbox_ids');
        self::assertCount(1, $outboxIds);
        $outboxRow = $outboxRepo->getById((int) $outboxIds[0]);
        self::assertNotNull($outboxRow);
        self::assertSame(OutboxStatus::RETRYING, $outboxRow->status);
    }

    // --- isFgaClientAvailable() fail-closed guards -----------------------

    public function testRequireAdminForAllResourcesForbidsResourceAdminWhenFgaUnavailable(): void
    {
        // Non-global admin (no 'admin' role) attempting to approve when no
        // OpenFgaClient is reachable — neither injected nor env-configured.
        // requireAdminForAllResources must fail closed with 403.
        $repo = new AccessRequestRepository(self::$pdo);
        $id   = $repo->create('user-a', 'a@x.test', null, 'developer', [
            ['object_type' => 'national_calendar', 'object_id' => 'IT', 'relation' => 'editor'],
        ]);

        $this->expectException(\LiturgicalCalendar\Api\Http\Exception\ForbiddenException::class);
        $this->expectExceptionMessage('Admin role required');

        $this->withoutEnv(
            array_merge(self::ZITADEL_ENV_VARS, self::OPENFGA_ENV_VARS),
            fn() => ( new AccessRequestAdminHandler() )->handle(
                $this->withOidcUser(
                    $this->requestFor(
                        'POST',
                        '/admin/access-requests/' . $id . '/approve',
                        [],
                        ['notes' => 'try']
                    ),
                    'resource-admin-1',
                    ['calendar_editor']
                )
            )
        );
    }

    public function testListRequestsAsResourceAdminWithoutFgaReturnsEmpty(): void
    {
        // Non-global admin listing when OpenFGA is unreachable —
        // filterByAdminAccess fails closed by returning [], so the visible
        // set is empty regardless of how many requests exist.
        $repo = new AccessRequestRepository(self::$pdo);
        $repo->create('user-a', 'a@x.test', null, 'developer', []);
        $repo->create('user-b', 'b@x.test', null, 'developer', []);

        $response = $this->withoutEnv(
            array_merge(self::ZITADEL_ENV_VARS, self::OPENFGA_ENV_VARS),
            fn() => ( new AccessRequestAdminHandler() )->handle(
                $this->withOidcUser(
                    $this->requestFor('GET', '/admin/access-requests'),
                    'resource-admin-1',
                    ['calendar_editor']
                )
            )
        );

        $body = $this->decodeJsonBody($response);
        self::assertSame([], $body['requests']);
        self::assertSame(0, $body['count']);
        // total reflects the SQL paginator's pre-filter count, not the
        // empty post-filter visible set.
        self::assertSame(2, $body['total']);
    }

    // --- Outbox pattern (Task 20: issue #567 Options B+C) ----------------

    public function testApproveCommitsOutboxRowsAtomicallyWithDbWrite(): void
    {
        // Two permissions: first call returns 503 (transient — RETRY → retrying),
        // second call returns 200 (success → succeeded). After the sync fast path:
        // - success: true (DB committed)
        // - outbox_pending: 1 (the retrying row)
        // - outbox_failed: 0
        // - outbox_ids: 2 entries
        $repo = new AccessRequestRepository(self::$pdo);
        $id   = $repo->create('user-a', 'a@x.test', null, 'developer', [
            ['object_type' => 'national_calendar', 'object_id' => 'IT', 'relation' => 'editor'],
            ['object_type' => 'diocesan_calendar', 'object_id' => 'roma_it', 'relation' => 'viewer'],
        ]);

        $mock = new MockHandler([
            new GuzzleResponse(503, [], ''), // tuple 1: transient → RETRY
            new GuzzleResponse(200, [], '{}'), // tuple 2: OK → BENIGN_SUCCESS
        ]);

        $outboxRepo = new OutboxRepository(self::$pdo);
        $notifier   = new OutboxNotifier(null, 'litcal:reconcile-stream');

        $response = $this->withoutEnv(self::ZITADEL_ENV_VARS, fn() => $this->withMockOpenFgaClient(
            $mock,
            function (OpenFgaClient $client) use ($id, $outboxRepo, $notifier): \Psr\Http\Message\ResponseInterface {
                $processor = new OutboxProcessor($outboxRepo, $client);
                $handler   = new AccessRequestAdminHandler($client);
                $handler->setOutboxDependencies($outboxRepo, $notifier, $processor);
                return $handler->handle(
                    $this->withOidcUser($this->requestFor(
                        'POST',
                        '/admin/access-requests/' . $id . '/approve',
                        [],
                        ['notes' => 'ok']
                    ))
                );
            }
        ));

        $body = $this->decodeJsonBody($response);
        self::assertTrue($body['success']);
        self::assertSame(1, $body['outbox_pending']);
        self::assertSame(0, $body['outbox_failed']);
        self::assertCount(2, self::arrayFieldFrom($body, 'outbox_ids'));

        // Verify the DB row is approved.
        $row = $repo->getById($id);
        self::assertNotNull($row);
        self::assertSame('approved', $row['status']);

        // Verify the outbox row statuses: one retrying, one succeeded.
        $outboxIds = self::arrayFieldFrom($body, 'outbox_ids');
        $statuses  = [];
        foreach ($outboxIds as $rowId) {
            $outboxRow = $outboxRepo->getById((int) $rowId);
            self::assertNotNull($outboxRow);
            $statuses[] = $outboxRow->status->value;
        }
        sort($statuses);
        self::assertSame([OutboxStatus::RETRYING->value, OutboxStatus::SUCCEEDED->value], $statuses);
    }

    public function testApproveIsIdempotentOnReissue(): void
    {
        // First approval: all tuples succeed → outbox rows in 'succeeded'.
        // Second approval of the same request: repo.approve() returns false
        // (status is already 'approved'), so the handler throws ValidationException.
        // This test therefore verifies that the idempotency_key in insertBatch
        // collapses duplicate inserts to the same row IDs when the first call
        // succeeded and the second call cannot re-enter the approval flow.
        //
        // To test the actual idempotency_key collapse, we directly call insertBatch
        // twice on the outbox repo with the same key and assert same IDs are returned.
        $outboxRepo     = new OutboxRepository(self::$pdo);
        $idempotencyKey = 'access_request:test-uuid:write_tuple:user:alice:editor:national_calendar:IT';

        $rows = [
            [
                'operation'       => \LiturgicalCalendar\Api\Services\Outbox\OutboxOperation::WRITE_TUPLE,
                'fga_user'        => 'user:alice',
                'fga_relation'    => 'editor',
                'fga_object'      => 'national_calendar:IT',
                'idempotency_key' => $idempotencyKey,
                'metadata'        => ['access_request_id' => 'test-uuid'],
            ],
        ];

        $firstIds  = $outboxRepo->insertBatch($rows);
        $secondIds = $outboxRepo->insertBatch($rows); // same key → same row

        self::assertSame(
            $firstIds,
            $secondIds,
            'idempotency_key must collapse second insert to same row IDs'
        );

        // Now verify the handler-level idempotency: re-approving an already-approved
        // request must be rejected with a ValidationException (not crash or duplicate rows).
        $repo = new AccessRequestRepository(self::$pdo);
        $id   = $repo->create('user-a', 'a@x.test', null, 'developer', [
            ['object_type' => 'national_calendar', 'object_id' => 'IT', 'relation' => 'editor'],
        ]);

        $mock1 = new MockHandler([new GuzzleResponse(200, [], '{}')]);

        $notifier = new OutboxNotifier(null, 'litcal:reconcile-stream');

        // First approval succeeds.
        $this->withoutEnv(self::ZITADEL_ENV_VARS, fn() => $this->withMockOpenFgaClient(
            $mock1,
            function (OpenFgaClient $client) use ($id, $outboxRepo, $notifier): \Psr\Http\Message\ResponseInterface {
                $processor = new OutboxProcessor($outboxRepo, $client);
                $handler   = new AccessRequestAdminHandler($client);
                $handler->setOutboxDependencies($outboxRepo, $notifier, $processor);
                return $handler->handle(
                    $this->withOidcUser($this->requestFor(
                        'POST',
                        '/admin/access-requests/' . $id . '/approve',
                        [],
                        []
                    ))
                );
            }
        ));

        // Second approval on the same (now 'approved') request must be rejected.
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Cannot approve a request with status: approved');

        $mock2 = new MockHandler([]);

        $this->withoutEnv(self::ZITADEL_ENV_VARS, fn() => $this->withMockOpenFgaClient(
            $mock2,
            function (OpenFgaClient $client) use ($id, $outboxRepo, $notifier): \Psr\Http\Message\ResponseInterface {
                $processor = new OutboxProcessor($outboxRepo, $client);
                $handler   = new AccessRequestAdminHandler($client);
                $handler->setOutboxDependencies($outboxRepo, $notifier, $processor);
                return $handler->handle(
                    $this->withOidcUser($this->requestFor(
                        'POST',
                        '/admin/access-requests/' . $id . '/approve',
                        [],
                        []
                    ))
                );
            }
        ));
    }

    // --- Outbox pattern (Task 21: revokeRequest) --------------------------

    public function testRevokeCommitsOutboxRowsAtomicallyWithDbWrite(): void
    {
        // Two permissions: first call returns 503 (transient — RETRY → retrying),
        // second call returns 200 (success → succeeded). After the sync fast path:
        // - success: true (DB committed)
        // - outbox_pending: 1 (the retrying row)
        // - outbox_failed: 0
        // - outbox_ids: 2 entries
        // Both outbox rows have operation 'delete_tuple'.
        $repo = new AccessRequestRepository(self::$pdo);
        $id   = $repo->create('user-a', 'a@x.test', null, 'developer', [
            ['object_type' => 'national_calendar', 'object_id' => 'IT', 'relation' => 'editor'],
            ['object_type' => 'diocesan_calendar', 'object_id' => 'roma_it', 'relation' => 'viewer'],
        ]);
        $repo->approve($id, 'admin-bob');

        $mock = new MockHandler([
            new GuzzleResponse(503, [], ''),   // tuple 1: transient → RETRY
            new GuzzleResponse(200, [], '{}'), // tuple 2: OK → BENIGN_SUCCESS
        ]);

        $outboxRepo = new OutboxRepository(self::$pdo);
        $notifier   = new OutboxNotifier(null, 'litcal:reconcile-stream');

        $response = $this->withoutEnv(self::ZITADEL_ENV_VARS, fn() => $this->withMockOpenFgaClient(
            $mock,
            function (OpenFgaClient $client) use ($id, $outboxRepo, $notifier): \Psr\Http\Message\ResponseInterface {
                $processor = new OutboxProcessor($outboxRepo, $client);
                $handler   = new AccessRequestAdminHandler($client);
                $handler->setOutboxDependencies($outboxRepo, $notifier, $processor);
                return $handler->handle(
                    $this->withOidcUser($this->requestFor(
                        'POST',
                        '/admin/access-requests/' . $id . '/revoke',
                        [],
                        []
                    ))
                );
            }
        ));

        $body = $this->decodeJsonBody($response);
        self::assertTrue($body['success']);
        self::assertSame(1, $body['outbox_pending']);
        self::assertSame(0, $body['outbox_failed']);
        self::assertCount(2, self::arrayFieldFrom($body, 'outbox_ids'));

        // DB row is revoked (commit happened before the FGA sync attempt).
        $row = $repo->getById($id);
        self::assertNotNull($row);
        self::assertSame('revoked', $row['status']);

        // Outbox rows: one retrying, one succeeded — both delete_tuple.
        $outboxIds = self::arrayFieldFrom($body, 'outbox_ids');
        $statuses  = [];
        foreach ($outboxIds as $rowId) {
            $outboxRow = $outboxRepo->getById((int) $rowId);
            self::assertNotNull($outboxRow);
            $statuses[] = $outboxRow->status->value;
            self::assertSame(OutboxOperation::DELETE_TUPLE, $outboxRow->operation);
        }
        sort($statuses);
        self::assertSame([OutboxStatus::RETRYING->value, OutboxStatus::SUCCEEDED->value], $statuses);
    }

    public function testRevokeIsIdempotentOnReissue(): void
    {
        // Verify idempotency_key collapse for delete_tuple rows.
        $outboxRepo     = new OutboxRepository(self::$pdo);
        $idempotencyKey = 'access_request:test-uuid:delete_tuple:user:alice:editor:national_calendar:IT';

        $rows = [
            [
                'operation'       => OutboxOperation::DELETE_TUPLE,
                'fga_user'        => 'user:alice',
                'fga_relation'    => 'editor',
                'fga_object'      => 'national_calendar:IT',
                'idempotency_key' => $idempotencyKey,
                'metadata'        => ['access_request_id' => 'test-uuid'],
            ],
        ];

        $firstIds  = $outboxRepo->insertBatch($rows);
        $secondIds = $outboxRepo->insertBatch($rows); // same key → same row

        self::assertSame(
            $firstIds,
            $secondIds,
            'idempotency_key must collapse second insert to same row IDs'
        );

        // Handler-level: re-revoking an already-revoked request must be rejected
        // with a ValidationException (status is no longer 'approved').
        $repo = new AccessRequestRepository(self::$pdo);
        $id   = $repo->create('user-a', 'a@x.test', null, 'developer', [
            ['object_type' => 'national_calendar', 'object_id' => 'IT', 'relation' => 'editor'],
        ]);
        $repo->approve($id, 'admin-bob');

        $notifier = new OutboxNotifier(null, 'litcal:reconcile-stream');
        $mock1    = new MockHandler([new GuzzleResponse(200, [], '{}')]);

        // First revoke succeeds.
        $this->withoutEnv(self::ZITADEL_ENV_VARS, fn() => $this->withMockOpenFgaClient(
            $mock1,
            function (OpenFgaClient $client) use ($id, $outboxRepo, $notifier): \Psr\Http\Message\ResponseInterface {
                $processor = new OutboxProcessor($outboxRepo, $client);
                $handler   = new AccessRequestAdminHandler($client);
                $handler->setOutboxDependencies($outboxRepo, $notifier, $processor);
                return $handler->handle(
                    $this->withOidcUser($this->requestFor(
                        'POST',
                        '/admin/access-requests/' . $id . '/revoke',
                        [],
                        []
                    ))
                );
            }
        ));

        // Second revoke on the same (now 'revoked') request must be rejected.
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Cannot revoke a request with status: revoked');

        $mock2 = new MockHandler([]);

        $this->withoutEnv(self::ZITADEL_ENV_VARS, fn() => $this->withMockOpenFgaClient(
            $mock2,
            function (OpenFgaClient $client) use ($id, $outboxRepo, $notifier): \Psr\Http\Message\ResponseInterface {
                $processor = new OutboxProcessor($outboxRepo, $client);
                $handler   = new AccessRequestAdminHandler($client);
                $handler->setOutboxDependencies($outboxRepo, $notifier, $processor);
                return $handler->handle(
                    $this->withOidcUser($this->requestFor(
                        'POST',
                        '/admin/access-requests/' . $id . '/revoke',
                        [],
                        []
                    ))
                );
            }
        ));
    }
}
