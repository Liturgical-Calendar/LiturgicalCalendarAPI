<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests;

use LiturgicalCalendar\Api\Enum\LitSchema;
use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Enum\Status;
use LiturgicalCalendar\Api\Enum\Step;
use LiturgicalCalendar\Api\Health;
use LiturgicalCalendar\Tests\Support\HealthQueueIsolationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ratchet\ConnectionInterface;

/**
 * The `hello` frame — #806 section F, the last section of that issue.
 *
 * Two things are being asserted here, and they pull in opposite directions.
 *
 * **That the frame says something true.** Every list in `capabilities` is derived from the thing that
 * already defines it — the `Rite`, `Step` and `Status` enums, the response formats
 * `Health::validateCalendar()` actually has a branch for, and the actions the *published schema*
 * declares. A hand-written list here would be a second place to edit in lockstep, which is the defect
 * #806 exists to remove; these tests are what stops one being introduced later by someone adding a
 * case and not noticing the frame went stale.
 *
 * **That the frame is invisible to the client that cannot read it.** Both shipped runners open their
 * message handler with `if (currentState === Stopped || currentRunToken === null) return;` and then
 * `if (responseData.runToken !== currentRunToken) return;`. An unsolicited frame is dropped by the
 * first guard before a run and by the second during one — but only for as long as it carries no run
 * token. `Health::sendMessage()` stamps `runToken`/`runId` onto any frame whose connection is on a
 * run, so "carries no run token" is a property of *when* the frame is sent, not of how it is built.
 * {@see testTheHelloFrameCarriesNoRunTokenSoAV1ClientDropsIt()} pins that, because a later change
 * that sent `hello` somewhere other than on connect would paint a failed check in the live UI —
 * UnitTestInterface#46 made an unrecognised `type` a visible failure — and nothing else would notice.
 */
#[CoversClass(Health::class)]
final class HealthHelloFrameTest extends TestCase
{
    // onOpen() queues the connect-time /calendars metadata fetch. Without this the process would
    // dispatch it for real at PHPUnit shutdown. See the trait.
    use HealthQueueIsolationTrait;

    /**
     * The `Health` statics `onOpen()` writes, saved so this file can put them back.
     *
     * @var array<string, mixed>
     */
    private array $cacheStateBackup = [];

    /**
     * The statics `onOpen()` initialises on its way past. Named here rather than inline so the
     * save and the restore cannot drift apart.
     *
     * @var list<string>
     */
    private const CACHE_STATICS = ['cacheInitialized', 'cacheEnabled', 'cacheBackend', 'redis'];

    /**
     * **This file is the only one in the suite that calls `onOpen()` for real, and that is a
     * process-wide event, not a per-test one.**
     *
     * `onOpen()` initialises `Health`'s cache backend — a set of *static* properties, guarded by
     * `self::$cacheInitialized` so it happens once per process — and on a host with a reachable Redis
     * or a working APCu it turns caching **on**. Every other Health suite relies on it being off:
     * `HealthCorrelationTest` says so in as many words ("caching is off in this process (it is
     * initialised in `onOpen()`, which no test calls), so every outbound request really is queued"),
     * and their assertions are written against a queue that a cache hit empties. Measured: calling
     * `onOpen()` without this guard turned 23 tests in six other Health suites red, all of them
     * asserting on requests that were no longer queued — and none of them red for a reason a reader
     * of those files could have traced back to here.
     *
     * So the cache-init block is skipped rather than exercised: `cacheInitialized` is set **before**
     * `onOpen()` runs, which is the flag's own way of saying "already done", and the values are put
     * back afterwards so a suite that ran before this one is not left looking at this one's state
     * either. Nothing about the `hello` frame depends on the cache, so nothing is lost by skipping
     * it — and `HealthApcuDetectionTest` is where that block is actually tested.
     */
    protected function setUp(): void
    {
        foreach (self::CACHE_STATICS as $name) {
            $property                      = new \ReflectionProperty(Health::class, $name);
            $this->cacheStateBackup[$name] = $property->getValue();
        }

        ( new \ReflectionProperty(Health::class, 'cacheInitialized') )->setValue(null, true);
    }

    protected function tearDown(): void
    {
        foreach ($this->cacheStateBackup as $name => $value) {
            ( new \ReflectionProperty(Health::class, $name) )->setValue(null, $value);
        }
        $this->cacheStateBackup = [];
    }

    /**
     * A Ratchet connection that records what was sent to it. `resourceId` is a dynamic public property
     * Ratchet assigns and is not part of `ConnectionInterface`, so this is a stub rather than a PHPUnit
     * mock, which would trigger a dynamic-property deprecation. Same convention as HealthCancelRunTest.
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
     * Open a connection and return the `hello` frame it was sent.
     *
     * `onOpen()` also writes progress to stdout; it is captured and discarded so the frame assertions
     * are not read through pages of connection logging.
     */
    private function helloFrameFor(ConnectionInterface $conn): \stdClass
    {
        $health = $this->newHealth();

        ob_start();
        $health->onOpen($conn);
        ob_end_clean();

        /** @var object{sent: list<string>} $conn */
        self::assertNotEmpty($conn->sent, 'a connecting client was sent nothing at all');

        $frame = json_decode($conn->sent[0]);
        self::assertInstanceOf(\stdClass::class, $frame, 'the first frame is not a JSON object');

        return $frame;
    }

    public function testAHelloFrameIsTheFirstThingAConnectingClientIsSent(): void
    {
        $frame = $this->helloFrameFor(self::createStubConnection(1));

        self::assertSame('hello', $frame->type ?? null);
        self::assertSame(1, $frame->protocol ?? null, 'the frame advertises the protocol version it speaks');
        self::assertInstanceOf(\stdClass::class, $frame->capabilities ?? null);
    }

    public function testTheHelloFrameCarriesNoRunTokenSoAV1ClientDropsIt(): void
    {
        $frame = $this->helloFrameFor(self::createStubConnection(2));

        self::assertObjectNotHasProperty('runToken', $frame);
        self::assertObjectNotHasProperty('runId', $frame);
        self::assertObjectNotHasProperty('requestId', $frame);
    }

    public function testEveryRiteIsAdvertised(): void
    {
        $frame = $this->helloFrameFor(self::createStubConnection(3));

        self::assertSame(
            array_column(Rite::cases(), 'value'),
            $frame->capabilities->rites ?? null,
            'a rite the API supports is not advertised — capabilities must be derived from Rite, not transcribed'
        );
    }

    public function testTheFrameVocabularyIsAdvertised(): void
    {
        $frame = $this->helloFrameFor(self::createStubConnection(4));

        self::assertSame(array_column(Step::cases(), 'value'), $frame->capabilities->steps ?? null);
        self::assertSame(array_column(Status::cases(), 'value'), $frame->capabilities->statuses ?? null);
    }

    /**
     * The response formats advertised are the ones `validateCalendar()` has a validation branch for —
     * narrower than `ReturnTypeParam`, deliberately, because a format outside that list reaches
     * `ReturnTypeParam::from()` and throws a `\ValueError`, which Ratchet does not catch. Advertising
     * the wider list would invite a client to kill the server.
     */
    public function testOnlyTheValidatableResponseFormatsAreAdvertised(): void
    {
        $frame = $this->helloFrameFor(self::createStubConnection(5));

        /** @var list<string> $validatable */
        $validatable = ( new \ReflectionClassConstant(Health::class, 'VALIDATABLE_RESPONSE_FORMATS') )->getValue();

        self::assertSame($validatable, $frame->capabilities->responseFormats ?? null);
    }

    /**
     * `actions` is read from the published schema, not written down here or in `Health`.
     *
     * The schema is the authority on what the server accepts — `WebSocketMessageValidator` refuses
     * anything whose action does not name one of its definitions — so deriving the advertisement from
     * it makes "advertised" and "accepted" the same statement rather than two that must agree.
     *
     * `validateCalendar` has two definitions (a legacy and a typed shape) and must be advertised once:
     * the wire carries actions, not definition names, and `validateCalendarTyped` is a name no client
     * can send.
     */
    public function testTheAdvertisedActionsAreTheOnesTheSchemaDeclares(): void
    {
        $frame = $this->helloFrameFor(self::createStubConnection(6));

        $schema = json_decode((string) file_get_contents(LitSchema::WEBSOCKET_MESSAGE->path()));
        self::assertInstanceOf(\stdClass::class, $schema);

        $declared = [];
        foreach ((array) $schema->definitions as $definition) {
            if ($definition instanceof \stdClass && isset($definition->properties->action->const)) {
                $declared[] = (string) $definition->properties->action->const;
            }
        }
        $declared = array_values(array_unique($declared));

        self::assertNotEmpty($declared, 'the schema declares no actions at all — the test cannot mean anything');
        self::assertEqualsCanonicalizing($declared, $frame->capabilities->actions ?? null);
        self::assertSame(
            count($declared),
            count($frame->capabilities->actions ?? []),
            'validateCalendar has two definitions but is one action; the advertisement must not list it twice'
        );
    }
}
