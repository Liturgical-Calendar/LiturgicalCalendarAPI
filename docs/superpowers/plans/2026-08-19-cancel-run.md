# `cancelRun` Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or
> superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a stopped test run tell the Health WebSocket server it was abandoned, so the server stops fetching
calendars and validating files for nobody.

**Architecture:** The server already discards queued requests whose `runToken` no longer matches the connection's stored
token (`Health::dropSupersededQueuedRequests()`), and `cachedGet()` already tags every queue entry with its `resourceId`
and `runToken`. A new `cancelRun` action clears the stored token and reuses that existing filter; it introduces no
cancellation machinery of its own and sends nothing back. The client sends the frame from the stop branch of both
runners.

**Tech Stack:** PHP 8.4 (Ratchet WebSocket, ReactPHP event loop, Guzzle), PHPUnit 12; vanilla ES modules and
Playwright 1.57 on the client.

## Global Constraints

- **Two repositories, two branches.** Server work happens in the API worktree at
  `/home/johnrdorazio/development/LiturgicalCalendar/LiturgicalCalendarAPI-cancelrun` on branch `feat/cancel-run`
  (PR base: `development`). Client work happens in place at
  `/home/johnrdorazio/development/LiturgicalCalendar/UnitTestInterface` on branch `feat/43-cancel-run`
  (PR base: `main` — note UnitTestInterface's default branch is `main`, not `development`).
- **Never commit in the API main checkout** (`.../LiturgicalCalendarAPI`). It is shared with other concurrent agents.
  Only the worktree above. `git push` from the worktree is safe.
- **Never use `--no-verify`.** CaptainHook pre-commit runs phpcs and markdownlint; fix and re-commit instead.
- Commits are GPG-signed. If signing fails headlessly, stop and ask the user to unlock the key — do not disable signing.
- PHP style: PSR-12 per `phpcs.xml`, short array syntax, 4-space indent, single quotes unless interpolating.
- PHPStan runs at level 10 but scans `src` only, so `phpunit_tests/` is not analysed.
- The client field keeps the name `runToken`. API#806's proposed `runId` rename is explicitly out of scope.
- The server sends **no** response to `cancelRun`. UnitTestInterface PR #46 made an unrecognised response `type` paint a
  visible failure, so any new type would need matching handling in both runners.
- Spec: `docs/superpowers/specs/2026-08-19-cancel-run-design.md`.

---

## File Structure

**API (worktree `LiturgicalCalendarAPI-cancelrun`):**

| File                                    | Responsibility                                                                   |
|-----------------------------------------|----------------------------------------------------------------------------------|
| `src/Health.php`                        | Declares the action, exempts it from the ambient token store, handles it         |
| `phpunit_tests/HealthCancelRunTest.php` | In-process coverage: queue filtering, the stale-cancel guard, protocol rejection |

**UnitTestInterface (checkout `UnitTestInterface`):**

| File                     | Responsibility                                                    |
|--------------------------|-------------------------------------------------------------------|
| `e2e/websocket-stub.ts`  | Reusable `window.WebSocket` recorder that opens and never replies |
| `e2e/cancel-run.spec.ts` | Asserts both runners emit the cancel frame when a run is stopped  |
| `assets/js/index.js`     | Sends the frame from the Calendars runner's stop branch           |
| `assets/js/resources.js` | Sends the frame from the Resources runner's stop branch           |

---

## Task 1: Server-side `cancelRun`

**Files:**

- Modify: `src/Health.php` (class docblock ~line 67; `ACTION_PROPERTIES` ~line 87; `onMessage()` ~lines 365-374 and
  the switch ~line 408; a new method after `resolveRunToken()` ~line 512; `dropSupersededQueuedRequests()` docblock
  ~line 1878)
- Create: `phpunit_tests/HealthCancelRunTest.php`

**Interfaces:**

- Consumes: `Health::dropSupersededQueuedRequests()` (private, existing, unchanged behaviour);
  `Health::$runTokens` (`array<int, string>`, keyed by `resourceId`); `Health::$queue` entries shaped
  `array{url: string, options: array, resolve: \Closure, reject: \Closure, resourceId: int|null, runToken: string|null}`.
- Produces: `Health::cancelRun(string $runToken, ConnectionInterface $from): void` (private); the wire action
  `{"action": "cancelRun", "runToken": "<token>"}`; the phpstan-type `CancelRun`.

- [ ] **Step 1: Create the branch and confirm the worktree is usable**

```bash
cd /home/johnrdorazio/development/LiturgicalCalendar/LiturgicalCalendarAPI-cancelrun
git status --short
git branch --show-current   # expect: feat/cancel-run
ls vendor/bin/phpunit       # must exist; if not, run `composer install` first
```

The `vendor/` directory must be a real install, never a symlink to the main checkout's — a symlinked vendor makes
PHPUnit silently test the main checkout's `src`.

- [ ] **Step 2: Write the failing test**

Four test methods cover the spec's six cases: case 1 (matching cancel drops the run's entries) and case 6 (no frame
emitted) are the first method; cases 2 and 3 (untagged and other-connection entries survive) are the second; case 4
(stale cancel) is the third; case 5 (missing `runToken`) is the fourth.

Create `phpunit_tests/HealthCancelRunTest.php`:

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests;

use LiturgicalCalendar\Api\Health;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ratchet\ConnectionInterface;

/**
 * `cancelRun` — the client telling the server a run was abandoned.
 *
 * Stopping a run used to be purely client-side: the runner dropped incoming frames while the server
 * kept working through the abandoned run's backlog. The server already knew how to discard such
 * requests — `dropSupersededQueuedRequests()` filters queue entries whose `runToken` no longer matches
 * the connection's stored token — but only a *restart* ever advanced that token. `cancelRun` clears it
 * on demand, which is why these tests assert on the queue rather than on any response frame: the
 * action is deliberately silent.
 *
 * See UnitTestInterface#43 and #806 section H.
 */
#[CoversClass(Health::class)]
final class HealthCancelRunTest extends TestCase
{
    /**
     * A minimal Ratchet connection that records every outbound frame. `resourceId` is a dynamic public
     * property Ratchet assigns and is not part of `ConnectionInterface`, so this mirrors the stub
     * convention already used by HealthFolderStepResultTest rather than a PHPUnit mock, which would
     * trigger a dynamic-property deprecation.
     */
    private static function createStubConnection(int $resourceId)
    {
        return new class ($resourceId) implements ConnectionInterface {
            /** @var list<string> */
            public array $sent = [];

            public function __construct(public int $resourceId)
            {
            }

            public function send($data)
            {
                $this->sent[] = (string) $data;

                return $this;
            }

            public function close()
            {
            }
        };
    }

    /**
     * A queue entry shaped like the ones `cachedGet()` enqueues. The promise callbacks are never
     * invoked here — these tests only ever inspect which entries survive the filter.
     *
     * @return array<string, mixed>
     */
    private static function queueEntry(string $url, ?int $resourceId, ?string $runToken): array
    {
        return [
            'url'        => $url,
            'options'    => [],
            'resolve'    => static function (): void {
            },
            'reject'     => static function (): void {
            },
            'resourceId' => $resourceId,
            'runToken'   => $runToken
        ];
    }

    /** @param list<array<string, mixed>> $queue */
    private static function setQueue(Health $health, array $queue): void
    {
        ( new \ReflectionProperty(Health::class, 'queue') )->setValue($health, $queue);
    }

    /** @return list<array<string, mixed>> */
    private static function getQueue(Health $health): array
    {
        /** @var list<array<string, mixed>> */
        return ( new \ReflectionProperty(Health::class, 'queue') )->getValue($health);
    }

    /** @param array<int, string> $tokens */
    private static function setRunTokens(Health $health, array $tokens): void
    {
        ( new \ReflectionProperty(Health::class, 'runTokens') )->setValue($health, $tokens);
    }

    /** @return array<int, string> */
    private static function getRunTokens(Health $health): array
    {
        /** @var array<int, string> */
        return ( new \ReflectionProperty(Health::class, 'runTokens') )->getValue($health);
    }

    /** @param array<string, string> $payload */
    private static function cancel(Health $health, ConnectionInterface $conn, array $payload): void
    {
        $health->onMessage($conn, (string) json_encode($payload));
    }

    public function testCancellingTheCurrentRunDropsItsQueuedRequestsAndSaysNothing(): void
    {
        $health = new Health();
        $conn   = self::createStubConnection(1);

        self::setRunTokens($health, [1 => 'run-a']);
        self::setQueue($health, [
            self::queueEntry('https://example.test/a', 1, 'run-a'),
            self::queueEntry('https://example.test/b', 1, 'run-a')
        ]);

        self::cancel($health, $conn, ['action' => 'cancelRun', 'runToken' => 'run-a']);

        self::assertSame([], self::getQueue($health), 'the cancelled run keeps no queued work');
        self::assertSame([], self::getRunTokens($health), 'the connection is no longer on any run');
        self::assertSame([], $conn->sent, 'cancelRun is acknowledged by silence, not by a frame');
    }

    public function testUntaggedAndOtherConnectionsRequestsSurvive(): void
    {
        $health = new Health();
        $conn   = self::createStubConnection(1);

        self::setRunTokens($health, [1 => 'run-a', 2 => 'run-b']);
        self::setQueue($health, [
            // The connect-time metadata fetch carries no run token and belongs to no run.
            self::queueEntry('https://example.test/metadata', null, null),
            self::queueEntry('https://example.test/mine', 1, 'run-a'),
            self::queueEntry('https://example.test/theirs', 2, 'run-b')
        ]);

        self::cancel($health, $conn, ['action' => 'cancelRun', 'runToken' => 'run-a']);

        self::assertSame(
            ['https://example.test/metadata', 'https://example.test/theirs'],
            array_column(self::getQueue($health), 'url')
        );
        self::assertSame([2 => 'run-b'], self::getRunTokens($health), 'the other connection keeps its run');
    }

    public function testAStaleCancelDoesNotTouchTheRunThatReplacedIt(): void
    {
        $health = new Health();
        $conn   = self::createStubConnection(1);

        // The user stopped and restarted faster than the cancel frame travelled: the connection is
        // already on run-b when the cancel naming run-a arrives.
        self::setRunTokens($health, [1 => 'run-b']);
        self::setQueue($health, [self::queueEntry('https://example.test/new-run', 1, 'run-b')]);

        self::cancel($health, $conn, ['action' => 'cancelRun', 'runToken' => 'run-a']);

        self::assertCount(1, self::getQueue($health), 'the new run keeps its queued work');
        self::assertSame([1 => 'run-b'], self::getRunTokens($health), 'the new run keeps its token');
        self::assertSame([], $conn->sent);
    }

    public function testACancelWithoutARunTokenIsRejectedAndChangesNothing(): void
    {
        $health = new Health();
        $conn   = self::createStubConnection(1);

        self::setRunTokens($health, [1 => 'run-a']);
        self::setQueue($health, [self::queueEntry('https://example.test/a', 1, 'run-a')]);

        self::cancel($health, $conn, ['action' => 'cancelRun']);

        self::assertCount(1, self::getQueue($health));
        self::assertSame([1 => 'run-a'], self::getRunTokens($health));

        self::assertCount(1, $conn->sent, 'a malformed cancel is a protocol error, and those are visible');
        $frame = json_decode($conn->sent[0]);
        self::assertSame('echobot', $frame->type);
        self::assertSame('Invalid message properties', $frame->errorMsg);
    }
}
```

- [ ] **Step 3: Run the test to verify it fails**

```bash
cd /home/johnrdorazio/development/LiturgicalCalendar/LiturgicalCalendarAPI-cancelrun
vendor/bin/phpunit phpunit_tests/HealthCancelRunTest.php
```

Expected: **4 failures**, roughly —

- `testCancellingTheCurrentRunDropsItsQueuedRequestsAndSaysNothing`: the queue still holds 2 entries and `$conn->sent`
  holds one `echobot` frame, because `cancelRun` reaches the switch's `default:` branch.
- `testUntaggedAndOtherConnectionsRequestsSurvive`: all three URLs still present.
- `testAStaleCancelDoesNotTouchTheRunThatReplacedIt`: passes on the queue assertion but the ambient token store has
  overwritten `run-b` with `run-a`, so the `runTokens` assertion fails.
- `testACancelWithoutARunTokenIsRejectedAndChangesNothing`: the emitted frame has no `errorMsg` property (the `default:`
  branch sets only `type` and `text`), so the last assertion fails.

A PHP warning about `foreach()` on `null` may also appear: `validateMessageProperties()` indexes
`ACTION_PROPERTIES['cancelRun']`, which does not exist yet. That warning disappears in Step 4.

- [ ] **Step 4: Declare the action's required properties**

In `src/Health.php`, extend `ACTION_PROPERTIES`:

```php
    private const ACTION_PROPERTIES = [
        'executeValidation' => ['category', 'validate', 'sourceFile'],
        'validateCalendar'  => ['category', 'calendar', 'year', 'responsetype'],
        'executeUnitTest'   => ['category', 'calendar', 'year', 'test'],
        'cancelRun'         => ['runToken']
    ];
```

`runToken` is optional on every other action. Requiring it here means a cancel that omits it is rejected by the existing
`validateMessageProperties()` path instead of reaching a handler with nothing to match on.

- [ ] **Step 5: Add the `CancelRun` phpstan-type**

In the class docblock, after the `ExecuteUnitTest` line:

```php
 * @phpstan-type CancelRun \stdClass&object{action:'cancelRun',runToken:string}
```

and widen the `@var` union inside `onMessage()`:

```php
        /** @var ExecuteValidationSourceFolder|ExecuteValidationSourceFile|ExecuteValidationResource|ValidateCalendar|ExecuteUnitTest|CancelRun $messageReceived */
```

- [ ] **Step 6: Exempt `cancelRun` from the ambient token store**

Replace the token-store block near the top of `onMessage()`:

```php
        // Store optional run token for response correlation. `cancelRun` is exempt: it names the run it
        // wants abandoned rather than the run this connection is on, and storing it here would install
        // the very token cancelRun() is about to clear — making even a stale cancel match, and dropping
        // the queue of the run that replaced it.
        if (
            $messageReceived instanceof \stdClass
            && property_exists($messageReceived, 'action')
            && $messageReceived->action !== 'cancelRun'
            && property_exists($messageReceived, 'runToken')
            && is_string($messageReceived->runToken)
            && preg_match('/^[A-Za-z0-9_\-]{1,64}$/', $messageReceived->runToken)
        ) {
            $this->runTokens[$resourceId] = $messageReceived->runToken;
        }
```

- [ ] **Step 7: Dispatch the action**

In the `switch ($messageReceived->action)` block, immediately before `default:`:

```php
                case 'cancelRun':
                    /** @var CancelRun $messageReceived */
                    $this->cancelRun($messageReceived->runToken, $from);
                    break;
```

- [ ] **Step 8: Implement the handler**

Insert immediately after `resolveRunToken()`:

```php
    /**
     * Abandon a run: forget its token, so its queued requests stop being work worth doing.
     *
     * Sent by the client when the user stops a run, so the server does not keep fetching calendars and
     * validating files for a run nobody is watching. Only the queued backlog is dropped — requests
     * already in flight are capped at maxConcurrency and their frames are discarded client-side, so
     * chasing them would buy little for a great deal of plumbing.
     *
     * The token must match what this connection is currently running. A cancel naming a run the
     * connection has already left — the user stopped and restarted faster than the frame travelled —
     * is a no-op: acting on it would clear the token of the run that *replaced* it and drop that run's
     * queue instead, which is a worse bug than the one this fixes.
     *
     * Nothing is sent back; see #806 section H.
     *
     * @param string $runToken The run the client wants abandoned.
     * @param ConnectionInterface $from The connection that asked.
     */
    private function cancelRun(string $runToken, ConnectionInterface $from): void
    {
        $resourceId = $from->resourceId;
        if (false === is_int($resourceId) || ( $this->runTokens[$resourceId] ?? null ) !== $runToken) {
            return;
        }
        unset($this->runTokens[$resourceId]);
        $this->dropSupersededQueuedRequests();
    }
```

`dropSupersededQueuedRequests()` is called rather than `processQueue()` on purpose: dropping is the whole point of a
cancel, whereas `processQueue()` would additionally dispatch the surviving requests and re-arm the tick timer as a side
effect. The tick loop already running picks up the shortened queue by itself.

The `is_int($resourceId)` test is not only defensive: `resourceId` is a dynamic property Ratchet assigns and is not
declared on `ConnectionInterface`, so PHPStan level 10 sees it as `mixed`. Without the test, using it as an array key
fails `composer analyse`.

- [ ] **Step 9: Update the filter's docblock to name its second trigger**

In `dropSupersededQueuedRequests()`, replace the opening sentence:

```php
    /**
     * Drop queued requests whose run this connection is no longer on. Two things cause that: the client
     * stopped and started a new run, so the connection's stored token advanced; or the client sent
     * `cancelRun` and {@see Health::cancelRun()} cleared the stored token outright. Their responses would
     * be discarded by the client anyway, so skipping the work lets a restarted run dispatch immediately
     * instead of first draining the abandoned run's backlog. Untagged requests (e.g. the metadata fetch
     * on connect) carry no token and are always kept. In-flight requests are not affected (they are few —
     * capped at maxConcurrency — and their responses are discarded client-side).
     */
```

- [ ] **Step 10: Run the test to verify it passes**

```bash
cd /home/johnrdorazio/development/LiturgicalCalendar/LiturgicalCalendarAPI-cancelrun
vendor/bin/phpunit phpunit_tests/HealthCancelRunTest.php
```

Expected: `OK (4 tests, ...)`.

- [ ] **Step 11: Run lint and static analysis**

```bash
cd /home/johnrdorazio/development/LiturgicalCalendar/LiturgicalCalendarAPI-cancelrun
composer lint
composer analyse
```

Expected: both clean. If `composer analyse` fails with a path "is not a file" error, the PHPStan result cache is shared
across worktrees and stale: run `rm -rf /tmp/phpstan` and retry (`--clear-result-cache` alone is not enough).

- [ ] **Step 12: Run the wider suite**

```bash
cd /home/johnrdorazio/development/LiturgicalCalendar/LiturgicalCalendarAPI-cancelrun
composer test:quick
```

Use the composer script, never a bare `phpunit --exclude-group` — a CLI `--exclude-group` overrides the XML config and
un-fences the golden-master-generate group, which silently rewrites the fixtures it is checked against.

Expected: no new failures. `phpunit_tests/WebSocket/ExecuteValidationTest.php` self-skips because no WebSocket server is
running, and `Routes/*` tests self-skip or hit the main checkout's server on `:8000` — a green result there says nothing
about this branch, so judge only on `HealthCancelRunTest` plus the absence of *new* failures elsewhere.

- [ ] **Step 13: Commit**

```bash
cd /home/johnrdorazio/development/LiturgicalCalendar/LiturgicalCalendarAPI-cancelrun
git add src/Health.php phpunit_tests/HealthCancelRunTest.php
git commit -m "feat(health): let a client abandon a run with cancelRun

Stopping a run was purely client-side: the runner dropped incoming frames
while the server worked through the whole abandoned backlog — 81 calendar
requests plus their validation on a wide year range.

The server already knew how to discard that work. dropSupersededQueuedRequests()
filters queue entries whose runToken no longer matches the connection's stored
token, and cachedGet() tags every entry with both. Only a restart ever advanced
the token, so stop-and-walk-away leaked. cancelRun clears it on demand and
reuses the same filter.

The token must match the connection's current run. A cancel naming a run the
connection has already left would otherwise clear the token of the run that
replaced it and drop *its* queue. Same reason cancelRun is exempt from the
ambient token store at the top of onMessage: storing the token would make every
stale cancel match.

Silent by design. UnitTestInterface#46 made an unrecognised response type paint
a visible failure, so an ack frame would need handling in both runners first.

Refs UnitTestInterface#43, #806

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

## Task 2: Client-side cancel frame

**Files:**

- Create: `e2e/websocket-stub.ts`
- Create: `e2e/cancel-run.spec.ts`
- Modify: `assets/js/index.js` (stop branch, ~line 1640-1644)
- Modify: `assets/js/resources.js` (stop branch, ~line 1209-1213)

**Interfaces:**

- Consumes: the wire action `{"action": "cancelRun", "runToken": "<token>"}` produced by Task 1.
- Produces: `installWebSocketStub(page: Page): Promise<void>` from `e2e/websocket-stub.ts`, which sets
  `window.__wsSent: string[]` in the page — reusable by later protocol work (#42).

- [ ] **Step 1: Create the branch and start the servers this task needs**

```bash
cd /home/johnrdorazio/development/LiturgicalCalendar/UnitTestInterface
git checkout main && git pull && git checkout -b feat/43-cancel-run
```

The spec stubs the WebSocket, so **no WebSocket server is needed**. Two things are: the API on `:8000`, whose
`/calendars`, `/missals` and `/tests` responses gate the start button, and the UnitTestInterface pages on `:3003`.

Both come from the docker stack in the frontend repo. Bringing up `litcal-tests` pulls in `litcal-api` as a dependency:

```bash
cd /home/johnrdorazio/development/LiturgicalCalendar/LiturgicalCalendarFrontend
docker compose up -d litcal-tests
curl -s -o /dev/null -w '%{http_code}\n' http://localhost:8000/calendars   # expect 200
curl -s -o /dev/null -w '%{http_code}\n' http://localhost:3003/            # expect 200
```

`docker-compose.override.yml` bind-mounts `../UnitTestInterface/assets:ro` into the `litcal-tests` container, so the
edits in Steps 5 and 6 are served live with no rebuild. It bind-mounts `../UnitTestInterface/index.php`,
`resources.php` and friends as **single files**, which pin their inodes — if a git operation ever replaces one of those
files the container keeps serving the old content until `docker compose up -d --force-recreate litcal-tests`. This task
only edits `assets/`, a directory mount, so that trap does not apply here.

**The `:8000` API container mounts the *main* API checkout's `src`, not the `feat/cancel-run` worktree.** The server-side
`cancelRun` from Task 1 is therefore *not* running behind `:8000` or `:8082`. That is fine — this task stubs the
WebSocket and never reaches a real server — but do not attempt a live end-to-end check here and do not read anything
into `:8082`'s behaviour. Task 1's coverage is the in-process PHPUnit test.

- [ ] **Step 2: Write the WebSocket stub**

Create `e2e/websocket-stub.ts`:

```typescript
import { Page } from '@playwright/test';

/**
 * Replaces `window.WebSocket` with a recorder that reports itself open and never replies.
 *
 * Both runners drive themselves from `conn.onmessage` — the next request is only sent once the previous
 * response has been handled — so a stub that never answers parks a started run after its first outbound
 * frame. That is exactly the state the stop button exists for, and it is reachable without a WebSocket
 * server, which `playwright.config.ts` does not start.
 *
 * Every outbound frame is recorded on `window.__wsSent` for assertions.
 *
 * Must be installed before navigation: the runners construct their WebSocket at module scope.
 */
export const installWebSocketStub = async (page: Page): Promise<void> => {
    await page.addInitScript(() => {
        const sent: string[] = [];
        (window as unknown as { __wsSent: string[] }).__wsSent = sent;

        class StubWebSocket {
            static readonly CONNECTING = 0;
            static readonly OPEN = 1;
            static readonly CLOSING = 2;
            static readonly CLOSED = 3;

            readonly CONNECTING = 0;
            readonly OPEN = 1;
            readonly CLOSING = 2;
            readonly CLOSED = 3;

            readyState = 0;
            onopen: ((event: unknown) => void) | null = null;
            onmessage: ((event: unknown) => void) | null = null;
            onclose: ((event: unknown) => void) | null = null;
            onerror: ((event: unknown) => void) | null = null;

            constructor(public readonly url: string) {
                // `onopen` is assigned after the constructor returns, so open on the next tick.
                setTimeout(() => {
                    this.readyState = 1;
                    this.onopen?.({});
                }, 0);
            }

            send(data: string): void {
                sent.push(data);
            }

            close(): void {
                this.readyState = 3;
            }

            addEventListener(): void {}

            removeEventListener(): void {}
        }

        (window as unknown as { WebSocket: unknown }).WebSocket = StubWebSocket;
    });
};

/** Every frame the page has sent, oldest first. */
export const sentFrames = (page: Page): Promise<string[]> =>
    page.evaluate(() => (window as unknown as { __wsSent: string[] }).__wsSent ?? []);
```

- [ ] **Step 3: Write the failing spec**

Create `e2e/cancel-run.spec.ts`:

```typescript
import { test, expect, Page } from '@playwright/test';
import { installWebSocketStub, sentFrames } from './websocket-stub';

/**
 * Stopping a run tells the server (UnitTestInterface#43, LiturgicalCalendarAPI#806 section H).
 *
 * Stopping used to be purely local: state was reset, incoming frames were dropped, and the server kept
 * fetching calendars for a run nobody was watching. Both runners must now send `cancelRun` naming the
 * run being abandoned.
 *
 * The WebSocket is stubbed, so these specs need no WebSocket server — only the API on :8000, whose
 * /calendars, /missals and /tests responses gate the start button.
 */

/** Start a run, then stop it, returning every frame the page sent. */
const startThenStop = async (page: Page, path: string): Promise<string[]> => {
    await installWebSocketStub(page);
    await page.goto(path);

    const startBtn = page.locator('#startTestRunnerBtn');
    await expect(startBtn).toBeEnabled({ timeout: 20000 });

    await startBtn.click();
    // The stub never replies, so the run parks after its first request. Wait for that request rather
    // than for a fixed delay, so the cancel is provably the *second* thing sent.
    await expect.poll(async () => (await sentFrames(page)).length, { timeout: 10000 }).toBeGreaterThan(0);

    await startBtn.click(); // same button, now in its stop role
    return sentFrames(page);
};

test('the Calendars runner tells the server when a run is stopped', async ({ page }) => {
    const frames = await startThenStop(page, '/');

    const cancel = JSON.parse(frames[frames.length - 1]);
    expect(cancel.action).toBe('cancelRun');
    expect(typeof cancel.runToken).toBe('string');

    // The cancel must name the run it is abandoning, not some fresh value.
    const firstRequest = JSON.parse(frames[0]);
    expect(cancel.runToken).toBe(firstRequest.runToken);
});

test('the Resources runner tells the server when a run is stopped', async ({ page }) => {
    const frames = await startThenStop(page, '/resources.php');

    const cancel = JSON.parse(frames[frames.length - 1]);
    expect(cancel.action).toBe('cancelRun');
    expect(typeof cancel.runToken).toBe('string');

    const firstRequest = JSON.parse(frames[0]);
    expect(cancel.runToken).toBe(firstRequest.runToken);
});
```

- [ ] **Step 4: Run the spec to verify it fails**

```bash
cd /home/johnrdorazio/development/LiturgicalCalendar/UnitTestInterface
npx playwright test e2e/cancel-run.spec.ts --project=chromium --no-deps
```

`--no-deps` skips the `setup` project, which authenticates against Zitadel and is unrelated here; the stored
`e2e/.auth/user.json` is reused.

Expected: **2 failures**. The last recorded frame is still the run's final validation request, so
`expect(cancel.action).toBe('cancelRun')` fails with the action of whatever the runner sent last
(`executeValidation` or `validateCalendar`).

- [ ] **Step 5: Send the cancel from the Calendars runner**

In `assets/js/index.js`, in the stop branch of the `#startTestRunnerBtn` click handler, insert immediately after
`console.log( 'Stopping test run...' );` and before `currentState = TestState.Stopped;`:

```javascript
        // Tell the server the run is abandoned, so it stops draining a backlog nobody is watching.
        // Must happen before currentRunToken is cleared: sendMessage() attaches the token, and the
        // cancel has to name the run it is stopping. The explicit null test matters because
        // sendMessage() omits the token when there is none, and a cancel without one is a protocol
        // error the server rejects.
        if ( conn.readyState === WebSocket.OPEN && currentRunToken !== null ) {
            sendMessage( { action: 'cancelRun' } );
        }
```

- [ ] **Step 6: Send the cancel from the Resources runner**

In `assets/js/resources.js`, in the stop branch of the `#startTestRunnerBtn` click handler, insert immediately after
`console.log( 'Stopping test run...' );` and before `currentState = TestState.Stopped;`:

```javascript
        // Tell the server the run is abandoned, so it stops draining a backlog nobody is watching.
        // Must happen before currentRunToken is cleared: sendMessage() attaches the token, and the
        // cancel has to name the run it is stopping. The explicit null test matters because
        // sendMessage() omits the token when there is none, and a cancel without one is a protocol
        // error the server rejects.
        if ( conn.readyState === WebSocket.OPEN && currentRunToken !== null ) {
            sendMessage( { action: 'cancelRun' } );
        }
```

- [ ] **Step 7: Run the spec to verify it passes**

```bash
cd /home/johnrdorazio/development/LiturgicalCalendar/UnitTestInterface
npx playwright test e2e/cancel-run.spec.ts --project=chromium --no-deps
```

Expected: `2 passed`.

If instead the start button never enables, the API on `:8000` is not up — fix that rather than relaxing the assertion.
If the run does not park after its first frame, report it; do not replace the poll with a fixed `waitForTimeout`.

- [ ] **Step 8: Typecheck and syntax-check**

```bash
cd /home/johnrdorazio/development/LiturgicalCalendar/UnitTestInterface
npm run typecheck
node --check assets/js/index.js
node --check assets/js/resources.js
```

Expected: all clean.

- [ ] **Step 9: Check for regressions in the specs that share this surface**

```bash
cd /home/johnrdorazio/development/LiturgicalCalendar/UnitTestInterface
npx playwright test e2e/result-painting.spec.ts e2e/results-replay.spec.ts e2e/results-replay-resources.spec.ts e2e/rite-selection.spec.ts --project=chromium --no-deps
```

Expected: all pass. These are the specs that touch the runners and the painter.

- [ ] **Step 10: Commit**

```bash
cd /home/johnrdorazio/development/LiturgicalCalendar/UnitTestInterface
git add e2e/websocket-stub.ts e2e/cancel-run.spec.ts assets/js/index.js assets/js/resources.js
git commit -m "fix(runner): tell the server when a run is stopped

Stopping a run was purely local: state was reset, incoming frames were
dropped, and the server kept fetching calendars and validating files for a
run nobody was watching. On a wide year range that is 81 calendar requests
plus all their downstream validation, wasted on every stopped run.

Both runners now send {action: 'cancelRun', runToken} before clearing the
token — the cancel has to name the run it is abandoning. The readyState
guard matters because stop is also reachable while the socket is
reconnecting; a closed socket needs no cancel, since the server drops the
run's queued work when the connection closes.

The server side is LiturgicalCalendarAPI#806 section H. It answers with
silence: PR #46 made an unrecognised response type paint a visible failure,
so an ack frame would have needed handling here first.

Verified with a new e2e spec that stubs window.WebSocket with a recorder
that opens and never replies, parking the run after its first request. The
stub needs no WebSocket server — playwright.config.ts starts none — and is
written for reuse by the protocol work in #42.

Closes #43

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

## Task 3: Publish and cross-link

**Files:** none — repository and issue metadata only.

**Interfaces:**

- Consumes: the branches produced by Tasks 1 and 2.

- [ ] **Step 1: Push both branches**

```bash
cd /home/johnrdorazio/development/LiturgicalCalendar/LiturgicalCalendarAPI-cancelrun
git push -u origin feat/cancel-run

cd /home/johnrdorazio/development/LiturgicalCalendar/UnitTestInterface
git push -u origin feat/43-cancel-run
```

- [ ] **Step 2: Open the server PR**

Base `development`. Body must state: what leaked (stop-and-walk-away), that
`dropSupersededQueuedRequests()` already existed and is reused unchanged, why the token-match guard is load-bearing, why
the action is silent, and the verification output from Task 1 Steps 10-12.

- [ ] **Step 3: Open the client PR**

Base `main` (not `development`). Body must state the same wire contract, link the server PR, and carry the verification
output from Task 2 Steps 7-9. Note explicitly that the WebSocket stub is groundwork for #42.

- [ ] **Step 4: Comment on UnitTestInterface#43**

Record which of its items this closes (item 3, the missing cancel message) and which it does not: the mid-run reconnect
inconsistency, which is a resume-vs-abort design decision overlapping #28 rather than a bug fix. State that #43 closes on
the client PR and that the reconnect item should move to #28.

- [ ] **Step 5: Comment on API#806**

Record that section H is implemented ahead of the rest of the proposal, using today's `runToken` field name rather than
the proposed `runId`, so the rename is one line when the contract lands.
