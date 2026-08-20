<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests;

use LiturgicalCalendar\Api\Health;
use LiturgicalCalendar\Api\Models\Metadata\MetadataCalendars;
use LiturgicalCalendar\Api\Router;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ratchet\ConnectionInterface;

/**
 * `validateCalendar` with a typed calendar identity — #806 section D.
 *
 * The action keeps its name because the action is unchanged: compute a calendar for a year and
 * validate the response. What changes is how the calendar is named. A v1 message spreads the
 * identity across two properties — an opaque `calendar` string plus a `category` whose vocabulary
 * is shared, confusingly, with `executeValidation`'s unrelated schema-resolution strategies — and
 * carries the rite as an optional hint the server is free to guess at. A v2 message carries one
 * `calendar` **object** holding `kind`, `id` and `rite`.
 *
 * Because the action name is unchanged, the shape of `calendar` is the only discriminator there
 * is, and it has to be applied before `validateMessageProperties()` compares against
 * `ACTION_PROPERTIES['validateCalendar']` — the v2 form has neither `category` nor `responsetype`,
 * so the list would turn every v2 message away before it reached a handler.
 *
 * Nothing legacy is touched: a string `calendar` still takes the old path byte for byte.
 *
 * ## What these tests assert against
 *
 * `validateCalendar()` is asynchronous — it hands a URL to `cachedGet()`, which appends it to
 * `Health::$queue` and returns a promise. Nothing is dispatched until the ReactPHP loop runs, which
 * it never does here, so the queued entry is a complete and inert record of the decision the
 * dispatch made: the URL encodes the category (`/nation/`, `/diocese/`, or neither), the calendar
 * id and the rite segment, and the request options carry the Accept header the response format
 * chose. That is the whole of what this task decides, observable without a WebSocket server, an
 * HTTP API, or a mock of the method under test.
 */
#[CoversClass(Health::class)]
final class HealthTypedCalendarTest extends TestCase
{
    /** @var list<Health> every instance this test created, so tearDown can defuse its queue */
    private array $healths = [];

    public static function setUpBeforeClass(): void
    {
        // Route::CALENDAR->path() is built from the resolved API paths.
        Router::getApiPaths();
    }

    /**
     * Empty the request queue of every Health this test built.
     *
     * Not hygiene — necessary. `cachedGet()` parks the request *and* registers a ReactPHP
     * `futureTick`, and `React\EventLoop\Loop` installs a shutdown function that runs the loop when
     * the process ends. Without this, every queued URL would be fetched for real at the end of the
     * PHPUnit run: the suite would start depending on an API server being up, and would hammer it
     * with dozens of full calendar computations to no purpose. `drainHandler()` stops ticking the
     * moment it finds nothing queued and nothing in flight, so emptying the queue is enough.
     */
    protected function tearDown(): void
    {
        $queue = new \ReflectionProperty(Health::class, 'queue');
        foreach ($this->healths as $health) {
            $queue->setValue($health, []);
        }
        $this->healths = [];
    }

    /**
     * A Health whose queue tearDown will defuse. Always use this rather than `new Health()`.
     */
    private function newHealth(): Health
    {
        $health          = new Health();
        $this->healths[] = $health;

        return $health;
    }

    // ---------------------------------------------------------------- the typed identity dispatches

    /**
     * Each row is one message and the request it must produce.
     *
     * The URL is the assertion that matters: `/{rite}/nation/{id}`, `/{rite}/diocese/{id}` and
     * `/{rite}` are three different categories, so a row that lands on the right URL has had its
     * `kind` mapped, its `id` carried and its `rite` honoured, all three at once.
     *
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function typedIdentityProvider(): array
    {
        return [
            'ambrosian diocesan' => [
                ['kind' => 'diocesan', 'id' => 'lugano_ch', 'rite' => 'ambrosian'],
                '/ambrosian/diocese/lugano_ch/2026?year_type=CIVIL'
            ],
            'roman diocesan'     => [
                ['kind' => 'diocesan', 'id' => 'rotter_nl', 'rite' => 'roman'],
                '/roman/diocese/rotter_nl/2026?year_type=CIVIL'
            ],
            'roman national'     => [
                ['kind' => 'national', 'id' => 'IT', 'rite' => 'roman'],
                '/roman/nation/IT/2026?year_type=CIVIL'
            ],
            'rite level'         => [
                ['kind' => 'rite', 'id' => 'ambrosian', 'rite' => 'ambrosian'],
                '/ambrosian/2026?year_type=CIVIL'
            ],
            // `general` names the one General Roman Calendar, so it is the only kind that needs no
            // id: there is nothing to choose between.
            'general'            => [
                ['kind' => 'general', 'rite' => 'roman'],
                '/roman/2026?year_type=CIVIL'
            ],
        ];
    }

    /**
     * @param array<string, mixed> $calendar
     */
    #[DataProvider('typedIdentityProvider')]
    public function testATypedIdentityReachesTheCalendarRequestItNames(array $calendar, string $expectedPath): void
    {
        $health = $this->newHealth();
        $conn   = self::createStubConnection();

        self::withMetadata(static function () use ($health, $conn, $calendar): void {
            self::send($health, $conn, [
                'action'         => 'validateCalendar',
                'calendar'       => $calendar,
                'year'           => 2026,
                'responseFormat' => 'JSON'
            ]);
        });

        self::assertSame([], $conn->sent, 'a well-formed v2 message must not be answered with a frame before it is checked: ' . implode(' ', $conn->sent));
        self::assertSame($expectedPath, self::soleQueuedPath($health));
    }

    /**
     * The discriminator has to be applied ahead of the `ACTION_PROPERTIES` list, not inside the
     * handler. This is the test that says so: the message below carries neither `category` nor
     * `responsetype`, both of which that list requires, so if the list ran first the message would
     * come back as the generic `Invalid message properties` and never reach a calendar request at
     * all.
     */
    public function testTheV2FormIsNotTurnedAwayForMissingCategoryAndResponsetype(): void
    {
        $health = $this->newHealth();
        $conn   = self::createStubConnection();

        self::withMetadata(static function () use ($health, $conn): void {
            self::send($health, $conn, [
                'action'         => 'validateCalendar',
                'calendar'       => ['kind' => 'diocesan', 'id' => 'lugano_ch', 'rite' => 'ambrosian'],
                'year'           => 2026,
                'responseFormat' => 'JSON'
            ]);
        });

        self::assertSame(
            [],
            array_map(static fn (string $raw): mixed => json_decode($raw)->errorMsg ?? null, $conn->sent),
            'the v2 shape was rejected by the property list that only the legacy shape satisfies'
        );
    }

    // ---------------------------------------------------------------- the legacy shape is untouched

    /**
     * A string `calendar` is a v1 message and must behave exactly as it did: `category` names the
     * calendar type, `responsetype` names the format, and the rite is *resolved* rather than
     * asserted — here from the diocese metadata, which is how a rite-unaware client gets the
     * `/ambrosian/` segment its Ambrosian diocese needs (issue #767).
     */
    public function testTheStringFormStillTakesTheLegacyPath(): void
    {
        $health = $this->newHealth();
        $conn   = self::createStubConnection();

        self::withMetadata(static function () use ($health, $conn): void {
            self::send($health, $conn, [
                'action'       => 'validateCalendar',
                'calendar'     => 'lugano_ch',
                'category'     => 'diocesancalendar',
                'year'         => 2026,
                'responsetype' => 'JSON'
            ]);
        });

        self::assertSame([], $conn->sent);
        self::assertSame('/ambrosian/diocese/lugano_ch/2026?year_type=CIVIL', self::soleQueuedPath($health));
    }

    /**
     * The branch must not have loosened the legacy list on its way past. A v1 message missing
     * `category` is still an invalid v1 message — it is not a v2 message merely because it is
     * missing the properties a v2 message would also be missing.
     */
    public function testTheStringFormStillRequiresCategoryAndResponsetype(): void
    {
        $health = $this->newHealth();
        $conn   = self::createStubConnection();

        self::send($health, $conn, [
            'action'   => 'validateCalendar',
            'calendar' => 'lugano_ch',
            'year'     => 2026
        ]);

        self::assertCount(1, $conn->sent);
        $frame = json_decode($conn->sent[0]);
        self::assertSame('echobot', $frame->type);
        self::assertSame('Invalid message properties', $frame->errorMsg);
        self::assertSame([], self::queuedPaths($health), 'an invalid message must not have queued a request');
    }

    // ---------------------------------------------------------------- the response format

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function responseFormatProvider(): array
    {
        return [
            'JSON' => ['JSON', 'application/json'],
            'XML'  => ['XML', 'application/xml'],
            'ICS'  => ['ICS', 'text/calendar'],
            'YML'  => ['YML', 'application/yaml'],
        ];
    }

    /**
     * `responseFormat` is the v2 spelling of `responsetype`, and it has to reach the Accept header
     * the request is made with — a format that is accepted and then ignored would validate the
     * wrong representation and say nothing about it.
     */
    #[DataProvider('responseFormatProvider')]
    public function testResponseFormatIsHonouredOnTheObjectForm(string $format, string $expectedAccept): void
    {
        $health = $this->newHealth();
        $conn   = self::createStubConnection();

        self::send($health, $conn, [
            'action'         => 'validateCalendar',
            'calendar'       => ['kind' => 'national', 'id' => 'IT', 'rite' => 'roman'],
            'year'           => 2026,
            'responseFormat' => $format
        ]);

        self::assertSame([], $conn->sent);
        self::assertSame($expectedAccept, self::soleQueuedAccept($health));
    }

    /**
     * And the v1 spelling keeps working on the v1 shape. The two spellings are not interchangeable
     * across shapes on purpose: `responsetype` on a v2 message is a client that half-migrated, and
     * `responseFormat` on a v1 message is the same mistake mirrored.
     */
    #[DataProvider('responseFormatProvider')]
    public function testResponsetypeIsHonouredOnTheStringForm(string $format, string $expectedAccept): void
    {
        $health = $this->newHealth();
        $conn   = self::createStubConnection();

        self::send($health, $conn, [
            'action'       => 'validateCalendar',
            'calendar'     => 'IT',
            'category'     => 'nationalcalendar',
            'year'         => 2026,
            'responsetype' => $format
        ]);

        self::assertSame([], $conn->sent);
        self::assertSame($expectedAccept, self::soleQueuedAccept($health));
    }

    // ---------------------------------------------------------------- rejections

    public function testAnUnknownKindIsRejected(): void
    {
        $health = $this->newHealth();
        $conn   = self::createStubConnection();

        self::send($health, $conn, [
            'action'         => 'validateCalendar',
            'calendar'       => ['kind' => 'widerregion', 'id' => 'Europe', 'rite' => 'roman'],
            'year'           => 2026,
            'responseFormat' => 'JSON'
        ]);

        self::assertCount(1, $conn->sent, 'an unusable identity is answered once and not computed');
        $frame = json_decode($conn->sent[0]);
        self::assertSame('echobot', $frame->type, 'rejections reuse the echobot shape: since UnitTestInterface#46 an unknown type is painted as a failed check');
        self::assertSame('Unknown calendar kind: widerregion', $frame->text);
        self::assertSame([], self::queuedPaths($health));
    }

    /**
     * The rite-disagreement rejections, one per kind that can be checked.
     *
     * A `rite` on a v1 message is a *hint*: `resolveRite()` prefers it whenever it parses, which is
     * the right thing to do for a value the client may have guessed at. On a v2 message it is an
     * *assertion* about a calendar the server already knows the rite of, and an assertion that is
     * wrong is a client bug. Preferring the assertion would compute the wrong calendar silently;
     * preferring the metadata would compute the right one while leaving the client convinced of
     * something false. Saying so is more useful than either.
     *
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function riteDisagreementProvider(): array
    {
        return [
            // lugano_ch is one of the four Ambrosian dioceses; /calendars says so.
            'diocesan claimed roman'        => [['kind' => 'diocesan', 'id' => 'lugano_ch', 'rite' => 'roman']],
            'diocesan claimed ambrosian'    => [['kind' => 'diocesan', 'id' => 'rotter_nl', 'rite' => 'ambrosian']],
            // There are no Ambrosian national calendars: /calendar/ambrosian/nation/IT is a 400.
            'national claimed ambrosian'    => [['kind' => 'national', 'id' => 'IT', 'rite' => 'ambrosian']],
            // For a rite-level calendar the id IS the rite, so the message contradicts itself.
            'rite level contradicts itself' => [['kind' => 'rite', 'id' => 'ambrosian', 'rite' => 'roman']],
            // `general` is the General *Roman* Calendar; a rite-level Ambrosian calendar is kind `rite`.
            'general claimed ambrosian'     => [['kind' => 'general', 'rite' => 'ambrosian']],
        ];
    }

    /**
     * @param array<string, mixed> $calendar
     */
    #[DataProvider('riteDisagreementProvider')]
    public function testARiteThatDisagreesWithTheCalendarIsRejected(array $calendar): void
    {
        $health = $this->newHealth();
        $conn   = self::createStubConnection();

        // Inside withMetadata: the diocesan rows are only *checkable* when /calendars has been
        // fetched. Without it the server has no opinion to disagree with and, deliberately, lets
        // the request through — see testARiteIsNotCheckedWhenTheServerHasNoOpinionToCheckItAgainst().
        self::withMetadata(static function () use ($health, $conn, $calendar): void {
            self::send($health, $conn, [
                'action'         => 'validateCalendar',
                'calendar'       => $calendar,
                'year'           => 2026,
                'responseFormat' => 'JSON'
            ]);
        });

        self::assertCount(1, $conn->sent, 'a contradicted rite is answered once and not computed');
        $frame = json_decode($conn->sent[0]);
        self::assertSame('echobot', $frame->type);
        self::assertStringContainsString('calendar.rite says', (string) $frame->text);
        self::assertSame([], self::queuedPaths($health), 'a rejected message must not have queued a request');
    }

    /**
     * What the server cannot check it must not pretend to have checked.
     *
     * A diocese's real rite comes from `/calendars`, which `Health` fetches asynchronously when the
     * WebSocket connection opens. Two states have no answer to compare an assertion against: the
     * metadata not being loaded at all, and its being loaded without the diocese in it. Rejecting on
     * either would turn a server-side condition into a reported client bug — the #800 blindness in
     * miniature — so the assertion is taken at its word and the request goes out.
     * `resolveRite()` already makes the same call, for the same reason: the request itself reports
     * the real problem, as a 404 the client can see.
     *
     * This drives the second of the two states. The first cannot be driven deterministically:
     * `Health::$metadata` is a typed static with no default, and once any test in the process has
     * set it PHP offers no way back to genuinely uninitialised. Both funnel into the same `null`
     * from `actualRiteForKind()`, so the branch is covered either way.
     *
     * Both rites are asserted because each catches a different way of getting this wrong, and
     * either alone would pass against the other's mutation. `roman` catches an implementation that
     * *rejects* what it cannot look up — the honest-sounding mistake. `ambrosian` catches one that
     * substitutes the default rite for "unknown", which reads as a check and is not one: it would
     * agree with `roman` for every diocese in the world and disagree with `ambrosian` for all four
     * that are.
     *
     * @return array<string, array{0: string}>
     */
    public static function unknowableRiteProvider(): array
    {
        return [
            'roman'     => ['roman'],
            'ambrosian' => ['ambrosian'],
        ];
    }

    #[DataProvider('unknowableRiteProvider')]
    public function testARiteIsNotCheckedWhenTheServerHasNoOpinionToCheckItAgainst(string $rite): void
    {
        $health = $this->newHealth();
        $conn   = self::createStubConnection();

        // Force the state rather than inheriting whatever an earlier test left: lugano_ch really is
        // Ambrosian, and the whole point is that nothing loaded here knows that.
        self::withEmptyMetadata(static function () use ($health, $conn, $rite): void {
            self::send($health, $conn, [
                'action'         => 'validateCalendar',
                'calendar'       => ['kind' => 'diocesan', 'id' => 'lugano_ch', 'rite' => $rite],
                'year'           => 2026,
                'responseFormat' => 'JSON'
            ]);
        });

        self::assertSame([], $conn->sent, 'an unverifiable assertion was reported as a client bug');
        self::assertSame("/{$rite}/diocese/lugano_ch/2026?year_type=CIVIL", self::soleQueuedPath($health));
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function malformedIdentityProvider(): array
    {
        return [
            'kind missing'      => [['id' => 'IT', 'rite' => 'roman'], 'calendar.kind must be a string naming one of: general, national, diocesan, rite.'],
            'kind not a string' => [['kind' => 42, 'id' => 'IT', 'rite' => 'roman'], 'calendar.kind must be a string naming one of: general, national, diocesan, rite.'],
            'rite missing'      => [['kind' => 'national', 'id' => 'IT'], 'calendar.rite must be a string naming a known rite.'],
            'rite unknown'      => [['kind' => 'national', 'id' => 'IT', 'rite' => 'byzantine'], 'Unknown rite: byzantine'],
            'id missing'        => [['kind' => 'national', 'rite' => 'roman'], 'calendar.id is required for kind national.'],
            'id not a string'   => [['kind' => 'diocesan', 'id' => 42, 'rite' => 'roman'], 'calendar.id must be a string.'],
        ];
    }

    /**
     * @param array<string, mixed> $calendar
     */
    #[DataProvider('malformedIdentityProvider')]
    public function testAMalformedIdentityIsRejectedWithWhatIsWrongWithIt(array $calendar, string $expected): void
    {
        $health = $this->newHealth();
        $conn   = self::createStubConnection();

        self::send($health, $conn, [
            'action'         => 'validateCalendar',
            'calendar'       => $calendar,
            'year'           => 2026,
            'responseFormat' => 'JSON'
        ]);

        self::assertCount(1, $conn->sent);
        $frame = json_decode($conn->sent[0]);
        self::assertSame('echobot', $frame->type);
        self::assertSame($expected, $frame->text);
        self::assertSame([], self::queuedPaths($health));
    }

    /**
     * A format `validateCalendar()` has no branch for must be turned away here rather than reaching
     * `ReturnTypeParam::from()`, which throws a `\ValueError` on an unknown case. That is an
     * `\Error`, and Ratchet's `IoServer::handleData` catches only `\Exception`, so it escapes and
     * takes the whole WebSocket process down over one malformed message — the hazard `cancelRun()`
     * documents, reached by a different door.
     */
    public function testAResponseFormatWithNoValidationBranchIsRejectedRatherThanThrown(): void
    {
        $health = $this->newHealth();
        $conn   = self::createStubConnection();

        self::send($health, $conn, [
            'action'         => 'validateCalendar',
            'calendar'       => ['kind' => 'national', 'id' => 'IT', 'rite' => 'roman'],
            'year'           => 2026,
            'responseFormat' => 'PDF'
        ]);

        self::assertCount(1, $conn->sent);
        $frame = json_decode($conn->sent[0]);
        self::assertSame('echobot', $frame->type);
        self::assertSame('validateCalendar responseFormat must be one of: JSON, XML, ICS, YML.', $frame->text);
        self::assertSame([], self::queuedPaths($health));
    }

    public function testAYearThatIsNotAnIntegerIsRejectedRatherThanThrown(): void
    {
        $health = $this->newHealth();
        $conn   = self::createStubConnection();

        self::send($health, $conn, [
            'action'         => 'validateCalendar',
            'calendar'       => ['kind' => 'national', 'id' => 'IT', 'rite' => 'roman'],
            'year'           => null,
            'responseFormat' => 'JSON'
        ]);

        self::assertCount(1, $conn->sent);
        $frame = json_decode($conn->sent[0]);
        self::assertSame('echobot', $frame->type);
        self::assertSame('validateCalendar year must be an integer.', $frame->text);
        self::assertSame([], self::queuedPaths($health));
    }

    // ---------------------------------------------------------------- harness

    /**
     * A minimal Ratchet connection that records every outbound frame. `resourceId` is a dynamic
     * public property Ratchet assigns and is not part of `ConnectionInterface`, so this mirrors the
     * stub convention already used by HealthValidateSourceTest and HealthCancelRunTest rather than
     * a PHPUnit mock, which would trigger a dynamic-property deprecation.
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
     * The requests `cachedGet()` parked on the queue, as paths relative to `/calendar`.
     *
     * @return list<string>
     */
    private static function queuedPaths(Health $health): array
    {
        $prefix = \LiturgicalCalendar\Api\Enum\Route::CALENDAR->path();

        return array_map(
            static function (array $entry) use ($prefix): string {
                /** @var array{url:string} $entry */
                self::assertStringStartsWith($prefix, $entry['url'], 'a calendar request was made against something other than /calendar');

                return substr($entry['url'], strlen($prefix));
            },
            self::queuedRequests($health)
        );
    }

    private static function soleQueuedPath(Health $health): string
    {
        $paths = self::queuedPaths($health);
        self::assertCount(1, $paths, 'expected exactly one queued calendar request, got: ' . json_encode($paths));

        return $paths[0];
    }

    private static function soleQueuedAccept(Health $health): string
    {
        $queued = self::queuedRequests($health);
        self::assertCount(1, $queued, 'expected exactly one queued calendar request');
        /** @var array{options:array{headers?:array<string, string>}} $entry */
        $entry = $queued[0];
        self::assertArrayHasKey('headers', $entry['options']);
        self::assertArrayHasKey('Accept', $entry['options']['headers']);

        return $entry['options']['headers']['Accept'];
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
     * Populate `Health::$metadata` with the same two-diocese stand-in HealthRiteRequestPathTest
     * uses — one Ambrosian, one Roman — for the duration of $fn, then put back what was there.
     *
     * `Health::$metadata` is a typed static with no default, so PHP offers no way to return it to
     * the genuinely uninitialised state. When it started out uninitialised we therefore leave an
     * *empty* MetadataCalendars behind rather than the fixture, so a later test in the same process
     * cannot resolve `lugano_ch` or `rotter_nl` from data this helper invented.
     */
    private static function withMetadata(callable $fn): void
    {
        $property = new \ReflectionProperty(Health::class, 'metadata');
        $wasSet   = $property->isInitialized();
        $previous = $wasSet ? $property->getValue() : null;

        $property->setValue(null, MetadataCalendars::fromObject((object) [
            'national_calendars'       => [],
            'national_calendars_keys'  => [],
            'diocesan_calendars_keys'  => ['lugano_ch', 'rotter_nl'],
            'diocesan_groups'          => [],
            'wider_regions'            => [],
            'wider_regions_keys'       => [],
            'ambrosian_calendars'      => [],
            'ambrosian_calendars_keys' => [],
            'locales'                  => ['en'],
            'diocesan_calendars'       => [
                (object) [
                    'calendar_id' => 'lugano_ch',
                    'diocese'     => 'Lugano',
                    'nation'      => 'CH',
                    'locales'     => ['it_IT'],
                    'timezone'    => 'Europe/Zurich',
                    'rite'        => 'ambrosian',
                ],
                (object) [
                    'calendar_id' => 'rotter_nl',
                    'diocese'     => 'Rotterdam',
                    'nation'      => 'NL',
                    'locales'     => ['nl_NL'],
                    'timezone'    => 'Europe/Amsterdam',
                    'rite'        => 'roman',
                ],
            ],
        ]));

        try {
            $fn();
        } finally {
            if ($wasSet && $previous !== null) {
                $property->setValue(null, $previous);
            } else {
                $property->setValue(null, self::emptyMetadata());
            }
        }
    }

    /**
     * Run $fn with `Health::$metadata` populated but empty — the state in which the server has no
     * opinion about any diocese's rite.
     */
    private static function withEmptyMetadata(callable $fn): void
    {
        $property = new \ReflectionProperty(Health::class, 'metadata');
        $wasSet   = $property->isInitialized();
        $previous = $wasSet ? $property->getValue() : null;

        $property->setValue(null, self::emptyMetadata());

        try {
            $fn();
        } finally {
            if ($wasSet && $previous !== null) {
                $property->setValue(null, $previous);
            }
        }
    }

    private static function emptyMetadata(): MetadataCalendars
    {
        return MetadataCalendars::fromObject((object) [
            'national_calendars'       => [],
            'national_calendars_keys'  => [],
            'diocesan_calendars'       => [],
            'diocesan_calendars_keys'  => [],
            'diocesan_groups'          => [],
            'wider_regions'            => [],
            'wider_regions_keys'       => [],
            'ambrosian_calendars'      => [],
            'ambrosian_calendars_keys' => [],
            'locales'                  => ['en'],
        ]);
    }
}
