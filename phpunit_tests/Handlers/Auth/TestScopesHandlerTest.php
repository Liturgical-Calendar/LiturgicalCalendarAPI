<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers\Auth;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use LiturgicalCalendar\Api\Handlers\Auth\TestScopesHandler;
use LiturgicalCalendar\Api\Http\Exception\UnauthorizedException;
use LiturgicalCalendar\Api\Services\OpenFgaClient;
use LiturgicalCalendar\Api\Services\ResourceAdminService;
use LiturgicalCalendar\Tests\Handlers\AbstractHandlerTestCase;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(TestScopesHandler::class)]
final class TestScopesHandlerTest extends AbstractHandlerTestCase
{
    /**
     * @param array<int, GuzzleResponse> $responses
     */
    private function handlerWith(array $responses): TestScopesHandler
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
        return new TestScopesHandler($client);
    }

    /**
     * One empty list-objects response per (relation × TEST_OBJECT_TYPES) probe:
     * editor for each type, then admin for each type.
     *
     * @return array<int, GuzzleResponse>
     */
    private function allEmpty(): array
    {
        return array_fill(0, 2 * count(ResourceAdminService::TEST_OBJECT_TYPES), new GuzzleResponse(200, [], '{"objects":[]}'));
    }

    public function testMissingOidcUserIsUnauthorized(): void
    {
        $this->expectException(UnauthorizedException::class);
        $this->handlerWith([])->handle($this->requestFor('GET', '/auth/test-scopes'));
    }

    public function testBlankSubIsUnauthorized(): void
    {
        $request = $this->requestFor('GET', '/auth/test-scopes')
            ->withAttribute('oidc_user', ['sub' => '   ', 'roles' => ['test_editor']]);

        $this->expectException(UnauthorizedException::class);
        $this->handlerWith([])->handle($request);
    }

    public function testScopedEditorGetsEditorAndAdminLists(): void
    {
        // Exactly one response per (relation x TEST_OBJECT_TYPES) probe: the first
        // editor probe returns an object, the rest are empty.
        $responses    = $this->allEmpty();
        $responses[0] = new GuzzleResponse(200, [], '{"objects":["national_calendar_test:roman/USA"]}');
        $handler      = $this->handlerWith($responses);

        $request = $this->requestFor('GET', '/auth/test-scopes')
            ->withAttribute('oidc_user', ['sub' => 'usa-editor', 'roles' => ['test_editor']]);

        $body = $this->decodeJsonBody($handler->handle($request));

        self::assertFalse($body['is_global_admin']);
        self::assertSame(
            [['object_type' => 'national_calendar_test', 'object_id' => 'roman/USA']],
            $body['editor']
        );
        self::assertSame([], $body['admin']);
    }

    public function testGlobalAdminFlaggedWithEmptyScopes(): void
    {
        $handler = $this->handlerWith($this->allEmpty());

        $request = $this->requestFor('GET', '/auth/test-scopes')
            ->withAttribute('oidc_user', ['sub' => 'root', 'roles' => ['admin']]);

        $body = $this->decodeJsonBody($handler->handle($request));

        self::assertTrue($body['is_global_admin']);
        self::assertSame([], $body['editor']);
        self::assertSame([], $body['admin']);
    }

    public function testFailsClosedWhenOpenFgaErrors(): void
    {
        $handler = $this->handlerWith([new GuzzleResponse(500, [], 'boom')]);

        $request = $this->requestFor('GET', '/auth/test-scopes')
            ->withAttribute('oidc_user', ['sub' => 'usa-editor', 'roles' => ['test_editor']]);

        $body = $this->decodeJsonBody($handler->handle($request));

        self::assertFalse($body['is_global_admin']);
        self::assertSame([], $body['editor']);
        self::assertSame([], $body['admin']);
    }
}
