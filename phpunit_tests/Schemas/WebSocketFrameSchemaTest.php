<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Schemas;

use LiturgicalCalendar\Api\Enum\LitSchema;
use LiturgicalCalendar\Api\Enum\Status;
use LiturgicalCalendar\Api\Enum\Step;
use LiturgicalCalendar\Api\Health;
use LiturgicalCalendar\Api\Router;
use LiturgicalCalendar\Tests\Support\HealthQueueIsolationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ratchet\ConnectionInterface;
use Swaggest\JsonSchema\Schema;

/**
 * `WebSocketFrame.json` — the published contract for what the server *sends*.
 *
 * Its inbound counterpart has been published since #806 section G; this half arrived with section F,
 * because a client's own types are almost entirely about received frames and there was nothing to
 * check them against.
 *
 * **Every frame here is produced by the server, not transcribed into a fixture.** A published
 * document that describes frames a test author wrote out by hand is exactly the drift this repo keeps
 * filing bugs about — #805 (annotations that disagreed with the code), #822, #833, #834 (checks that
 * reported something they had not verified). So `hello` and `protocolError` are captured by driving
 * `onOpen()` and `onMessage()`, and the two result frames are captured by calling the private
 * emitters that every real result frame goes through. If a frame's shape changes and this document
 * does not, these fail.
 */
#[CoversClass(Health::class)]
final class WebSocketFrameSchemaTest extends TestCase
{
    use HealthQueueIsolationTrait;

    /** @var array<string, mixed> */
    private array $cacheStateBackup = [];

    /** @var list<string> */
    private const CACHE_STATICS = ['cacheInitialized', 'cacheEnabled', 'cacheBackend', 'redis'];

    /**
     * Paths must exist before the schema can be located, and this class may be the first to run.
     */
    public static function setUpBeforeClass(): void
    {
        Router::getApiPaths();
    }

    /**
     * Skip `onOpen()`'s cache initialisation and put the statics back afterwards. The reasoning is
     * recorded in full on `HealthHelloFrameTest::setUp()`: initialising the cache backend is a
     * process-wide event that turns 23 tests in other Health suites red, none of them in a way a
     * reader of those files could trace back to here.
     */
    protected function setUp(): void
    {
        foreach (self::CACHE_STATICS as $name) {
            $this->cacheStateBackup[$name] = ( new \ReflectionProperty(Health::class, $name) )->getValue();
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

    private static function schema(): Schema
    {
        $schema = Schema::import(LitSchema::WEBSOCKET_FRAME->path());
        self::assertInstanceOf(Schema::class, $schema);

        return $schema;
    }

    /** Same stub convention as the other Health suites; see HealthCancelRunTest. */
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
     * Assert a frame the server really emitted validates, and say which frame if it does not.
     */
    private static function assertFrameValidates(string $raw, string $what): void
    {
        try {
            self::schema()->in(json_decode($raw));
        } catch (\Throwable $e) {
            self::fail(sprintf("the %s frame the server emits does not match the published contract:\n%s\n%s", $what, $e->getMessage(), $raw));
        }
        self::assertTrue(true);
    }

    public function testTheHelloFrameMatchesThePublishedContract(): void
    {
        $health = $this->newHealth();
        $conn   = self::createStubConnection(1);

        ob_start();
        $health->onOpen($conn);
        ob_end_clean();

        /** @var object{sent: list<string>} $conn */
        self::assertFrameValidates($conn->sent[0], 'hello');
    }

    public function testAProtocolErrorFrameMatchesThePublishedContract(): void
    {
        $health = $this->newHealth();
        $conn   = self::createStubConnection(2);

        ob_start();
        $health->onMessage($conn, (string) json_encode(['action' => 'cancelRun', 'runToken' => 'run-a', 'protocol' => 7]));
        ob_end_clean();

        /** @var object{sent: list<string>} $conn */
        self::assertCount(1, $conn->sent);
        self::assertFrameValidates($conn->sent[0], 'protocolError');
    }

    /**
     * The step-result frame, taken from the emitter every real one passes through rather than written
     * out here. Both outcomes are exercised, because `type` is projected from `status` and the two
     * halves of that projection are different frames as far as the schema is concerned.
     */
    public function testAStepResultFrameMatchesThePublishedContract(): void
    {
        foreach ([Status::PASS, Status::FAIL] as $status) {
            $health = $this->newHealth();
            $conn   = self::createStubConnection(3);

            $send = new \ReflectionMethod(Health::class, 'sendStepResult');
            $send->invoke(
                $health,
                $conn,
                'temporale-roman',
                (object) ['id' => 'temporale:roman'],
                Step::VALIDATES,
                $status,
                'a message a human reads',
                Status::FAIL === $status ? ['one failure', 'another failure'] : null,
                'run-a',
                null,
                \LiturgicalCalendar\Api\Enum\FrameFamily::CHECK,
                null,
                'req-alpha'
            );

            /** @var object{sent: list<string>} $conn */
            self::assertFrameValidates($conn->sent[0], 'step result (' . $status->value . ')');
        }
    }

    /**
     * The terminal frame, both as it ends a finished request and as it ends a cancelled one — the
     * `cancelled` key is the only difference and is omitted rather than sent as `false`.
     */
    public function testATerminalFrameMatchesThePublishedContract(): void
    {
        foreach ([false, true] as $cancelled) {
            $health = $this->newHealth();
            $conn   = self::createStubConnection(4);

            $send = new \ReflectionMethod(Health::class, 'sendComplete');
            $send->invoke($health, $conn, (object) ['id' => 'temporale:roman'], 'run-a', 'req-alpha', $cancelled);

            /** @var object{sent: list<string>} $conn */
            self::assertCount(1, $conn->sent, 'the terminal frame was not emitted at all');
            self::assertFrameValidates($conn->sent[0], $cancelled ? 'complete (cancelled)' : 'complete');
        }
    }

    /**
     * The published error codes are the ones the server can actually send.
     *
     * A code the enum has and the schema lacks would make a real refusal fail its own contract; a code
     * the schema has and the enum lacks would promise a client a branch that can never be taken.
     */
    public function testThePublishedErrorCodesAreTheOnesTheServerSends(): void
    {
        $raw = json_decode((string) file_get_contents(LitSchema::WEBSOCKET_FRAME->path()));
        self::assertInstanceOf(\stdClass::class, $raw);

        self::assertEqualsCanonicalizing(
            array_column(\LiturgicalCalendar\Api\Enum\ProtocolErrorCode::cases(), 'value'),
            array_map('strval', (array) $raw->definitions->protocolError->properties->errorCode->enum)
        );
    }

    /**
     * The steps a result frame may report are the ones the server has, minus the terminal one, which
     * has a frame of its own.
     */
    public function testThePublishedStepsAreTheOnesTheServerSends(): void
    {
        $raw = json_decode((string) file_get_contents(LitSchema::WEBSOCKET_FRAME->path()));
        self::assertInstanceOf(\stdClass::class, $raw);

        $reportable = array_values(array_filter(
            array_column(Step::cases(), 'value'),
            static fn (string $step): bool => Step::COMPLETE->value !== $step
        ));

        self::assertEqualsCanonicalizing(
            $reportable,
            array_map('strval', (array) $raw->definitions->stepResult->properties->step->enum)
        );
        self::assertSame(
            Step::COMPLETE->value,
            $raw->definitions->complete->properties->step->const,
            'the terminal frame must name the terminal step'
        );
    }
}
