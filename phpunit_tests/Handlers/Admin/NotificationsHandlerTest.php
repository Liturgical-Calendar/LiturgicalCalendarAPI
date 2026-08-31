<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers\Admin;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use LiturgicalCalendar\Api\Handlers\Admin\NotificationsHandler;
use LiturgicalCalendar\Api\Http\Exception\ForbiddenException;
use LiturgicalCalendar\Api\Http\Exception\MethodNotAllowedException;
use LiturgicalCalendar\Api\Http\Exception\UnauthorizedException;
use LiturgicalCalendar\Api\Repositories\AccessRequestRepository;
use LiturgicalCalendar\Api\Repositories\ApplicationRepository;
use LiturgicalCalendar\Api\Services\OpenFgaClient;
use LiturgicalCalendar\Tests\Handlers\AbstractHandlerTestCase;
use LiturgicalCalendar\Tests\Support\OpenApiSchemaKeys;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(NotificationsHandler::class)]
final class NotificationsHandlerTest extends AbstractHandlerTestCase
{
    use OpenApiSchemaKeys;

    protected static bool $requiresDatabase = true;

    public function testOptionsPreflightSucceeds(): void
    {
        $response = ( new NotificationsHandler() )->handle(
            $this->requestFor('OPTIONS', '/admin/notifications', [
                'Origin'                        => 'https://app.example.test',
                'Access-Control-Request-Method' => 'GET',
            ])
        );

        self::assertSame(204, $response->getStatusCode());
    }

    public function testPostIsMethodNotAllowed(): void
    {
        $this->expectException(MethodNotAllowedException::class);
        ( new NotificationsHandler() )->handle($this->requestFor('POST', '/admin/notifications'));
    }

    public function testMissingOidcUserIsUnauthorized(): void
    {
        $this->expectException(UnauthorizedException::class);
        ( new NotificationsHandler() )->handle($this->requestFor('GET', '/admin/notifications'));
    }

    public function testNonAdminIsForbidden(): void
    {
        $request = $this->requestFor('GET', '/admin/notifications')
            ->withAttribute('oidc_user', ['sub' => 'user-1', 'roles' => ['viewer']]);

        $this->expectException(ForbiddenException::class);
        $this->expectExceptionMessage('Admin role required');

        ( new NotificationsHandler() )->handle($request);
    }

    public function testAdminGetsZeroCountsWhenNoPendingItems(): void
    {
        $request = $this->withOidcUser($this->requestFor('GET', '/admin/notifications'));

        $response = ( new NotificationsHandler() )->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        $body = $this->decodeJsonBody($response);
        self::assertSame(0, $body['pending_access_requests']);
        self::assertSame(0, $body['pending_applications']);
        self::assertSame(0, $body['total']);
        self::assertSame([], $body['items']);
    }

    public function testAdminGetsCountsWhenPendingItemsExist(): void
    {
        // usleep between inserts so the rows have monotonically increasing
        // created_at timestamps the handler can sort by.
        $accessRepo = new AccessRequestRepository(self::$pdo);
        $accessRepo->create('user-a', 'a@x.test', 'Alice', 'developer', []);
        usleep(2000);
        $accessRepo->create('user-b', 'b@x.test', 'Bob', 'developer', []);
        usleep(2000);

        $appRepo = new ApplicationRepository(self::$pdo);
        $appRepo->create('user-c', 'AppC', 'desc', null, 'read'); // pending by default, newest

        $request  = $this->withOidcUser($this->requestFor('GET', '/admin/notifications'));
        $response = ( new NotificationsHandler() )->handle($request);

        self::assertSame(200, $response->getStatusCode());
        $body = $this->decodeJsonBody($response);
        self::assertSame(2, $body['pending_access_requests']);
        self::assertSame(1, $body['pending_applications']);
        self::assertSame(3, $body['total']);
        self::assertCount(3, $body['items']);

        // Verify items are returned newest-first by created_at, then assert
        // the type sequence matches the insertion order in reverse: application
        // was inserted last (so it's first), the two access_requests follow.
        $timestamps = array_column($body['items'], 'created_at');
        $sorted     = $timestamps;
        rsort($sorted, SORT_STRING);
        self::assertSame($sorted, $timestamps, 'Items must be sorted by created_at descending');

        $types = array_column($body['items'], 'type');
        self::assertSame(['application', 'access_request', 'access_request'], $types);
    }

    /**
     * `AdminNotificationsResponse` and both item shapes are `additionalProperties: false` with
     * every property required, and `openapi.json` is used to generate typed clients. So a key the
     * handler emits that the schema omits is not undocumented — it is *forbidden*, and a strict
     * validator rejects a well-formed live response.
     *
     * That is exactly what #946 was. `pending_applications` was returned on every response and
     * absent from the schema, while `total` — which the handler defines at
     * {@see \LiturgicalCalendar\Api\Handlers\Admin\NotificationsHandler} as the sum of the two
     * counters — *was* documented. The schema therefore published a total while hiding one of its
     * summands, and a client reading it could only conclude that `total` was buggy. The two item
     * shapes were broken the same way: the application item's `app_name`, `zitadel_user_id` and
     * `requested_scope` were all forbidden by an `items` schema that described only the
     * access-request shape.
     *
     * Every other assertion in this class reads the keys it cares about and is blind to all of
     * this, which is why the comparison has to be key-for-key and in both directions.
     */
    public function testTheBodyAndBothItemShapesMatchTheirOpenApiSchemasKeyForKey(): void
    {
        $accessRepo = new AccessRequestRepository(self::$pdo);
        $accessRepo->create('user-a', 'a@x.test', 'Alice', 'developer', []);
        usleep(2000);

        $appRepo = new ApplicationRepository(self::$pdo);
        $appRepo->create('user-c', 'AppC', 'desc', null, 'read');

        $response = ( new NotificationsHandler() )->handle(
            $this->withOidcUser($this->requestFor('GET', '/admin/notifications'))
        );

        $body = $this->decodeJsonBody($response);
        self::assertSchemaKeysMatch('AdminNotificationsResponse', $body);

        // Both shapes must be present, or the loop below would assert nothing about one of them.
        self::assertIsArray($body['items']);
        $types = array_column($body['items'], 'type');
        sort($types);
        self::assertSame(['access_request', 'application'], $types, 'both item shapes must be exercised');

        foreach ($body['items'] as $item) {
            self::assertIsArray($item);
            self::assertSchemaKeysMatch(
                'access_request' === $item['type'] ? 'AdminAccessRequestNotification' : 'AdminApplicationNotification',
                $item
            );
        }
    }

    /**
     * @param array<int, GuzzleResponse> $responses
     */
    private function handlerWithFga(array $responses): NotificationsHandler
    {
        $stack  = HandlerStack::create(new MockHandler($responses));
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
        return new NotificationsHandler($client);
    }

    public function testResourceAdminGetsScopedCount(): void
    {
        $accessRepo = new AccessRequestRepository(self::$pdo);
        // Request the resource-admin administers (national_calendar:IT)
        $accessRepo->create('user-it', 'it@x.test', 'ItUser', 'calendar_editor', [
            ['object_type' => 'national_calendar', 'object_id' => 'IT', 'relation' => 'editor'],
        ]);
        usleep(2000);
        // Request the resource-admin does NOT administer (national_calendar:US)
        $accessRepo->create('user-us', 'us@x.test', 'UsUser', 'calendar_editor', [
            ['object_type' => 'national_calendar', 'object_id' => 'US', 'relation' => 'editor'],
        ]);

        // resolveScopes: 4 list-objects calls (national_calendar -> IT, rest empty),
        // then filterByAdminAccess: 1 check() per request (IT -> allowed, US -> denied).
        $handler = $this->handlerWithFga([
            new GuzzleResponse(200, [], '{"objects":["national_calendar:IT"]}'),
            new GuzzleResponse(200, [], '{"objects":[]}'),
            new GuzzleResponse(200, [], '{"objects":[]}'),
            new GuzzleResponse(200, [], '{"objects":[]}'),
            new GuzzleResponse(200, [], '{"allowed":true}'),
            new GuzzleResponse(200, [], '{"allowed":false}'),
        ]);

        $request = $this->requestFor('GET', '/admin/notifications')
            ->withAttribute('oidc_user', ['sub' => 'cei-admin', 'roles' => ['calendar_editor']]);

        $response = $handler->handle($request);

        self::assertSame(200, $response->getStatusCode());
        $body = $this->decodeJsonBody($response);
        self::assertSame(1, $body['pending_access_requests']);
        self::assertSame(0, $body['pending_applications']);
        self::assertSame(1, $body['total']);
        self::assertCount(1, $body['items']);
        self::assertSame('access_request', $body['items'][0]['type']);
        self::assertSame('admin-permissions.php', $body['items'][0]['url']);
    }

    public function testPlainEditorWithNoScopesIsForbidden(): void
    {
        // resolveScopes: 4 empty list-objects responses -> no scopes -> rejected.
        $handler = $this->handlerWithFga([
            new GuzzleResponse(200, [], '{"objects":[]}'),
            new GuzzleResponse(200, [], '{"objects":[]}'),
            new GuzzleResponse(200, [], '{"objects":[]}'),
            new GuzzleResponse(200, [], '{"objects":[]}'),
        ]);

        $request = $this->requestFor('GET', '/admin/notifications')
            ->withAttribute('oidc_user', ['sub' => 'plain-editor', 'roles' => ['calendar_editor']]);

        $this->expectException(ForbiddenException::class);
        $this->expectExceptionMessage('Admin role required');

        $handler->handle($request);
    }
}
