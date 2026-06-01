<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers\Admin;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use LiturgicalCalendar\Api\Handlers\Admin\PermissionAdminHandler;
use LiturgicalCalendar\Api\Http\Exception\MethodNotAllowedException;
use LiturgicalCalendar\Api\Http\Exception\UnauthorizedException;
use LiturgicalCalendar\Api\Http\Exception\ValidationException;
use LiturgicalCalendar\Api\Services\OpenFgaClient;
use LiturgicalCalendar\Tests\Handlers\AbstractHandlerTestCase;
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
}
