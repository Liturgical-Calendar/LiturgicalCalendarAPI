# Health WebSocket Authentication Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-
> task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Stop anonymous callers starting validation runs on the Health WebSocket server, by identifying the caller from the handshake cookie and gating every action on a
permission the `hello` frame also advertises.

**Architecture:** `Health::onOpen()` resolves a `WsCaller` from the `Cookie:` header on `$conn->httpRequest` (Zitadel RS256 via a shared `CachedKeySet`, else legacy
HS256), remembers it per `resourceId`, and advertises its permissions on the `hello` frame. `Health::onMessage()` asks one `TestRunPolicy` object twice: coarsely at the
top, before any run state is mutated, and again with the message's `TestTarget` once the message has been validated. Anonymous connections are accepted and refused per
action, never closed.

**Tech Stack:** PHP 8.4, Ratchet/ReactPHP, `firebase/php-jwt` (`CachedKeySet`), `symfony/cache` (`FilesystemAdapter`), PHPUnit 12, PHPStan level 10, PSR-12 via phpcs.

**Spec:** `docs/superpowers/specs/2026-08-27-ws-health-auth-design.md`

## Global Constraints

- PHP >= 8.4. Short array syntax, 4-space indent, single quotes unless interpolating.
- PHPStan level 10 must pass: `composer analyse`. Suppress only with `@phpstan-ignore <identifier>`, never a bare `@phpstan-ignore-line`.
- phpcs must pass: `composer lint`. Auto-fix with `composer lint:fix`.
- Markdown must pass `composer lint:md` (180-char lines, blank lines around lists and fences, aligned tables).
- **Never `--no-verify`.** If a hook fails, fix the cause.
- **No network calls inside the ReactPHP event loop.** Roles come from the token claim only; the Zitadel Management API fallback that `OidcAuthMiddleware` uses is
deliberately NOT carried into the WebSocket path.
- Everything fails **closed**: any caller that cannot be verified is anonymous, and an anonymous caller may not run.
- `SUPPORTED_PROTOCOL_VERSIONS` stays `[1]`. No version bump.
- Run the suite with `composer test:quick`. Never pass a bare `--exclude-group` on the CLI: it un-fences `golden-master-generate`, which rewrites the fixtures it is checked against.
- Mark a new test `slow` only with a **measured** reason, and only with the `#[Group('slow')]` attribute — never an `@group` docblock.

---

## File Structure

**Create:**

| Path                                    | Responsibility                                                |
|-----------------------------------------|---------------------------------------------------------------|
| `src/Models/Auth/WsCaller.php`          | Immutable identity of one WebSocket connection.               |
| `src/Models/Auth/TestTarget.php`        | What a message wants validated; the seam's parameter.         |
| `src/Services/TestRunPolicy.php`        | The single answer to "may this caller start a run?".          |
| `src/Services/ZitadelRoles.php`         | Read the Zitadel project-roles claim off a token payload.     |
| `src/Services/ZitadelKeySetFactory.php` | Build and memoize a `CachedKeySet` per issuer.                |
| `src/Services/WsCallerResolver.php`     | Handshake -> `WsCaller`. Cookie parsing plus two-step verify. |

**Modify:**

| Path                                          | Change                                                            |
|-----------------------------------------------|-------------------------------------------------------------------|
| `src/Enum/ProtocolErrorCode.php`              | Two new cases.                                                    |
| `jsondata/schemas/WebSocketFrame.json`        | `hello.caller`; two new `errorCode` values.                       |
| `src/Http/Middleware/OidcAuthMiddleware.php`  | Delegate `getKeySet()` and role-claim reading to the new helpers. |
| `src/Health.php`                              | Resolve, remember, advertise, gate.                               |
| `bin/LitCalTestServer.php`                    | Warm the key set at boot; log which verify paths are live.        |
| `phpunit_tests/WebSocket/WsTestClient.php`    | Optional `Cookie:` header on the handshake.                       |
| `phpunit_tests/WebSocket/*Test.php` (4 files) | Authenticate; skip loudly without `JWT_SECRET`.                   |

---

### Task 1: `WsCaller` and `TestRunPolicy`

Pure logic, no I/O. Everything else depends on these names.

**Files:**

- Create: `src/Models/Auth/WsCaller.php`
- Create: `src/Models/Auth/TestTarget.php`
- Create: `src/Services/TestRunPolicy.php`
- Test: `phpunit_tests/Services/TestRunPolicyTest.php`

**Interfaces:**

- Produces: `WsCaller::anonymous(): self`, `WsCaller::authenticated(string $sub, array $roles): self`,
  readonly `$authenticated`, `$sub`, `$roles`, `hasAnyRole(string ...$roles): bool`.
  `TestTarget::__construct(?string $kind, ?string $rite, ?string $calendarId)`,
  `TestTarget::fromMessage(mixed $message): ?self`.
  `TestRunPolicy::RUN_TESTS_ROLES`, `TestRunPolicy::mayRun(WsCaller $caller, ?TestTarget $target = null): bool`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services;

use LiturgicalCalendar\Api\Models\Auth\TestTarget;
use LiturgicalCalendar\Api\Models\Auth\WsCaller;
use LiturgicalCalendar\Api\Services\TestRunPolicy;
use PHPUnit\Framework\TestCase;

final class TestRunPolicyTest extends TestCase
{
    public function testAnonymousCallerMayNotRun(): void
    {
        $this->assertFalse((new TestRunPolicy())->mayRun(WsCaller::anonymous()));
    }

    public function testAuthenticatedCallerWithoutQualifyingRoleMayNotRun(): void
    {
        $caller = WsCaller::authenticated('user-1', ['developer', 'calendar_editor']);
        $this->assertFalse((new TestRunPolicy())->mayRun($caller));
    }

    public function testTestEditorMayRun(): void
    {
        $caller = WsCaller::authenticated('user-1', ['test_editor']);
        $this->assertTrue((new TestRunPolicy())->mayRun($caller));
    }

    public function testAdminMayRun(): void
    {
        $caller = WsCaller::authenticated('user-1', ['admin']);
        $this->assertTrue((new TestRunPolicy())->mayRun($caller));
    }

    public function testTargetIsAcceptedAndIgnoredByTheCoarsePolicy(): void
    {
        $policy = new TestRunPolicy();
        $target = new TestTarget('rite', 'roman', null);
        $this->assertTrue($policy->mayRun(WsCaller::authenticated('u', ['admin']), $target));
        $this->assertFalse($policy->mayRun(WsCaller::anonymous(), $target));
    }

    public function testTargetIsReadFromAValidateCalendarMessage(): void
    {
        $message = json_decode('{"action":"validateCalendar","calendar":{"kind":"rite","rite":"roman"}}');
        $target  = TestTarget::fromMessage($message);
        $this->assertNotNull($target);
        $this->assertSame('rite', $target->kind);
        $this->assertSame('roman', $target->rite);
    }

    public function testTargetIsNullWhenTheMessageNamesNone(): void
    {
        $this->assertNull(TestTarget::fromMessage(json_decode('{"action":"runTest"}')));
        $this->assertNull(TestTarget::fromMessage('not an object'));
    }

    public function testRolesAreDeduplicatedAndAnonymousHasNone(): void
    {
        $caller = WsCaller::authenticated('u', ['admin', 'admin']);
        $this->assertSame(['admin'], $caller->roles);
        $this->assertSame([], WsCaller::anonymous()->roles);
        $this->assertNull(WsCaller::anonymous()->sub);
    }
}
```

- [ ] **Step 2: Run it and confirm it fails**

Run: `vendor/bin/phpunit phpunit_tests/Services/TestRunPolicyTest.php`
Expected: FAIL — `Class "LiturgicalCalendar\Api\Models\Auth\WsCaller" not found`.

- [ ] **Step 3: Implement `WsCaller`**

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Models\Auth;

/**
 * The identity of one WebSocket connection, settled once at handshake time.
 *
 * Anonymity is a state, not a failure: {@see \LiturgicalCalendar\Api\Services\WsCallerResolver}
 * answers with an anonymous caller for a missing cookie, an expired token and a forged one alike,
 * because the connection is accepted either way and the refusal happens per action.
 */
final readonly class WsCaller
{
    /**
     * @param array<int, string> $roles
     */
    private function __construct(
        public bool $authenticated,
        public ?string $sub,
        public array $roles
    ) {
    }

    public static function anonymous(): self
    {
        return new self(false, null, []);
    }

    /**
     * @param array<int, string> $roles
     */
    public static function authenticated(string $sub, array $roles): self
    {
        /** @var array<int, string> $clean */
        $clean = array_values(array_unique(array_filter($roles, 'is_string')));
        return new self(true, $sub, $clean);
    }

    public function hasAnyRole(string ...$roles): bool
    {
        return [] !== array_intersect($roles, $this->roles);
    }
}
```

- [ ] **Step 4: Implement `TestTarget`**

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Models\Auth;

/**
 * What a message wants validated, in the terms a permission check would need.
 *
 * Carried through {@see \LiturgicalCalendar\Api\Services\TestRunPolicy::mayRun()} from day one even
 * though the coarse policy ignores it. The alternative — adding the parameter when a fine-grained
 * policy arrives — would mean changing every call site at exactly the moment the behaviour changes,
 * which is the worst time to be moving plumbing.
 */
final readonly class TestTarget
{
    public function __construct(
        public ?string $kind,
        public ?string $rite,
        public ?string $calendarId
    ) {
    }

    /**
     * Read the target off a validated message, or null when the message names none.
     *
     * Takes `mixed` for the same reason {@see \LiturgicalCalendar\Api\Health::declaredCorrelationId()}
     * does: the caller holds a raw `json_decode()` result, and narrowing it here would narrow it for
     * every guard downstream.
     */
    public static function fromMessage(mixed $message): ?self
    {
        if (false === $message instanceof \stdClass || false === property_exists($message, 'calendar')) {
            return null;
        }

        $calendar = $message->calendar;
        if (false === $calendar instanceof \stdClass) {
            return null;
        }

        $read = static function (string $property) use ($calendar): ?string {
            if (false === property_exists($calendar, $property)) {
                return null;
            }
            $value = $calendar->{$property};
            return is_string($value) ? $value : null;
        };

        $kind = $read('kind');
        $rite = $read('rite');
        // The id property is named for the kind it belongs to; the first one present wins.
        $calendarId = $read('calendar') ?? $read('nation') ?? $read('diocese');

        if (null === $kind && null === $rite && null === $calendarId) {
            return null;
        }

        return new self($kind, $rite, $calendarId);
    }
}
```

- [ ] **Step 5: Implement `TestRunPolicy`**

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services;

use LiturgicalCalendar\Api\Models\Auth\TestTarget;
use LiturgicalCalendar\Api\Models\Auth\WsCaller;

/**
 * Whether a caller may start a validation run.
 *
 * One object, asked by both the `hello` frame and the action gate, so the advertisement and the
 * enforcement cannot disagree — the failure mode that made UnitTestInterface's client-side gate
 * worth closing in the first place.
 *
 * `$target` is accepted and ignored. A fine-grained implementation would resolve it through
 * {@see TestScopeResolver} to a `*_test` object and ask OpenFGA; the seam exists so that arriving
 * changes one class rather than a protocol.
 */
class TestRunPolicy
{
    /**
     * The roles permitted to start a run.
     *
     * The same pair UnitTestInterface enforces in `JwtAuth::RUN_TESTS_ROLES` when storing a run's
     * result. They are not shared through a package, so this server is the one that answers and the
     * client is told — see the `caller` object on the `hello` frame.
     *
     * @var array<int, string>
     */
    public const RUN_TESTS_ROLES = ['admin', 'test_editor'];

    public function mayRun(WsCaller $caller, ?TestTarget $target = null): bool
    {
        if (false === $caller->authenticated) {
            return false;
        }

        return $caller->hasAnyRole(...self::RUN_TESTS_ROLES);
    }
}
```

- [ ] **Step 6: Run the test and confirm it passes**

Run: `vendor/bin/phpunit phpunit_tests/Services/TestRunPolicyTest.php`
Expected: PASS, 8 tests.

- [ ] **Step 7: Lint, analyse, commit**

```bash
composer lint:fix
composer analyse
git add src/Models/Auth/WsCaller.php src/Models/Auth/TestTarget.php src/Services/TestRunPolicy.php phpunit_tests/Services/TestRunPolicyTest.php
git commit -m "feat(ws): add WsCaller identity and TestRunPolicy permission seam"
```

---

### Task 2: Share the Zitadel key set and role-claim reading

A DRY refactor ahead of the new consumer, so the WebSocket resolver does not re-implement JWKS
plumbing that already exists in `OidcAuthMiddleware`. Behaviour must not change.

**Files:**

- Create: `src/Services/ZitadelRoles.php`
- Create: `src/Services/ZitadelKeySetFactory.php`
- Modify: `src/Http/Middleware/OidcAuthMiddleware.php` (`getKeySet()`, the roles-claim block in `extractUserInfo()`)
- Test: `phpunit_tests/Services/ZitadelRolesTest.php`

**Interfaces:**

- Consumes: nothing from Task 1.
- Produces: `ZitadelRoles::CLAIM`, `ZitadelRoles::fromPayload(object $payload): array<int, string>`,
  `ZitadelKeySetFactory::for(string $issuer, ?string $internalUrl, int $cacheTtl): CachedKeySet`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services;

use LiturgicalCalendar\Api\Services\ZitadelRoles;
use PHPUnit\Framework\TestCase;

final class ZitadelRolesTest extends TestCase
{
    public function testReadsRoleNamesFromTheProjectRolesClaim(): void
    {
        $payload = json_decode('{"' . ZitadelRoles::CLAIM . '":{"admin":{"org_id":"1"},"test_editor":{"org_id":"1"}}}');
        $this->assertSame(['admin', 'test_editor'], ZitadelRoles::fromPayload($payload));
    }

    public function testReturnsEmptyWhenTheClaimIsAbsent(): void
    {
        $this->assertSame([], ZitadelRoles::fromPayload(json_decode('{"sub":"u"}')));
    }

    public function testReturnsEmptyWhenTheClaimIsNotAnObject(): void
    {
        $payload = json_decode('{"' . ZitadelRoles::CLAIM . '":"admin"}');
        $this->assertSame([], ZitadelRoles::fromPayload($payload));
    }
}
```

- [ ] **Step 2: Run it and confirm it fails**

Run: `vendor/bin/phpunit phpunit_tests/Services/ZitadelRolesTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement `ZitadelRoles`**

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services;

/**
 * The Zitadel project-roles claim, read the one way.
 *
 * Zitadel returns roles as an object keyed by role name — `{"admin": {"org_id": "1"}}` — so the role
 * names are the keys, not the values.
 */
final class ZitadelRoles
{
    public const CLAIM = 'urn:zitadel:iam:org:project:roles';

    /**
     * @return array<int, string>
     */
    public static function fromPayload(object $payload): array
    {
        if (false === isset($payload->{self::CLAIM})) {
            return [];
        }

        $claim = $payload->{self::CLAIM};
        if (false === is_object($claim) && false === is_array($claim)) {
            return [];
        }

        /** @var array<int, string> $roles */
        $roles = array_values(array_filter(array_keys((array) $claim), 'is_string'));

        return $roles;
    }
}
```

- [ ] **Step 4: Implement `ZitadelKeySetFactory`**

Move the body of `OidcAuthMiddleware::getKeySet()` verbatim, including the static per-issuer memo and
the `Host`-header middleware for the Docker-internal URL. Note the cache directory: from
`src/Services/` the project root is `dirname(__DIR__, 2)`, not `dirname(__DIR__, 3)` as it was from
`src/Http/Middleware/`.

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services;

use Firebase\JWT\CachedKeySet;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\HttpFactory;
use Psr\Http\Message\RequestInterface;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;

/**
 * Builds and memoizes the Zitadel JWKS key set.
 *
 * Shared by {@see \LiturgicalCalendar\Api\Http\Middleware\OidcAuthMiddleware} and
 * {@see WsCallerResolver} so both reach the same filesystem cache: a key already fetched by the HTTP
 * API is a key the WebSocket server does not have to fetch, which matters because the WebSocket
 * server fetches from inside a single-threaded event loop.
 */
final class ZitadelKeySetFactory
{
    /** @var array<string, CachedKeySet> */
    private static array $keySets = [];

    public static function for(string $issuer, ?string $internalUrl = null, int $cacheTtl = 3600): CachedKeySet
    {
        $issuer = rtrim($issuer, '/');

        if (isset(self::$keySets[$issuer])) {
            return self::$keySets[$issuer];
        }

        $jwksUri = $issuer . '/oauth/v2/keys';

        if (null !== $internalUrl && '' !== $internalUrl) {
            // Rewrite the JWKS URI for Docker networking. CachedKeySet uses PSR-18 sendRequest(),
            // which does not apply Guzzle's default headers, so the Host header is injected here or
            // Zitadel refuses a request addressed to the compose service name.
            $jwksUri    = rtrim($internalUrl, '/') . '/oauth/v2/keys';
            $hostHeader = ZitadelHostHeader::deriveFromIssuer($issuer);
            $stack      = HandlerStack::create();
            $stack->push(Middleware::mapRequest(
                static fn(RequestInterface $request): RequestInterface => $request->withHeader('Host', $hostHeader)
            ));
            $httpClient = new Client(['handler' => $stack]);
        } else {
            $httpClient = new Client();
        }

        $cacheDir = dirname(__DIR__, 2) . '/cache';

        self::$keySets[$issuer] = new CachedKeySet(
            $jwksUri,
            $httpClient,
            new HttpFactory(),
            new FilesystemAdapter('jwks', $cacheTtl, $cacheDir),
            $cacheTtl,
            true // Rate limit JWKS fetches
        );

        return self::$keySets[$issuer];
    }
}
```

- [ ] **Step 5: Point `OidcAuthMiddleware` at both helpers**

Replace the body of `getKeySet()` with
`return ZitadelKeySetFactory::for($this->issuer, $this->internalUrl, $this->cacheTtl);`
and delete the now-unused `self::$keySets` property and the imports it alone needed
(`CachedKeySet`, `Client`, `HandlerStack`, `Middleware`, `HttpFactory`, `RequestInterface` — keep any
still used elsewhere in the file).

In `extractUserInfo()`, replace the inline roles-claim loop with `$roles = ZitadelRoles::fromPayload($payload);`
and **leave the Management-API fallback below it exactly as it is** — that fallback belongs to the HTTP
path, which may block.

- [ ] **Step 6: Run the affected suites**

Run: `vendor/bin/phpunit phpunit_tests/Services/ZitadelRolesTest.php phpunit_tests/Services/ZitadelServiceTest.php phpunit_tests/Services/ZitadelHostHeaderTest.php phpunit_tests/Http`
Expected: PASS, no behaviour change.

- [ ] **Step 7: Lint, analyse, commit**

```bash
composer lint:fix
composer analyse
git add src/Services/ZitadelRoles.php src/Services/ZitadelKeySetFactory.php src/Http/Middleware/OidcAuthMiddleware.php phpunit_tests/Services/ZitadelRolesTest.php
git commit -m "refactor(auth): extract shared Zitadel key set factory and roles-claim reader"
```

---

### Task 3: `WsCallerResolver`

**Files:**

- Create: `src/Services/WsCallerResolver.php`
- Test: `phpunit_tests/Services/WsCallerResolverTest.php`

**Interfaces:**

- Consumes: `WsCaller` (Task 1), `ZitadelRoles`, `ZitadelKeySetFactory` (Task 2).
- Produces: `WsCallerResolver::fromEnv(): self`, `fromHandshake(?RequestInterface $request): WsCaller`,
  `fromToken(string $token): WsCaller`, `parseCookieHeader(string $header): array<string, string>`,
  `warmKeySet(): bool`, `describeAvailablePaths(): string`.

- [ ] **Step 1: Write the failing test**

`EnvIsolationTrait::withoutEnv()` is the existing helper for exercising "service not configured"
branches without leaking the host's `.env.local` into assertions.

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services;

use GuzzleHttp\Psr7\Request;
use LiturgicalCalendar\Api\Services\JwtService;
use LiturgicalCalendar\Api\Services\WsCallerResolver;
use LiturgicalCalendar\Tests\Support\EnvIsolationTrait;
use PHPUnit\Framework\TestCase;

final class WsCallerResolverTest extends TestCase
{
    use EnvIsolationTrait;

    private const SECRET = 'a-test-secret-that-is-at-least-32-characters-long';

    private function resolver(): WsCallerResolver
    {
        return new WsCallerResolver(new JwtService(self::SECRET), null, null);
    }

    private function tokenFor(string $sub, array $roles): string
    {
        return (new JwtService(self::SECRET))->generate($sub, ['roles' => $roles]);
    }

    public function testNoRequestIsAnonymous(): void
    {
        $this->assertFalse($this->resolver()->fromHandshake(null)->authenticated);
    }

    public function testNoCookieHeaderIsAnonymous(): void
    {
        $request = new Request('GET', '/');
        $this->assertFalse($this->resolver()->fromHandshake($request)->authenticated);
    }

    public function testLegacyHs256CookieIsAuthenticatedWithItsRoles(): void
    {
        $token   = $this->tokenFor('someone', ['test_editor']);
        $request = new Request('GET', '/', ['Cookie' => 'litcal_access_token=' . $token]);

        $caller = $this->resolver()->fromHandshake($request);

        $this->assertTrue($caller->authenticated);
        $this->assertSame('someone', $caller->sub);
        $this->assertSame(['test_editor'], $caller->roles);
    }

    public function testCookieIsFoundAmongOthers(): void
    {
        $token   = $this->tokenFor('someone', ['admin']);
        $request = new Request('GET', '/', [
            'Cookie' => 'other=1; litcal_access_token=' . $token . '; litcal_id_token=zzz',
        ]);

        $this->assertTrue($this->resolver()->fromHandshake($request)->authenticated);
    }

    public function testGarbageTokenIsAnonymousRatherThanAnError(): void
    {
        $request = new Request('GET', '/', ['Cookie' => 'litcal_access_token=not.a.jwt']);
        $this->assertFalse($this->resolver()->fromHandshake($request)->authenticated);
    }

    public function testExpiredTokenIsAnonymous(): void
    {
        $expired = (new JwtService(self::SECRET, 'HS256', -10))->generate('someone', ['roles' => ['admin']]);
        $request = new Request('GET', '/', ['Cookie' => 'litcal_access_token=' . $expired]);
        $this->assertFalse($this->resolver()->fromHandshake($request)->authenticated);
    }

    public function testTokenWithoutRolesClaimAuthenticatesWithNoRoles(): void
    {
        $token  = (new JwtService(self::SECRET))->generate('someone');
        $caller = $this->resolver()->fromToken($token);

        $this->assertTrue($caller->authenticated);
        $this->assertSame([], $caller->roles);
    }

    public function testMalformedCookieHeaderDoesNotThrow(): void
    {
        $request = new Request('GET', '/', ['Cookie' => '=; ;;  ; nonsense']);
        $this->assertFalse($this->resolver()->fromHandshake($request)->authenticated);
    }

    public function testCookieValuesAreUrlDecoded(): void
    {
        $parsed = WsCallerResolver::parseCookieHeader('a=one%20two');
        $this->assertSame('one two', $parsed['a']);
    }

    public function testResolverWithNoVerificationPathAtAllIsAnonymous(): void
    {
        $this->withoutEnv(['JWT_SECRET', 'ZITADEL_ISSUER', 'ZITADEL_CLIENT_ID'], function (): void {
            $resolver = WsCallerResolver::fromEnv();
            $request  = new Request('GET', '/', ['Cookie' => 'litcal_access_token=anything']);

            $this->assertFalse($resolver->fromHandshake($request)->authenticated);
            $this->assertStringContainsString('none', strtolower($resolver->describeAvailablePaths()));
        });
    }
}
```

- [ ] **Step 2: Run it and confirm it fails**

Run: `vendor/bin/phpunit phpunit_tests/Services/WsCallerResolverTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement `WsCallerResolver`**

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services;

use Firebase\JWT\JWT;
use LiturgicalCalendar\Api\Http\CookieHelper;
use LiturgicalCalendar\Api\Models\Auth\WsCaller;
use Psr\Http\Message\RequestInterface;

/**
 * Who is on the other end of a WebSocket handshake.
 *
 * Two things make this a separate service rather than a reuse of `OidcAuthMiddleware`:
 *
 *  1. The handshake carries a PSR-7 **`RequestInterface`**, not a `ServerRequestInterface`, so there
 *     is no `getCookieParams()` and the `Cookie:` header is parsed here.
 *  2. It runs inside a single-threaded ReactPHP loop, so it must not make a network call per
 *     connection. Roles are read from the token claim only; the Management-API lookup that
 *     `OidcAuthMiddleware` falls back to is deliberately absent. A Zitadel token issued without the
 *     project-roles claim therefore reads as authenticated with no roles, and is refused — which is
 *     the fail-closed direction.
 *
 * Every failure yields {@see WsCaller::anonymous()}. Nothing here throws: the connection is accepted
 * either way, and the refusal happens per action.
 */
final class WsCallerResolver
{
    public function __construct(
        private readonly ?JwtService $jwtService,
        private readonly ?string $issuer,
        private readonly ?string $internalUrl,
        private readonly int $cacheTtl = 3600
    ) {
    }

    /**
     * Build from the environment, degrading rather than throwing.
     *
     * A missing `JWT_SECRET` or a missing `ZITADEL_ISSUER` disables that one verification path and
     * leaves the other working. Disabling both is legal and means every caller is anonymous.
     */
    public static function fromEnv(): self
    {
        try {
            $jwtService = JwtServiceFactory::fromEnv();
        } catch (\Throwable) {
            $jwtService = null;
        }

        $issuerEnv      = getenv('ZITADEL_ISSUER') ?: ( $_ENV['ZITADEL_ISSUER'] ?? '' );
        $internalUrlEnv = getenv('ZITADEL_INTERNAL_URL') ?: ( $_ENV['ZITADEL_INTERNAL_URL'] ?? '' );

        $issuer      = is_string($issuerEnv) && '' !== $issuerEnv ? $issuerEnv : null;
        $internalUrl = is_string($internalUrlEnv) && '' !== $internalUrlEnv ? $internalUrlEnv : null;

        return new self($jwtService, $issuer, $internalUrl);
    }

    public function fromHandshake(?RequestInterface $request): WsCaller
    {
        if (null === $request) {
            return WsCaller::anonymous();
        }

        $cookies = self::parseCookieHeader($request->getHeaderLine('Cookie'));
        $token   = CookieHelper::getAccessToken($cookies);

        if (null === $token || '' === $token) {
            return WsCaller::anonymous();
        }

        return $this->fromToken($token);
    }

    /**
     * Zitadel first, legacy second — the same order {@see \LiturgicalCalendar\Api\Http\Middleware\OidcAuthMiddleware}
     * uses, and for the same reason: a live deployment issues RS256, while HS256 tokens still exist.
     */
    public function fromToken(string $token): WsCaller
    {
        if (null !== $this->issuer) {
            try {
                $payload = JWT::decode($token, ZitadelKeySetFactory::for($this->issuer, $this->internalUrl, $this->cacheTtl));
                $sub     = $payload->sub ?? null;
                if (is_string($sub) && '' !== $sub) {
                    return WsCaller::authenticated($sub, ZitadelRoles::fromPayload($payload));
                }
            } catch (\Throwable) {
                // Not a Zitadel token, or the key set is unreachable. Fall through to the legacy path.
            }
        }

        if (null !== $this->jwtService) {
            $payload = $this->jwtService->verify($token);
            if (null !== $payload) {
                $sub = $payload->sub ?? null;
                if (is_string($sub) && '' !== $sub) {
                    $roles = $payload->roles ?? [];
                    /** @var array<int, string> $roleList */
                    $roleList = is_array($roles) ? array_values(array_filter($roles, 'is_string')) : [];
                    return WsCaller::authenticated($sub, $roleList);
                }
            }
        }

        return WsCaller::anonymous();
    }

    /**
     * Parse a `Cookie:` header into a name => value map.
     *
     * @return array<string, string>
     */
    public static function parseCookieHeader(string $header): array
    {
        $cookies = [];

        foreach (explode(';', $header) as $pair) {
            $pair = trim($pair);
            if ('' === $pair || false === str_contains($pair, '=')) {
                continue;
            }
            [$name, $value] = explode('=', $pair, 2);
            $name           = trim($name);
            if ('' === $name) {
                continue;
            }
            $cookies[$name] = urldecode(trim($value));
        }

        return $cookies;
    }

    /**
     * Fetch the JWKS once, off the event loop, so `onOpen()` never pays for it.
     *
     * @return bool Whether a key set was fetched. False means RS256 verification is unavailable or
     *              not configured; it is not a reason to refuse to start.
     */
    public function warmKeySet(): bool
    {
        if (null === $this->issuer) {
            return false;
        }

        try {
            $keySet = ZitadelKeySetFactory::for($this->issuer, $this->internalUrl, $this->cacheTtl);
            // offsetExists() drives the fetch without needing to know a key id.
            $keySet->offsetExists('warm');
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Which verification paths are live, for a startup log line. A deployment that has silently lost
     * one of them degrades every real user to anonymous, which is safe but total, and worth saying.
     */
    public function describeAvailablePaths(): string
    {
        $paths = [];
        if (null !== $this->issuer) {
            $paths[] = 'Zitadel RS256';
        }
        if (null !== $this->jwtService) {
            $paths[] = 'legacy HS256';
        }

        return [] === $paths ? 'none (every caller will be anonymous)' : implode(' + ', $paths);
    }
}
```

- [ ] **Step 4: Run the test and confirm it passes**

Run: `vendor/bin/phpunit phpunit_tests/Services/WsCallerResolverTest.php`
Expected: PASS, 11 tests.

- [ ] **Step 5: Lint, analyse, commit**

```bash
composer lint:fix
composer analyse
git add src/Services/WsCallerResolver.php phpunit_tests/Services/WsCallerResolverTest.php
git commit -m "feat(ws): resolve the calling identity from the handshake cookie"
```

---

### Task 4: Two new protocol error codes

**Files:**

- Modify: `src/Enum/ProtocolErrorCode.php`
- Modify: `jsondata/schemas/WebSocketFrame.json` (`definitions/protocolError/properties/errorCode/enum`)
- Test: `phpunit_tests/Enum/ProtocolErrorCodeSchemaTest.php`

**Interfaces:**

- Produces: `ProtocolErrorCode::NOT_AUTHENTICATED` (`not_authenticated`), `ProtocolErrorCode::INSUFFICIENT_ROLE` (`insufficient_role`).

- [ ] **Step 1: Write the failing test**

This test also guards the pairing that is easy to break later: an enum case with no schema entry
produces a frame the published contract rejects.

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Enum;

use LiturgicalCalendar\Api\Enum\ProtocolErrorCode;
use PHPUnit\Framework\TestCase;

final class ProtocolErrorCodeSchemaTest extends TestCase
{
    public function testTheAuthenticationCodesExist(): void
    {
        $this->assertSame('not_authenticated', ProtocolErrorCode::NOT_AUTHENTICATED->value);
        $this->assertSame('insufficient_role', ProtocolErrorCode::INSUFFICIENT_ROLE->value);
    }

    public function testEveryEnumCaseIsDeclaredInTheFrameSchema(): void
    {
        $schemaPath = dirname(__DIR__, 2) . '/jsondata/schemas/WebSocketFrame.json';
        $schema     = json_decode((string) file_get_contents($schemaPath), true, 512, JSON_THROW_ON_ERROR);

        $declared = $schema['definitions']['protocolError']['properties']['errorCode']['enum'];
        $cases    = array_column(ProtocolErrorCode::cases(), 'value');

        sort($declared);
        sort($cases);
        $this->assertSame($cases, $declared, 'Every ProtocolErrorCode must be declared in WebSocketFrame.json');
    }
}
```

- [ ] **Step 2: Run it and confirm it fails**

Run: `vendor/bin/phpunit phpunit_tests/Enum/ProtocolErrorCodeSchemaTest.php`
Expected: FAIL — undefined constant `NOT_AUTHENTICATED`.

- [ ] **Step 3: Add the enum cases**

Append to `src/Enum/ProtocolErrorCode.php`, with a docblock explaining why these are two codes and not one:

```php
    /**
     * The caller is not logged in — the connection carried no usable credential.
     *
     * Separate from {@see self::INSUFFICIENT_ROLE} by the rule this enum opens with: the remedy
     * differs. This one is answered by logging in; the other cannot be, and telling a logged-in
     * `developer` to log in again is advice that cannot help.
     */
    case NOT_AUTHENTICATED = 'not_authenticated';

    /**
     * The caller is logged in, but holds none of the roles that may start a validation run.
     *
     * The remedy is to be granted a role, not to re-authenticate.
     */
    case INSUFFICIENT_ROLE = 'insufficient_role';
```

- [ ] **Step 4: Add both values to the schema**

In `jsondata/schemas/WebSocketFrame.json`, append `"not_authenticated"` and `"insufficient_role"` to
`definitions/protocolError/properties/errorCode/enum`. Edit programmatically to preserve formatting:

```bash
python3 - <<'PY'
import json, collections
p = 'jsondata/schemas/WebSocketFrame.json'
d = json.load(open(p), object_pairs_hook=collections.OrderedDict)
e = d['definitions']['protocolError']['properties']['errorCode']['enum']
for v in ('not_authenticated', 'insufficient_role'):
    if v not in e:
        e.append(v)
json.dump(d, open(p, 'w'), indent=4, ensure_ascii=False)
open(p, 'a').write('\n')
PY
git diff --stat jsondata/schemas/WebSocketFrame.json
```

Verify the diff touches only the enum array. If the whole file reformats, revert and match the
existing indent width instead.

- [ ] **Step 5: Run the test and confirm it passes**

Run: `vendor/bin/phpunit phpunit_tests/Enum/ProtocolErrorCodeSchemaTest.php`
Expected: PASS, 2 tests.

- [ ] **Step 6: Commit**

```bash
composer lint:fix
git add src/Enum/ProtocolErrorCode.php jsondata/schemas/WebSocketFrame.json phpunit_tests/Enum/ProtocolErrorCodeSchemaTest.php
git commit -m "feat(ws): add not_authenticated and insufficient_role protocol error codes"
```

---

### Task 5: Resolve, remember and advertise the caller

**Files:**

- Modify: `src/Health.php` (constructor, `onOpen()`, `onClose()`, `helloFrame()`)
- Modify: `jsondata/schemas/WebSocketFrame.json` (`definitions/hello`)
- Test: `phpunit_tests/HealthCallerFrameTest.php`

**Interfaces:**

- Consumes: `WsCaller`, `TestRunPolicy` (Task 1), `WsCallerResolver` (Task 3).
- Produces: `Health::__construct(?WsCallerResolver $callerResolver = null, ?TestRunPolicy $policy = null)`;
  `hello.caller` on the wire.

- [ ] **Step 1: Write the failing test**

`helloFrame()` is private, so reach it the way the existing `HealthHelloFrameTest` does — read that
file first and follow its access pattern rather than inventing a second one.

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests;

use LiturgicalCalendar\Api\Health;
use LiturgicalCalendar\Api\Models\Auth\WsCaller;
use PHPUnit\Framework\TestCase;

final class HealthCallerFrameTest extends TestCase
{
    private function helloFor(WsCaller $caller): \stdClass
    {
        $health = new Health();
        $method = new \ReflectionMethod(Health::class, 'helloFrame');
        /** @var \stdClass $frame */
        $frame = $method->invoke($health, $caller);
        return $frame;
    }

    public function testAnonymousCallerIsAdvertisedAsUnpermitted(): void
    {
        $frame = $this->helloFor(WsCaller::anonymous());

        $this->assertObjectHasProperty('caller', $frame);
        $this->assertFalse($frame->caller->authenticated);
        $this->assertFalse($frame->caller->permissions->runTests);
    }

    public function testTestEditorIsAdvertisedAsPermitted(): void
    {
        $frame = $this->helloFor(WsCaller::authenticated('someone', ['test_editor']));

        $this->assertTrue($frame->caller->authenticated);
        $this->assertTrue($frame->caller->permissions->runTests);
    }

    public function testAuthenticatedWithoutRoleIsAuthenticatedButUnpermitted(): void
    {
        $frame = $this->helloFor(WsCaller::authenticated('someone', ['developer']));

        $this->assertTrue($frame->caller->authenticated);
        $this->assertFalse($frame->caller->permissions->runTests);
    }

    public function testCapabilitiesAreUnchanged(): void
    {
        $frame = $this->helloFor(WsCaller::anonymous());

        $this->assertObjectHasProperty('capabilities', $frame);
        $this->assertObjectNotHasProperty('caller', $frame->capabilities);
        $this->assertSame(1, $frame->protocol);
    }
}
```

- [ ] **Step 2: Run it and confirm it fails**

Run: `vendor/bin/phpunit phpunit_tests/HealthCallerFrameTest.php`
Expected: FAIL — `helloFrame()` takes no arguments.

- [ ] **Step 3: Add the collaborators and per-connection state to `Health`**

Add the imports, two readonly properties initialized in the constructor (lazily defaulted so
construction order is unchanged — see `HealthConstructionOrderTest`), and the caller map beside
`$runTokens`:

```php
use LiturgicalCalendar\Api\Models\Auth\WsCaller;
use LiturgicalCalendar\Api\Services\TestRunPolicy;
use LiturgicalCalendar\Api\Services\WsCallerResolver;
```

```php
    /** @var array<int, WsCaller> The identity settled at handshake time, per connection. */
    private array $callers = [];

    private WsCallerResolver $callerResolver;

    private TestRunPolicy $policy;
```

`Health::__construct()` currently takes no parameters, so add
`?WsCallerResolver $callerResolver = null, ?TestRunPolicy $policy = null`.

Place the two assignments **after** the `Router::$apiFilePath` guard block, not before it. That guard
is documented as needing to come before anything that resolves a path, and while neither of these
does, moving code above it is exactly the kind of edit its comment exists to prevent:

```php
        $this->callerResolver = $callerResolver ?? WsCallerResolver::fromEnv();
        $this->policy         = $policy ?? new TestRunPolicy();
```

- [ ] **Step 4: Resolve in `onOpen()`, forget in `onClose()`**

In `onOpen()`, before the `sendMessage(...helloFrame())` call and after the `resourceId` guard:

```php
        // Settled once, here, rather than per message: the handshake is the only moment the cookie is
        // on the wire. `httpRequest` is a PSR-7 RequestInterface set by Ratchet's WsServer and reached
        // through AbstractConnectionDecorator's magic getter.
        $request                     = isset($conn->httpRequest) ? $conn->httpRequest : null;
        $caller                      = $this->callerResolver->fromHandshake($request instanceof RequestInterface ? $request : null);
        $this->callers[$resourceId]  = $caller;
```

Add `use Psr\Http\Message\RequestInterface;` to the imports, and change the hello send to
`$this->sendMessage($conn, $this->helloFrame($caller));`.

In `onClose()`, beside the other per-connection cleanup:

```php
        unset($this->callers[$resourceId]);
```

- [ ] **Step 5: Advertise on the hello frame**

Change `helloFrame()` to take the caller and attach a `caller` sibling — not a member of
`capabilities`, which is documented as server-derived and must stay that way:

```php
    private function helloFrame(WsCaller $caller): \stdClass
    {
        // ... existing $capabilities assembly, unchanged ...

        $permissions           = new \stdClass();
        $permissions->runTests = $this->policy->mayRun($caller);

        $callerFrame                = new \stdClass();
        $callerFrame->authenticated = $caller->authenticated;
        $callerFrame->permissions   = $permissions;

        $frame       = new \stdClass();
        $frame->type = 'hello';
        $frame->protocol     = max(WebSocketMessageValidator::SUPPORTED_PROTOCOL_VERSIONS);
        $frame->capabilities = $capabilities;
        // A sibling of `capabilities`, deliberately: that object answers what this *server* can be
        // asked for, every entry derived from an enum so it cannot go stale. Who is asking is a
        // different axis, and per-connection, so it does not belong inside it.
        $frame->caller = $callerFrame;

        return $frame;
    }
```

- [ ] **Step 6: Declare `caller` in the schema**

```bash
python3 - <<'PY'
import json, collections
p = 'jsondata/schemas/WebSocketFrame.json'
d = json.load(open(p), object_pairs_hook=collections.OrderedDict)
hello = d['definitions']['hello']
if 'caller' not in hello['required']:
    hello['required'].append('caller')
hello['properties']['caller'] = collections.OrderedDict([
    ('type', 'object'),
    ('description',
     'Who this server believes is on the other end of the connection, and what they may ask for. Sent '
     'on `hello` because that is the one frame every client receives before it can act, and because a '
     'permission the client reads from the server cannot drift from the one the server enforces — both '
     'come from the same policy object. `authenticated` says whether a credential was verified; '
     '`permissions` says what follows from it. A caller may be authenticated and still permitted '
     'nothing.'),
    ('required', ['authenticated', 'permissions']),
    ('properties', collections.OrderedDict([
        ('authenticated', collections.OrderedDict([('type', 'boolean')])),
        ('permissions', collections.OrderedDict([
            ('type', 'object'),
            ('required', ['runTests']),
            ('properties', collections.OrderedDict([
                ('runTests', collections.OrderedDict([
                    ('type', 'boolean'),
                    ('description',
                     'Whether this caller may start a validation run. False for an anonymous caller and '
                     'for an authenticated one holding none of the permitted roles; a client should '
                     'render its run controls from this rather than from a role list of its own.'),
                ])),
            ])),
        ])),
    ])),
])
json.dump(d, open(p, 'w'), indent=4, ensure_ascii=False)
open(p, 'a').write('\n')
PY
git diff --stat jsondata/schemas/WebSocketFrame.json
```

- [ ] **Step 7: Run the tests and confirm they pass**

Run: `vendor/bin/phpunit phpunit_tests/HealthCallerFrameTest.php phpunit_tests/HealthHelloFrameTest.php phpunit_tests/HealthConstructionOrderTest.php phpunit_tests/HealthFrameProjectionTest.php`
Expected: PASS. If `HealthHelloFrameTest` calls `helloFrame()` with no argument, update those call
sites to pass `WsCaller::anonymous()` — that is a real contract change, not a test workaround.

- [ ] **Step 8: Lint, analyse, commit**

```bash
composer lint:fix
composer analyse
git add src/Health.php jsondata/schemas/WebSocketFrame.json phpunit_tests/HealthCallerFrameTest.php phpunit_tests/HealthHelloFrameTest.php
git commit -m "feat(ws): resolve the caller at handshake and advertise it on the hello frame"
```

---

### Task 6: Gate every action

The heart of the change. Two call sites, for the reason the spec gives.

**Files:**

- Modify: `src/Health.php` (`onMessage()`)
- Test: `phpunit_tests/HealthActionGateTest.php`

**Interfaces:**

- Consumes: everything from Tasks 1, 3, 5.
- Produces: refusal frames carrying `not_authenticated` / `insufficient_role`.

- [ ] **Step 1: Write the failing test**

The `CheckableInventory::reset()` assertion is the one that proves the early placement is
load-bearing. The stub-policy test is what keeps the target-scoped seam from being dead code.

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests;

use LiturgicalCalendar\Api\Enum\ProtocolErrorCode;
use LiturgicalCalendar\Api\Health;
use LiturgicalCalendar\Api\Models\Auth\TestTarget;
use LiturgicalCalendar\Api\Models\Auth\WsCaller;
use LiturgicalCalendar\Api\Services\TestRunPolicy;
use PHPUnit\Framework\TestCase;
use Ratchet\ConnectionInterface;

final class HealthActionGateTest extends TestCase
{
    private ?string $appEnvBackup = null;

    /** @var array<int, Health> Built during a test, drained afterwards. */
    private array $built = [];

    protected function setUp(): void
    {
        parent::setUp();
        // Keeps handleHttpResponse() out of its development-only debug-logging branch, as
        // HealthFulfilHandlerThrowTest does.
        $this->appEnvBackup = isset($_ENV['APP_ENV']) && is_string($_ENV['APP_ENV']) ? $_ENV['APP_ENV'] : null;
        $_ENV['APP_ENV']    = 'test';
    }

    /**
     * Drain any work a permitted message queued.
     *
     * Health queues outbound calendar fetches and drives them from the ReactPHP loop; left in place,
     * they are issued at PHPUnit shutdown against whatever is listening on the configured API port —
     * a real HTTP request from a unit test, attributed to a suite that has already reported green.
     */
    protected function tearDown(): void
    {
        foreach ($this->built as $health) {
            ( new \ReflectionProperty(Health::class, 'queue') )->setValue($health, []);
            ( new \ReflectionProperty(Health::class, 'inFlight') )->setValue($health, 0);
        }
        $this->built = [];

        if (null === $this->appEnvBackup) {
            unset($_ENV['APP_ENV']);
        } else {
            $_ENV['APP_ENV'] = $this->appEnvBackup;
        }
        parent::tearDown();
    }

    /** @return array{0: Health, 1: ConnectionInterface, 2: array<int, \stdClass>} */
    private function serverFor(WsCaller $caller, ?TestRunPolicy $policy = null): array
    {
        $sent = new \ArrayObject();

        $conn = new class ($sent) implements ConnectionInterface {
            public int $resourceId = 1;
            public function __construct(private \ArrayObject $sent)
            {
            }
            public function send($data)
            {
                $this->sent[] = json_decode((string) $data);
                return $this;
            }
            public function close()
            {
            }
        };

        $health        = new Health(null, $policy);
        $this->built[] = $health;

        // Seed the identity the handshake would have settled.
        $callers = new \ReflectionProperty(Health::class, 'callers');
        $callers->setValue($health, [1 => $caller]);

        return [$health, $conn, $sent];
    }

    private function validateCalendarMessage(): string
    {
        return json_encode([
            'action'         => 'validateCalendar',
            'calendar'       => ['kind' => 'rite', 'rite' => 'roman'],
            'year'           => 2024,
            'responseFormat' => 'JSON',
            'requestId'      => 'req-1',
        ], JSON_THROW_ON_ERROR);
    }

    private function refusals(\ArrayObject $sent): array
    {
        return array_values(array_filter(
            iterator_to_array($sent),
            static fn(\stdClass $f): bool => 'protocolError' === ( $f->type ?? null )
        ));
    }

    public function testAnonymousCallerIsRefusedWithNotAuthenticated(): void
    {
        [$health, $conn, $sent] = $this->serverFor(WsCaller::anonymous());

        $health->onMessage($conn, $this->validateCalendarMessage());

        $refusals = $this->refusals($sent);
        $this->assertCount(1, $refusals);
        $this->assertSame(ProtocolErrorCode::NOT_AUTHENTICATED->value, $refusals[0]->errorCode);
        $this->assertSame('req-1', $refusals[0]->requestId, 'the refusal must be correlated');
    }

    public function testAuthenticatedWithoutRoleIsRefusedWithInsufficientRole(): void
    {
        [$health, $conn, $sent] = $this->serverFor(WsCaller::authenticated('u', ['developer']));

        $health->onMessage($conn, $this->validateCalendarMessage());

        $refusals = $this->refusals($sent);
        $this->assertCount(1, $refusals);
        $this->assertSame(ProtocolErrorCode::INSUFFICIENT_ROLE->value, $refusals[0]->errorCode);
    }

    public function testARefusedMessageDoesNotInstallItsRunToken(): void
    {
        [$health, $conn, ] = $this->serverFor(WsCaller::anonymous());

        $message = json_decode($this->validateCalendarMessage());
        $message->runToken = 'run-1';
        $health->onMessage($conn, json_encode($message, JSON_THROW_ON_ERROR));

        $tokens = new \ReflectionProperty(Health::class, 'runTokens');
        $this->assertSame([], $tokens->getValue($health), 'an unauthorized message must not install a run token');
    }

    public function testAPermittedCallerIsNotRefused(): void
    {
        [$health, $conn, $sent] = $this->serverFor(WsCaller::authenticated('u', ['admin']));

        $health->onMessage($conn, $this->validateCalendarMessage());

        $this->assertSame([], $this->refusals($sent));
    }

    public function testTheTargetScopedGateCanRefuseAMessageTheCoarseGateAllowed(): void
    {
        $policy = new class extends TestRunPolicy {
            public function mayRun(WsCaller $caller, ?TestTarget $target = null): bool
            {
                // Coarse question: yes. Target-scoped question: no.
                return null === $target;
            }
        };

        [$health, $conn, $sent] = $this->serverFor(WsCaller::authenticated('u', ['admin']), $policy);

        $health->onMessage($conn, $this->validateCalendarMessage());

        $refusals = $this->refusals($sent);
        $this->assertCount(1, $refusals);
        $this->assertSame(ProtocolErrorCode::INSUFFICIENT_ROLE->value, $refusals[0]->errorCode);
    }
}
```

- [ ] **Step 2: Run it and confirm it fails**

Run: `vendor/bin/phpunit phpunit_tests/HealthActionGateTest.php`
Expected: FAIL — no refusal frame is sent.

- [ ] **Step 3: Decide early, beside `$protocolViolation`**

In `onMessage()`, immediately after `$protocolViolation` is computed:

```php
        // Decided here and answered below, for the same reason `$protocolViolation` is — #894.
        //
        // The *question* has to be asked before the run-token block, because that block installs a
        // token and can rebuild the checkable inventory, and a caller this server is about to refuse
        // must be able to do neither. The *answer* waits until `requestId` has been read, so the
        // refusal reaches the client correlated to the request it is waiting on.
        //
        // This is the coarse question only — it depends on the caller, not on the message, so it can
        // be settled before the message is understood. The target-scoped question is asked further
        // down, once the message has been validated and its target can be trusted.
        $caller            = $this->callers[$resourceId] ?? WsCaller::anonymous();
        $permissionRefusal = null;
        if (null === $protocolViolation && false === $this->policy->mayRun($caller)) {
            $permissionRefusal = $caller->authenticated
                ? [ProtocolErrorCode::INSUFFICIENT_ROLE, 'This account may not start validation runs. Ask an administrator to grant the test_editor role.']
                : [ProtocolErrorCode::NOT_AUTHENTICATED, 'Log in to start validation runs.'];
        }
```

Then add `&& null === $permissionRefusal` to the condition on the run-token install block, beside the
existing `null === $protocolViolation`.

- [ ] **Step 4: Answer late, after the protocol-violation answer**

Immediately after the `if (null !== $protocolViolation) { ... return; }` block:

```php
        if (null !== $permissionRefusal) {
            [$refusalCode, $refusalText] = $permissionRefusal;
            // The connection id has no reader on the wire; it belongs in the log line, not in prose
            // the client has to display.
            echo sprintf('Refused %1$s from connection %2$d', $refusalCode->value, $resourceId);
            $this->rejectMessage($from, $refusalCode, $refusalText, requestId: $requestId, runToken: $declaredRunToken);
            return;
        }
```

- [ ] **Step 5: Ask the target-scoped question before dispatch**

Immediately before the `switch ($messageReceived->action)` dispatch block, inside the guard that has
already established the message is a validated `\stdClass` with an `action`:

```php
            // The seam the coarse check above deliberately left open. Asked here rather than at the
            // top because a target read from an unvalidated message is a guess, and a permission
            // decision must not rest on one. A coarse policy answers this the same way it answered
            // above; a fine-grained one does not, which is the whole point.
            $target = TestTarget::fromMessage($messageReceived);
            if (null !== $target && false === $this->policy->mayRun($caller, $target)) {
                $this->rejectMessage(
                    $from,
                    ProtocolErrorCode::INSUFFICIENT_ROLE,
                    'This account may not start validation runs for the calendar this message names.',
                    requestId: $requestId
                );
                return;
            }
```

Add `use LiturgicalCalendar\Api\Models\Auth\TestTarget;` to the imports.

- [ ] **Step 6: Run the tests and confirm they pass**

Run: `vendor/bin/phpunit phpunit_tests/HealthActionGateTest.php`
Expected: PASS, 5 tests.

- [ ] **Step 7: Run the whole Health suite for regressions**

Run: `vendor/bin/phpunit --filter Health`
Expected: PASS. Existing in-process Health tests construct `Health` directly and drive `onMessage()`
without a handshake, so they now see an anonymous caller and will be refused. Fix them by seeding a
permitted caller the way `HealthActionGateTest::serverFor()` does — a shared helper in
`phpunit_tests/Support/` is worth extracting once the second suite needs it.

- [ ] **Step 8: Lint, analyse, commit**

```bash
composer lint:fix
composer analyse
git add src/Health.php phpunit_tests/
git commit -m "feat(ws): refuse validation actions from callers that may not run them"
```

---

### Task 7: Authenticate the over-the-wire tests

**Files:**

- Modify: `phpunit_tests/WebSocket/WsTestClient.php`
- Modify: `phpunit_tests/WebSocket/ExecuteUnitTestTest.php`, `ExecuteValidationTest.php`, `LitCalTestServerTest.php`, `ValidateCalendarTest.php`
- Create: `phpunit_tests/Support/WsAuthTrait.php`

**Interfaces:**

- Consumes: `JwtService`.
- Produces: `WsTestClient::connect(string $host, int $port, float $timeoutSeconds = 5.0, ?string $accessToken = null): self`;
  `WsAuthTrait::wsAccessTokenOrSkip(array $roles = ['admin']): string`.

- [ ] **Step 1: Add the trait**

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Support;

use LiturgicalCalendar\Api\Services\JwtServiceFactory;

/**
 * A minted access token for the WebSocket tests.
 *
 * Skips loudly rather than letting a suite pass unexecuted: a fresh worktree has no `.env.local`, so
 * `JWT_SECRET` is absent, and a test that quietly stops exercising the gate it was written to guard
 * is worse than one that fails.
 */
trait WsAuthTrait
{
    /**
     * @param array<int, string> $roles
     */
    protected function wsAccessTokenOrSkip(array $roles = ['admin']): string
    {
        try {
            $jwtService = JwtServiceFactory::fromEnv();
        } catch (\Throwable $e) {
            $this->markTestSkipped('JWT_SECRET is not configured, so no WebSocket credential can be minted: ' . $e->getMessage());
        }

        return $jwtService->generate('phpunit', ['roles' => $roles]);
    }
}
```

- [ ] **Step 2: Send the cookie from `WsTestClient`**

Add the parameter and one header line to the hand-rolled handshake:

```php
    public static function connect(string $host, int $port, float $timeoutSeconds = 5.0, ?string $accessToken = null): self
```

and build the request with the cookie when a token is supplied:

```php
        $cookieHeader = null !== $accessToken && '' !== $accessToken
            ? 'Cookie: litcal_access_token=' . $accessToken . "\r\n"
            : '';

        $request = "GET / HTTP/1.1\r\n"
            . "Host: $host:$port\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . "Sec-WebSocket-Key: $key\r\n"
            . "Sec-WebSocket-Version: 13\r\n"
            . $cookieHeader
            . "\r\n";
```

- [ ] **Step 3: Pass a token from each of the four suites**

In each file, `use WsAuthTrait;` and change every `WsTestClient::connect($host, $port)` call to
`WsTestClient::connect($host, $port, 5.0, $this->wsAccessTokenOrSkip())`.

- [ ] **Step 4: Add anonymous coverage over the wire**

Append to `phpunit_tests/WebSocket/LitCalTestServerTest.php`:

```php
    public function testAnonymousConnectionIsAdvertisedAsUnpermitted(): void
    {
        $client = WsTestClient::connect(self::wsHost(), self::wsPort());
        $hello  = $client->readHelloFrame();

        $this->assertNotNull($hello);
        $this->assertFalse($hello->caller->authenticated);
        $this->assertFalse($hello->caller->permissions->runTests);

        $client->close();
    }
```

Match the host/port accessors this suite already uses; read it first rather than assuming the names
above.

- [ ] **Step 5: Start a server from this worktree and run the suite**

The suite talks to `WS_PORT`; a worktree's `.env.local` must name a port the shared server is not on,
or a green run proves nothing about this branch.

```bash
grep '^WS_PORT=' .env.local     # must NOT be the shared server's port
composer ws:start
vendor/bin/phpunit phpunit_tests/WebSocket
composer ws:stop
```

Expected: PASS. Prove the target before believing the result: stop the server and re-run — every test
must SKIP. A green run with no server of your own was talking to somebody else's.

- [ ] **Step 6: Commit**

```bash
composer lint:fix
git add phpunit_tests/
git commit -m "test(ws): authenticate the over-the-wire suites and cover the anonymous case"
```

---

### Task 8: Warm the key set at boot and say what is live

**Files:**

- Modify: `bin/LitCalTestServer.php`

**Interfaces:**

- Consumes: `WsCallerResolver` (Task 3).

- [ ] **Step 1: Warm before the loop starts**

Immediately before the `IoServer::factory(` call, and after the Dotenv block:

```php
// Fetch the Zitadel signing keys once, here, off the event loop. Ratchet is single-threaded, so a
// JWKS fetch inside onOpen() stalls every other connection for its duration; paying it at boot means
// steady-state handshakes are pure CPU and only a key rotation ever costs a fetch.
//
// A failure is not fatal and must not be: the server starts, and the first connection needing RS256
// pays the fetch instead. What it must never do is fail open — an unverifiable caller is anonymous.
$callerResolver = \LiturgicalCalendar\Api\Services\WsCallerResolver::fromEnv();
$warmed         = $callerResolver->warmKeySet();
echo sprintf(
    "Caller verification paths: %s. JWKS pre-warmed: %s\n",
    $callerResolver->describeAvailablePaths(),
    $warmed ? 'yes' : 'no'
);
```

and pass it in: `new Health($callerResolver)`.

- [ ] **Step 2: Start the server and read the line**

```bash
composer ws:start
sleep 2
tail -5 logs/php-error-litcaltestserver.log 2>/dev/null
composer ws:stop
```

Expected: a line naming the live paths. On a dev box with `ZITADEL_ISSUER` set but Zitadel down, expect
`JWKS pre-warmed: no` and a server that still starts.

- [ ] **Step 3: Commit**

```bash
composer lint:fix
git add bin/LitCalTestServer.php
git commit -m "feat(ws): pre-warm the JWKS off the event loop and log live verification paths"
```

---

### Task 9: Full verification

- [ ] **Step 1: Whole suite**

Run: `composer test:quick`
Expected: 0 failures. Baseline before this work was 3515 tests, 183729 assertions, 28 skipped.
A rise in skips means a credential went missing, not that a test became irrelevant — investigate any
increase.

- [ ] **Step 2: Static analysis and style**

```bash
composer analyse
composer lint
composer lint:md
```

Expected: all clean. If `composer analyse` reports "... is not a file", a sibling worktree was deleted
out from under the shared PHPStan cache: `rm -rf /tmp/phpstan` and re-run.

- [ ] **Step 3: Schema lint**

Run: `composer lint:openapi`
Expected: clean.

- [ ] **Step 4: Manual check against a real anonymous client**

```bash
composer ws:start
php -r '$s=stream_socket_client("tcp://127.0.0.1:".getenv("WS_PORT"));fwrite($s,"GET / HTTP/1.1\r\nHost: x\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: ".base64_encode(random_bytes(16))."\r\nSec-WebSocket-Version: 13\r\n\r\n");usleep(300000);echo fread($s,4096);'
composer ws:stop
```

Expected: `101 Switching Protocols` followed by a `hello` frame whose `caller.permissions.runTests` is
false. The connection must **open** — closing it is the behaviour this design deliberately rejected.

- [ ] **Step 5: Push and open the PR**

Write the PR body to a file first — a heredoc inside a `git`/`gh` invocation is refused by the
worktree-isolation guard:

```bash
git push -u origin HEAD
printf '%s\n' \
  'Closes #894.' '' \
  'The Health WebSocket server accepted any connection and dispatched run-starting actions on that' \
  'basis alone. It now identifies the caller from the handshake cookie, advertises what they may do' \
  'on the `hello` frame, and refuses `validateSource`, `executeValidation`, `validateCalendar`,' \
  '`runTest` and `cancelRun` from callers that may not run them.' '' \
  'Anonymous connections are still accepted, deliberately: both UnitTestInterface runner pages call' \
  'connect() unconditionally at module load, so closing the handshake would put every anonymous' \
  'visitor into a reconnect loop for the sake of a page that only replays past runs.' '' \
  'Design: `docs/superpowers/specs/2026-08-27-ws-health-auth-design.md`' '' \
  'UnitTestInterface follow-up is tracked in that spec; the API change is safe to deploy first.' \
  > /tmp/pr-body.md
gh pr create --base development --title "feat(ws): authenticate the Health WebSocket server (#894)" --body-file /tmp/pr-body.md
```

Target `development`, never `stable`. `gh pr merge` from a worktree exits 1 *after* merging fine —
verify with `gh pr view --json state` rather than retrying on the exit code.

---

## Follow-up: UnitTestInterface (separate repository)

Not part of this plan's deliverable, and deliberately so: the rollout is API-first, and the API change
is safe on its own — UnitTestInterface's current client ignores unknown `hello` fields and its Run
button is already role-gated client-side, so nobody who could legitimately run loses the ability
between the two deploys.

The follow-up work, in `../../UnitTestInterface`:

1. `assets/js/wsProtocol.js` — `consumeHello()` stores `frame.caller`; `resetHello()` clears it; add a
   `callerPermissions()` accessor beside `capabilities()`.
2. `assets/js/common.js` — `canRunTests()` prefers the server's verdict, falling back to
   `LitCalConfig.canRunTests` before `hello` arrives.
3. `assets/js/index.js`, `assets/js/resources.js` — `ReadyToRunTests.check()` folds in the server verdict.
4. Update the CLAUDE.md section "Reading Past Runs is Public; Running Tests is Not", which currently
   records the gap as accepted.

Note that repository gained four PR checks (phpcs, eslint, markdownlint, Playwright); the e2e runner has
no components-js symlink and no WebSocket server.
