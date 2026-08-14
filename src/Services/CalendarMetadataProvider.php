<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services;

use LiturgicalCalendar\Api\Enum\Ascension;
use LiturgicalCalendar\Api\Enum\Epiphany;
use LiturgicalCalendar\Api\Enum\JsonData;
use LiturgicalCalendar\Api\Enum\LitLocale;
use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Enum\Route;
use LiturgicalCalendar\Api\Models\Calendar\Rite\RiteProfileFactory;
use LiturgicalCalendar\Api\Models\CatholicDiocesesLatinRite\CatholicDiocesesMap;
use LiturgicalCalendar\Api\Models\Metadata\MetadataAmbrosianCalendarItem;
use LiturgicalCalendar\Api\Models\Metadata\MetadataCalendars;
use LiturgicalCalendar\Api\Models\Metadata\MetadataDiocesanCalendarItem;
use LiturgicalCalendar\Api\Models\Metadata\MetadataNationalCalendarItem;
use LiturgicalCalendar\Api\Models\Metadata\MetadataWiderRegionItem;
use LiturgicalCalendar\Api\Router;
use LiturgicalCalendar\Api\Utilities;

/**
 * Builds the calendars metadata index ({@see MetadataCalendars}) directly from
 * local source data, in-process — without looping back through the network to
 * the API's own `/calendars` endpoint.
 *
 * This is the single source of truth for that index. {@see MetadataHandler}
 * (serving GET /calendars), {@see \LiturgicalCalendar\Api\Handlers\RegionalDataHandler},
 * {@see \LiturgicalCalendar\Api\Params\EventsParams}, and
 * {@see \LiturgicalCalendar\Api\Params\CalendarParams} all consume the object
 * produced here. The object returned by {@see self::create()} is, by design,
 * identical to what those consumers previously obtained via
 * `MetadataCalendars::fromObject(jsonDecode(GET /calendars))`: the build path
 * populates every field (`*_keys`, `diocesan_groups`, and the per-nation
 * `dioceses` lists) that `jsonSerialize()` emits and `fromObject()` reads back,
 * so the JSON round-trip is idempotent (guarded by CalendarMetadataProviderTest).
 *
 * Building reads the same on-disk source data on every call (no cross-request
 * memoization): the `/data` write endpoints can mutate calendar definition
 * files at runtime, so caching the index across requests would risk serving
 * stale metadata.
 *
 * @phpstan-import-type NationalCalendarDataObject from \LiturgicalCalendar\Api\Models\RegionalData\NationalData\NationalData
 * @phpstan-import-type DiocesanCalendarDataObject from \LiturgicalCalendar\Api\Models\RegionalData\DiocesanData\DiocesanData
 */
final class CalendarMetadataProvider
{
    /**
     * Lazily-loaded world dioceses map (reference data, not written by the
     * /data endpoints), memoized for the lifetime of the process so repeated
     * diocese-name lookups within and across builds don't re-read the file.
     */
    private static ?CatholicDiocesesMap $worldDiocesesLatinRite = null;

    private const array FULLY_TRANSLATED_LOCALES = ['en', 'fr', 'it', 'nl', 'la'];

    /**
     * Builds and returns the complete calendars metadata index from local
     * source data.
     */
    public static function create(): MetadataCalendars
    {
        $metadata = new MetadataCalendars();
        self::buildNationalCalendarData($metadata);
        self::buildDiocesanCalendarData($metadata);
        self::buildWiderRegionData($metadata);
        self::buildLocales($metadata);
        self::buildAmbrosianCalendarData($metadata);
        return $metadata;
    }

    /**
     * glob() that throws instead of returning the system-error sentinel `false`.
     *
     * glob() returns an empty array (not false) when a readable directory simply
     * has no matches, so the false branch only fires on a genuine filesystem
     * error against a directory we expect to exist — an unreachable defensive
     * guard in normal operation, hence excluded from coverage.
     *
     * @return string[]
     * @codeCoverageIgnore
     */
    private static function globOrThrow(string $pattern, int $flags, string $context): array
    {
        $result = glob($pattern, $flags);
        if (false === $result) {
            throw new \RuntimeException($context . ': glob failed');
        }
        return $result;
    }

    /**
     * Scans the JsonData::NATIONAL_CALENDARS_FOLDER directory and builds an index of all National calendars,
     * their metadata and their supported locales.
     *
     * Each National calendar is identified by a folder name and a JSON file of the same name within that folder.
     * The JSON file must contain a "metadata" section with a "region" attribute.
     * The folder name is used as the National calendar identifier.
     *
     * @return void
     */
    private static function buildNationalCalendarData(MetadataCalendars $metadata): void
    {
        // We add the General Roman Calendar as used in the Vatican to the list of "national" calendars
        $metadataNationalCalendarItem = MetadataNationalCalendarItem::fromArray([
            'calendar_id' => 'VA',
            'locales'     => [ 'la_VA' ],
            'missals'     => [
                'EDITIO_TYPICA_1970',
                'EDITIO_TYPICA_1971',
                'EDITIO_TYPICA_1975',
                'EDITIO_TYPICA_2002',
                'EDITIO_TYPICA_2008'
            ],
            'settings'    => [
                'epiphany'               => Epiphany::JAN6->value,
                'ascension'              => Ascension::THURSDAY->value,
                'corpus_christi'         => Ascension::THURSDAY->value,
                'eternal_high_priest'    => false,
                'holydays_of_obligation' => [
                    'Christmas'            => true,
                    'Epiphany'             => true,
                    'Ascension'            => true,
                    'CorpusChristi'        => true,
                    'MaryMotherOfGod'      => true,
                    'ImmaculateConception' => true,
                    'Assumption'           => true,
                    'StJoseph'             => true,
                    'StsPeterPaulAp'       => true,
                    'AllSaints'            => true
                ]
            ]
        ]);
        $metadata->pushNationalCalendarMetadata($metadataNationalCalendarItem);

        $folderGlob = self::globOrThrow(JsonData::NATIONAL_CALENDARS_FOLDER->path() . '/*', GLOB_ONLYDIR, 'CalendarMetadataProvider::buildNationalCalendarData');

        /** @var string[] $countryISOs */
        $countryISOs = array_map('basename', $folderGlob);
        foreach ($countryISOs as $countryISO) {
            $nationalCalendarDataFile = JsonData::NATIONAL_CALENDARS_FOLDER->path() . "/$countryISO/$countryISO.json";
            /** @var NationalCalendarDataObject $nationalCalendarData */
            $nationalCalendarData                        = Utilities::jsonFileToObject($nationalCalendarDataFile);
            $nationalCalendarData->metadata->settings    = $nationalCalendarData->settings;
            $nationalCalendarData->metadata->calendar_id = $nationalCalendarData->metadata->nation;
            unset($nationalCalendarData->metadata->nation);
            $nationalCalendarData->metadata->dioceses = [];
            $metadataNationalCalendarItem             = MetadataNationalCalendarItem::fromObject($nationalCalendarData->metadata);
            $metadata->pushNationalCalendarMetadata($metadataNationalCalendarItem);
        }
    }

    /**
     * Takes a diocese ID and returns the corresponding diocese name.
     * If the diocese ID is not found, returns null.
     *
     * @param string $id The diocese ID.
     * @return string|null The diocese name or null if not found.
     */
    private static function dioceseIdToName(string $nation, string $id): ?string
    {
        if (null === self::$worldDiocesesLatinRite) {
            $worldDiocesesFile = JsonData::FOLDER->path() . '/world_dioceses.json';
            $worldDiocesesData = Utilities::jsonFileToObject($worldDiocesesFile);

            self::$worldDiocesesLatinRite = CatholicDiocesesMap::fromObject($worldDiocesesData);
        }
        return self::$worldDiocesesLatinRite->dioceseNameFromId($nation, $id);
    }

    /**
     * Builds an index of all diocesan calendars, scanning both the Roman
     * dioceses tree and (if present) the Ambrosian dioceses tree, tagging
     * each discovered diocese with the rite of the tree it was found in.
     *
     * @return void
     */
    private static function buildDiocesanCalendarData(MetadataCalendars $metadata): void
    {
        self::scanDiocesanTree($metadata, JsonData::DIOCESAN_CALENDARS_FOLDER->path(), Rite::ROMAN);

        // The Ambrosian dioceses tree is optional (defensive): an absent
        // tree is a harmless no-op rather than a glob error.
        $ambrosianDiocesanFolder = JsonData::AMBROSIAN_DIOCESAN_CALENDARS_FOLDER->path();
        if (is_dir($ambrosianDiocesanFolder)) {
            self::scanDiocesanTree($metadata, $ambrosianDiocesanFolder, Rite::AMBROSIAN);
        }
    }

    /**
     * Scans a single diocesan calendars root (Roman or Ambrosian) and pushes
     * every discovered diocese, tagged with the given rite, onto the
     * metadata index.
     *
     * @param MetadataCalendars $metadata The metadata index to populate.
     * @param string $folderPath The root folder to scan (nation folders as immediate children, diocese folders beneath those).
     * @param Rite $rite The liturgical rite this tree's dioceses are tagged with.
     * @return void
     */
    private static function scanDiocesanTree(MetadataCalendars $metadata, string $folderPath, Rite $rite): void
    {
        $countryFolders = self::globOrThrow($folderPath . '/*', GLOB_ONLYDIR, 'CalendarMetadataProvider::scanDiocesanTree');

        foreach ($countryFolders as $countryFolder) {
            $nation         = basename($countryFolder);
            $dioceseFolders = self::globOrThrow($countryFolder . '/*', GLOB_ONLYDIR, 'CalendarMetadataProvider::scanDiocesanTree');

            /** @var string[] $dioceseIDs */
            $dioceseIDs = array_map('basename', $dioceseFolders);
            foreach ($dioceseIDs as $calendar_id) {
                $dioceseName = self::dioceseIdToName($nation, $calendar_id);
                // @codeCoverageIgnoreStart
                // Defensive: every bundled diocese folder maps to a name in
                // world_dioceses.json, so this guard only fires on inconsistent
                // source data and is unreachable in unit tests.
                if (null === $dioceseName) {
                    throw new \RuntimeException("CalendarMetadataProvider::scanDiocesanTree: diocese name not found for nation = `{$nation}` and calendar_id = `{$calendar_id}`");
                }
                // @codeCoverageIgnoreEnd
                $diocesanCalendarFile = "$folderPath/$nation/$calendar_id/$dioceseName.json";
                $diocesanCalendarData = Utilities::jsonFileToObject($diocesanCalendarFile);
                /** @var DiocesanCalendarDataObject $diocesanCalendarData */
                $diocesanCalendarData->metadata->diocese = $dioceseName;
                if (property_exists($diocesanCalendarData, 'settings')) {
                    $diocesanCalendarData->metadata->settings = $diocesanCalendarData->settings;
                }
                $diocesanCalendarData->metadata->calendar_id = $diocesanCalendarData->metadata->diocese_id;
                unset($diocesanCalendarData->metadata->diocese_id);
                // The rite is authoritative by which tree was scanned, not by
                // re-reading the source file's own `rite` field.
                $diocesanCalendarData->metadata->rite = $rite->value;
                $metadataDiocesanCalendarItem         = MetadataDiocesanCalendarItem::fromObject($diocesanCalendarData->metadata);
                $metadata->pushDiocesanCalendarMetadata($metadataDiocesanCalendarItem);
            }
        }
    }


    /**
     * Scans the {@see \LiturgicalCalendar\Api\Enum\JsonData::WIDER_REGIONS_FOLDER} directory and build an index of all Wider regions,
     * their supported locales and their data files.
     *
     * Each Wider region is identified by a folder name and a JSON file of the same name within that folder.
     * Supported locales are retrieved by scanning the `i18n` subfolder for each Wider region,
     * based on the JSON files present.
     *
     * @return void
     */
    private static function buildWiderRegionData(MetadataCalendars $metadata): void
    {
        $folderGlob = self::globOrThrow(JsonData::WIDER_REGIONS_FOLDER->path() . '/*', GLOB_ONLYDIR, 'CalendarMetadataProvider::buildWiderRegionData');

        /** @var string[] $widerRegionIDs */
        $widerRegionIDs = array_map('basename', $folderGlob);
        foreach ($widerRegionIDs as $widerRegionId) {
            $WiderRegionFile = strtr(
                JsonData::WIDER_REGION_FILE->path(),
                ['{wider_region}' => $widerRegionId]
            );

            if (file_exists($WiderRegionFile)) {
                $widerRegionI18nFolder = strtr(
                    JsonData::WIDER_REGION_I18N_FOLDER->path(),
                    [ '{wider_region}' => $widerRegionId ]
                );

                $folderGlob = self::globOrThrow($widerRegionI18nFolder . '/*.json', 0, 'CalendarMetadataProvider::buildWiderRegionData');

                $locales = array_map(
                    fn (string $filename) => pathinfo($filename, PATHINFO_FILENAME),
                    $folderGlob
                );

                $metadataWiderRegionItem = MetadataWiderRegionItem::fromArray([
                    'name'     => $widerRegionId,
                    'locales'  => $locales,
                    'api_path' => Router::$apiPath . Route::DATA_WIDERREGION->value . '/' . $widerRegionId . '?locale={locale}'
                ]);
                $metadata->pushWiderRegionMetadata($metadataWiderRegionItem);
            }
        }
    }

    /**
     * Populates the MetadataCalendars::$locales array with the list of supported locales.
     *
     * It does this by scanning the i18n/ folder and retrieving the folder names
     * of all its subfolders. The result is an array of strings, where each string
     * is a locale code. The locale code is in the format of a single string
     * containing the language code (optionally followed by an underscore and the
     * region code; for now none of the locales have regional identifiers).
     */
    private static function buildLocales(MetadataCalendars $metadata): void
    {
        // Since we can't actually request the General Roman Calendar for locales that are not fully translated,
        // we remove those locales from the list of supported locales
        $folderGlob = self::globOrThrow(Router::$apiFilePath . 'i18n/*', GLOB_ONLYDIR, 'CalendarMetadataProvider::buildLocales');

        // array_unique guards against a duplicate 'en' if an i18n/en/ folder is
        // ever added (en is prepended explicitly as the untranslated source
        // language, and array_intersect preserves duplicates from its first arg).
        $metadata->locales = array_values(array_unique(array_intersect(
            array_merge(['en'], array_map('basename', $folderGlob)),
            self::FULLY_TRANSLATED_LOCALES
        )));
    }

    /**
     * Announces the comune `/calendar/ambrosian` calendar.
     *
     * Unlike the General Roman Calendar as used in the Vatican (which is
     * added to the National calendars as a special case, since it is still
     * Roman rite), the Ambrosian comune has no representation as a nation,
     * diocese, or wider region: it is a distinct liturgical rite reachable
     * only via the `ambrosian` rite segment on the calendar route (see
     * {@see \LiturgicalCalendar\Api\Router::extractRiteSegment()}). This
     * builds the `ambrosian_calendars` surface so that `/calendars`
     * discovery clients can find it, alongside the locales it supports
     * (derived from the Ambrosian Proprium de Tempore's `i18n` folder, the
     * same source used at request time by {@see \LiturgicalCalendar\Api\Handlers\CalendarHandler}).
     *
     * The announced `settings` come from {@see \LiturgicalCalendar\Api\Models\Calendar\Rite\RiteProfile::fixedCalendarSettings()}, the
     * same authority {@see \LiturgicalCalendar\Api\Handlers\CalendarHandler::applyRiteFixedSettings()}
     * applies before calculating, so what `/calendars` announces and what
     * `/calendar/ambrosian` echoes cannot drift apart (issue #776).
     *
     * @return void
     */
    private static function buildAmbrosianCalendarData(MetadataCalendars $metadata): void
    {
        $fixedSettings = RiteProfileFactory::forRite(Rite::AMBROSIAN)->fixedCalendarSettings();

        if (null === $fixedSettings) {
            throw new \LogicException('The Ambrosian rite profile must declare the calendar settings the rite fixes.');
        }

        $metadataAmbrosianCalendarItem = MetadataAmbrosianCalendarItem::fromArray([
            'calendar_id' => Rite::AMBROSIAN->value,
            'rite'        => Rite::AMBROSIAN->value,
            'locales'     => self::ambrosianLocales(),
            'settings'    => $fixedSettings->jsonSerialize()
        ]);
        $metadata->pushAmbrosianCalendarMetadata($metadataAmbrosianCalendarItem);
    }

    /**
     * The locales a rite has liturgical books for, i.e. the set announced for that rite
     * on `/calendars`, or an empty array when the rite restricts nothing beyond the
     * API-wide {@see \LiturgicalCalendar\Api\Enum\LitLocale} set.
     *
     * This is the enforcement side of what {@see self::buildAmbrosianCalendarData()}
     * announces: both read the same glob, so the request-time check can never drift
     * from the declared metadata (issue #761 — `/calendar/ambrosian?locale=nl` used to
     * return 200 and echo `nl_NL` back for a rite with no Dutch liturgical books).
     *
     * The Roman rite returns `[]`: it is the API's universal calendar, translated into
     * every locale the API ships, so it imposes no rite-level restriction. (Individual
     * Roman national and diocesan calendars declare their own narrower `locales`, which
     * the handlers apply as a graceful downgrade rather than a rejection; that behavior
     * is untouched here.)
     *
     * @return string[] locale identifiers as declared in the metadata, e.g. `['it', 'la']`
     */
    public static function localesForRite(Rite $rite): array
    {
        return $rite === Rite::AMBROSIAN ? self::ambrosianLocales() : [];
    }

    /**
     * Whether a requested locale is one the rite has liturgical books for.
     *
     * Matching is on primary language, not on the full identifier, for two reasons: the
     * rite-level metadata declares bare languages (`it`, `la`) while the Ambrosian
     * dioceses declare full identifiers (`it_IT`, `la_VA`), and a regional variant of a
     * supported language is still that language — `it_CH`, the shape a Ticino client
     * would send for the Ambrosian parishes of the Diocese of Lugano, is Italian and
     * must be accepted.
     */
    public static function riteSupportsLocale(Rite $rite, string $locale): bool
    {
        $riteLanguages = self::riteLanguages($rite);
        if ([] === $riteLanguages) {
            return true;
        }

        $requestedLanguage = \Locale::getPrimaryLanguage($locale);

        return null !== $requestedLanguage && in_array($requestedLanguage, $riteLanguages, true);
    }

    /**
     * The locale identifiers a rite's routes may negotiate an `Accept-Language` header
     * down to — every locale the API would otherwise consider, minus those in a language
     * the rite has no liturgical books for. Empty for an unrestricted rite, which is
     * {@see \LiturgicalCalendar\Api\Http\Negotiator::pickLanguage()}'s own signal to use
     * its full default list.
     *
     * This *filters* the negotiator's candidate list rather than replacing it with the
     * bare languages {@see self::localesForRite()} returns, and that distinction is load-
     * bearing. The rite-level metadata declares `it`/`la`, but the Ambrosian diocesan
     * layer matches the negotiated locale against its own full identifiers
     * (`it_IT`/`la_VA`) with a strict `in_array()`, falling back to its first locale on a
     * miss. Handing the negotiator only bare languages made it answer `la` where it used
     * to answer `la_VA`, which failed that membership test — so `Accept-Language: la-VA`
     * on `/events/ambrosian/diocese/milano_it` silently came back in Italian. Narrowing
     * the *set* of acceptable languages must not narrow the *shape* of the tag returned
     * for one that is acceptable.
     *
     * @return string[]
     */
    public static function negotiableLocalesForRite(Rite $rite): array
    {
        $riteLanguages = self::riteLanguages($rite);
        if ([] === $riteLanguages) {
            return [];
        }

        LitLocale::init();

        // The same candidate set Negotiator::pickLanguage() builds for itself when handed
        // an empty $supported (manually-defined locales such as Latin, which ICU does not
        // know, merged with the ICU-derived ones), narrowed to the rite's languages.
        $allLocales = array_unique(array_merge(LitLocale::$values, LitLocale::$AllAvailableLocales));

        return array_values(array_filter(
            $allLocales,
            static fn (string $locale): bool => in_array(\Locale::getPrimaryLanguage($locale), $riteLanguages, true)
        ));
    }

    /**
     * The primary languages of a rite's declared locales, e.g. `['it', 'la']`. Empty when
     * the rite restricts nothing.
     *
     * @return string[]
     */
    private static function riteLanguages(Rite $rite): array
    {
        $languages = [];
        foreach (self::localesForRite($rite) as $locale) {
            $language = \Locale::getPrimaryLanguage($locale);
            if (null !== $language) {
                $languages[] = $language;
            }
        }

        return array_values(array_unique($languages));
    }

    /**
     * The Ambrosian rite's locales, derived from the Ambrosian Proprium de Tempore's
     * `i18n` folder — the same source the handlers read at request time when loading
     * Ambrosian temporale and sanctorale names. Currently `['it', 'la']`: the *Messale
     * Ambrosiano* (Italian) and the *Missale Ambrosianum* (Latin, the *editio typica*)
     * are the rite's only approved liturgical books, and no episcopal conference outside
     * Italy commissions an Ambrosian translation.
     *
     * @return string[]
     */
    private static function ambrosianLocales(): array
    {
        $folderGlob = self::globOrThrow(JsonData::AMBROSIAN_TEMPORALE_I18N_FOLDER->path() . '/*.json', 0, 'CalendarMetadataProvider::ambrosianLocales');

        return array_map(
            fn (string $filename) => pathinfo($filename, PATHINFO_FILENAME),
            $folderGlob
        );
    }
}
