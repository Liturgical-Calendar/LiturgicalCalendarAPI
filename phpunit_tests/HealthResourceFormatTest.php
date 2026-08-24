<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests;

use LiturgicalCalendar\Api\Enum\ProtocolErrorCode;
use LiturgicalCalendar\Api\Health;
use LiturgicalCalendar\Api\Models\ValidationsPath\CheckableInventory;
use LiturgicalCalendar\Api\Router;
use LiturgicalCalendar\Tests\Support\HealthQueueIsolationTrait;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Ratchet\ConnectionInterface;

/**
 * `executeValidation` honours the response format it is asked for.
 *
 * Until this landed, `responsetype` rode along on every resource check and was never read: the URL
 * was fetched under default content negotiation (JSON) whatever the client selected, while the
 * client's own card labelled the result with the format it had asked for. Picking YAML in
 * UnitTestInterface's Resources runner therefore produced a page of green cards claiming to have
 * parsed YAML, over thirty-odd payloads that were all JSON — a wrong-green manufactured by the
 * interface whose job is to detect them.
 *
 * **The mechanism is the Accept header, and that is not a stylistic choice.** `return_type` is a
 * property of `CalendarParams`, so it exists only on `/calendar`; every route a resource check
 * addresses negotiates through `Negotiator`, which reads `Accept` and nothing else. Appending
 * `?return_type=YML` to `/calendars` returns `application/json` with a 200 — the exact shape of a
 * silent wrong-green — which is why {@see Health::ACCEPT_HEADER_FOR_FORMAT} exists and why the first
 * test here asserts on the queued request's Guzzle options rather than only on the frames.
 */
#[CoversClass(Health::class)]
final class HealthResourceFormatTest extends TestCase
{
    use HealthQueueIsolationTrait;

    private const CALENDARS_URL = 'http://localhost:8000/calendars';

    /** A minimal but genuinely-YAML body: `json_decode()` fails on it, `Yaml::parse()` does not. */
    private const YAML_BODY = "litcal_metadata:\n    national_calendars: []\n";

    public static function setUpBeforeClass(): void
    {
        Router::getApiPaths();
        CheckableInventory::reset();
    }

    public static function tearDownAfterClass(): void
    {
        CheckableInventory::reset();
    }

    private ?string $appEnvBackup = null;

    protected function setUp(): void
    {
        parent::setUp();
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

    // ---------------------------------------------------------------- harness

    private static function stubConnection(int $resourceId = 1)
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
        ob_start();
        $health->onMessage($conn, (string) json_encode($payload));
        ob_end_clean();
    }

    /**
     * @return list<\stdClass>
     */
    private static function framesOf(ConnectionInterface $conn): array
    {
        /** @var object{sent: list<string>} $conn */
        return array_map(static fn (string $raw): \stdClass => json_decode($raw), $conn->sent);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function queuedRequests(Health $health): array
    {
        /** @var list<array<string, mixed>> */
        return ( new \ReflectionProperty(Health::class, 'queue') )->getValue($health);
    }

    private static function fulfilQueued(Health $health, int $index, int $status, string $body, string $contentType): void
    {
        $queued = self::queuedRequests($health);
        self::assertArrayHasKey($index, $queued, "expected a queued request at index {$index}");
        /** @var \Closure(ResponseInterface):void $resolve */
        $resolve = $queued[$index]['resolve'];

        ob_start();
        $resolve(new Response($status, ['Content-Type' => $contentType], $body));
        ob_end_clean();
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private static function resourceCheck(array $extra = []): array
    {
        return array_merge([
            'action'     => 'executeValidation',
            'category'   => 'resourceDataCheck',
            'validate'   => 'calendars-path',
            'sourceFile' => self::CALENDARS_URL,
            'requestId'  => 'req-fmt'
        ], $extra);
    }

    /**
     * @return list<\stdClass>
     */
    private static function stepFrames(ConnectionInterface $conn, string $step): array
    {
        return array_values(array_filter(
            self::framesOf($conn),
            static fn (\stdClass $f): bool => ( $f->step ?? null ) === $step
        ));
    }

    // ---------------------------------------------------------------- the Accept header

    /**
     * The assertion the whole change exists for: the format reaches the wire as an Accept header.
     *
     * Asserted on the queued Guzzle options rather than on a frame, because a frame cannot tell the
     * difference between "fetched as YAML" and "fetched as JSON and labelled YAML" — which is exactly
     * the bug. `cachedGet()` keys its cache on `serialize($options)`, so this also establishes that
     * the two formats of one URL cannot collide in the cache.
     */
    public function testAYmlResourceCheckIsFetchedWithAYamlAcceptHeader(): void
    {
        $health = $this->newHealth();
        $conn   = self::stubConnection();

        self::send($health, $conn, self::resourceCheck(['responseFormat' => 'YML']));

        $queued = self::queuedRequests($health);
        self::assertArrayHasKey(0, $queued, 'the check queued no HTTP request');
        self::assertSame(self::CALENDARS_URL, $queued[0]['url']);
        /** @var array{headers?: array<string, string>} $options */
        $options = $queued[0]['options'];
        self::assertSame(
            'application/yaml',
            $options['headers']['Accept'] ?? null,
            'the requested format never reached the request'
        );
    }

    /**
     * The default is JSON, explicitly asked for rather than left to the server's own default, so a
     * message that names no format still pins one on the wire.
     */
    public function testAResourceCheckWithNoFormatAsksForJson(): void
    {
        $health = $this->newHealth();
        $conn   = self::stubConnection();

        self::send($health, $conn, self::resourceCheck());

        $queued = self::queuedRequests($health);
        self::assertArrayHasKey(0, $queued);
        /** @var array{headers?: array<string, string>} $options */
        $options = $queued[0]['options'];
        self::assertSame('application/json', $options['headers']['Accept'] ?? null);
    }

    // ---------------------------------------------------------------- the parses step

    /**
     * A YAML body decodes on the `parses` step instead of failing it.
     *
     * Before the format was threaded through, this arm ran `json_decode()` over whatever came back,
     * so honouring the Accept header without also teaching the parse step YAML would have turned
     * every YAML check red on syntax — trading a silent wrong-green for a loud wrong-red.
     */
    public function testAYamlBodyParsesAsYaml(): void
    {
        $health = $this->newHealth();
        $conn   = self::stubConnection();

        self::send($health, $conn, self::resourceCheck(['responseFormat' => 'YML']));
        self::fulfilQueued($health, 0, 200, self::YAML_BODY, 'application/yaml');

        $parses = self::stepFrames($conn, 'parses');
        self::assertCount(1, $parses, 'exactly one frame per step');
        self::assertSame('pass', $parses[0]->status);
        self::assertStringContainsString('decoded as YAML', $parses[0]->text);
    }

    /**
     * And a body that is not valid YAML fails it, naming YAML rather than JSON — a client reading
     * "could not be decoded as JSON" after asking for YAML would hunt the wrong problem.
     */
    public function testAMalformedYamlBodyFailsTheParseStepNamingYaml(): void
    {
        $health = $this->newHealth();
        $conn   = self::stubConnection();

        self::send($health, $conn, self::resourceCheck(['responseFormat' => 'YML']));
        self::fulfilQueued($health, 0, 200, "key: [unclosed\n  bad: : :\n", 'application/yaml');

        $parses = self::stepFrames($conn, 'parses');
        self::assertCount(1, $parses);
        self::assertSame('fail', $parses[0]->status);
        self::assertStringContainsString('as YAML', $parses[0]->text);
        self::assertStringNotContainsString('as JSON', $parses[0]->text);
    }

    /**
     * A JSON check is unchanged, wording included. The rename of the message must not silently
     * reword every existing frame a client may be matching on.
     */
    public function testAJsonBodyStillParsesAndStillSaysJson(): void
    {
        $health = $this->newHealth();
        $conn   = self::stubConnection();

        self::send($health, $conn, self::resourceCheck(['responseFormat' => 'JSON']));
        self::fulfilQueued($health, 0, 200, '{"litcal_metadata":{}}', 'application/json');

        $parses = self::stepFrames($conn, 'parses');
        self::assertCount(1, $parses);
        self::assertSame('pass', $parses[0]->status);
        self::assertStringContainsString('decoded as JSON', $parses[0]->text);
    }

    // ---------------------------------------------------------------- rejections

    /**
     * `responsetype` is retired on this action now, and says what replaces it.
     *
     * It was deliberately left accepted for as long as nothing read it. A client still sending the
     * old spelling once the new one is honoured would have its choice silently ignored and every
     * check fetched as JSON while its cards claimed otherwise, so silence stopped being harmless.
     */
    public function testTheRetiredResponsetypeIsRefusedWithItsReplacement(): void
    {
        $health = $this->newHealth();
        $conn   = self::stubConnection();

        self::send($health, $conn, self::resourceCheck(['responsetype' => 'YML']));

        $frames = self::framesOf($conn);
        self::assertSame('protocolError', $frames[0]->type);
        self::assertSame(ProtocolErrorCode::RETIRED_PROPERTY->value, $frames[0]->errorCode);
        self::assertSame(
            'responsetype is not part of a executeValidation message: responseFormat replaces it.',
            $frames[0]->text
        );
        self::assertSame([], self::queuedRequests($health), 'a refused message must not fetch anything');
    }

    /**
     * XML and ICS are valid on `validateCalendar` and not here.
     *
     * Not a stylistic restriction: `/calendar` serves all four, and every route a resource check
     * addresses answers **406** to `application/xml` and `text/calendar`. Accepting them would hand a
     * client a format the check can only ever fail on, and `ReturnTypeParam::from()` throws a
     * `\ValueError` — an `\Error` Ratchet does not catch — on anything outside its own list, so an
     * unvalidated format is a process-killing hazard rather than merely a useless one.
     */
    #[DataProvider('formatsValidOnCalendarButNotOnResources')]
    public function testAFormatNoResourcePathServesIsRefused(string $format): void
    {
        $health = $this->newHealth();
        $conn   = self::stubConnection();

        self::send($health, $conn, self::resourceCheck(['responseFormat' => $format]));

        $frames = self::framesOf($conn);
        self::assertSame('protocolError', $frames[0]->type);
        self::assertSame([], self::queuedRequests($health), 'a refused message must not fetch anything');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function formatsValidOnCalendarButNotOnResources(): array
    {
        return [
            'XML' => ['XML'],
            'ICS' => ['ICS']
        ];
    }
}
