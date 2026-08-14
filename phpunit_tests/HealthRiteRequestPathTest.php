<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests;

use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Health;
use LiturgicalCalendar\Api\Models\Metadata\MetadataCalendars;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Coverage for the rite-aware calendar request path the WebSocket runner builds
 * (issue #767).
 *
 * Before this change `buildCalendarRequestPath()` emitted `/diocese/{id}/{year}`
 * with no rite segment, which the router rejects with a 400 for the four
 * Ambrosian dioceses — so a test scoped to one of them could never pass. The
 * two methods under test are pure enough to drive directly by reflection,
 * without standing up Ratchet or the HTTP API.
 */
#[CoversClass(Health::class)]
final class HealthRiteRequestPathTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: int, 2: string, 3: Rite, 4: string}>
     */
    public static function requestPathProvider(): array
    {
        return [
            'roman rite-level'     => ['roman', 2026, 'ritecalendar', Rite::ROMAN, '/roman/2026?year_type=CIVIL'],
            'ambrosian rite-level' => ['ambrosian', 2026, 'ritecalendar', Rite::AMBROSIAN, '/ambrosian/2026?year_type=CIVIL'],
            'legacy VA marker'     => ['VA', 2026, 'nationalcalendar', Rite::ROMAN, '/roman/2026?year_type=CIVIL'],
            'roman national'       => ['US', 2026, 'nationalcalendar', Rite::ROMAN, '/roman/nation/US/2026?year_type=CIVIL'],
            'roman diocesan'       => ['rotter_nl', 2026, 'diocesancalendar', Rite::ROMAN, '/roman/diocese/rotter_nl/2026?year_type=CIVIL'],
            'ambrosian diocesan'   => ['lugano_ch', 2026, 'diocesancalendar', Rite::AMBROSIAN, '/ambrosian/diocese/lugano_ch/2026?year_type=CIVIL'],
        ];
    }

    #[DataProvider('requestPathProvider')]
    public function testBuildCalendarRequestPath(string $calendar, int $year, string $category, Rite $rite, string $expected): void
    {
        self::assertSame($expected, self::buildPath($calendar, $year, $category, $rite));
    }

    public function testUnknownCategoryThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown calendar category: widerregioncalendar');
        self::buildPath('europe', 2026, 'widerregioncalendar', Rite::ROMAN);
    }

    public function testExplicitRiteHintWins(): void
    {
        self::assertSame(Rite::AMBROSIAN, self::resolveRite('US', 'nationalcalendar', 'ambrosian'));
    }

    public function testUnknownRiteHintFallsBackToDefault(): void
    {
        self::assertSame(Rite::ROMAN, self::resolveRite('US', 'nationalcalendar', 'byzantine'));
    }

    public function testRiteCalendarCategoryReadsTheRiteFromTheCalendarId(): void
    {
        self::assertSame(Rite::AMBROSIAN, self::resolveRite('ambrosian', 'ritecalendar', null));
        self::assertSame(Rite::ROMAN, self::resolveRite('roman', 'ritecalendar', null));
    }

    public function testUnknownRiteCalendarIdFallsBackToDefault(): void
    {
        self::assertSame(Rite::ROMAN, self::resolveRite('byzantine', 'ritecalendar', null));
    }

    public function testNationalCalendarAlwaysResolvesToTheDefaultRite(): void
    {
        // /calendars announces no rite for national calendars, and
        // /calendar/ambrosian/nation/IT is a 400 — there are none.
        self::assertSame(Rite::ROMAN, self::resolveRite('IT', 'nationalcalendar', null));
    }

    public function testDiocesanCalendarWithoutLoadedMetadataFallsBackToDefault(): void
    {
        // findDioceseMetadata() throws RuntimeException until the WS connection
        // has fetched /calendars; the request itself will surface the real error.
        self::assertSame(Rite::ROMAN, self::resolveRite('lugano_ch', 'diocesancalendar', null));
    }

    public function testDiocesanCalendarReadsTheRiteFromMetadata(): void
    {
        self::withMetadata(function (): void {
            self::assertSame(Rite::AMBROSIAN, self::resolveRite('lugano_ch', 'diocesancalendar', null));
            self::assertSame(Rite::ROMAN, self::resolveRite('rotter_nl', 'diocesancalendar', null));
        });
    }

    public function testUnknownDioceseFallsBackToDefault(): void
    {
        self::withMetadata(function (): void {
            self::assertSame(Rite::ROMAN, self::resolveRite('nowhere_zz', 'diocesancalendar', null));
        });
    }

    public function testReadRiteHintIgnoresAbsentAndNonStringValues(): void
    {
        $method = new \ReflectionMethod(Health::class, 'readRiteHint');

        self::assertNull($method->invoke(null, (object) ['action' => 'executeUnitTest']));
        self::assertNull($method->invoke(null, (object) ['rite' => 42]));
        self::assertSame('ambrosian', $method->invoke(null, (object) ['rite' => 'ambrosian']));
    }

    /**
     * The WebSocket dispatch has to hand the message's `rite` to the path builder,
     * or a rite-aware client's selection is silently dropped and every Ambrosian
     * diocesan request 400s (issue #767).
     *
     * Driven with a category the builder rejects, so the assertion lands on the
     * synchronous path-building step and no HTTP request is ever queued — this
     * needs neither the WS server nor the API to be running.
     *
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function dispatchedActionProvider(): array
    {
        return [
            'executeUnitTest'  => [
                [
                    'action'   => 'executeUnitTest',
                    'category' => 'widerregioncalendar',
                    'calendar' => 'europe',
                    'year'     => 2026,
                    'test'     => 'SomeTest',
                    'rite'     => 'ambrosian',
                ]
            ],
            'validateCalendar' => [
                [
                    'action'       => 'validateCalendar',
                    'category'     => 'widerregioncalendar',
                    'calendar'     => 'europe',
                    'year'         => 2026,
                    'responsetype' => 'JSON',
                    'rite'         => 'ambrosian',
                ]
            ],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('dispatchedActionProvider')]
    public function testWebSocketDispatchReachesTheRiteAwarePathBuilder(array $payload): void
    {
        $health = new Health();
        $conn   = self::stubConnection();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown calendar category: widerregioncalendar');

        // onMessage() echoes progress; swallow it so the assertion output stays clean.
        ob_start();
        try {
            $health->onMessage($conn, (string) json_encode($payload));
        } finally {
            ob_end_clean();
        }
    }

    public function testDispatchAcceptsAMessageWithNoRiteProperty(): void
    {
        // A client that predates rite awareness must still dispatch; its rite is
        // resolved from metadata instead of the message.
        $health = new Health();
        $conn   = self::stubConnection();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown calendar category: widerregioncalendar');

        ob_start();
        try {
            $health->onMessage($conn, (string) json_encode([
                'action'   => 'executeUnitTest',
                'category' => 'widerregioncalendar',
                'calendar' => 'europe',
                'year'     => 2026,
                'test'     => 'SomeTest',
            ]));
        } finally {
            ob_end_clean();
        }
    }

    /**
     * A minimal Ratchet connection: onMessage() only needs somewhere to send.
     */
    private static function stubConnection(): \Ratchet\ConnectionInterface
    {
        return new class (1) implements \Ratchet\ConnectionInterface {
            public function __construct(public int $resourceId)
            {
            }

            public function send($data)
            {
                return $this;
            }

            public function close()
            {
            }
        };
    }

    private static function buildPath(string $calendar, int $year, string $category, Rite $rite): string
    {
        $health = ( new \ReflectionClass(Health::class) )->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(Health::class, 'buildCalendarRequestPath');

        /** @var string $path */
        $path = $method->invoke($health, $calendar, $year, $category, $rite);
        return $path;
    }

    private static function resolveRite(string $calendar, string $category, ?string $riteHint): Rite
    {
        $health = ( new \ReflectionClass(Health::class) )->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(Health::class, 'resolveRite');

        /** @var Rite $rite */
        $rite = $method->invoke($health, $calendar, $category, $riteHint);
        return $rite;
    }

    /**
     * Populate Health::$metadata with a two-diocese stand-in for the duration of
     * $fn, then put back what was there before.
     *
     * `Health::$metadata` is a typed static with no default, so PHP offers no way
     * to return it to the genuinely uninitialised state. When it started out
     * uninitialised we therefore leave an *empty* MetadataCalendars behind rather
     * than the fixture, so a later test in the same process cannot resolve
     * `lugano_ch` or `rotter_nl` from data this helper invented.
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
