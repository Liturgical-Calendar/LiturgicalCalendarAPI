# `cancelRun`: telling the Health server a run was abandoned

Design for the last open item in [UnitTestInterface#43](https://github.com/Liturgical-Calendar/UnitTestInterface/issues/43),
implementing section H of [API#806](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/issues/806).

## Problem

Stopping a test run in UnitTestInterface is currently a purely client-side act. The stop branch of both runners sets
`currentState = TestState.Stopped` and `currentRunToken = null`, after which `conn.onmessage` drops every arriving frame.
**No message is sent to the server.** The server keeps fetching calendars and validating files for a run nobody is watching.

On a wide year range that is 81 calendar requests plus all their downstream validation, every one of them wasted.

### What already exists

The server is closer to solving this than #43 assumed. `Health::dropSupersededQueuedRequests()` already discards queued
requests whose `runToken` no longer matches the connection's stored token, and queue entries are already tagged with both
`resourceId` and `runToken` at enqueue time in `cachedGet()`.

That covers **stop-then-restart**: a new run overwrites the connection's stored token, and the abandoned run's backlog is
filtered on the next `processQueue()` pass.

The gap is **stop-and-walk-away**. Nothing advances the stored token, so nothing is ever filtered, and the server drains
the entire abandoned backlog.

So this design is not "add cancellation to the server". It is "give the client a way to trigger the drop the server can
already do".

## Scope

| In scope                                                    | Out of scope                                                        |
|-------------------------------------------------------------|---------------------------------------------------------------------|
| A `cancelRun` action that drops a run's **queued** requests | Aborting in-flight HTTP requests                                    |
| The client-side send in both runners' stop branches         | Aborting local file reads and schema validation for a cancelled run |
| A `CancelRun` phpstan-type alongside the existing five      | The rest of API#805's stale annotations                             |
| Server-side unit coverage, client-side e2e coverage         | Any part of the v2 contract (API#806 sections A-G)                  |
| —                                                           | The `runToken` → `runId` rename API#806 proposes                    |

In-flight requests are deliberately left alone. They are capped at `maxConcurrency` — 4 in development, 10 in production —
and the client already discards their result frames because `currentRunToken` is `null`. Chasing them would mean threading
a cancellation check through roughly fifteen call sites in `Health.php` that currently thread only `$runToken`, for a
bounded and small saving.

The field keeps the name `runToken` rather than adopting API#806's proposed `runId`. Shipping a real fix today is worth
more than pre-aligning with a contract that is still a proposal; the rename, when it lands, is one line here.

## Server design — `src/Health.php`

### 1. Declare the action's required properties

`ACTION_PROPERTIES` gains:

```php
'cancelRun' => ['runToken']
```

`runToken` is optional on every other action. Making it **required** here means a cancel that omits it is rejected by the
existing `validateMessageProperties()` path — surfacing as the standard protocol error — rather than reaching a handler
that would have to decide what to clear. It also gives the unguarded `ACTION_PROPERTIES[$message->action]` lookup a
defined entry for this action.

### 2. Exempt `cancelRun` from the ambient token store

`onMessage()` stores `runToken` from *any* inbound message before the switch runs. Left alone, a cancel would install the
very token it is about to clear, and the match test in step 3 would always succeed — including for a stale cancel. The
store is therefore gated on `$messageReceived->action !== 'cancelRun'`.

### 3. The handler

```php
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

The match test is the load-bearing line. A cancel that names a run the connection is no longer on — the user stopped and
restarted faster than the frame travelled — must be a no-op. Without the test it would clear the **new** run's token and
drop the new run's queue, which is a worse bug than the one being fixed.

`dropSupersededQueuedRequests()` is called directly rather than `processQueue()`. Dropping is the entire point of a
cancel; `processQueue()` would additionally dispatch the surviving requests and re-arm the tick timer as a side effect.
The tick loop that is already running picks up the shortened queue on its own.

### 4. No new response vocabulary

The server sends nothing back. This matches API#806 section H, and it matters more than it looks: UnitTestInterface PR #46
just made an unrecognised response `type` paint a **visible failure**, so any acknowledgement frame would need matching
handling in both runners or it would surface in the UI as a spurious failed check. A silent action adds nothing to a
protocol that is about to be redesigned.

### 5. Why `dropSupersededQueuedRequests()` needs no code change

Its predicate keeps an entry when any of these holds:

```php
null === $item['resourceId']                                   // untagged, e.g. the connect-time metadata fetch
|| null === $item['runToken']                                  // enqueued before any run token was set
|| ( $this->runTokens[$item['resourceId']] ?? null ) === $item['runToken']
```

With the stored token unset, the third clause evaluates `null === 'run-a'` for the cancelled run's entries, so they are
filtered. Untagged entries and other connections' entries are already protected by the first two clauses. Only the
docblock changes, to name the second trigger — it currently describes supersession alone.

## Client design — UnitTestInterface

The stop branches of `assets/js/index.js` and `assets/js/resources.js` are identical in shape. Rather than pasting the
same block into both, the behaviour goes into a new shared module, `assets/js/wsProtocol.js`:

```javascript
export const sendCancelRun = ( conn, runToken ) => {
    if ( !conn || conn.readyState !== WebSocket.OPEN ) {
        return false;
    }
    if ( typeof runToken !== 'string' || runToken === '' ) {
        return false;
    }
    conn.send( JSON.stringify( { action: 'cancelRun', runToken } ) );
    return true;
};
```

Both runners import it and call `sendCancelRun( conn, currentRunToken )` immediately before `currentRunToken = null` —
the cancel has to name the run it is stopping.

The two runners are already independent implementations of the same protocol that have drifted apart in ways that cost
debugging sessions — different state names, different `runToken` guards, two vocabularies for the same file. Adding a
fourth thing to keep in lockstep would be the wrong direction, and both files are already ES modules importing from
`./common.js`, so a shared module costs nothing in page wiring. `wsProtocol.js` is the seed of the shared protocol
client #42 will grow.

The `readyState` guard matters because the stop button is also reachable while the socket is reconnecting. A closed socket
needs no cancel: `Health::onClose()` already unsets the connection's stored token, and the queue drains on the next pass.
The token test is the second half of the same care — a cancel carrying no token is a protocol error the server rejects,
and since PR #46 the UI would paint that rejection as a failed check.

Returning a boolean is what makes the two guard branches testable at all: neither is reachable by clicking.

Nothing else in the stop branch changes, and no response handling is added.

The stop button is the **only** trigger. Navigating away or closing the tab needs no cancel: the socket closes, and
`Health::onClose()` already unsets the connection's stored token, which produces the same drop on the next queue pass.

## Data flow

1. User clicks stop while a run is in flight.
2. Client sends `{action: 'cancelRun', runToken: <the run's own token>}`, then performs its existing teardown.
3. Server matches the token against the connection's stored token; on a match, clears it.
4. `dropSupersededQueuedRequests()` filters every queued request tagged with that token.
5. Requests already in flight complete and emit their frames; the client discards them, as it does today.

## Error handling

| Condition                              | Behaviour                                                                                 |
|----------------------------------------|-------------------------------------------------------------------------------------------|
| `runToken` missing from the message    | Rejected by `validateMessageProperties()`; existing protocol-error path, no state touched |
| `runToken` does not match stored token | No-op. No frame, no state change, no queue filtering                                      |
| `resourceId` is not an int             | No-op, same as above                                                                      |
| Socket not `OPEN` when stop is clicked | Client skips the send; `onClose()` handles the cleanup                                    |
| Cancel arrives with no queued requests | `dropSupersededQueuedRequests()` returns early on an empty queue                          |

Only the first row produces anything visible. Since UnitTestInterface PR #46, the protocol-error frame is painted as a
failure rather than silently consumed — which is the wanted behaviour for a client that sent a malformed cancel, and is
the reason the success path stays silent.

## Testing

### Server

New `phpunit_tests/HealthCancelRunTest.php`, following the stub-connection and reflection pattern established by
`HealthFolderStepResultTest` — an anonymous `ConnectionInterface` that records outbound frames, driven in-process with no
live socket. Reflection seeds `$runTokens` and `$queue`, then `onMessage()` is driven with a real JSON payload so the
`ACTION_PROPERTIES` and token-store changes are exercised rather than bypassed.

Cases:

1. A matching cancel drops exactly that run's queued entries.
2. Untagged entries survive.
3. Another connection's entries survive.
4. A stale cancel — the connection has already moved to a new token — changes nothing.
5. A cancel missing `runToken` is rejected and changes nothing.
6. No frame is emitted in the success case.

### Client

A Playwright spec in two halves. Three cases import `wsProtocol.js` directly in the page context — the technique
`e2e/result-painting.spec.ts` already uses — and call `sendCancelRun()` against a recording fake connection, covering
the frame it emits and the two guard branches that no amount of clicking can reach. Two more cases stub
`window.WebSocket` with a recorder that reports itself open and never replies, then start and stop a run on each page
and assert the last recorded frame is the `cancelRun` carrying the token the run started with.

The stub is written to be reusable: #42 will need exactly this to test protocol behaviour without a live server, and
`playwright.config.ts` starts no WebSocket server today.

If the stub proves flaky against the real start path, that will be reported rather than the client test being quietly
dropped.

## What this does not fix

The mid-run reconnect inconsistency also recorded in #43 — `onclose` reconnects and `onopen` resets `currentState`
underneath an in-flight run while `currentRunToken` stays set — is untouched. Resolving it means choosing between
resuming and aborting the run, which is a design decision overlapping UnitTestInterface#28, not a bug fix. #43 can close
on this work; the reconnect item belongs with #28.
