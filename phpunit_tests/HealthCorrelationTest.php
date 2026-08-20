<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests;

use LiturgicalCalendar\Api\Health;
use LiturgicalCalendar\Api\Models\ValidationsPath\CheckableInventory;
use LiturgicalCalendar\Api\Router;
use LiturgicalCalendar\Tests\Support\HealthQueueIsolationTrait;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ratchet\ConnectionInterface;

/**
 * `requestId` — mapping a frame back to the request that asked for it.
 *
 * Before this, a client attributed an answer by matching the CSS selector the server had built into
 * `classes`, which meant the server had to know how the client painted its page and the client had
 * to parse a selector to find out what it was looking at. `requestId` is client-supplied and echoed
 * verbatim: whatever the client used to key its own state comes back on every frame that request
 * produced. `runId` is published alongside `runToken` in the same pass — the same value under the
 * name the protocol is moving to — so UnitTestInterface#42 renames once. See #806 section C.
 *
 * **The tests that carry the weight of this file are the three interleaved ones.** A test that
 * issues one request at a time cannot tell a correctly threaded `requestId` from one stored per
 * connection: with a single request outstanding, "the id this request was called with" and "the id
 * of the most recent request on this connection" are the same string. They only diverge when a
 * second request arrives before the first has answered, which is the normal condition in `Health` —
 * frames are emitted from promise closures, and `$this->queue` / `$this->inFlight` exist precisely
 * because several requests are in flight on one connection at once. Each of the three drives two
 * requests into the queue and settles them **in reverse order**, so a per-connection value would
 * stamp both answers with the second request's id: the exact misattribution correlation exists to
 * prevent, and the reason the field is threaded through the closures instead of stored.
 *
 * The three cover the three asynchronous emission paths separately, because they reach the wire by
 * three different routes: `sendCalendarStepResult()`, `sendStepResult()` via
 * `handleValidationDataError()`, and `LitTestRunner::setMessage()` — the last of which builds its
 * own frame and hands it to `Health::sendMessage()`, the one funnel where stamping from a
 * per-connection field would have looked most reasonable.
 *
 * Nothing here drives the ReactPHP loop. A queued `cachedGet()` entry holds the `resolve` and
 * `reject` closures the promise was created with, so settling one by hand is exactly what the loop
 * would have done later — which is what makes "later" expressible in a synchronous test at all.
 * Caching is off in this process (it is initialised in `onOpen()`, which no test calls), so every
 * outbound request really is queued rather than served from a cache.
 */
#[CoversClass(Health::class)]
final class HealthCorrelationTest extends TestCase
{
    use HealthQueueIsolationTrait;

    /**
     * A calendar response body minimal enough to reach {@see \LiturgicalCalendar\Api\Test\LitTestRunner},
     * which reads `settings.year` for the frame's year and `settings.national_calendar` for its calendar id.
     */
    private const CALENDAR_BODY = '{"settings":{"year":2026,"national_calendar":"IT"},"litcal":[]}';

    private ?string $appEnvBackup = null;

    public static function setUpBeforeClass(): void
    {
        // The inventory resolves every path from Router::$apiFilePath; build it against these paths
        // rather than against whatever an earlier test class in this process left behind.
        Router::getApiPaths();
        CheckableInventory::reset();
    }

    public static function tearDownAfterClass(): void
    {
        CheckableInventory::reset();
    }

    protected function setUp(): void
    {
        parent::setUp();
        // Keeps handleHttpResponse() out of its development-only debug-logging branch.
        $this->appEnvBackup = isset($_ENV['APP_ENV']) && is_string($_ENV['APP_ENV']) ? $_ENV['APP_ENV'] : null;
        $_ENV['APP_ENV']    = 'test';
    }

    protected function tearDown(): void
    {
        if (null === $this->appEnvBackup) {
            unset($_ENV['APP_ENV']);
        } else {
            $_ENV['APP_ENV'] = $this->appEnvBackup;
        }
        parent::tearDown();
    }

    // ---------------------------------------------------------------- the id comes back on every frame

    /**
     * The whole point, at its simplest: what the client sent is on every frame the request produced.
     *
     * A source check is the shortest path to three frames, and all three must carry it — a client
     * that got the id on the first frame and not the third would have to fall back to selector
     * matching for the rest of the check, which is the thing being replaced.
     */
    public function testEveryFrameOfARequestCarriesTheRequestIdTheClientSent(): void
    {
        $health = $this->newHealth();
        $conn   = self::createStubConnection();

        self::send($health, $conn, [
            'action'    => 'validateSource',
            'target'    => ['id' => 'temporale:roman'],
            'requestId' => 'req-alpha'
        ]);

        $frames = self::framesOf($conn);
        self::assertCount(3, $frames, 'a source check answers with one frame per published step');
        foreach ($frames as $frame) {
            self::assertNotSame('echobot', $frame->type, "the message was refused: {$frame->text}");
            self::assertSame('req-alpha', $frame->requestId, 'every frame must name the request that caused it');
        }
    }

    /**
     * Absent means absent. A v1 client sends no `requestId` and must not start receiving one — an
     * empty string or a fabricated id would be a new field to reason about for a client that never
     * asked for the feature, and `property_exists` is how a client tells "not correlated" from
     * "correlated with something".
     */
    public function testFramesCarryNoRequestIdWhenTheClientSentNone(): void
    {
        $health = $this->newHealth();
        $conn   = self::createStubConnection();

        self::send($health, $conn, ['action' => 'validateSource', 'target' => ['id' => 'temporale:roman']]);

        $frames = self::framesOf($conn);
        self::assertCount(3, $frames);
        foreach ($frames as $frame) {
            self::assertObjectNotHasProperty('requestId', $frame, 'a request that named no id must be answered without one');
        }
    }

    /**
     * An id the server cannot echo is refused, and refused *before* the check runs.
     *
     * The shape is `runToken`'s, deliberately: both are opaque client-minted handles the server
     * echoes back, and a client that minted one of them must not discover that the other accepts a
     * different alphabet. Junk is stopped at the door rather than written onto every frame of a run —
     * `classes` is a CSS selector a client feeds to `querySelectorAll()`, and a frame is JSON a
     * client parses, so unbounded client-controlled strings are not free.
     *
     * Refused rather than ignored: a client that sent an unusable id is about to wait for frames it
     * means to attribute by it, and answering with un-correlated frames would look like success.
     *
     * @param mixed $requestId
     */
    #[DataProvider('malformedRequestIdProvider')]
    public function testAMalformedRequestIdIsRejectedAndNoCheckRuns(mixed $requestId): void
    {
        $health = $this->newHealth();
        $conn   = self::createStubConnection();

        self::send($health, $conn, [
            'action'    => 'validateSource',
            'target'    => ['id' => 'temporale:roman'],
            'requestId' => $requestId
        ]);

        $frames = self::framesOf($conn);
        self::assertCount(1, $frames, 'a malformed requestId is answered once and nothing is checked');
        self::assertSame('echobot', $frames[0]->type, 'rejections reuse the echobot shape: since UnitTestInterface#46 an unknown type is painted as a failed check');
        self::assertStringContainsString('requestId', (string) $frames[0]->text);
        self::assertObjectNotHasProperty('requestId', $frames[0], 'the id that could not be validated is not echoed back');
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function malformedRequestIdProvider(): array
    {
        return [
            'empty string'              => [''],
            'a space'                   => ['req alpha'],
            'a colon'                   => ['req:alpha'],
            'a dot'                     => ['req.alpha'],
            'markup'                    => ['<script>'],
            'sixty-five'                => [str_repeat('a', 65)],
            // PHP's `$` matches before a trailing newline unless the pattern is anchored with `\z`
            // or the `D` modifier, so an un-anchored bound admits a 65-byte id through a {1,64} rule
            // and admits a newline through an alphabet that lists none. Sharing one constant with
            // `runToken` would have propagated that latitude to a second field, so both rows are here.
            'trailing newline'          => ["req-alpha\n"],
            'sixty-four plus a newline' => [str_repeat('a', 64) . "\n"],
            'an integer'                => [42],
            'null'                      => [null],
            'a list'                    => [['req-alpha']],
            'an object'                 => [['id' => 'req-alpha']],
            'a boolean'                 => [true]
        ];
    }

    /**
     * Sixty-four is inside the bound, and a hyphen and an underscore are inside the alphabet. The
     * rejection rows above would pass just as well against a rule that refused everything, so the
     * accepting rows are what makes them mean anything.
     *
     * @param string $requestId
     */
    #[DataProvider('wellFormedRequestIdProvider')]
    public function testAWellFormedRequestIdIsAccepted(string $requestId): void
    {
        $health = $this->newHealth();
        $conn   = self::createStubConnection();

        self::send($health, $conn, [
            'action'    => 'validateSource',
            'target'    => ['id' => 'temporale:roman'],
            'requestId' => $requestId
        ]);

        $frames = self::framesOf($conn);
        self::assertCount(3, $frames, "a well-formed requestId was refused: {$frames[0]->text}");
        self::assertSame($requestId, $frames[0]->requestId);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function wellFormedRequestIdProvider(): array
    {
        return [
            'one character'  => ['a'],
            'hyphens'        => ['req-alpha-1'],
            'underscores'    => ['req_alpha_1'],
            'mixed case'     => ['ReqAlpha42'],
            'sixty-four'     => [str_repeat('a', 64)],
            'a uuid, dashed' => ['3f2504e0-4f89-11d3-9a0c-0305e82c3301']
        ];
    }

    /**
     * A rejection is an answer too, and the one a client most needs to attribute: it arrives instead
     * of the frames the request was going to produce, so a client that could not tell which of its
     * outstanding requests had been refused would be left waiting for the other one's frames.
     */
    public function testARejectionCarriesTheRequestIdOfTheMessageItRefuses(): void
    {
        $health = $this->newHealth();
        $conn   = self::createStubConnection();

        self::send($health, $conn, [
            'action'    => 'validateSource',
            'target'    => ['id' => 'nation:roman:ZZ'],
            'requestId' => 'req-alpha'
        ]);

        $frames = self::framesOf($conn);
        self::assertCount(1, $frames);
        self::assertSame('echobot', $frames[0]->type);
        self::assertSame('Unknown validation target: nation:roman:ZZ', $frames[0]->text);
        self::assertSame('req-alpha', $frames[0]->requestId);
    }

    // ---------------------------------------------------------------- runId, published alongside runToken

    /**
     * `runId` is `runToken` under its new name, and both go out on the same frames.
     *
     * Publishing the new spelling now, alongside rather than instead of the old one, means
     * UnitTestInterface#42 adopts it in the migration it is already doing rather than in a second
     * one later. Asserting they are the *same value* is the point: two names for one run are only
     * useful while they cannot disagree.
     */
    public function testRunIdIsPublishedAlongsideRunTokenWithTheSameValue(): void
    {
        $health = $this->newHealth();
        $conn   = self::createStubConnection();

        self::send($health, $conn, [
            'action'   => 'validateSource',
            'target'   => ['id' => 'temporale:roman'],
            'runToken' => 'run-a'
        ]);

        $frames = self::framesOf($conn);
        self::assertCount(3, $frames);
        foreach ($frames as $frame) {
            self::assertSame('run-a', $frame->runToken, 'the legacy name must keep working');
            self::assertSame('run-a', $frame->runId, 'the new name must carry the same value');
        }
    }

    /**
     * The two names appear and disappear together: a client that omits `runToken` gets neither, so
     * `runId` never becomes a field that is present in one case and absent in the other for reasons
     * a client cannot see.
     */
    public function testRunIdIsAbsentWhenTheClientNamedNoRun(): void
    {
        $health = $this->newHealth();
        $conn   = self::createStubConnection();

        self::send($health, $conn, ['action' => 'validateSource', 'target' => ['id' => 'temporale:roman']]);

        foreach (self::framesOf($conn) as $frame) {
            self::assertObjectNotHasProperty('runToken', $frame);
            self::assertObjectNotHasProperty('runId', $frame);
        }
    }

    /**
     * The two ids are independent axes and both ride on the same frame: a run is many requests, and
     * a client filters by the run and keys by the request.
     */
    public function testARunTokenAndARequestIdRideTogether(): void
    {
        $health = $this->newHealth();
        $conn   = self::createStubConnection();

        self::send($health, $conn, [
            'action'    => 'validateSource',
            'target'    => ['id' => 'temporale:roman'],
            'runToken'  => 'run-a',
            'requestId' => 'req-alpha'
        ]);

        foreach (self::framesOf($conn) as $frame) {
            self::assertSame('run-a', $frame->runToken);
            self::assertSame('run-a', $frame->runId);
            self::assertSame('req-alpha', $frame->requestId);
        }
    }

    // ---------------------------------------------------------------- two requests in flight at once

    /**
     * **The test this feature exists for.** Two calendar validations outstanding on one connection,
     * settled in the reverse of the order they arrived, each answered with its own `requestId`.
     *
     * Reverse order is not decoration. In arrival order a per-connection "current requestId" would
     * still be wrong — it would be `req-beta` for both — but reversing makes the failure legible:
     * the frame that comes back first belongs to the request that arrived second.
     *
     * This is the ordinary case for `Health`, not a contrived one. A run issues dozens of calendar
     * validations, `maxConcurrency` of them are in flight at any moment, and they complete in
     * whatever order the API answers.
     */
    public function testTwoCalendarValidationsInFlightKeepTheirOwnRequestIds(): void
    {
        $health = $this->newHealth();
        $conn   = self::createStubConnection();

        self::send($health, $conn, [
            'action'         => 'validateCalendar',
            'calendar'       => ['kind' => 'national', 'id' => 'IT', 'rite' => 'roman'],
            'year'           => 2026,
            'responseFormat' => 'JSON',
            'requestId'      => 'req-alpha'
        ]);
        self::send($health, $conn, [
            'action'         => 'validateCalendar',
            'calendar'       => ['kind' => 'national', 'id' => 'US', 'rite' => 'roman'],
            'year'           => 2027,
            'responseFormat' => 'JSON',
            'requestId'      => 'req-beta'
        ]);

        self::assertSame([], $conn->sent, 'precondition: neither request has been answered yet — both are in flight');
        self::assertCount(2, self::queuedRequests($health), 'precondition: two requests are outstanding on one connection');

        // The second request answers first, which is what makes a per-connection id observably wrong.
        self::failQueuedRequest($health, 1);
        self::failQueuedRequest($health, 0);

        $frames = self::framesOf($conn);
        self::assertCount(2, $frames);

        self::assertSame('US', $frames[0]->target->id, 'precondition: the request that arrived second answered first');
        self::assertSame(2027, $frames[0]->target->year);
        self::assertSame('req-beta', $frames[0]->requestId);

        self::assertSame('IT', $frames[1]->target->id);
        self::assertSame(2026, $frames[1]->target->year);
        self::assertSame(
            'req-alpha',
            $frames[1]->requestId,
            'a late frame was stamped with a later request\'s id: requestId is being read from the connection instead of from the request'
        );
    }

    /**
     * The same proof for the source-data emitter, which reaches the wire by a different route:
     * `handleValidationDataError()` → `sendStepResult()`, three frames per request rather than one.
     *
     * Three frames matter here beyond mere coverage: they are emitted in a loop from inside one
     * closure, so a `requestId` recovered per frame rather than captured once could drift *within*
     * a single request's answer as well as between requests.
     */
    public function testTwoSourceChecksInFlightKeepTheirOwnRequestIds(): void
    {
        $health = $this->newHealth();
        $conn   = self::createStubConnection();

        self::send($health, $conn, [
            'action'     => 'executeValidation',
            'category'   => 'resourceDataCheck',
            'validate'   => 'national-calendar-IT',
            'sourceFile' => 'https://example.test/data/nation/IT',
            'requestId'  => 'req-alpha'
        ]);
        self::send($health, $conn, [
            'action'     => 'executeValidation',
            'category'   => 'resourceDataCheck',
            'validate'   => 'national-calendar-US',
            'sourceFile' => 'https://example.test/data/nation/US',
            'requestId'  => 'req-beta'
        ]);

        self::assertSame([], $conn->sent, 'precondition: neither request has been answered yet — both are in flight');
        self::assertCount(2, self::queuedRequests($health), 'precondition: two requests are outstanding on one connection');

        self::failQueuedRequest($health, 1);
        self::failQueuedRequest($health, 0);

        $frames = self::framesOf($conn);
        self::assertCount(6, $frames, 'an unreadable source answers all three steps, per request');

        foreach (array_slice($frames, 0, 3) as $frame) {
            self::assertStringContainsString('national-calendar-US', (string) $frame->classes, 'precondition: the request that arrived second answered first');
            self::assertSame('req-beta', $frame->requestId);
        }
        foreach (array_slice($frames, 3, 3) as $frame) {
            self::assertStringContainsString('national-calendar-IT', (string) $frame->classes);
            self::assertSame(
                'req-alpha',
                $frame->requestId,
                'a late frame was stamped with a later request\'s id: requestId is being read from the connection instead of from the request'
            );
        }
    }

    /**
     * And for the fifth emitter, which is not in `Health` at all.
     *
     * A test that actually ran is reported by a frame `LitTestRunner::setMessage()` builds; `Health`
     * only hands it to `sendMessage()`. That funnel already stamps `runToken` from a per-connection
     * store when the caller passes none, so it is the single most tempting place to stamp
     * `requestId` the same way — and the one place where doing so would be silently wrong for the
     * same reason as everywhere else. The id is captured in the promise closure and passed
     * explicitly, exactly as `runToken` already is on that call, and this is what says so.
     */
    public function testTwoTestRunsInFlightKeepTheirOwnRequestIds(): void
    {
        $health = $this->newHealth();
        $conn   = self::createStubConnection();

        self::send($health, $conn, [
            'action'    => 'runTest',
            'test'      => 'CorrelationHarnessAlpha',
            'calendar'  => ['kind' => 'national', 'id' => 'IT', 'rite' => 'roman'],
            'year'      => 2026,
            'requestId' => 'req-alpha'
        ]);
        self::send($health, $conn, [
            'action'    => 'runTest',
            'test'      => 'CorrelationHarnessBeta',
            'calendar'  => ['kind' => 'national', 'id' => 'US', 'rite' => 'roman'],
            'year'      => 2026,
            'requestId' => 'req-beta'
        ]);

        self::assertSame([], $conn->sent, 'precondition: neither request has been answered yet — both are in flight');
        self::assertCount(2, self::queuedRequests($health), 'precondition: two requests are outstanding on one connection');

        // Fulfilled rather than failed, because a *failure* is answered by Health::sendTestResult()
        // and it is LitTestRunner's own frame that this test is about.
        self::fulfilQueuedRequest($health, 1);
        self::fulfilQueuedRequest($health, 0);

        $frames = self::framesOf($conn);
        self::assertCount(2, $frames);

        self::assertSame('CorrelationHarnessBeta', $frames[0]->target->id, 'precondition: the request that arrived second answered first');
        self::assertSame('req-beta', $frames[0]->requestId);

        self::assertSame('CorrelationHarnessAlpha', $frames[1]->target->id);
        self::assertSame(
            'req-alpha',
            $frames[1]->requestId,
            'the LitTestRunner frame was stamped with a later request\'s id: requestId is being read from the connection inside sendMessage()'
        );
    }

    /**
     * The run token and the request id do not have to move together, and this pins the reason they
     * are stored differently. Both requests belong to one run — the token is the connection's, and
     * both frames get it — while the ids are per request and stay apart.
     */
    public function testOneRunSpansTwoRequestsAndTheTokenIsSharedWhileTheIdsAreNot(): void
    {
        $health = $this->newHealth();
        $conn   = self::createStubConnection();

        foreach ([['IT', 2026, 'req-alpha'], ['US', 2027, 'req-beta']] as [$calendar, $year, $requestId]) {
            self::send($health, $conn, [
                'action'         => 'validateCalendar',
                'calendar'       => ['kind' => 'national', 'id' => $calendar, 'rite' => 'roman'],
                'year'           => $year,
                'responseFormat' => 'JSON',
                'runToken'       => 'run-a',
                'requestId'      => $requestId
            ]);
        }

        self::failQueuedRequest($health, 1);
        self::failQueuedRequest($health, 0);

        $frames = self::framesOf($conn);
        self::assertCount(2, $frames);
        self::assertSame(['run-a', 'run-a'], array_map(static fn (\stdClass $f): mixed => $f->runToken, $frames));
        self::assertSame(['run-a', 'run-a'], array_map(static fn (\stdClass $f): mixed => $f->runId, $frames));
        self::assertSame(['req-beta', 'req-alpha'], array_map(static fn (\stdClass $f): mixed => $f->requestId, $frames));
    }

    // ---------------------------------------------------------------- the paths the interleaved tests do not reach

    /**
     * The legacy action correlates too.
     *
     * `requestId` is a v2 field, but nothing about it is v2-only: it is read off any message, and a
     * client midway through UnitTestInterface#42 may well have adopted correlation before it has
     * adopted `runTest`. This also pins the arm the interleaved test cannot reach — a `runTest` that
     * *fulfils* is answered by `LitTestRunner`, so `sendTestResult()`'s own failure frame is only
     * reachable by failing the fetch.
     */
    public function testTheLegacyExecuteUnitTestActionCorrelatesThroughSendTestResult(): void
    {
        $health = $this->newHealth();
        $conn   = self::createStubConnection();

        self::send($health, $conn, [
            'action'    => 'executeUnitTest',
            'category'  => 'nationalcalendar',
            'calendar'  => 'IT',
            'year'      => 2026,
            'test'      => 'CorrelationHarnessLegacy',
            'requestId' => 'req-alpha'
        ]);

        self::assertSame([], $conn->sent, 'precondition: no frame before the calendar is fetched');
        self::failQueuedRequest($health, 0);

        $frames = self::framesOf($conn);
        self::assertCount(1, $frames);
        self::assertSame('CorrelationHarnessLegacy', $frames[0]->target->id, 'precondition: this is the sendTestResult() failure frame');
        self::assertSame('validates', $frames[0]->step);
        self::assertSame('req-alpha', $frames[0]->requestId);
    }

    /**
     * The folder branch, which is a different emitter reached by a different route.
     *
     * `runValidationSteps()` splits on `kind` into two branches that share almost nothing:
     * `sendFolderStepResult()` summarises a whole folder into one frame per step, where the file
     * branch reports per file. `nation:roman:US:i18n` is the inventory's folder-kind entry, and
     * without a row for it the folder emitter would be threaded but unexercised.
     */
    public function testAFolderCheckCarriesTheRequestIdOnAllThreeFrames(): void
    {
        $health = $this->newHealth();
        $conn   = self::createStubConnection();

        self::send($health, $conn, [
            'action'    => 'validateSource',
            'target'    => ['id' => 'nation:roman:US:i18n'],
            'requestId' => 'req-alpha'
        ]);

        $frames = self::framesOf($conn);
        self::assertCount(3, $frames, 'a folder check answers with exactly one frame per step');
        foreach ($frames as $frame) {
            self::assertNotSame('echobot', $frame->type, "the message was refused: {$frame->text}");
            self::assertStringContainsString('folder', (string) $frame->text, 'precondition: these are the folder branch\'s frames');
            self::assertSame('req-alpha', $frame->requestId);
        }
    }

    /**
     * A message the server could not act on is still answered, and the answer is still correlated —
     * as long as there was a message to read an id from.
     *
     * The two halves are one test because the distinction is the whole content: a well-formed object
     * that fails property validation carries an id the client is waiting on, while a string that is
     * not JSON at all carries nothing the server may echo. Fabricating an id for the second — or
     * dropping it for the first — would leave a client blocked on a request it never learns was
     * refused.
     */
    public function testAnInvalidMessageIsCorrelatedWhenItCarriedAnIdAndNotWhenItCouldNot(): void
    {
        $health = $this->newHealth();
        $conn   = self::createStubConnection();

        // Parses as an object, but `validateSource` requires a `target`.
        self::send($health, $conn, ['action' => 'validateSource', 'requestId' => 'req-alpha']);
        // Does not parse at all: there is no message, so there is no id.
        $health->onMessage($conn, 'not json at all');

        $frames = self::framesOf($conn);
        self::assertCount(2, $frames);

        self::assertSame('echobot', $frames[0]->type);
        self::assertSame('Invalid message properties', $frames[0]->errorMsg);
        self::assertSame('req-alpha', $frames[0]->requestId, 'a refusal must name the request it refuses');

        self::assertSame('echobot', $frames[1]->type);
        self::assertObjectNotHasProperty('requestId', $frames[1], 'nothing may be invented for a message that could not be read');
    }

    // ---------------------------------------------------------------- harness

    /**
     * A minimal Ratchet connection that records every outbound frame. `resourceId` is a dynamic
     * public property Ratchet assigns and is not part of `ConnectionInterface`, so this mirrors the
     * stub convention the other Health tests use rather than a PHPUnit mock, which would trigger a
     * dynamic-property deprecation.
     */
    private static function createStubConnection(int $resourceId = 1)
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
     * @param array<string, mixed> $payload
     */
    private static function send(Health $health, ConnectionInterface $conn, array $payload): void
    {
        $health->onMessage($conn, (string) json_encode($payload));
    }

    /**
     * @return list<\stdClass>
     */
    private static function framesOf(ConnectionInterface $conn): array
    {
        /** @var object{sent: list<string>} $conn */
        return array_map(
            /** @return \stdClass */
            static fn (string $raw): object => (object) json_decode($raw),
            $conn->sent
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function queuedRequests(Health $health): array
    {
        /** @var list<array<string, mixed>> */
        return ( new \ReflectionProperty(Health::class, 'queue') )->getValue($health);
    }

    /**
     * Settle one outstanding request as a network failure, without dispatching it.
     *
     * The queued entry holds the very `reject` closure `cachedGet()` built for that request, so
     * calling it does what the event loop would have done later — which is how "later" becomes
     * expressible in a synchronous test, and why the order can be chosen.
     */
    private static function failQueuedRequest(Health $health, int $index): void
    {
        $queued = self::queuedRequests($health);
        self::assertArrayHasKey($index, $queued, "expected a queued request at index {$index}");
        /** @var \Closure(\Throwable):void $reject */
        $reject = $queued[$index]['reject'];
        $reject(new \RuntimeException('the network is not this test\'s business'));
    }

    /**
     * Settle one outstanding request with a successful response carrying {@see self::CALENDAR_BODY}.
     */
    private static function fulfilQueuedRequest(Health $health, int $index): void
    {
        $queued = self::queuedRequests($health);
        self::assertArrayHasKey($index, $queued, "expected a queued request at index {$index}");
        /** @var \Closure(\Psr\Http\Message\ResponseInterface):void $resolve */
        $resolve = $queued[$index]['resolve'];
        $resolve(new Response(200, ['Content-Type' => 'application/json'], self::CALENDAR_BODY));
    }
}
