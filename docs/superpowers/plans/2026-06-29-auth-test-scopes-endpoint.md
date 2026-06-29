# `GET /auth/test-scopes` Endpoint Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development
> (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use
> checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add an authenticated `GET /auth/test-scopes` endpoint that reports the caller's OpenFGA
`editor` and `admin` scopes for the three test object types, so the admin-tests frontend can gate
edit/delete buttons exactly.

**Architecture:** Mirror the existing `GET /auth/admin-scopes` slice. A new `ResourceAdminService::
resolveTestScopes()` enumerates the caller's scopes over the `*_test` object types via the existing
`OpenFgaClient::listObjects()`; a new `TestScopesHandler` (copy of `AdminScopesHandler`) returns
`{is_global_admin, editor[], admin[]}`; the route is wired into `Router.php` behind the same OIDC
auth gate as `admin-scopes`.

**Tech Stack:** PHP 8.4, PSR-7/15 handlers, OpenFGA via `OpenFgaClient`, PHPUnit
(`AbstractHandlerTestCase` for handlers, `TestCase` + Guzzle `MockHandler` for the service),
PHPStan level 10, phpcs (PSR-12).

## Global Constraints

- PHP >= 8.4; `declare(strict_types=1);` in every new file.
- Code style: PSR-12 via phpcs; static analysis: PHPStan level 10 (`composer analyse` scans `src`).
- **Fail closed:** any OpenFGA `\RuntimeException` yields empty scope sets (never a 500).
- Endpoint is **authenticated** (requires `oidc_user` request attribute); JSON only; `Cache-Control:
  no-store`.
- Exact response shape: `{ "is_global_admin": bool, "editor": [{object_type, object_id}], "admin":
  [{object_type, object_id}] }`. `editor` ⊇ `admin` (the FGA model defines test `editor` = direct
  ∪ `admin`).
- Test object types, in this fixed order: `national_calendar_test`, `diocesan_calendar_test`,
  `general_roman_calendar_test`.

---

## File structure

- **Modify** `src/Services/ResourceAdminService.php` — add `TEST_OBJECT_TYPES` const and
  `resolveTestScopes(string $sub): array{editor: list<Scope>, admin: list<Scope>}`.
- **Create** `src/Handlers/Auth/TestScopesHandler.php` — the endpoint handler (mirror of
  `AdminScopesHandler`).
- **Modify** `src/Router.php` — dispatch `auth/test-scopes` to `TestScopesHandler` (~line 366) and
  add `test-scopes` to the authenticated `auth` subroute allowlist (~line 617).
- **Create** `phpunit_tests/Services/ResourceAdminServiceTest.php` — unit test for
  `resolveTestScopes()`.
- **Create** `phpunit_tests/Handlers/Auth/TestScopesHandlerTest.php` — handler test (mirror of
  `AdminScopesHandlerTest`).
- **Modify** `jsondata/schemas/openapi.json` — document the `/auth/test-scopes` path.

Where `Scope` = `array{object_type: string, object_id: string}`.

---

### Task 1: `ResourceAdminService::resolveTestScopes()`

**Files:**

- Modify: `src/Services/ResourceAdminService.php`
- Test: `phpunit_tests/Services/ResourceAdminServiceTest.php`

**Interfaces:**

- Consumes: `OpenFgaClient::listObjects(string $user, string $relation, string $type): array`
  (returns object IDs without the type prefix).
- Produces: `ResourceAdminService::resolveTestScopes(string $sub): array{editor: list<array{
  object_type: string, object_id: string}>, admin: list<array{object_type: string, object_id:
  string}>}` and the const `ResourceAdminService::TEST_OBJECT_TYPES`.

- [ ] **Step 1: Write the failing test**

Create `phpunit_tests/Services/ResourceAdminServiceTest.php`:

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use LiturgicalCalendar\Api\Services\OpenFgaClient;
use LiturgicalCalendar\Api\Services\ResourceAdminService;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ResourceAdminService::class)]
final class ResourceAdminServiceTest extends TestCase
{
    /**
     * @param array<int, GuzzleResponse> $responses
     */
    private function serviceWith(array $responses): ResourceAdminService
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
        return new ResourceAdminService($client);
    }

    public function testResolveTestScopesGroupsEditorThenAdmin(): void
    {
        // Order: editor for the 3 test types, then admin for the 3 test types.
        $service = $this->serviceWith([
            new GuzzleResponse(200, [], '{"objects":["national_calendar_test:USA"]}'),
            new GuzzleResponse(200, [], '{"objects":[]}'),
            new GuzzleResponse(200, [], '{"objects":[]}'),
            new GuzzleResponse(200, [], '{"objects":["national_calendar_test:USA"]}'),
            new GuzzleResponse(200, [], '{"objects":[]}'),
            new GuzzleResponse(200, [], '{"objects":[]}'),
        ]);

        $scopes = $service->resolveTestScopes('cei-admin');

        self::assertSame(
            [['object_type' => 'national_calendar_test', 'object_id' => 'USA']],
            $scopes['editor']
        );
        self::assertSame(
            [['object_type' => 'national_calendar_test', 'object_id' => 'USA']],
            $scopes['admin']
        );
    }

    public function testResolveTestScopesFailsClosedOnError(): void
    {
        $service = $this->serviceWith([new GuzzleResponse(500, [], 'boom')]);

        self::assertSame(['editor' => [], 'admin' => []], $service->resolveTestScopes('x'));
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit phpunit_tests/Services/ResourceAdminServiceTest.php`
Expected: FAIL — `Call to undefined method ...::resolveTestScopes()`.

- [ ] **Step 3: Implement `resolveTestScopes()`**

In `src/Services/ResourceAdminService.php`, add the const after `ADMIN_OBJECT_TYPES` (after line 27):

```php
    /**
     * Test object types a user can hold `editor`/`admin` on. Mirrors the
     * test-scoped object types in the OpenFGA authorization model.
     */
    public const TEST_OBJECT_TYPES = [
        'national_calendar_test',
        'diocesan_calendar_test',
        'general_roman_calendar_test',
    ];
```

And add this method after `resolveScopes()` (after line 58):

```php
    /**
     * The caller's `editor` and `admin` scopes across TEST_OBJECT_TYPES.
     *
     * `editor` is a superset of `admin` (the model grants test `editor` to
     * `admin`). Used to gate the admin-tests UI: edit requires `editor`,
     * delete requires `admin`.
     *
     * Fails closed: any OpenFGA transport error yields empty scope sets.
     *
     * @param string $sub Zitadel user ID (without "user:" prefix)
     * @return array{editor: list<array{object_type: string, object_id: string}>, admin: list<array{object_type: string, object_id: string}>}
     */
    public function resolveTestScopes(string $sub): array
    {
        $fgaUser = "user:{$sub}";
        $editor  = [];
        $admin   = [];

        try {
            foreach (self::TEST_OBJECT_TYPES as $type) {
                foreach ($this->fgaClient->listObjects($fgaUser, 'editor', $type) as $objectId) {
                    $editor[] = ['object_type' => $type, 'object_id' => $objectId];
                }
            }
            foreach (self::TEST_OBJECT_TYPES as $type) {
                foreach ($this->fgaClient->listObjects($fgaUser, 'admin', $type) as $objectId) {
                    $admin[] = ['object_type' => $type, 'object_id' => $objectId];
                }
            }
        } catch (\RuntimeException) {
            return ['editor' => [], 'admin' => []];
        }

        return ['editor' => $editor, 'admin' => $admin];
    }
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit phpunit_tests/Services/ResourceAdminServiceTest.php`
Expected: PASS (2 tests).

- [ ] **Step 5: Static analysis + style**

Run: `composer analyse` and `composer lint`
Expected: no new errors. (Auto-fix style if needed: `composer lint:fix`.)

- [ ] **Step 6: Commit**

```bash
git add src/Services/ResourceAdminService.php phpunit_tests/Services/ResourceAdminServiceTest.php
git commit -m "feat(auth): ResourceAdminService::resolveTestScopes for test object scopes"
```

---

### Task 2: `TestScopesHandler`

**Files:**

- Create: `src/Handlers/Auth/TestScopesHandler.php`
- Test: `phpunit_tests/Handlers/Auth/TestScopesHandlerTest.php`

**Interfaces:**

- Consumes: `ResourceAdminService::resolveTestScopes()` (Task 1); `OidcAuthMiddleware::isAdmin()`;
  `AbstractHandler` base; the `oidc_user` request attribute `array{sub?: string, roles?:
  array<string>}`.
- Produces: `TestScopesHandler` with a `__construct(?OpenFgaClient $fgaClient = null)` and
  `handle(ServerRequestInterface): ResponseInterface` returning `{is_global_admin, editor, admin}`.

- [ ] **Step 1: Write the failing test**

Create `phpunit_tests/Handlers/Auth/TestScopesHandlerTest.php`:

```php
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

    /** Six list-objects: editor x3 types, then admin x3 types. */
    private function sixEmpty(): array
    {
        return array_fill(0, 6, new GuzzleResponse(200, [], '{"objects":[]}'));
    }

    public function testMissingOidcUserIsUnauthorized(): void
    {
        $this->expectException(UnauthorizedException::class);
        $this->handlerWith([])->handle($this->requestFor('GET', '/auth/test-scopes'));
    }

    public function testScopedEditorGetsEditorAndAdminLists(): void
    {
        $handler = $this->handlerWith([
            new GuzzleResponse(200, [], '{"objects":["national_calendar_test:USA"]}'),
            new GuzzleResponse(200, [], '{"objects":[]}'),
            new GuzzleResponse(200, [], '{"objects":[]}'),
            new GuzzleResponse(200, [], '{"objects":[]}'),
            new GuzzleResponse(200, [], '{"objects":[]}'),
            new GuzzleResponse(200, [], '{"objects":[]}'),
        ]);

        $request = $this->requestFor('GET', '/auth/test-scopes')
            ->withAttribute('oidc_user', ['sub' => 'usa-editor', 'roles' => ['test_editor']]);

        $body = $this->decodeJsonBody($handler->handle($request));

        self::assertFalse($body['is_global_admin']);
        self::assertSame(
            [['object_type' => 'national_calendar_test', 'object_id' => 'USA']],
            $body['editor']
        );
        self::assertSame([], $body['admin']);
    }

    public function testGlobalAdminFlaggedWithEmptyScopes(): void
    {
        $handler = $this->handlerWith($this->sixEmpty());

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
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit phpunit_tests/Handlers/Auth/TestScopesHandlerTest.php`
Expected: FAIL — class `TestScopesHandler` not found.

- [ ] **Step 3: Implement the handler**

Create `src/Handlers/Auth/TestScopesHandler.php`:

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Handlers\Auth;

use LiturgicalCalendar\Api\Handlers\AbstractHandler;
use LiturgicalCalendar\Api\Http\Enum\AcceptabilityLevel;
use LiturgicalCalendar\Api\Http\Enum\AcceptHeader;
use LiturgicalCalendar\Api\Http\Enum\RequestMethod;
use LiturgicalCalendar\Api\Http\Exception\UnauthorizedException;
use LiturgicalCalendar\Api\Http\Middleware\OidcAuthMiddleware;
use LiturgicalCalendar\Api\Services\OpenFgaClient;
use LiturgicalCalendar\Api\Services\ResourceAdminService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Test Scopes Handler
 *
 * GET /auth/test-scopes — report the authenticated caller's test-editing scopes:
 *   - is_global_admin: the Zitadel `admin` role is present in the token.
 *   - editor: OpenFGA `editor` tuples across the *_test object types (gates edit).
 *   - admin:  OpenFGA `admin` tuples across the *_test object types (gates delete).
 *
 * Fails closed: when OpenFGA is unavailable, editor/admin are empty, but
 * is_global_admin is still honored from the token.
 */
final class TestScopesHandler extends AbstractHandler
{
    private ?OpenFgaClient $fgaClient = null;

    public function __construct(?OpenFgaClient $fgaClient = null)
    {
        parent::__construct();

        $this->fgaClient             = $fgaClient;
        $this->allowedRequestMethods = [RequestMethod::GET];
        $this->allowedAcceptHeaders  = [AcceptHeader::JSON];
        $this->allowCredentials      = true;
    }

    private function isFgaClientAvailable(): bool
    {
        return $this->fgaClient !== null || OpenFgaClient::isConfigured();
    }

    private function getFgaClient(): OpenFgaClient
    {
        if ($this->fgaClient === null) {
            $this->fgaClient = OpenFgaClient::fromEnv();
        }
        return $this->fgaClient;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $response = static::initResponse($request);
        $method   = RequestMethod::from($request->getMethod());

        if ($method === RequestMethod::OPTIONS) {
            return $this->handlePreflightRequest($request, $response);
        }

        $response = $this->setAccessControlAllowOriginHeader($request, $response);
        $this->validateRequestMethod($request);

        $mime     = $this->validateAcceptHeader($request, AcceptabilityLevel::LAX);
        $response = $response->withHeader('Content-Type', $mime)
            ->withHeader('Cache-Control', 'no-store');

        /** @var array{sub?: string, roles?: array<string>}|null $oidcUser */
        $oidcUser = $request->getAttribute('oidc_user');

        if ($oidcUser === null) {
            throw new UnauthorizedException('Authentication required');
        }

        $sub = $oidcUser['sub'] ?? null;
        if (!is_string($sub) || trim($sub) === '') {
            throw new UnauthorizedException('Invalid authentication token');
        }

        $isGlobalAdmin = OidcAuthMiddleware::isAdmin($oidcUser);

        $scopes = ['editor' => [], 'admin' => []];
        if ($this->isFgaClientAvailable()) {
            $scopes = ( new ResourceAdminService($this->getFgaClient()) )->resolveTestScopes($sub);
        }

        return $this->encodeResponseBody($response, [
            'is_global_admin' => $isGlobalAdmin,
            'editor'          => $scopes['editor'],
            'admin'           => $scopes['admin'],
        ]);
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit phpunit_tests/Handlers/Auth/TestScopesHandlerTest.php`
Expected: PASS (4 tests).

- [ ] **Step 5: Static analysis + style**

Run: `composer analyse` and `composer lint` (auto-fix with `composer lint:fix` if needed).
Expected: no new errors.

- [ ] **Step 6: Commit**

```bash
git add src/Handlers/Auth/TestScopesHandler.php phpunit_tests/Handlers/Auth/TestScopesHandlerTest.php
git commit -m "feat(auth): TestScopesHandler for GET /auth/test-scopes"
```

---

### Task 3: Wire the route

**Files:**

- Modify: `src/Router.php` (dispatch ~line 366; auth allowlist ~line 617)

**Interfaces:**

- Consumes: `TestScopesHandler` (Task 2).
- Produces: `GET /auth/test-scopes` reaching `TestScopesHandler::handle()` behind the OIDC auth
  gate (so unauthenticated callers get 401 and `oidc_user` is populated for authenticated ones).

- [ ] **Step 1: Add the import**

In `src/Router.php`, next to the existing `use ...Handlers\Auth\AdminScopesHandler;` (line 31) add:

```php
use LiturgicalCalendar\Api\Handlers\Auth\TestScopesHandler;
```

- [ ] **Step 2: Add the dispatch branch**

In `src/Router.php`, immediately after the `admin-scopes` branch (the block ending at line 369),
insert a new `elseif`:

```php
                    } elseif ($authRoute === 'test-scopes') {
                        // GET /auth/test-scopes - Report caller's test editor/admin scopes
                        $testScopesHandler = new TestScopesHandler();
                        $this->handler     = $testScopesHandler;
```

(It goes between the `admin-scopes` branch and the closing `} else {` at line 370.)

- [ ] **Step 3: Add to the authenticated auth-subroute allowlist**

In `src/Router.php` (~line 617), add `'test-scopes'` to the `in_array(...)` list so the route is
gated by OIDC auth like the others:

```php
            ( $route === 'auth' && count($requestPathParts) >= 1 && in_array($requestPathParts[0], ['access-requests', 'email-verification', 'notifications', 'admin-scopes', 'test-scopes'], true) )
```

- [ ] **Step 4: Verify routing in-process (extend the handler test is not enough — assert wiring)**

Because handler tests bypass the Router, verify wiring with a quick manual run against the dev
server. Start it if needed (`composer start`), then:

```bash
curl -s -o /dev/null -w '%{http_code}\n' http://localhost:8000/auth/test-scopes
```

Expected: `401` (unauthenticated — proves the route exists and is gated; a missing route would be
`404`). Stop the server afterward if you started it (`composer stop`).

- [ ] **Step 5: Static analysis + style**

Run: `composer analyse` and `composer lint`.
Expected: no new errors.

- [ ] **Step 6: Commit**

```bash
git add src/Router.php
git commit -m "feat(auth): route GET /auth/test-scopes through OIDC auth"
```

---

### Task 4: Document the endpoint in OpenAPI

**Files:**

- Modify: `jsondata/schemas/openapi.json`

**Interfaces:**

- Consumes: nothing (documentation only).
- Produces: an OpenAPI `paths./auth/test-scopes.get` entry validated by `composer lint:openapi`.

- [ ] **Step 1: Locate the existing admin-scopes entry**

Run: `grep -n '"/auth/admin-scopes"' jsondata/schemas/openapi.json`
Open that path object — it is the template to mirror (same security scheme, tags, 200/401
responses).

- [ ] **Step 2: Add the `/auth/test-scopes` path**

Add a sibling `"/auth/test-scopes"` path object mirroring `/auth/admin-scopes`'s `get` (same
`security`, `tags`, `401` response), with this `200` response body schema (inline or as a named
component, matching how admin-scopes is modeled):

```json
{
  "type": "object",
  "required": ["is_global_admin", "editor", "admin"],
  "properties": {
    "is_global_admin": { "type": "boolean" },
    "editor": {
      "type": "array",
      "items": {
        "type": "object",
        "required": ["object_type", "object_id"],
        "properties": {
          "object_type": { "type": "string" },
          "object_id": { "type": "string" }
        }
      }
    },
    "admin": {
      "type": "array",
      "items": {
        "type": "object",
        "required": ["object_type", "object_id"],
        "properties": {
          "object_type": { "type": "string" },
          "object_id": { "type": "string" }
        }
      }
    }
  }
}
```

- [ ] **Step 3: Lint the schema**

Run: `composer lint:openapi`
Expected: no errors.

- [ ] **Step 4: Commit**

```bash
git add jsondata/schemas/openapi.json
git commit -m "docs(openapi): document GET /auth/test-scopes"
```

---

## Self-review

- **Spec coverage:** This plan implements the spec's section "A. API — `GET /auth/test-scopes`"
  (sibling endpoint, `ResourceAdminService` over the three `*_test` types, `{is_global_admin,
  editor, admin}` shape, fail-closed). The frontend page and UnitTestInterface retirement are
  separate plans (next).
- **Placeholders:** none — every step has the actual code/command.
- **Type consistency:** `resolveTestScopes()` returns `{editor, admin}`; `TestScopesHandler` reads
  `$scopes['editor']` / `$scopes['admin']`; response keys `editor`/`admin` match the tests and the
  OpenAPI schema. `listObjects($user, $relation, $type)` argument order matches
  `OpenFgaClient.php:299` and `resolveScopes()` usage.
- **Call ordering:** `resolveTestScopes()` issues all three `editor` `listObjects` calls first, then
  all three `admin` calls; both the service test and handler test supply mock responses in that
  exact order.
