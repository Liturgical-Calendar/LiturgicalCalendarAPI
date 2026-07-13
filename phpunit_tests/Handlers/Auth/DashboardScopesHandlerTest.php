<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers\Auth;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use LiturgicalCalendar\Api\Handlers\Auth\DashboardScopesHandler;
use LiturgicalCalendar\Api\Http\Exception\UnauthorizedException;
use LiturgicalCalendar\Api\Services\OpenFgaClient;
use LiturgicalCalendar\Tests\Handlers\AbstractHandlerTestCase;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(DashboardScopesHandler::class)]
final class DashboardScopesHandlerTest extends AbstractHandlerTestCase
{
    /**
     * @param array<int, GuzzleResponse> $responses
     */
    private function handlerWith(array $responses): DashboardScopesHandler
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
        return new DashboardScopesHandler($client);
    }

    /**
     * Response queue order: 4 admin list-objects (ADMIN_OBJECT_TYPES: national_calendar,
     * diocesan_calendar, wider_region, general_roman_calendar), then 4 viewer list-objects
     * (VIEWER_OBJECT_TYPES: general_roman_calendar, national_calendar_test,
     * diocesan_calendar_test, general_roman_calendar_test).
     *
     * @param array<int, GuzzleResponse> $viewerResponses
     * @return array<int, GuzzleResponse>
     */
    private static function emptyAdminThenViewer(array $viewerResponses): array
    {
        $empty = new GuzzleResponse(200, [], '{"objects":[]}');
        return [$empty, $empty, $empty, $empty, ...$viewerResponses];
    }

    public function testMissingOidcUserIsUnauthorized(): void
    {
        $this->expectException(UnauthorizedException::class);
        $this->handlerWith([])->handle($this->requestFor('GET', '/auth/dashboard-scopes'));
    }

    public function testViewerScopesAreKeyedByType(): void
    {
        $handler = $this->handlerWith(self::emptyAdminThenViewer([
            new GuzzleResponse(200, [], '{"objects":["general_roman_calendar:decrees"]}'),
            new GuzzleResponse(200, [], '{"objects":["national_calendar_test:IT"]}'),
            new GuzzleResponse(200, [], '{"objects":[]}'),
            new GuzzleResponse(200, [], '{"objects":[]}'),
        ]));

        $request = $this->requestFor('GET', '/auth/dashboard-scopes')
            ->withAttribute('oidc_user', ['sub' => 'cei-editor', 'roles' => ['calendar_editor']]);

        $body = $this->decodeJsonBody($handler->handle($request));

        self::assertFalse($body['is_global_admin']);
        self::assertFalse($body['is_resource_admin']);
        self::assertSame([], $body['admin_scopes']);
        self::assertSame(
            [
                'general_roman_calendar'      => ['decrees'],
                'national_calendar_test'      => ['IT'],
                'diocesan_calendar_test'      => [],
                'general_roman_calendar_test' => [],
            ],
            $body['viewer_scopes']
        );
    }

    public function testResourceAdminScopesMatchAdminScopesEndpointSemantics(): void
    {
        $empty   = new GuzzleResponse(200, [], '{"objects":[]}');
        $handler = $this->handlerWith([
            new GuzzleResponse(200, [], '{"objects":["national_calendar:IT"]}'),
            $empty,
            $empty,
            $empty, // remaining admin types
            $empty,
            $empty,
            $empty,
            $empty, // viewer types
        ]);

        $request = $this->requestFor('GET', '/auth/dashboard-scopes')
            ->withAttribute('oidc_user', ['sub' => 'cei-admin', 'roles' => ['calendar_editor']]);

        $body = $this->decodeJsonBody($handler->handle($request));

        self::assertTrue($body['is_resource_admin']);
        self::assertSame(
            [['object_type' => 'national_calendar', 'object_id' => 'IT']],
            $body['admin_scopes']
        );
    }

    public function testGlobalAdminIsFlaggedFromToken(): void
    {
        $handler = $this->handlerWith(self::emptyAdminThenViewer([
            new GuzzleResponse(200, [], '{"objects":[]}'),
            new GuzzleResponse(200, [], '{"objects":[]}'),
            new GuzzleResponse(200, [], '{"objects":[]}'),
            new GuzzleResponse(200, [], '{"objects":[]}'),
        ]));

        $request = $this->requestFor('GET', '/auth/dashboard-scopes')
            ->withAttribute('oidc_user', ['sub' => 'root', 'roles' => ['admin']]);

        $body = $this->decodeJsonBody($handler->handle($request));

        self::assertTrue($body['is_global_admin']);
        self::assertFalse($body['is_resource_admin']);
    }

    public function testFailsClosedWhenOpenFgaErrors(): void
    {
        // First 500 aborts resolveScopes(); second 500 aborts resolveViewerScopes().
        $handler = $this->handlerWith([
            new GuzzleResponse(500, [], 'boom'),
            new GuzzleResponse(500, [], 'boom'),
        ]);

        $request = $this->requestFor('GET', '/auth/dashboard-scopes')
            ->withAttribute('oidc_user', ['sub' => 'root', 'roles' => ['admin']]);

        $body = $this->decodeJsonBody($handler->handle($request));

        self::assertTrue($body['is_global_admin']);
        self::assertFalse($body['is_resource_admin']);
        self::assertSame([], $body['admin_scopes']);
        self::assertSame(
            [
                'general_roman_calendar'      => [],
                'national_calendar_test'      => [],
                'diocesan_calendar_test'      => [],
                'general_roman_calendar_test' => [],
            ],
            $body['viewer_scopes']
        );
    }
}
