<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests;

use LiturgicalCalendar\Api\Health;
use LiturgicalCalendar\Api\Models\ValidationsPath\CheckableInventory;
use LiturgicalCalendar\Api\Router;
use LiturgicalCalendar\Tests\Support\HealthQueueIsolationTrait;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ratchet\ConnectionInterface;

/**
 * `complete` — the frame that lets a client stop without counting.
 *
 * Both clients hardcode three responses per check in four places, and when this frame was added
 * #806 section A's published `steps` could not replace that constant because the count was not
 * reliable: a file whose JSON failed to decode emitted two step frames, not three (#821). The
 * terminal frame made that moot rather than fixed; #821 has since fixed it too, so the counts now
 * agree. The frame is not thereby redundant — it is what makes a client's stopping condition
 * independent of any arm's step count, including arms not yet written.
 *
 * **The failure arms are the point of this file.** A client that stops on `complete` hangs forever on
 * an arm that terminates without one, which is the folder-branch wedge `ea29b678` fixed, one level up
 * and with the counting moved to a different place. So every arm below that *starts work* is driven to
 * its end and asserted to terminate, and the two that start no work are asserted not to.
 *
 * **And it is gated on `requestId`.** A new frame changes the *stream*, not just a frame's contents:
 * `resources.js` sizes a phase as `checks * 3` and advances on `>=`, so a v1 client would reach its
 * threshold on the three real frames and then let the terminal frame increment whichever counter had
 * become active — finishing the *following* phase early too, with nothing visibly failing. The last
 * test here asserts the absence rather than a count, because "the count did not change" would also
 * pass if the frame were merely being sent somewhere else.
 *
 * Nothing here drives the ReactPHP loop; the harness settles queued requests by hand, exactly as
 * {@see HealthCorrelationTest} does and for the same reason — that is what makes "later" expressible
 * in a synchronous test.
 */
#[CoversClass(Health::class)]
final class HealthTerminalFrameTest extends TestCase
{
    use HealthQueueIsolationTrait;

    /** A calendar body that decodes but lacks the four keys the JSON branch requires. */
    private const TRUNCATED_CALENDAR_BODY = '{"litcal":[]}';

    /** A calendar body complete enough for {@see \LiturgicalCalendar\Api\Test\LitTestRunner} to report on. */
    private const CALENDAR_BODY = '{"settings":{"year":2026,"national_calendar":"IT"},"litcal":[]}';

    private ?string $appEnvBackup = null;

    public static function setUpBeforeClass(): void
    {
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

    // ---------------------------------------------------------------- the shape of the frame

    /**
     * The happy path, and the frame's shape where it is easiest to read: three step frames in the
     * published vocabulary, then one that says the work is over.
     *
     * `status` is absent because the frame reports that the run *finished*, not that it passed — a
     * check whose every step failed completes exactly as this one does. `classes` is absent because
     * there is no legacy class for a step the legacy protocol never had, and inventing one would put
     * a selector on the wire that matches zero cards. The target is the one its step frames carried,
     * so a client attributes the terminal frame the same way it attributed the rest.
     */
    public function testTheHappyPathEndsWithATerminalFrameThatClaimsNoOutcome(): void
    {
        $conn = self::createStubConnection();

        self::send($this->newHealth(), $conn, [
            'action'    => 'validateSource',
            'target'    => ['id' => 'temporale:roman'],
            'requestId' => 'req-alpha'
        ]);

        $frames = self::framesOf($conn);
        self::assertSame(
            ['exists', 'parses', 'validates', 'complete'],
            self::stepsOf($frames),
            'a source check reports its three steps and then terminates'
        );

        $terminal = json_decode($conn->sent[3], true);
        self::assertIsArray($terminal);
        self::assertSame('success', $terminal['type'], 'the frame reports that work finished, so it is never an error');
        self::assertArrayNotHasKey('status', $terminal, 'finishing is not an outcome: a run whose every step failed completes too');
        self::assertArrayNotHasKey('classes', $terminal, 'there is no legacy class for a step the legacy protocol never had');
        self::assertSame(['id' => 'temporale:roman'], $terminal['target'], 'the terminal frame names what its step frames named');
        self::assertSame('req-alpha', $terminal['requestId']);
    }

    // ---------------------------------------------------------------- every arm that starts work

    /**
     * The arm that #821 was filed about: a file that exists but does not decode. It emitted **two**
     * step frames where every sibling arm emits three, which is why the terminal frame was written
     * to be independent of the step count rather than derived from it — a client that had been told
     * `steps` was three waited forever for a `validates` frame that was never coming.
     *
     * That is fixed: the third frame is now sent, failed. The assertion is kept pointing at this arm
     * rather than moved to a passing one, because the arm's history is the reason it is worth
     * pinning — and the terminal frame still arrives after a step frame that failed, which is the
     * property this class exists to hold.
     */
    public function testAJsonDecodeFailureReportsEveryStepAndThenTerminates(): void
    {
        $health = $this->newHealth();
        $conn   = self::createStubConnection();

        self::send($health, $conn, self::resourceCheck('req-alpha'));
        self::fulfilQueuedRequest($health, 0, 'not json at all');

        $frames = self::framesOf($conn);
        self::assertSame(
            ['exists', 'parses', 'validates', 'complete'],
            self::stepsOf($frames),
            'a check that stops early still reports every step it declared, and then terminates'
        );
        self::assertSame('pass', $frames[0]->status);
        self::assertSame('fail', $frames[1]->status, 'precondition: this is the decode failure, not a passing check');
        self::assertSame('fail', $frames[2]->status, 'a file that would not decode was never schema-checked, so the step failed');
        self::assertSame('req-alpha', $frames[3]->requestId);
    }

    /**
     * The unreadable-source arm, which reports all three steps as failures and is still a
     * termination. A client stops on `complete`, so an arm that fails is exactly as obliged to send
     * one as an arm that passes — more so, since a failing run is when a client most needs to move on.
     */
    public function testAnUnreadableSourceTerminatesAfterItsThreeFailures(): void
    {
        $health = $this->newHealth();
        $conn   = self::createStubConnection();

        self::send($health, $conn, self::resourceCheck('req-alpha'));
        self::failQueuedRequest($health, 0);

        $frames = self::framesOf($conn);
        self::assertSame(['exists', 'parses', 'validates', 'complete'], self::stepsOf($frames));
        self::assertSame(['fail', 'fail', 'fail'], array_map(
            static fn (\stdClass $f): mixed => $f->status,
            array_slice($frames, 0, 3)
        ), 'precondition: every step failed');
    }

    /**
     * The missing-folder arm, which is the short path `ea29b678` had to fix from the other side.
     *
     * It is also the only arm that terminates **synchronously**: the `glob()` guard returns before
     * any promise is created, so nothing settles it later and a `complete` forgotten here would be
     * invisible to every asynchronous test in this file.
     */
    public function testAFolderThatDoesNotExistTerminates(): void
    {
        $conn = self::createStubConnection();

        self::send($this->newHealth(), $conn, [
            'action'       => 'executeValidation',
            'category'     => 'sourceDataCheck',
            'validate'     => 'tests-NoSuchTest-i18n',
            'sourceFolder' => 'jsondata/definitely-not-a-real-folder',
            'requestId'    => 'req-alpha'
        ]);

        $frames = self::framesOf($conn);
        self::assertSame(['exists', 'parses', 'validates', 'complete'], self::stepsOf($frames));
        self::assertNull(
            $frames[3]->target,
            'a v1 executeValidation message names no id, and none is fabricated for its terminal frame either'
        );
    }

    /**
     * A test run is one named outcome rather than a three-step pipeline, and it terminates the same
     * way — which is the point of the uniform shape: a client's phase logic must not have to know
     * which kind of work it asked for.
     *
     * Both of its arms are driven here because they emit through different code entirely: a run that
     * happened is reported by a frame `LitTestRunner` builds, while a calendar that could not be
     * fetched is reported by `Health::sendTestResult()`.
     */
    public function testATestRunTerminatesWhetherItRanOrCouldNotBeFetched(): void
    {
        $health = $this->newHealth();
        $conn   = self::createStubConnection();

        self::send($health, $conn, [
            'action'    => 'runTest',
            'test'      => 'TerminalHarnessAlpha',
            'calendar'  => ['kind' => 'national', 'id' => 'IT', 'rite' => 'roman'],
            'year'      => 2026,
            'requestId' => 'req-alpha'
        ]);
        self::send($health, $conn, [
            'action'    => 'runTest',
            'test'      => 'TerminalHarnessBeta',
            'calendar'  => ['kind' => 'national', 'id' => 'US', 'rite' => 'roman'],
            'year'      => 2026,
            'requestId' => 'req-beta'
        ]);

        self::fulfilQueuedRequest($health, 0, self::CALENDAR_BODY);
        self::failQueuedRequest($health, 1);

        $frames = self::framesOf($conn);
        self::assertSame(['validates', 'complete', 'validates', 'complete'], self::stepsOf($frames));
        self::assertSame(['req-alpha', 'req-alpha', 'req-beta', 'req-beta'], array_map(
            static fn (\stdClass $f): mixed => $f->requestId,
            $frames
        ), 'each run is terminated by its own request\'s frame');
        self::assertSame(
            ['id' => 'TerminalHarnessAlpha', 'calendar' => 'IT', 'year' => 2026],
            (array) $frames[1]->target,
            'a test run\'s terminal frame names the test, the calendar and the year, as its result frame does'
        );
    }

    /**
     * The calendar cluster, both arms. It is the largest cluster in `Health` by some way — sixteen of
     * the twenty-seven legacy `classes` sites — and it reaches its end through a `switch` with an
     * arm per response format, several of which `break` out early. The terminal frame sits after the
     * switch precisely so that no format and no early break can leave a client waiting.
     */
    public function testACalendarValidationTerminatesOnBothArms(): void
    {
        $health = $this->newHealth();
        $conn   = self::createStubConnection();

        foreach ([['IT', 2026, 'req-alpha'], ['US', 2027, 'req-beta']] as [$calendar, $year, $requestId]) {
            self::send($health, $conn, [
                'action'         => 'validateCalendar',
                'calendar'       => ['kind' => 'national', 'id' => $calendar, 'rite' => 'roman'],
                'year'           => $year,
                'responseFormat' => 'JSON',
                'requestId'      => $requestId
            ]);
        }

        // A body that decodes but is missing the four required keys: the JSON arm reports the parse
        // failure and breaks out of the switch, which is the early exit worth pinning.
        self::fulfilQueuedRequest($health, 0, self::TRUNCATED_CALENDAR_BODY);
        self::failQueuedRequest($health, 1);

        $frames = self::framesOf($conn);
        self::assertSame(
            ['exists', 'parses', 'complete', 'exists', 'complete'],
            self::stepsOf($frames),
            'a fetch that succeeded and one that did not, each terminated'
        );
        self::assertSame(
            ['id' => 'IT', 'year' => 2026],
            (array) $frames[2]->target,
            'a calendar\'s terminal frame names the calendar and the year, as its step frames do'
        );
        self::assertSame('req-beta', $frames[4]->requestId);
    }

    /**
     * The arm with **no step frames at all**: an `executeValidation` naming a diocese the server
     * cannot resolve answers with one `.diocese-metadata` error and returns.
     *
     * It is the arm a client counting to three would hang on longest, and the one a terminal frame is
     * least likely to be remembered for, since it emits through neither of the step emitters. Either
     * lookup failure reaches the same handler — an unknown id, or metadata that has not loaded —
     * and the assertion accepts both, because which one occurs depends on whether some earlier test
     * in the process populated the static.
     */
    public function testADioceseThatCannotBeResolvedTerminates(): void
    {
        $conn = self::createStubConnection();

        ob_start();
        self::send($this->newHealth(), $conn, [
            'action'     => 'executeValidation',
            'category'   => 'sourceDataCheck',
            'validate'   => 'diocesan-calendar-zzzzzz_zz',
            'sourceFile' => 'jsondata/irrelevant.json',
            'requestId'  => 'req-alpha'
        ]);
        ob_end_clean();

        $frames = self::framesOf($conn);
        self::assertCount(2, $frames, 'the metadata error, and the frame that ends the request it answered');
        self::assertSame('error', $frames[0]->type);
        self::assertContains($frames[0]->error_code, ['unknown_diocese', 'metadata_loading'], 'precondition: this is the diocese-metadata arm');
        self::assertSame('complete', $frames[1]->step);
        self::assertSame('req-alpha', $frames[1]->requestId);
    }

    // ---------------------------------------------------------------- and the paths that start none

    /**
     * A rejection is not a termination, because nothing was started. A client that sent an id the
     * server cannot resolve is answered once and is done; a `complete` after a refusal would tell it
     * that work it never had ran to the end.
     */
    public function testARefusedMessageIsNotTerminated(): void
    {
        $conn = self::createStubConnection();

        self::send($this->newHealth(), $conn, [
            'action'    => 'validateSource',
            'target'    => ['id' => 'nation:roman:ZZ'],
            'requestId' => 'req-alpha'
        ]);

        $frames = self::framesOf($conn);
        self::assertCount(1, $frames, 'an unresolvable target is answered by the rejection alone');
        self::assertSame('echobot', $frames[0]->type);
        self::assertNotContains('complete', self::stepsOf($frames), 'nothing was started, so nothing terminates');
    }

    /**
     * **The gate.** A client that did not correlate does not receive the frame at all.
     *
     * Asserted as an *absence* over three arms rather than as a frame count, because a count that
     * happens to be unchanged would also pass if the frame were arriving somewhere unexpected — and
     * arriving somewhere unexpected is precisely the failure this gate exists to prevent. A v1
     * client's `resources.js` sizes a phase as `checks * 3` and advances on `>=`, so a stray terminal
     * frame would be counted toward whichever phase had become active by the time it landed,
     * finishing that one early too: a cascading miscount with nothing visibly failing.
     */
    public function testNoTerminalFrameIsSentToAClientThatDidNotCorrelate(): void
    {
        $health = $this->newHealth();
        $conn   = self::createStubConnection();

        // The happy path, the synchronous short path, and an asynchronous failure — one arm from
        // each of the three ways a request can end.
        self::send($health, $conn, ['action' => 'validateSource', 'target' => ['id' => 'temporale:roman']]);
        self::send($health, $conn, [
            'action'       => 'executeValidation',
            'category'     => 'sourceDataCheck',
            'validate'     => 'tests-NoSuchTest-i18n',
            'sourceFolder' => 'jsondata/definitely-not-a-real-folder'
        ]);
        self::send($health, $conn, [
            'action'         => 'validateCalendar',
            'calendar'       => ['kind' => 'national', 'id' => 'IT', 'rite' => 'roman'],
            'year'           => 2026,
            'responseFormat' => 'JSON'
        ]);
        self::failQueuedRequest($health, 0);

        $frames = self::framesOf($conn);
        self::assertNotEmpty($frames, 'precondition: the requests were answered, so the absence below means something');
        self::assertNotContains('complete', self::stepsOf($frames), 'a v1 stream must be exactly what it was');
        foreach ($frames as $frame) {
            self::assertObjectNotHasProperty('requestId', $frame, 'precondition: none of these requests correlated');
        }
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
     * A v1 `executeValidation` naming an API path, which is the shortest way to a file-branch check
     * whose outcome the test chooses by settling the queued request.
     *
     * @return array<string, mixed>
     */
    private static function resourceCheck(string $requestId): array
    {
        return [
            'action'     => 'executeValidation',
            'category'   => 'resourceDataCheck',
            'validate'   => 'national-calendar-IT',
            'sourceFile' => 'https://example.test/data/nation/IT',
            'requestId'  => $requestId
        ];
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
     * The `step` of each frame in order, with a frame that carries none — a rejection — reported as
     * `null` rather than dropped, so a stream is compared as a whole.
     *
     * @param list<\stdClass> $frames
     * @return list<string|null>
     */
    private static function stepsOf(array $frames): array
    {
        return array_map(
            static fn (\stdClass $frame): ?string => property_exists($frame, 'step') && is_string($frame->step) ? $frame->step : null,
            $frames
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
     * Settle one outstanding request as a network failure, without dispatching it. The queued entry
     * holds the very `reject` closure `cachedGet()` built for it, so calling it does what the event
     * loop would have done later.
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
     * Settle one outstanding request with a 200 carrying the given body.
     */
    private static function fulfilQueuedRequest(Health $health, int $index, string $body): void
    {
        $queued = self::queuedRequests($health);
        self::assertArrayHasKey($index, $queued, "expected a queued request at index {$index}");
        /** @var \Closure(\Psr\Http\Message\ResponseInterface):void $resolve */
        $resolve = $queued[$index]['resolve'];

        ob_start();
        $resolve(new Response(200, ['Content-Type' => 'application/json'], $body));
        ob_end_clean();
    }
}
