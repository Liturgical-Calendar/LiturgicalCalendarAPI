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
use LiturgicalCalendar\Api\Services\OpenFgaClient;
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

        // Mirror testApproveHappyPathWithoutFgaOrZitadel — clear both gate's
        // env vars so the handler hits the not-configured branch for each
        // service. Seeding with a real permission (rather than the previous
        // empty []) makes the "tuples_deleted=[]" assertion meaningful: an
        // FGA-configured run would have tuples to delete; here it shouldn't
        // even try.
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
        self::assertSame([], $body['tuples_deleted']);
        self::assertSame([], $body['fga_errors']);

        $row = $repo->getById($id);
        self::assertNotNull($row);
        self::assertSame('revoked', $row['status']);
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
        $body = $this->decodeJsonBody($response);
        self::assertCount(2, $body['requests']);
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

        $body = $this->decodeJsonBody($response);
        self::assertCount(1, $body['requests']);
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

        $body = $this->decodeJsonBody($response);
        self::assertCount(1, $body['requests']);
        self::assertSame(1, $body['count']);
        // total counts only matching (pending) rows, ignoring limit/offset.
        self::assertSame(2, $body['total']);
        self::assertTrue($body['has_more']); // 0 + 1 = 1 < 2
        self::assertSame('pending', $body['requests'][0]['status']);
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

        $body = $this->decodeJsonBody($response);
        self::assertCount(1, $body['requests']);
        $row = $body['requests'][0];

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
     */
    private function withMockOpenFgaClient(MockHandler $mock, callable $fn): mixed
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

        $response = $this->withoutEnv(self::ZITADEL_ENV_VARS, fn() => $this->withMockOpenFgaClient(
            $mock,
            fn(OpenFgaClient $client) => ( new AccessRequestAdminHandler($client) )->handle(
                $this->withOidcUser($this->requestFor(
                    'POST',
                    '/admin/access-requests/' . $id . '/approve',
                    [],
                    ['notes' => 'ok']
                ))
            )
        ));

        self::assertSame(200, $response->getStatusCode());
        $body = $this->decodeJsonBody($response);
        self::assertTrue($body['success']);
        self::assertCount(2, $body['tuples_created']);
        self::assertSame([], $body['fga_errors']);

        $row = $repo->getById($id);
        self::assertNotNull($row);
        self::assertSame('approved', $row['status']);
    }

    public function testApproveTreatsDuplicateTupleAsBenign(): void
    {
        // Idempotent re-approval: one tuple already exists. The handler
        // should treat that write as success, still mutate the DB, and
        // return success: true.
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

        $response = $this->withoutEnv(self::ZITADEL_ENV_VARS, fn() => $this->withMockOpenFgaClient(
            $mock,
            fn(OpenFgaClient $client) => ( new AccessRequestAdminHandler($client) )->handle(
                $this->withOidcUser($this->requestFor(
                    'POST',
                    '/admin/access-requests/' . $id . '/approve',
                    [],
                    ['notes' => 'retry']
                ))
            )
        ));

        $body = $this->decodeJsonBody($response);
        self::assertTrue($body['success']);
        self::assertCount(1, $body['tuples_created']);
        self::assertSame([], $body['fga_errors']);

        $row = $repo->getById($id);
        self::assertNotNull($row);
        self::assertSame('approved', $row['status']);
    }

    public function testApproveBailsAndKeepsRequestPendingOnRealFgaError(): void
    {
        // Genuine OpenFGA failure (e.g. validation error). The handler must
        // NOT mark the DB as approved — leaving it pending lets the admin
        // retry once the underlying error is resolved.
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

        $response = $this->withoutEnv(self::ZITADEL_ENV_VARS, fn() => $this->withMockOpenFgaClient(
            $mock,
            fn(OpenFgaClient $client) => ( new AccessRequestAdminHandler($client) )->handle(
                $this->withOidcUser($this->requestFor(
                    'POST',
                    '/admin/access-requests/' . $id . '/approve',
                    [],
                    ['notes' => 'try']
                ))
            )
        ));

        $body = $this->decodeJsonBody($response);
        self::assertFalse($body['success']);
        self::assertCount(1, $body['fga_errors']);
        self::assertStringContainsString('Approval aborted', $body['message']);

        // DB row must still be pending — the request was NOT approved.
        $row = $repo->getById($id);
        self::assertNotNull($row);
        self::assertSame('pending', $row['status']);
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

        $response = $this->withoutEnv(self::ZITADEL_ENV_VARS, fn() => $this->withMockOpenFgaClient(
            $mock,
            fn(OpenFgaClient $client) => ( new AccessRequestAdminHandler($client) )->handle(
                $this->withOidcUser($this->requestFor(
                    'POST',
                    '/admin/access-requests/' . $id . '/revoke',
                    [],
                    []
                ))
            )
        ));

        $body = $this->decodeJsonBody($response);
        self::assertTrue($body['success']);
        self::assertCount(1, $body['tuples_deleted']);
        self::assertSame([], $body['fga_errors']);

        $row = $repo->getById($id);
        self::assertNotNull($row);
        self::assertSame('revoked', $row['status']);
    }

    public function testRevokeTreatsMissingTupleAsBenign(): void
    {
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

        $response = $this->withoutEnv(self::ZITADEL_ENV_VARS, fn() => $this->withMockOpenFgaClient(
            $mock,
            fn(OpenFgaClient $client) => ( new AccessRequestAdminHandler($client) )->handle(
                $this->withOidcUser($this->requestFor(
                    'POST',
                    '/admin/access-requests/' . $id . '/revoke',
                    [],
                    []
                ))
            )
        ));

        $body = $this->decodeJsonBody($response);
        self::assertTrue($body['success']);
        self::assertCount(1, $body['tuples_deleted']);
        self::assertSame([], $body['fga_errors']);

        $row = $repo->getById($id);
        self::assertNotNull($row);
        self::assertSame('revoked', $row['status']);
    }

    public function testRevokeBailsAndKeepsRequestApprovedOnRealFgaError(): void
    {
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

        $response = $this->withoutEnv(self::ZITADEL_ENV_VARS, fn() => $this->withMockOpenFgaClient(
            $mock,
            fn(OpenFgaClient $client) => ( new AccessRequestAdminHandler($client) )->handle(
                $this->withOidcUser($this->requestFor(
                    'POST',
                    '/admin/access-requests/' . $id . '/revoke',
                    [],
                    []
                ))
            )
        ));

        $body = $this->decodeJsonBody($response);
        self::assertFalse($body['success']);
        self::assertCount(1, $body['fga_errors']);
        self::assertStringContainsString('Revocation aborted', $body['message']);

        // DB row must still be approved — the revoke was NOT committed.
        $row = $repo->getById($id);
        self::assertNotNull($row);
        self::assertSame('approved', $row['status']);
    }
}
