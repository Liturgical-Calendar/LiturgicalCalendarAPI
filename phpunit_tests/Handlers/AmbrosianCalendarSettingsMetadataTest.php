<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers;

use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Handlers\CalendarHandler;
use LiturgicalCalendar\Api\Handlers\MetadataHandler;
use LiturgicalCalendar\Api\Http\Enum\ReturnTypeParam;
use LiturgicalCalendar\Api\Models\Calendar\Rite\RiteProfileFactory;
use LiturgicalCalendar\Api\Services\CalendarMetadataProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Issue #776: `/calendars` must announce the settings the Ambrosian rite fixes, and what it
 * announces must be exactly what `/calendar/ambrosian` applies.
 *
 * The assertions deliberately compare the two live responses against each other rather than
 * against a hand-written literal: a literal would be a second copy of the values and would
 * re-introduce precisely the drift this issue is about. The single authority both sides read
 * is {@see \LiturgicalCalendar\Api\Models\Calendar\Rite\RiteProfile::fixedCalendarSettings()}.
 *
 * @phpstan-type SettingsArray array<string,mixed>
 */
#[CoversClass(CalendarMetadataProvider::class)]
#[CoversClass(CalendarHandler::class)]
#[CoversClass(RiteProfileFactory::class)]
final class AmbrosianCalendarSettingsMetadataTest extends AbstractHandlerTestCase
{
    /**
     * The keys a calendar-tier `settings` block carries. The `/calendar` response adds
     * request-scoped keys on top (`year`, `locale`, `return_type`, `year_type`, `rite`),
     * which have no meaning as discovery metadata and are excluded from the comparison.
     */
    private const ANNOUNCED_KEYS = [
        'epiphany',
        'ascension',
        'corpus_christi',
        'eternal_high_priest',
        'holydays_of_obligation',
    ];

    /**
     * `CalendarHandler` pins the process locale while building an Ambrosian calendar; left
     * pinned it leaks into later tests. Same rationale as {@see SettingsRiteEchoTest::tearDown()}.
     */
    protected function tearDown(): void
    {
        setlocale(LC_ALL, 'C');
        parent::tearDown();
    }

    /**
     * The `settings` block `/calendars` announces for the Ambrosian comune calendar.
     *
     * @return array<string,mixed>
     */
    private function announcedSettings(): array
    {
        $response = ( new MetadataHandler() )->handle($this->requestFor('GET', '/calendars'));
        self::assertSame(200, $response->getStatusCode());

        $body = $this->decodeJsonBody($response);
        self::assertIsArray($body['litcal_metadata']);
        self::assertIsArray($body['litcal_metadata']['ambrosian_calendars']);

        $entries = array_values(array_filter(
            $body['litcal_metadata']['ambrosian_calendars'],
            static fn (mixed $c): bool => is_array($c) && ( $c['calendar_id'] ?? null ) === 'ambrosian'
        ));
        self::assertCount(1, $entries, 'Expected exactly one Ambrosian comune calendar entry');

        self::assertArrayHasKey('settings', $entries[0], '/calendars must announce settings for the Ambrosian comune calendar');
        self::assertIsArray($entries[0]['settings']);

        return $entries[0]['settings'];
    }

    /**
     * The `settings` block `/calendar/ambrosian` echoes for an actually calculated calendar,
     * narrowed to the discovery-relevant keys.
     *
     * @param array<string,string> $query
     * @return array<string,mixed>
     */
    private function calculatedSettings(string $uri, array $query = []): array
    {
        $handler = new CalendarHandler(['2026'], Rite::AMBROSIAN);
        $handler->setAllowedReturnTypes([ReturnTypeParam::JSON]);
        $response = $handler->handle(
            $this->requestFor('GET', $uri, ['Accept' => 'application/json'])->withQueryParams($query)
        );
        self::assertSame(200, $response->getStatusCode());

        $body = $this->decodeJsonBody($response);
        self::assertIsArray($body['settings']);

        return array_intersect_key($body['settings'], array_flip(self::ANNOUNCED_KEYS));
    }

    /**
     * The Roman rite fixes nothing at rite level — its national and diocesan tiers own those
     * settings — so its profile announces nothing. This is what keeps the seam general rather
     * than Ambrosian-specific: a future rite either fixes a block or returns null.
     */
    public function testRomanRiteProfileFixesNoSettings(): void
    {
        self::assertNull(RiteProfileFactory::forRite(Rite::ROMAN)->fixedCalendarSettings());
    }

    public function testAmbrosianRiteProfileFixesASettingsBlock(): void
    {
        $settings = RiteProfileFactory::forRite(Rite::AMBROSIAN)->fixedCalendarSettings();

        self::assertNotNull($settings);
        self::assertSame(self::ANNOUNCED_KEYS, array_keys($settings->jsonSerialize()));
    }

    /**
     * `/calendars` announces the block at all, with exactly the shape the national and
     * diocesan tiers use, so clients can reuse the same parsing code across all three tiers.
     */
    public function testCalendarsAnnouncesAmbrosianSettingsWithTheNationalShape(): void
    {
        $announced = $this->announcedSettings();

        self::assertSame(self::ANNOUNCED_KEYS, array_keys($announced));
        self::assertIsArray($announced['holydays_of_obligation']);
        self::assertNotEmpty($announced['holydays_of_obligation']);
    }

    /**
     * The point of the issue: what `/calendars` announces is what `/calendar/ambrosian`
     * applies. Asserted against the actual calculated response, never against a literal.
     */
    public function testAnnouncedSettingsEqualCalculatedSettings(): void
    {
        self::assertSame(
            $this->announcedSettings(),
            $this->calculatedSettings('/calendar/ambrosian/2026'),
            '/calendars must announce exactly the settings /calendar/ambrosian applies'
        );
    }

    /**
     * The Ambrosian rite *fixes* these settings: the OpenAPI schema already documents that
     * `epiphany`, `ascension` and `corpus_christi` are ignored under this rite. The echoed
     * block must therefore report what was applied, not what was asked for — otherwise a
     * client that sends a contradictory parameter is told its request was honoured when the
     * calculation quietly ignored it.
     *
     * @param array<string,string> $query
     */
    #[DataProvider('contradictoryQueries')]
    public function testFixedSettingsAreNotOverriddenByRequestParams(array $query): void
    {
        self::assertSame(
            $this->announcedSettings(),
            $this->calculatedSettings('/calendar/ambrosian/2026', $query),
            'Ambrosian rite-fixed settings must survive contradictory request parameters'
        );
    }

    /**
     * @return array<string,array{0:array<string,string>}>
     */
    public static function contradictoryQueries(): array
    {
        return [
            'ascension on Sunday'      => [['ascension' => 'SUNDAY']],
            'epiphany on Sunday'       => [['epiphany' => 'SUNDAY_JAN2_JAN8']],
            'corpus christi on Sunday' => [['corpus_christi' => 'SUNDAY']],
            'eternal high priest'      => [['eternal_high_priest' => 'true']],
            'all of the above'         => [
                [
                    'ascension'           => 'SUNDAY',
                    'epiphany'            => 'SUNDAY_JAN2_JAN8',
                    'corpus_christi'      => 'SUNDAY',
                    'eternal_high_priest' => 'true',
                ]
            ],
        ];
    }

    /**
     * The announced holy days of obligation must be the Ambrosian ones actually marked on the
     * calculated calendar, not the Roman set. The two rites do not share `event_key`s, so a
     * Roman key announced here would name a day the Ambrosian calendar does not contain.
     */
    public function testAnnouncedHolyDaysOfObligationAreMarkedOnTheCalculatedCalendar(): void
    {
        $announced = $this->announcedSettings();
        self::assertIsArray($announced['holydays_of_obligation']);

        $handler = new CalendarHandler(['2026'], Rite::AMBROSIAN);
        $handler->setAllowedReturnTypes([ReturnTypeParam::JSON]);
        $response = $handler->handle(
            $this->requestFor('GET', '/calendar/ambrosian/2026', ['Accept' => 'application/json'])
        );
        $body     = $this->decodeJsonBody($response);
        self::assertIsArray($body['litcal']);

        /** @var array<string,bool> $flagged */
        $flagged = [];
        foreach ($body['litcal'] as $event) {
            self::assertIsArray($event);
            if (true === ( $event['is_vigil_mass'] ?? false )) {
                continue;
            }
            $key = $event['event_key'];
            self::assertIsString($key);
            $flagged[$key] = true === ( $event['holy_day_of_obligation'] ?? false );
        }

        foreach ($announced['holydays_of_obligation'] as $eventKey => $observed) {
            self::assertIsString($eventKey);
            if (true !== $observed) {
                continue;
            }
            self::assertArrayHasKey(
                $eventKey,
                $flagged,
                "Announced holy day of obligation `$eventKey` does not exist in the calculated Ambrosian calendar"
            );
            self::assertTrue(
                $flagged[$eventKey],
                "Announced holy day of obligation `$eventKey` is not marked as such on the calculated Ambrosian calendar"
            );
        }
    }
}
