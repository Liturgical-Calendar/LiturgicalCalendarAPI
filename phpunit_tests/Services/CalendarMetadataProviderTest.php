<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services;

use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Models\Metadata\MetadataCalendars;
use LiturgicalCalendar\Api\Router;
use LiturgicalCalendar\Api\Services\CalendarMetadataProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CalendarMetadataProvider::class)]
final class CalendarMetadataProviderTest extends TestCase
{
    private static string $savedApiPath     = '';
    private static string $savedApiFilePath = '';

    public static function setUpBeforeClass(): void
    {
        // Pin Router::$apiPath/$apiFilePath so JsonData::*->path() resolves to
        // the real on-disk source data under the project root, mirroring
        // production. isset() is false for typed-uninitialised properties.
        self::$savedApiPath     = isset(Router::$apiPath) ? Router::$apiPath : '';
        self::$savedApiFilePath = isset(Router::$apiFilePath) ? Router::$apiFilePath : '';
        Router::$apiPath        = '';
        Router::$apiFilePath    = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR;
    }

    public static function tearDownAfterClass(): void
    {
        Router::$apiPath     = self::$savedApiPath;
        Router::$apiFilePath = self::$savedApiFilePath;
    }

    public function testCreateBuildsPopulatedIndexFromLocalSourceData(): void
    {
        $metadata = CalendarMetadataProvider::create();

        self::assertInstanceOf(MetadataCalendars::class, $metadata);
        // The General Roman Calendar as used in the Vatican is always injected.
        self::assertContains('VA', $metadata->national_calendars_keys);
        self::assertNotEmpty($metadata->national_calendars);
        self::assertNotEmpty($metadata->locales);

        // Item lists and their parallel key lists must stay in lock-step.
        self::assertCount(count($metadata->national_calendars), $metadata->national_calendars_keys);
        self::assertCount(count($metadata->diocesan_calendars), $metadata->diocesan_calendars_keys);
        self::assertCount(count($metadata->wider_regions), $metadata->wider_regions_keys);
        self::assertCount(count($metadata->ambrosian_calendars), $metadata->ambrosian_calendars_keys);
    }

    /**
     * The comune `/calendar/ambrosian` has no representation as a nation,
     * diocese, or wider region — it's announced through its own
     * `ambrosian_calendars` surface so clients can discover it.
     */
    public function testCreateAnnouncesTheAmbrosianComuneCalendar(): void
    {
        $metadata = CalendarMetadataProvider::create();

        self::assertContains('ambrosian', $metadata->ambrosian_calendars_keys);
        $ambrosian = current(array_filter(
            $metadata->ambrosian_calendars,
            fn ($item) => $item->calendar_id === 'ambrosian'
        ));
        self::assertNotFalse($ambrosian);
        self::assertSame('ambrosian', $ambrosian->rite);
        self::assertContains('it', $ambrosian->locales);
        self::assertContains('la', $ambrosian->locales);
    }

    /**
     * The locale set the endpoints enforce (issue #761) must be the very set
     * `/calendars` announces, not a parallel hardcoded copy that can drift from it.
     */
    public function testLocalesForRiteMatchesTheAnnouncedAmbrosianMetadata(): void
    {
        $metadata  = CalendarMetadataProvider::create();
        $announced = current(array_filter(
            $metadata->ambrosian_calendars,
            fn ($item) => $item->calendar_id === 'ambrosian'
        ));

        self::assertNotFalse($announced);
        self::assertSame($announced->locales, CalendarMetadataProvider::localesForRite(Rite::AMBROSIAN));
    }

    /**
     * The Roman rite is translated into every locale the API ships, so it declares no
     * rite-level restriction. The empty array is meaningful rather than incidental: it
     * is what `Negotiator::pickLanguage()` reads as "use your own full default list".
     */
    public function testRomanRiteDeclaresNoLocaleRestriction(): void
    {
        self::assertSame([], CalendarMetadataProvider::localesForRite(Rite::ROMAN));
        self::assertSame([], CalendarMetadataProvider::negotiableLocalesForRite(Rite::ROMAN));

        // An unrestricted rite accepts anything, including locales the Ambrosian rite
        // rejects. Asserted directly because the handlers reach the Roman path through
        // earlier early-returns, leaving this guard otherwise unexercised.
        self::assertTrue(CalendarMetadataProvider::riteSupportsLocale(Rite::ROMAN, 'nl_NL'));
        self::assertTrue(CalendarMetadataProvider::riteSupportsLocale(Rite::ROMAN, 'la_VA'));
    }

    /**
     * Matching is on primary language, so every regional variant of an Ambrosian
     * liturgical language is accepted — `it_CH` in particular, the shape a client in
     * Ticino would send for the Ambrosian parishes of the Diocese of Lugano.
     */
    public function testRiteSupportsLocaleMatchesOnPrimaryLanguage(): void
    {
        foreach (['it', 'it_IT', 'it_CH', 'la', 'la_VA'] as $locale) {
            self::assertTrue(CalendarMetadataProvider::riteSupportsLocale(Rite::AMBROSIAN, $locale), $locale);
        }

        foreach (['nl', 'nl_NL', 'en_US', 'fr_FR', 'de_DE'] as $locale) {
            self::assertFalse(CalendarMetadataProvider::riteSupportsLocale(Rite::AMBROSIAN, $locale), $locale);
        }
    }

    /**
     * The negotiable set narrows *which languages* are acceptable without narrowing the
     * *shape* of the identifiers offered: it must still carry the region-qualified tags
     * (`it_IT`, `la_VA`), because this list is the entire candidate set for the rite-level
     * routes and the negotiator can only answer with something it was offered. A set of
     * bare languages alone would make `Accept-Language: it-IT` on `/events/ambrosian`
     * negotiate down to `it`, reporting a coarser `settings.locale` than was asked for.
     *
     * It used to matter for a second, heavier reason, retired in #845: the Ambrosian
     * diocesan layer string-compared the negotiated tag against its own `it_IT`/`la_VA`,
     * so a bare `la` here turned a request for Latin into Italian one layer down. That
     * layer now re-negotiates the original header against its own declared locales — see
     * {@see \LiturgicalCalendar\Tests\Handlers\CalendarScopedLocaleNegotiationTest} — and
     * no longer depends on the shape produced here.
     */
    public function testNegotiableLocalesKeepRegionQualifiedTagsForTheRitesLanguages(): void
    {
        $negotiable = CalendarMetadataProvider::negotiableLocalesForRite(Rite::AMBROSIAN);

        self::assertContains('la_VA', $negotiable);
        self::assertContains('it_IT', $negotiable);
        self::assertContains('la', $negotiable);
        self::assertContains('it', $negotiable);

        foreach ($negotiable as $locale) {
            self::assertContains(
                \Locale::getPrimaryLanguage($locale),
                ['it', 'la'],
                "negotiable locale `{$locale}` is not one of the rite's languages"
            );
        }
    }

    /**
     * Single-source-of-truth guarantee.
     *
     * Consumers (RegionalDataHandler, EventsParams, CalendarParams) used to
     * obtain this index over the network: GET /calendars → json_decode →
     * MetadataCalendars::fromObject(). They now use the in-process built object
     * directly. This proves the two are equivalent: serializing the built
     * object exactly as the /calendars endpoint does, re-parsing it through the
     * same fromObject() path consumers used, and re-serializing yields byte-for-
     * byte identical JSON — i.e. the round-trip is idempotent, so "use the built
     * object directly" is indistinguishable from "fetch it over the network".
     */
    public function testBuiltObjectIsIdenticalToNetworkRoundTrip(): void
    {
        $built = CalendarMetadataProvider::create();

        // Exactly how MetadataHandler serializes the /calendars response body.
        $encoded = json_encode(['litcal_metadata' => $built], JSON_THROW_ON_ERROR);

        // Exactly how the former self-call consumers reconstructed it.
        $decoded   = json_decode($encoded, false, 512, JSON_THROW_ON_ERROR);
        $reparsed  = MetadataCalendars::fromObject($decoded->litcal_metadata);
        $reEncoded = json_encode(['litcal_metadata' => $reparsed], JSON_THROW_ON_ERROR);

        self::assertSame($encoded, $reEncoded);
    }

    public function testRepeatedBuildsAreDeterministic(): void
    {
        $first  = json_encode(['litcal_metadata' => CalendarMetadataProvider::create()], JSON_THROW_ON_ERROR);
        $second = json_encode(['litcal_metadata' => CalendarMetadataProvider::create()], JSON_THROW_ON_ERROR);

        self::assertSame($first, $second);
    }

    /**
     * Diocesan discovery must be rite-aware: it scans both the Roman and
     * Ambrosian dioceses trees, tags each discovered diocese with the rite
     * of the tree it was found in, and does NOT attach Ambrosian dioceses
     * under a national calendar (the Ambrosian comune has no national
     * parent — see {@see self::testCreateAnnouncesTheAmbrosianComuneCalendar}).
     */
    public function testDiocesanDiscoveryIsRiteAwareAndAmbrosianDiocesesHaveNoNationalParent(): void
    {
        $metadata = CalendarMetadataProvider::create();

        self::assertContains('milano_it', $metadata->diocesan_calendars_keys);
        self::assertContains('agrige_it', $metadata->diocesan_calendars_keys);

        $milano = current(array_filter(
            $metadata->diocesan_calendars,
            fn ($item) => $item->calendar_id === 'milano_it'
        ));
        self::assertNotFalse($milano);
        self::assertSame(Rite::AMBROSIAN, $milano->rite);

        $agrigento = current(array_filter(
            $metadata->diocesan_calendars,
            fn ($item) => $item->calendar_id === 'agrige_it'
        ));
        self::assertNotFalse($agrigento);
        self::assertSame(Rite::ROMAN, $agrigento->rite);

        // The other three Ambrosian dioceses are also discovered and tagged.
        foreach (['bergam_it', 'novara_it', 'lugano_ch'] as $ambrosianDioceseId) {
            self::assertContains($ambrosianDioceseId, $metadata->diocesan_calendars_keys);
            $item = current(array_filter(
                $metadata->diocesan_calendars,
                fn ($el) => $el->calendar_id === $ambrosianDioceseId
            ));
            self::assertNotFalse($item);
            self::assertSame(Rite::AMBROSIAN, $item->rite);
        }

        // No national calendar should list an Ambrosian diocese among its dioceses.
        foreach ($metadata->national_calendars as $nationalCalendar) {
            self::assertNotContains('milano_it', $nationalCalendar->dioceses ?? []);
            self::assertNotContains('bergam_it', $nationalCalendar->dioceses ?? []);
            self::assertNotContains('novara_it', $nationalCalendar->dioceses ?? []);
            self::assertNotContains('lugano_ch', $nationalCalendar->dioceses ?? []);
        }

        // Roman dioceses are still attached under their national calendar.
        $itNational = current(array_filter(
            $metadata->national_calendars,
            fn ($nc) => $nc->calendar_id === 'IT'
        ));
        self::assertNotFalse($itNational);
        self::assertContains('agrige_it', $itNational->dioceses ?? []);
    }
}
