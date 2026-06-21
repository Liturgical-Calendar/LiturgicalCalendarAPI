<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers\Auth;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use LiturgicalCalendar\Api\Handlers\Auth\AdminScopesHandler;
use LiturgicalCalendar\Api\Http\Exception\UnauthorizedException;
use LiturgicalCalendar\Api\Services\OpenFgaClient;
use LiturgicalCalendar\Tests\Handlers\AbstractHandlerTestCase;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(AdminScopesHandler::class)]
final class AdminScopesHandlerTest extends AbstractHandlerTestCase
{
    /**
     * @param array<int, GuzzleResponse> $responses
     */
    private function handlerWith(array $responses): AdminScopesHandler
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
        return new AdminScopesHandler($client);
    }

    public function testMissingOidcUserIsUnauthorized(): void
    {
        $this->expectException(UnauthorizedException::class);
        $this->handlerWith([])->handle($this->requestFor('GET', '/auth/admin-scopes'));
    }

    public function testResourceAdminGetsScopes(): void
    {
        // Four list-objects responses, in ADMIN_OBJECT_TYPES order.
        $handler = $this->handlerWith([
            new GuzzleResponse(200, [], '{"objects":["national_calendar:IT"]}'),
            new GuzzleResponse(200, [], '{"objects":[]}'),
            new GuzzleResponse(200, [], '{"objects":[]}'),
            new GuzzleResponse(200, [], '{"objects":[]}'),
        ]);

        $request = $this->requestFor('GET', '/auth/admin-scopes')
            ->withAttribute('oidc_user', ['sub' => 'cei-admin', 'roles' => ['calendar_editor']]);

        $body = $this->decodeJsonBody($handler->handle($request));

        self::assertFalse($body['is_global_admin']);
        self::assertTrue($body['is_resource_admin']);
        self::assertSame(
            [['object_type' => 'national_calendar', 'object_id' => 'IT']],
            $body['admin_scopes']
        );
    }

    public function testGlobalAdminIsFlaggedEvenWithNoFgaScopes(): void
    {
        $handler = $this->handlerWith([
            new GuzzleResponse(200, [], '{"objects":[]}'),
            new GuzzleResponse(200, [], '{"objects":[]}'),
            new GuzzleResponse(200, [], '{"objects":[]}'),
            new GuzzleResponse(200, [], '{"objects":[]}'),
        ]);

        $request = $this->requestFor('GET', '/auth/admin-scopes')
            ->withAttribute('oidc_user', ['sub' => 'root', 'roles' => ['admin']]);

        $body = $this->decodeJsonBody($handler->handle($request));

        self::assertTrue($body['is_global_admin']);
        self::assertFalse($body['is_resource_admin']);
        self::assertSame([], $body['admin_scopes']);
    }

    public function testPlainEditorGetsBothFalse(): void
    {
        $handler = $this->handlerWith([
            new GuzzleResponse(200, [], '{"objects":[]}'),
            new GuzzleResponse(200, [], '{"objects":[]}'),
            new GuzzleResponse(200, [], '{"objects":[]}'),
            new GuzzleResponse(200, [], '{"objects":[]}'),
        ]);

        $request = $this->requestFor('GET', '/auth/admin-scopes')
            ->withAttribute('oidc_user', ['sub' => 'cei-editor', 'roles' => ['calendar_editor']]);

        $body = $this->decodeJsonBody($handler->handle($request));

        self::assertFalse($body['is_global_admin']);
        self::assertFalse($body['is_resource_admin']);
        self::assertSame([], $body['admin_scopes']);
    }

    public function testFailsClosedWhenOpenFgaErrors(): void
    {
        $handler = $this->handlerWith([
            new GuzzleResponse(500, [], 'boom'),
        ]);

        $request = $this->requestFor('GET', '/auth/admin-scopes')
            ->withAttribute('oidc_user', ['sub' => 'cei-admin', 'roles' => ['admin']]);

        $body = $this->decodeJsonBody($handler->handle($request));

        self::assertTrue($body['is_global_admin']);
        self::assertFalse($body['is_resource_admin']);
        self::assertSame([], $body['admin_scopes']);
    }
}
