<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers\Admin;

use GuzzleHttp\Client;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use LiturgicalCalendar\Api\Handlers\Admin\PermissionAdminHandler;
use LiturgicalCalendar\Api\Http\Exception\MethodNotAllowedException;
use LiturgicalCalendar\Api\Http\Exception\UnauthorizedException;
use LiturgicalCalendar\Api\Http\Exception\ValidationException;
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

/**
 * Most of PermissionAdminHandler's code paths terminate in OpenFGA calls
 * (readTuples / writeTuple / deleteTuple / check), which we can't exercise
 * from in-process tests without an OpenFGA server. These tests therefore
 * focus on the gates that run BEFORE the FGA call: preflight, auth, path
 * routing, and request-shape validation.
 */
#[CoversClass(PermissionAdminHandler::class)]
final class PermissionAdminHandlerTest extends AbstractHandlerTestCase
{
    use EnvIsolationTrait;

    protected static bool $requiresDatabase = true;

    /**
     * Build a real OpenFgaClient backed by a Guzzle MockHandler that
     * replays the queued HTTP responses. We set the OPENFGA_* env vars so
     * `OpenFgaClient::isConfigured()` returns true (otherwise the outbox
     * fast-path's getClient() call would hit the env gate), and undo both
     * at the end of the test. Mirrors the pattern in AccessRequestAdminHandlerTest.
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

    /**
     * Extract and narrow an array-typed top-level body field (same helper
     * as in AccessRequestAdminHandlerTest, duplicated to keep tests self-contained).
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
     * Extract and narrow a string-typed top-level body field.
     *
     * @param array<string, mixed> $body
     */
    private static function stringFieldFrom(array $body, string $key): string
    {
        self::assertArrayHasKey($key, $body);
        self::assertIsString($body[$key]);
        return $body[$key];
    }

    /**
     * Dispatch DELETE /admin/permissions with the given tuple fields.
     * Zitadel and OpenFGA env vars are stripped so only the mock client is used.
     *
     * @param \LiturgicalCalendar\Api\Repositories\OutboxRepository $outboxRepo
     * @param \LiturgicalCalendar\Api\Services\Outbox\OutboxNotifier $notifier
     * @param \LiturgicalCalendar\Api\Services\OpenFgaClient $client
     */
    private function dispatchRevokePermission(
        string $user,
        string $objectType,
        string $objectId,
        string $relation,
        \LiturgicalCalendar\Api\Repositories\OutboxRepository $outboxRepo,
        \LiturgicalCalendar\Api\Services\Outbox\OutboxNotifier $notifier,
        \LiturgicalCalendar\Api\Services\OpenFgaClient $client
    ): \Psr\Http\Message\ResponseInterface {
        $processor = new OutboxProcessor($outboxRepo, $client);
        $handler   = new PermissionAdminHandler($client);
        $handler->setOutboxDependencies($outboxRepo, $notifier, $processor);
        return $handler->handle(
            $this->withOidcUser($this->requestFor(
                'DELETE',
                '/admin/permissions',
                [],
                [
                    'user'        => $user,
                    'object_type' => $objectType,
                    'object_id'   => $objectId,
                    'relation'    => $relation,
                ]
            ))
        );
    }

    public function testOptionsPreflightSucceeds(): void
    {
        $response = ( new PermissionAdminHandler() )->handle(
            $this->requestFor('OPTIONS', '/admin/permissions', [
                'Origin'                        => 'https://app.example.test',
                'Access-Control-Request-Method' => 'GET',
            ])
        );
        self::assertSame(204, $response->getStatusCode());
    }

    public function testPutIsMethodNotAllowed(): void
    {
        $this->expectException(MethodNotAllowedException::class);
        ( new PermissionAdminHandler() )->handle(
            $this->withOidcUser($this->requestFor('PUT', '/admin/permissions'))
        );
    }

    public function testMissingOidcUserIsUnauthorized(): void
    {
        $this->expectException(UnauthorizedException::class);
        ( new PermissionAdminHandler() )->handle($this->requestFor('GET', '/admin/permissions'));
    }

    public function testEmptySubIsUnauthorized(): void
    {
        $request = $this->requestFor('GET', '/admin/permissions')
            ->withAttribute('oidc_user', ['sub' => '', 'roles' => ['admin']]);

        $this->expectException(UnauthorizedException::class);
        $this->expectExceptionMessage('Invalid authentication token');

        ( new PermissionAdminHandler() )->handle($request);
    }

    public function testNonAdminWithoutObjectTypeIsValidationError(): void
    {
        // Resource admins (non-global) must specify object_type — the handler
        // rejects with ValidationException before reaching FGA.
        $request = $this->withOidcUser(
            $this->requestFor('GET', '/admin/permissions'),
            'resource-admin-1',
            ['developer'] // not admin
        );

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('must specify object_type');

        ( new PermissionAdminHandler() )->handle($request);
    }

    public function testInvalidObjectTypeIsValidationError(): void
    {
        $request = $this->withOidcUser(
            $this->requestFor('GET', '/admin/permissions?object_type=not_a_type', [])
        );

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid object_type');

        ( new PermissionAdminHandler() )->handle($request);
    }

    public function testInvalidRelationIsValidationError(): void
    {
        $request = $this->withOidcUser(
            $this->requestFor('GET', '/admin/permissions?object_type=national_calendar&relation=bogus', [])
        );

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid relation');

        ( new PermissionAdminHandler() )->handle($request);
    }

    public function testListWithLimitZeroIsValidationError(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('limit must be between 1 and 500');

        ( new PermissionAdminHandler() )->handle(
            $this->withOidcUser($this->requestFor('GET', '/admin/permissions?object_type=national_calendar&limit=0'))
        );
    }

    public function testListWithLimitTooLargeIsValidationError(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('limit must be between 1 and 500');

        ( new PermissionAdminHandler() )->handle(
            $this->withOidcUser($this->requestFor('GET', '/admin/permissions?object_type=national_calendar&limit=501'))
        );
    }

    public function testListWithNonNumericLimitIsValidationError(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('limit must be a positive integer');

        ( new PermissionAdminHandler() )->handle(
            $this->withOidcUser($this->requestFor('GET', '/admin/permissions?object_type=national_calendar&limit=abc'))
        );
    }

    public function testListWithNegativeLimitIsValidationError(): void
    {
        // ctype_digit('-1') is false, so this hits the "positive integer" branch.
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('limit must be a positive integer');

        ( new PermissionAdminHandler() )->handle(
            $this->withOidcUser($this->requestFor('GET', '/admin/permissions?object_type=national_calendar&limit=-1'))
        );
    }

    public function testListWithNonStringPageTokenIsValidationError(): void
    {
        // PSR-7 query parsing represents `?page_token[]=x` as an array;
        // the handler must reject non-string page_token rather than
        // silently coercing it to '' (which would restart pagination
        // and mask a client-side bug).
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('page_token must be a string');

        ( new PermissionAdminHandler() )->handle(
            $this->withOidcUser($this->requestFor('GET', '/admin/permissions?object_type=national_calendar&page_token[]=x'))
        );
    }

    public function testListWithLimitAtUpperBoundPassesValidation(): void
    {
        // At limit=500 the parseLimit() helper must accept the value. With
        // OpenFGA not configured in this test path the downstream
        // OpenFgaClient::fromEnv() throws a generic \RuntimeException — that's
        // the success signal. ValidationException IS-A \RuntimeException
        // (via ApiException), so we can't use expectException(RuntimeException)
        // — a regression that incorrectly re-rejects limit=500 would slip past.
        try {
            ( new PermissionAdminHandler() )->handle(
                $this->withOidcUser($this->requestFor('GET', '/admin/permissions?object_type=national_calendar&limit=500'))
            );
            self::fail('Expected a downstream \RuntimeException (OpenFGA not configured); none was thrown.');
        } catch (ValidationException $e) {
            self::fail('limit=500 should pass validation but ValidationException was thrown: ' . $e->getMessage());
        } catch (\RuntimeException $e) {
            // Expected: validation passed; the handler reached OpenFgaClient and
            // failed at the env gate. Any \RuntimeException that ISN'T a
            // ValidationException counts as "validation passed".
            self::assertNotInstanceOf(ValidationException::class, $e);
        }
    }

    public function testListDefaultsToLimit100AndNoToken(): void
    {
        // Stub OpenFGA's /read endpoint to capture the request and return an
        // empty result. Verifies (a) the handler sends page_size=100 when no
        // limit is provided, (b) it omits continuation_token entirely when no
        // page_token is provided, and (c) the response envelope has the new
        // shape with has_more=false and next_page_token=null.
        $requestHistory = [];
        $mock           = new MockHandler([
            new Response(200, [], (string) json_encode([
                'tuples'             => [],
                'continuation_token' => '',
            ])),
        ]);
        $handlerStack   = HandlerStack::create($mock);
        $handlerStack->push(\GuzzleHttp\Middleware::history($requestHistory));
        $httpClient = new Client(['handler' => $handlerStack]);
        $psr17      = new \Nyholm\Psr7\Factory\Psr17Factory();
        $fgaClient  = new OpenFgaClient(
            'http://localhost:8083',
            'store-123',
            'model-456',
            $httpClient,
            $psr17,
            $psr17
        );

        $response = ( new PermissionAdminHandler($fgaClient) )->handle(
            $this->withOidcUser($this->requestFor('GET', '/admin/permissions?object_type=national_calendar'))
        );

        self::assertSame(200, $response->getStatusCode());
        $body = $this->decodeJsonBody($response);
        self::assertSame([], $body['permissions']);
        self::assertSame(0, $body['count']);
        self::assertFalse($body['has_more']);
        self::assertNull($body['next_page_token']);

        self::assertCount(1, $requestHistory);
        $payload = json_decode((string) $requestHistory[0]['request']->getBody(), true);
        self::assertIsArray($payload);
        self::assertSame(100, $payload['page_size']);
        self::assertArrayNotHasKey('continuation_token', $payload);
    }

    public function testListAsGlobalAdminWithNoObjectTypeFilter(): void
    {
        // Global admin (withOidcUser defaults to roles=['admin']) without any
        // object_type filter exercises the ternary's `: ''` fallback in
        // $objectFilter construction — readTuples is called with object=''
        // (no OpenFGA object filter). The mock returns 0 tuples; we don't
        // care about the result payload here, only that the wire-level
        // request reflects the empty-object-filter path.
        $requestHistory = [];
        $mock           = new MockHandler([
            new Response(200, [], (string) json_encode([
                'tuples'             => [],
                'continuation_token' => '',
            ])),
        ]);
        $handlerStack   = HandlerStack::create($mock);
        $handlerStack->push(\GuzzleHttp\Middleware::history($requestHistory));
        $httpClient = new Client(['handler' => $handlerStack]);
        $psr17      = new \Nyholm\Psr7\Factory\Psr17Factory();
        $fgaClient  = new OpenFgaClient(
            'http://localhost:8083',
            'store-123',
            'model-456',
            $httpClient,
            $psr17,
            $psr17
        );

        $response = ( new PermissionAdminHandler($fgaClient) )->handle(
            $this->withOidcUser($this->requestFor('GET', '/admin/permissions'))
        );

        self::assertSame(200, $response->getStatusCode());

        self::assertCount(1, $requestHistory);
        $payload = json_decode((string) $requestHistory[0]['request']->getBody(), true);
        self::assertIsArray($payload);
        // With no filters at all, the request payload should have no
        // tuple_key (OpenFGA returns all tuples) and page_size=100.
        self::assertArrayNotHasKey('tuple_key', $payload);
        self::assertSame(100, $payload['page_size']);
    }

    // --- filterByAdminAccess uses ListObjects (issue #571) ---------------

    public function testListAsResourceAdminUsesListObjectsInOneRoundTrip(): void
    {
        // Non-global admin (no 'admin' role) listing without object_id —
        // triggers filterByAdminAccess. Before #571, this made one check()
        // call per unique object_id in the tuples page; after, it makes a
        // single listObjects() call regardless of N. We pin the new wire
        // pattern: exactly two HTTP calls (readTuples + listObjects),
        // never N+1.
        //
        // Tuples page returns 3 objects (IT, US, FR). listObjects returns
        // 2 IDs the admin can see (IT, US). Expected filtered output: 2
        // tuples (the one on FR is dropped).
        $requestHistory = [];
        $mock           = new MockHandler([
            // readTuples: 3 tuples on national_calendar
            new Response(200, [], (string) json_encode([
                'tuples'             => [
                    ['key' => ['user' => 'user:alice', 'relation' => 'editor', 'object' => 'national_calendar:IT']],
                    ['key' => ['user' => 'user:bob',   'relation' => 'viewer', 'object' => 'national_calendar:US']],
                    ['key' => ['user' => 'user:carol', 'relation' => 'editor', 'object' => 'national_calendar:FR']],
                ],
                'continuation_token' => '',
            ])),
            // listObjects: admin allowed on IT + US (NOT FR)
            new Response(200, [], (string) json_encode([
                'objects' => [
                    'national_calendar:IT',
                    'national_calendar:US',
                ],
            ])),
        ]);
        $handlerStack = HandlerStack::create($mock);
        $handlerStack->push(\GuzzleHttp\Middleware::history($requestHistory));
        $httpClient = new Client(['handler' => $handlerStack]);
        $psr17      = new \Nyholm\Psr7\Factory\Psr17Factory();
        $fgaClient  = new OpenFgaClient(
            'http://localhost:8083',
            'store-123',
            'model-456',
            $httpClient,
            $psr17,
            $psr17
        );

        $request = $this->withOidcUser(
            $this->requestFor('GET', '/admin/permissions?object_type=national_calendar'),
            'resource-admin-1',
            ['calendar_editor']
        );

        $response = ( new PermissionAdminHandler($fgaClient) )->handle($request);

        self::assertSame(200, $response->getStatusCode());
        $body = $this->decodeJsonBody($response);
        self::assertCount(2, $body['permissions']);
        $allowedObjects = array_column($body['permissions'], 'object');
        sort($allowedObjects);
        self::assertSame(['national_calendar:IT', 'national_calendar:US'], $allowedObjects);

        // Exactly two HTTP calls: readTuples + listObjects. Never N+1.
        self::assertCount(2, $requestHistory);
        self::assertStringContainsString('/read', (string) $requestHistory[0]['request']->getUri());
        self::assertStringContainsString('/list-objects', (string) $requestHistory[1]['request']->getUri());

        // listObjects payload exercises the right relation/type.
        $listPayload = json_decode((string) $requestHistory[1]['request']->getBody(), true);
        self::assertIsArray($listPayload);
        self::assertSame('user:resource-admin-1', $listPayload['user']);
        self::assertSame('admin', $listPayload['relation']);
        self::assertSame('national_calendar', $listPayload['type']);
    }

    // --- Outbox pattern: grantPermission (Task 22) -----------------------

    public function testGrantPersistsOutboxRowAndAppliesViaSyncFastPath(): void
    {
        // Global admin granting a permission. FGA returns 200. After the
        // outbox sync fast path the outbox row must be in 'succeeded' state
        // and the response shape must include outbox counters.
        $mock = new MockHandler([
            new GuzzleResponse(200, [], '{}'), // writeTuple OK
        ]);

        $outboxRepo = new OutboxRepository(self::$pdo);
        $notifier   = new OutboxNotifier(null, 'litcal:reconcile-stream');

        $response = $this->withMockOpenFgaClient(
            $mock,
            function (OpenFgaClient $client) use ($outboxRepo, $notifier): \Psr\Http\Message\ResponseInterface {
                $processor = new OutboxProcessor($outboxRepo, $client);
                $handler   = new PermissionAdminHandler($client);
                $handler->setOutboxDependencies($outboxRepo, $notifier, $processor);
                return $handler->handle(
                    $this->withOidcUser($this->requestFor(
                        'POST',
                        '/admin/permissions',
                        [],
                        [
                            'user'        => 'user-alice',
                            'object_type' => 'national_calendar',
                            'object_id'   => 'IT',
                            'relation'    => 'editor',
                        ]
                    ))
                );
            }
        );

        self::assertSame(200, $response->getStatusCode());
        $body = $this->decodeJsonBody($response);
        self::assertTrue($body['success']);
        self::assertSame(0, $body['outbox_pending']);
        self::assertSame(0, $body['outbox_failed']);
        self::assertCount(1, self::arrayFieldFrom($body, 'outbox_ids'));

        // Verify outbox row was created and marked succeeded.
        $outboxIds = self::arrayFieldFrom($body, 'outbox_ids');
        $outboxRow = $outboxRepo->getById((int) $outboxIds[0]);
        self::assertNotNull($outboxRow);
        self::assertSame(OutboxStatus::SUCCEEDED, $outboxRow->status);
        self::assertSame(OutboxOperation::WRITE_TUPLE, $outboxRow->operation);
    }

    public function testGrantIsIdempotentOnReissue(): void
    {
        // Two sequential grant calls for the same tuple from the same admin
        // must collapse to the same outbox row IDs (idempotency_key).
        $outboxRepo = new OutboxRepository(self::$pdo);
        $notifier   = new OutboxNotifier(null, 'litcal:reconcile-stream');

        $requestBody = [
            'user'        => 'user-alice',
            'object_type' => 'national_calendar',
            'object_id'   => 'IT',
            'relation'    => 'editor',
        ];

        $mock1 = new MockHandler([new GuzzleResponse(200, [], '{}')]);
        $body1 = $this->withMockOpenFgaClient(
            $mock1,
            function (OpenFgaClient $client) use ($outboxRepo, $notifier, $requestBody): \Psr\Http\Message\ResponseInterface {
                $processor = new OutboxProcessor($outboxRepo, $client);
                $handler   = new PermissionAdminHandler($client);
                $handler->setOutboxDependencies($outboxRepo, $notifier, $processor);
                return $handler->handle(
                    $this->withOidcUser($this->requestFor('POST', '/admin/permissions', [], $requestBody))
                );
            }
        );

        $mock2 = new MockHandler([new GuzzleResponse(200, [], '{}')]);
        $body2 = $this->withMockOpenFgaClient(
            $mock2,
            function (OpenFgaClient $client) use ($outboxRepo, $notifier, $requestBody): \Psr\Http\Message\ResponseInterface {
                $processor = new OutboxProcessor($outboxRepo, $client);
                $handler   = new PermissionAdminHandler($client);
                $handler->setOutboxDependencies($outboxRepo, $notifier, $processor);
                return $handler->handle(
                    $this->withOidcUser($this->requestFor('POST', '/admin/permissions', [], $requestBody))
                );
            }
        );

        $ids1 = self::arrayFieldFrom($this->decodeJsonBody($body1), 'outbox_ids');
        $ids2 = self::arrayFieldFrom($this->decodeJsonBody($body2), 'outbox_ids');

        self::assertSame(
            $ids1,
            $ids2,
            'idempotency_key must collapse second grant to same outbox row IDs'
        );
    }

    public function testGrantSurfacesTransientFailureAsOutboxPending(): void
    {
        // FGA returns 503 (transient). The outbox row must end up in 'retrying'
        // state and outbox_pending must be 1.
        $mock = new MockHandler([
            new GuzzleResponse(503, [], ''), // transient → RETRY
        ]);

        $outboxRepo = new OutboxRepository(self::$pdo);
        $notifier   = new OutboxNotifier(null, 'litcal:reconcile-stream');

        $response = $this->withMockOpenFgaClient(
            $mock,
            function (OpenFgaClient $client) use ($outboxRepo, $notifier): \Psr\Http\Message\ResponseInterface {
                $processor = new OutboxProcessor($outboxRepo, $client);
                $handler   = new PermissionAdminHandler($client);
                $handler->setOutboxDependencies($outboxRepo, $notifier, $processor);
                return $handler->handle(
                    $this->withOidcUser($this->requestFor(
                        'POST',
                        '/admin/permissions',
                        [],
                        [
                            'user'        => 'user-alice',
                            'object_type' => 'national_calendar',
                            'object_id'   => 'IT',
                            'relation'    => 'editor',
                        ]
                    ))
                );
            }
        );

        $body = $this->decodeJsonBody($response);
        self::assertTrue($body['success']);
        self::assertSame(1, $body['outbox_pending']);
        self::assertSame(0, $body['outbox_failed']);

        $outboxIds = self::arrayFieldFrom($body, 'outbox_ids');
        $outboxRow = $outboxRepo->getById((int) $outboxIds[0]);
        self::assertNotNull($outboxRow);
        self::assertSame(OutboxStatus::RETRYING, $outboxRow->status);
    }

    public function testGrantTreatsDuplicateTupleAsBenignSuccess(): void
    {
        // FGA returns 400 with cannot_allow_duplicate_tuple (already exists).
        // OutboxClassifier must classify this as BENIGN_SUCCESS — the row ends
        // up in 'succeeded' state and outbox_pending/failed are both 0.
        $mock = new MockHandler([
            new GuzzleResponse(400, [], (string) json_encode([
                'code'    => 'cannot_allow_duplicate_tuple',
                'message' => 'cannot write duplicate tuple',
            ])),
        ]);

        $outboxRepo = new OutboxRepository(self::$pdo);
        $notifier   = new OutboxNotifier(null, 'litcal:reconcile-stream');

        $response = $this->withMockOpenFgaClient(
            $mock,
            function (OpenFgaClient $client) use ($outboxRepo, $notifier): \Psr\Http\Message\ResponseInterface {
                $processor = new OutboxProcessor($outboxRepo, $client);
                $handler   = new PermissionAdminHandler($client);
                $handler->setOutboxDependencies($outboxRepo, $notifier, $processor);
                return $handler->handle(
                    $this->withOidcUser($this->requestFor(
                        'POST',
                        '/admin/permissions',
                        [],
                        [
                            'user'        => 'user-alice',
                            'object_type' => 'national_calendar',
                            'object_id'   => 'IT',
                            'relation'    => 'editor',
                        ]
                    ))
                );
            }
        );

        $body = $this->decodeJsonBody($response);
        self::assertTrue($body['success']);
        self::assertSame(0, $body['outbox_pending']);
        self::assertSame(0, $body['outbox_failed']);
        self::assertCount(1, self::arrayFieldFrom($body, 'tuples_created'));
    }

    // --- Outbox pattern: revokePermission (Task 22) ----------------------

    public function testRevokePersistsOutboxRowAndAppliesViaSyncFastPath(): void
    {
        // Global admin revoking a permission. FGA returns 200. After the
        // outbox sync fast path the outbox row must be in 'succeeded' state.
        $mock = new MockHandler([
            new GuzzleResponse(200, [], '{}'), // deleteTuple OK
        ]);

        $outboxRepo = new OutboxRepository(self::$pdo);
        $notifier   = new OutboxNotifier(null, 'litcal:reconcile-stream');

        $response = $this->withoutEnv(
            array_merge(self::ZITADEL_ENV_VARS, self::OPENFGA_ENV_VARS),
            fn() => $this->withMockOpenFgaClient(
                $mock,
                function (OpenFgaClient $client) use ($outboxRepo, $notifier): \Psr\Http\Message\ResponseInterface {
                    $processor = new OutboxProcessor($outboxRepo, $client);
                    $handler   = new PermissionAdminHandler($client);
                    $handler->setOutboxDependencies($outboxRepo, $notifier, $processor);
                    return $handler->handle(
                        $this->withOidcUser($this->requestFor(
                            'DELETE',
                            '/admin/permissions',
                            [],
                            [
                                'user'        => 'user-alice',
                                'object_type' => 'national_calendar',
                                'object_id'   => 'IT',
                                'relation'    => 'editor',
                            ]
                        ))
                    );
                }
            )
        );

        self::assertSame(200, $response->getStatusCode());
        $body = $this->decodeJsonBody($response);
        self::assertTrue($body['success']);
        self::assertSame(0, $body['outbox_pending']);
        self::assertSame(0, $body['outbox_failed']);
        self::assertSame(false, $body['cascade_deferred']);
        self::assertCount(1, self::arrayFieldFrom($body, 'outbox_ids'));
        self::assertCount(1, self::arrayFieldFrom($body, 'tuples_deleted'));

        // Verify outbox row was created and marked succeeded with delete_tuple operation.
        $outboxIds = self::arrayFieldFrom($body, 'outbox_ids');
        $outboxRow = $outboxRepo->getById((int) $outboxIds[0]);
        self::assertNotNull($outboxRow);
        self::assertSame(OutboxStatus::SUCCEEDED, $outboxRow->status);
        self::assertSame(OutboxOperation::DELETE_TUPLE, $outboxRow->operation);
    }

    public function testRevokeSurfacesTransientFailureAsOutboxPending(): void
    {
        // FGA returns 503 (transient). The outbox row must end up in 'retrying'
        // state and outbox_pending must be 1. success must still be true
        // (the outbox row committed to the DB before the sync attempt).
        $mock = new MockHandler([
            new GuzzleResponse(503, [], ''), // transient → RETRY
        ]);

        $outboxRepo = new OutboxRepository(self::$pdo);
        $notifier   = new OutboxNotifier(null, 'litcal:reconcile-stream');

        $response = $this->withoutEnv(
            array_merge(self::ZITADEL_ENV_VARS, self::OPENFGA_ENV_VARS),
            fn() => $this->withMockOpenFgaClient(
                $mock,
                function (OpenFgaClient $client) use ($outboxRepo, $notifier): \Psr\Http\Message\ResponseInterface {
                    $processor = new OutboxProcessor($outboxRepo, $client);
                    $handler   = new PermissionAdminHandler($client);
                    $handler->setOutboxDependencies($outboxRepo, $notifier, $processor);
                    return $handler->handle(
                        $this->withOidcUser($this->requestFor(
                            'DELETE',
                            '/admin/permissions',
                            [],
                            [
                                'user'        => 'user-alice',
                                'object_type' => 'national_calendar',
                                'object_id'   => 'IT',
                                'relation'    => 'editor',
                            ]
                        ))
                    );
                }
            )
        );

        $body = $this->decodeJsonBody($response);
        self::assertTrue($body['success']);
        self::assertSame(1, $body['outbox_pending']);
        self::assertSame(0, $body['outbox_failed']);
        self::assertSame(true, $body['cascade_deferred']);

        $outboxIds = self::arrayFieldFrom($body, 'outbox_ids');
        $outboxRow = $outboxRepo->getById((int) $outboxIds[0]);
        self::assertNotNull($outboxRow);
        self::assertSame(OutboxStatus::RETRYING, $outboxRow->status);
    }

    public function testRevokeTreatsMissingTupleAsBenignSuccess(): void
    {
        // FGA returns 400 with cannot_allow_unknown_tuple_to_be_deleted
        // (tuple not found — already gone). OutboxClassifier must classify this
        // as BENIGN_SUCCESS — the row ends up in 'succeeded' state.
        $mock = new MockHandler([
            new GuzzleResponse(400, [], (string) json_encode([
                'code'    => 'cannot_allow_unknown_tuple_to_be_deleted',
                'message' => 'cannot delete unknown tuple',
            ])),
        ]);

        $outboxRepo = new OutboxRepository(self::$pdo);
        $notifier   = new OutboxNotifier(null, 'litcal:reconcile-stream');

        $response = $this->withoutEnv(
            array_merge(self::ZITADEL_ENV_VARS, self::OPENFGA_ENV_VARS),
            fn() => $this->withMockOpenFgaClient(
                $mock,
                function (OpenFgaClient $client) use ($outboxRepo, $notifier): \Psr\Http\Message\ResponseInterface {
                    $processor = new OutboxProcessor($outboxRepo, $client);
                    $handler   = new PermissionAdminHandler($client);
                    $handler->setOutboxDependencies($outboxRepo, $notifier, $processor);
                    return $handler->handle(
                        $this->withOidcUser($this->requestFor(
                            'DELETE',
                            '/admin/permissions',
                            [],
                            [
                                'user'        => 'user-alice',
                                'object_type' => 'national_calendar',
                                'object_id'   => 'IT',
                                'relation'    => 'editor',
                            ]
                        ))
                    );
                }
            )
        );

        $body = $this->decodeJsonBody($response);
        self::assertTrue($body['success']);
        self::assertSame(0, $body['outbox_pending']);
        self::assertSame(0, $body['outbox_failed']);
        self::assertSame(false, $body['cascade_deferred']);
        // BENIGN_SUCCESS → counted in tuples_deleted (idempotent — tuple already gone).
        self::assertCount(1, self::arrayFieldFrom($body, 'tuples_deleted'));
    }

    public function testRevokeIsIdempotentOnReissue(): void
    {
        // Two sequential revoke calls for the same tuple from the same admin
        // must collapse to the same outbox row IDs (idempotency_key).
        $outboxRepo = new OutboxRepository(self::$pdo);
        $notifier   = new OutboxNotifier(null, 'litcal:reconcile-stream');

        $requestBody = [
            'user'        => 'user-alice',
            'object_type' => 'national_calendar',
            'object_id'   => 'IT',
            'relation'    => 'editor',
        ];

        $mock1 = new MockHandler([new GuzzleResponse(200, [], '{}')]);
        $body1 = $this->withoutEnv(
            array_merge(self::ZITADEL_ENV_VARS, self::OPENFGA_ENV_VARS),
            fn() => $this->withMockOpenFgaClient(
                $mock1,
                function (OpenFgaClient $client) use ($outboxRepo, $notifier, $requestBody): \Psr\Http\Message\ResponseInterface {
                    $processor = new OutboxProcessor($outboxRepo, $client);
                    $handler   = new PermissionAdminHandler($client);
                    $handler->setOutboxDependencies($outboxRepo, $notifier, $processor);
                    return $handler->handle(
                        $this->withOidcUser($this->requestFor('DELETE', '/admin/permissions', [], $requestBody))
                    );
                }
            )
        );

        $mock2 = new MockHandler([new GuzzleResponse(200, [], '{}')]);
        $body2 = $this->withoutEnv(
            array_merge(self::ZITADEL_ENV_VARS, self::OPENFGA_ENV_VARS),
            fn() => $this->withMockOpenFgaClient(
                $mock2,
                function (OpenFgaClient $client) use ($outboxRepo, $notifier, $requestBody): \Psr\Http\Message\ResponseInterface {
                    $processor = new OutboxProcessor($outboxRepo, $client);
                    $handler   = new PermissionAdminHandler($client);
                    $handler->setOutboxDependencies($outboxRepo, $notifier, $processor);
                    return $handler->handle(
                        $this->withOidcUser($this->requestFor('DELETE', '/admin/permissions', [], $requestBody))
                    );
                }
            )
        );

        $ids1 = self::arrayFieldFrom($this->decodeJsonBody($body1), 'outbox_ids');
        $ids2 = self::arrayFieldFrom($this->decodeJsonBody($body2), 'outbox_ids');

        self::assertSame(
            $ids1,
            $ids2,
            'idempotency_key must collapse second revoke to same outbox row IDs'
        );
    }

    public function testRevokePermissionWithDeferredRowDefersCascade(): void
    {
        // Single delete tuple, FGA returns 503 → row stays in retrying.
        // Zitadel stays CONFIGURED so we can verify the handler chose not to call it.
        $fgaMock = new MockHandler([
            new GuzzleResponse(503, [], '{"code":"service_unavailable"}'),
        ]);

        $outboxRepo = new OutboxRepository(self::$pdo);
        $notifier   = new OutboxNotifier(null, 'litcal:reconcile-stream');

        $response = $this->withMockOpenFgaClient(
            $fgaMock,
            fn(OpenFgaClient $client) => $this->dispatchRevokePermission(
                user: 'user:user-d9-12345',
                objectType: 'national_calendar',
                objectId: 'IT',
                relation: 'editor',
                outboxRepo: $outboxRepo,
                notifier: $notifier,
                client: $client,
            )
        );

        self::assertSame(200, $response->getStatusCode());
        $body = $this->decodeJsonBody($response);

        self::assertTrue($body['success']);
        self::assertSame(true, $body['cascade_deferred']);
        self::assertSame([], $body['cascaded_roles']);
        self::assertSame(1, $body['outbox_pending']);
        self::assertStringContainsString('role cascade deferred', $this->stringFieldFrom($body, 'message'));
    }
}
