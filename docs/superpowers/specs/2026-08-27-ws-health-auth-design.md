# Authenticating the Health WebSocket server

Design for API issue [#894](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/issues/894),
"Health WebSocket server accepts unauthenticated connections: anyone can start a validation run".

## Problem

`Health::onOpen()` accepts any WebSocket connection without authenticating it. The server is publicly
reachable at `wss://litcal-test.johnromanodorazio.com`, and `onMessage()` dispatches `validateSource`,
`executeValidation`, `validateCalendar`, `runTest` and `cancelRun` on that basis alone. A single
rite-level run scaffolds 82 calendar-data years, each one an HTTP request from the WebSocket server to
the public API. An anonymous caller can spend the API's rate-limit budget and the WebSocket server's
CPU at no cost to themselves, from a five-line script that never loads the page.

UnitTestInterface gates its Run Tests button on `JwtAuth::canRunTests()` (roles `admin` or
`test_editor`), but that gate is client-side only — its own `CLAUDE.md` records the gap as accepted.
The button is not the weak link; the socket is.

This is resource abuse, not disclosure. A run reveals only which validations fail, which is already
public through the Past Runs listing.

## Decisions

Four decisions shape everything below. The first three were settled during design; the fourth follows
from them.

1. **Refusal is per-action, not per-handshake.** Every connection is accepted. The `hello` frame
   carries the caller's permissions, and each privileged action is gated on the way in.
2. **The permission question is coarse today, with a seam for fine-grained later.** Day one mirrors
   `canRunTests()`; the policy object accepts the target from the start so an OpenFGA-backed
   implementation needs no protocol change.
3. **One credential transport: a `Cookie` header.** No subprotocol bearer, no shared-secret fallback,
   no environment bypass.
4. **No protocol version bump.** The changes are additive in the sense this contract already
   distinguishes.

### Why per-action rather than closing the handshake

The issue proposes closing the connection in `onOpen()` with a policy-violation code. That was
reconsidered on a measurement: `wsClient.connect()` is called unconditionally at module load in both
UnitTestInterface runner pages (`assets/js/index.js:2544`, `assets/js/resources.js:811`), with a
reconnect timer behind it. Every anonymous visitor opens a socket today, purely to replay past runs.

Closing the handshake on them produces a connect/close/reconnect loop and a red "disconnected" badge
unless UnitTestInterface changes in the same deploy. Per-action refusal closes the actual abuse vector
— dispatched work — while anonymous replay keeps working and the connection badge stays honest.

It also buys something the handshake approach cannot: the server can *tell* the client what it may do,
which is what removes the client-side-only gate rather than merely duplicating it.

The cost is that anonymous sockets stay open. They are idle: no work is dispatched, and no run state is
allocated. Rate-limiting them is out of scope (see [Out of scope](#out-of-scope)).

## Section 1 — Identity

### Reading the credential

`WsServer::onOpen()` assigns `$conn->httpRequest` (`vendor/plesk/ratchetphp/src/Ratchet/WebSocket/WsServer.php:131`)
and `Ratchet\AbstractConnectionDecorator::__get()` forwards attribute reads to the wrapped connection,
so `Health::onOpen()` can already reach it. No plumbing change is needed.

One catch drives a small helper: `$conn->httpRequest` is a PSR-7 **`RequestInterface`**, not a
`ServerRequestInterface`, so it has no `getCookieParams()`. The `Cookie:` header is parsed into an
array here, and that array is handed to the existing `CookieHelper::getAccessToken()` so the cookie
name lives in exactly one place.

That the cookie arrives at all is already established, not assumed: `LargeHeaderHttpServer` exists
precisely because a `COOKIE_DOMAIN`-scoped Zitadel session rides along on every handshake — measured at
~2.7 KB of `Cookie:` header — and Ratchet's 4096-byte default answered 413. The token has been arriving
all along, unread.

### Verifying it

Production cookies hold Zitadel RS256 tokens; the legacy HS256 flavour still exists. A new service
resolves both, mirroring `OidcAuthMiddleware`'s two-step:

1. **Zitadel RS256** via `Firebase\JWT\CachedKeySet` over the same `FilesystemAdapter` pattern
   `OidcAuthMiddleware.php:421` uses.
2. **Otherwise legacy HS256** via `JwtService::verify()`.

**It must not call `/auth/me`.** UnitTestInterface's `JwtAuth` docblock records that `/auth/me` verifies
with the API's HS256 service alone and answers `401 Invalid or expired token` to a perfectly valid
Zitadel token. An HTTP call per connection inside a single-threaded event loop would be the wrong shape
even if it worked.

### Not blocking the event loop

The JWKS fetch is the hazard: `CachedKeySet` fetches over HTTP on a cache miss, and Ratchet is a single
ReactPHP loop. Mitigation is to warm the key set once in `bin/LitCalTestServer.php`, before
`IoServer::factory()`. Steady-state `onOpen()` is then pure CPU, and only a key rotation costs one
blocking fetch, shared across every connection rather than paid per connection.

A failure to warm at boot is not fatal and must not be: the server starts, and the first connection
needing RS256 verification pays the fetch. What it must not do is fail *open* — see
[Failure modes](#failure-modes).

### What is remembered

Verification yields a small immutable value object per connection:

```php
final readonly class WsCaller
{
    public function __construct(
        public bool $authenticated,
        public ?string $sub,
        /** @var array<int, string> */
        public array $roles,
    ) {}
}
```

It is stored per `resourceId` alongside `runTokens`, and dropped in `onClose()` with the other
per-connection state.

**Anonymous is not an error.** No cookie, an expired token, and a garbage token all yield an anonymous
`WsCaller` and the connection proceeds. This is what decision 1 requires.

## Section 2 — The permission seam

One object answers the permission question, consulted by both the `hello` frame and the action gate, so
the two cannot drift:

```php
TestRunPolicy::mayRun(WsCaller $caller, ?TestTarget $target = null): bool
```

The day-one body is coarse: `admin` or `test_editor`. That mirrors UnitTestInterface's
`JwtAuth::RUN_TESTS_ROLES` and follows the API's own convention of an admin bypass over a required role
(`AuthorizationMiddleware`).

`WsCaller`, `TestRunPolicy` and `TestTarget` are all new. `TestScopeResolver`
(`src/Services/TestScopeResolver.php`) already exists and is what a later fine-grained policy would
resolve a `TestTarget` through.

`$target` is the seam. The coarse policy ignores it, but it is accepted and threaded from day one
because:

- every gated message already names its target (`validateCalendar` carries `calendar: {kind, rite, …}`);
- OpenFGA already models the destination object types — `national_calendar_test`,
  `diocesan_calendar_test`, `general_roman_calendar_test`, `rite_calendar_test` — with rite-qualified
  `<rite>/<calendarId>` ids resolved by `TestScopeResolver`.

Swapping in an FGA-backed policy later is then one class, with no protocol change and no new plumbing.

### On drift

The issue asks that the role check "mirror the one UnitTestInterface enforces on storing a run, so 'may
start a run' and 'may store its result' do not drift apart". This design closes that in the opposite
direction from a mirror: rather than the API hand-copying a predicate from another repository, the API
answers the question and UnitTestInterface stops deciding. A copied constant in two repositories is
exactly how the two answers would drift; a served answer cannot.

## Section 3 — Protocol

### Where the gate sits in `onMessage()`

`Health::onMessage()` already solves this shape of problem for `$protocolViolation`: the violation is
computed at the top of the method and answered further down, after `$requestId` has been read, so the
refusal is correlated to the request the client is waiting on. The file calls this deciding early and
answering late.

The permission check splits along the same seam, and the split falls out of decision 2:

- **Early**, computed beside `$protocolViolation`. This is the coarse question, which depends only on
  the caller and not on the message, so it can be answered before the message is understood. The
  run-token install block is gated on it as well as on `$protocolViolation` — otherwise an unauthorized
  caller still triggers `CheckableInventory::reset()`, which is real work and real state mutation.
  The refusal frame is sent where `$protocolViolation`'s is sent, so it carries `requestId` and
  `runToken`.
- **Later**, after schema validation and immediately before dispatch. This is the target-scoped
  question. Today it is a no-op, because the coarse policy already returned true. It is where the FGA
  check will land, and by then the target has been validated rather than guessed from an unparsed blob.

Every action is gated, `cancelRun` included. `cancelRun` only affects the sender's own connection, so
gating it changes nothing in practice; uniformity is worth more than the exception, because the next
action added inherits the gate by default.

### The `hello` frame

`hello` gains a sibling to `capabilities`, not a member of it:

```json
{
  "type": "hello",
  "protocol": 1,
  "capabilities": { "...": "unchanged" },
  "caller": {
    "authenticated": true,
    "permissions": { "runTests": true }
  }
}
```

`capabilities` is documented as what the *server* can be asked for, with every entry derived from an
enum so that an advertisement cannot go stale against the behaviour it describes. A per-connection
identity value has no business inside an object whose stated virtue is server-derivation.

`caller` is safe to send unprompted for the same reason the rest of `hello` is: the frame carries no run
correlation, and both shipped v1 runners drop untagged frames before their `type` dispatch, so a client
that does not understand it never sees it.

### Schema changes

In `jsondata/schemas/WebSocketFrame.json`:

- `definitions/hello/properties` gains `caller`; `definitions/hello/required` gains `"caller"`.
- `definitions/protocolError/properties/errorCode/enum` gains `not_authenticated` and
  `insufficient_role`.

Nothing in that schema sets `additionalProperties: false` except `capabilities.responseFormats`, so both
additions are backward-compatible for validators. Adding `caller` to `required` makes the schema reject
a `hello` from a server predating this change, which is the intended meaning: the schema describes this
server's contract, not the union of every server that ever spoke it.

### Two error codes, not one

`ProtocolErrorCode` gains:

| Case                | Value               | The client's remedy |
|---------------------|---------------------|---------------------|
| `NOT_AUTHENTICATED` | `not_authenticated` | Log in.             |
| `INSUFFICIENT_ROLE` | `insufficient_role` | Request a role.     |

The enum's own docblock states the rule: a code exists where a client would *act* differently, and where
it would only display the reason, the reason is prose in `text`. These are two different actions.
Collapsing them into one would tell a logged-in `developer` to go and log in, which is advice that
cannot help.

### No version bump

`WebSocketFrame.json` records the precedent explicitly. The terminal frame was gated behind a protocol
version because a new frame type changes the stream a v1 client counts; `protocolError` was ungated
because a new code on a frame the client already receives changes nothing for it. A new field on an
existing frame and two new codes are both the ungated kind, so `SUPPORTED_PROTOCOL_VERSIONS` is
untouched.

## Section 4 — Clients and tests

### UnitTestInterface

- `consumeHello()` (`assets/js/wsProtocol.js:457`) stores `frame.caller` beside the capabilities it
  already remembers, and `resetHello()` clears it.
- A `callerPermissions()` accessor joins `capabilities()`.
- `canRunTests()` in `assets/js/common.js` prefers the server's verdict over the render-time
  `LitCalConfig.canRunTests`, falling back to the current behaviour before `hello` arrives.
- `ReadyToRunTests.check()` folds the server verdict in.

This is the change that actually closes the gap UnitTestInterface's `CLAUDE.md` documents. Anonymous
visitors keep a green badge and working replay.

### Test client

`WsTestClient::connect()` gains an optional access token, written as a `Cookie:` header into the
hand-rolled RFC 6455 handshake. A helper mints an HS256 token via
`JwtService::generate($user, ['roles' => ['admin']])` from `JWT_SECRET` — the legacy flavour, verified
locally with no Zitadel dependency in the test path.

Four suites drive the server over the wire and need it: `phpunit_tests/WebSocket/ExecuteUnitTestTest.php`,
`ExecuteValidationTest.php`, `LitCalTestServerTest.php`, `ValidateCalendarTest.php`.

**Skip loudly, never pass silently.** A worktree with no `.env.local` has no `JWT_SECRET`. Those tests
must skip with a stated reason rather than sail through unexecuted, which is a known way for a new
regression test to "pass" without ever running.

### New coverage

| Scenario                              | Expected                                                           |
|---------------------------------------|--------------------------------------------------------------------|
| Anonymous connect                     | `hello.caller.authenticated: false`, `permissions.runTests: false` |
| Anonymous privileged action           | `protocolError` `not_authenticated`, correlated by `requestId`     |
| Anonymous privileged action           | `CheckableInventory::reset()` did **not** fire                     |
| Authenticated, no qualifying role     | `protocolError` `insufficient_role`                                |
| Authenticated `test_editor` / `admin` | Behaviour unchanged from today                                     |
| Expired or malformed token            | Treated as anonymous, connection still opens                       |

The `CheckableInventory::reset()` assertion is the one that proves the early placement in
`onMessage()` is load-bearing rather than decorative.

## Section 5 — Rollout and operations

### Order

API first, UnitTestInterface second. That order is safe rather than merely conventional: the API change
is additive, UnitTestInterface's current client ignores unknown `hello` fields, and its Run button is
already role-gated client-side. An admin still runs; an anonymous user's button was already disabled.
Nobody who could legitimately run loses the ability between the two deploys.

### Deployment

**The WebSocket server deploys by restart, not by `git pull`.** It is a long-running ReactPHP process
that never re-reads `src/`, so a pull ships half a `Health.php` change. The implementation plan must
carry this as an explicit step.

### Configuration

No new environment variables. `ZITADEL_ISSUER` and `ZITADEL_CLIENT_ID` are already present wherever the
HTTP API authenticates.

### Failure modes

Every one of these fails closed — an unverifiable caller is anonymous, and an anonymous caller may not
run:

| Condition                        | Result                                                         |
|----------------------------------|----------------------------------------------------------------|
| No `Cookie` header               | Anonymous caller; connection opens; privileged actions refused |
| `ZITADEL_ISSUER` unset           | HS256-only resolution; RS256 tokens read as anonymous          |
| Issuer set, no client/project id | RS256 path disabled entirely; see below                        |
| Token `iss` or `aud` mismatch    | Anonymous caller                                               |
| JWKS unreachable                 | RS256 tokens read as anonymous; HS256 unaffected               |
| JWKS slow rather than refused    | Bounded by a finite fetch timeout; then anonymous              |
| `JWT_SECRET` unset               | HS256 verification unavailable; those tokens read as anonymous |
| Token expired or malformed       | Anonymous caller                                               |

The `ZITADEL_ISSUER` row is the one to watch in staging: it degrades every real user to anonymous, which
is safe but total. It is worth a startup log line naming which verification paths are live.

### The audience boundary

Raised in review and worth stating as a rule rather than a row. A signature proves a token is genuine,
not that it is *this application's*: Zitadel signs every token in an instance with the same keys, so
verifying the signature alone accepts a correctly-signed token minted for any other application in that
instance — and one carrying `admin` or `test_editor` among its project roles would then be handed a run.

`OidcAuthMiddleware::tryOidcValidation()` already checks `iss` and `aud` (against the client id, and the
project id for machine-to-machine tokens) after decoding. The WebSocket resolver applies the same rule,
and treats an issuer it cannot audience-check as **no RS256 path at all** rather than as an
unaudienced one — an empty audience list must never mean "nothing to compare, so accept". That
configuration is the one most likely to look correct while behaving as though Zitadel were switched
off, so the startup line names it explicitly.

### The fetch has to be bounded

`CachedKeySet` fetches synchronously from inside `JWT::decode()` on a cold cache or an unknown `kid`,
and Guzzle's default `timeout` and `connect_timeout` are both `0` — wait indefinitely. In the HTTP API
that costs one php-fpm worker; here it stalls the single Ratchet loop for every connected client at
once. A refused connection was never the hazard, because it fails instantly; a provider that accepts the
connection and then goes quiet is, and it is indistinguishable from a healthy one until a timeout that
does not exist expires. Both clients the key-set factory builds carry finite timeouts.

## Out of scope

Deliberately deferred, and each is a separate piece of work:

- **Rate-limiting anonymous sockets.** Per-action gating means an anonymous connection allocates no run
  state, so the remaining cost is a file descriptor. Worth revisiting if idle-socket volume becomes
  measurable.
- **The fine-grained FGA policy body.** The seam is built; the implementation is not.
- **`Sec-WebSocket-Protocol` transport.** No caller needs it today. Browsers send the cookie, and
  non-browser callers can set the header.
- **Authenticating result storage.** Already enforced server-side by UnitTestInterface's `results.php`.
