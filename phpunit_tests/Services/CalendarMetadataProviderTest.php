<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services;

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
}
