<?php

namespace LiturgicalCalendar\Api\Handlers;

use LiturgicalCalendar\Api\Enum\AmbrosianMissal;
use LiturgicalCalendar\Api\Enum\RomanMissal;
use LiturgicalCalendar\Api\Enum\JsonData;
use LiturgicalCalendar\Api\Enum\LitLocale;
use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Http\Enum\AcceptabilityLevel;
use LiturgicalCalendar\Api\Http\Enum\StatusCode;
use LiturgicalCalendar\Api\Http\Enum\RequestMethod;
use LiturgicalCalendar\Api\Http\Exception\ServiceUnavailableException;
use LiturgicalCalendar\Api\Http\Exception\UnprocessableContentException;
use LiturgicalCalendar\Api\Http\Exception\ValidationException;
use LiturgicalCalendar\Api\Http\Negotiator;
use LiturgicalCalendar\Api\Models\Decrees\DecreeItemCollection;
use LiturgicalCalendar\Api\Models\Decrees\DecreeItemCreateNewFixed;
use LiturgicalCalendar\Api\Models\Decrees\DecreeItemCreateNewMobile;
use LiturgicalCalendar\Api\Models\Decrees\DecreeItemMakeDoctor;
use LiturgicalCalendar\Api\Models\Decrees\DecreeItemSetPropertyGrade;
use LiturgicalCalendar\Api\Models\Decrees\DecreeItemSetPropertyName;
use LiturgicalCalendar\Api\Models\Calendar\Missal\AmbrosianMissalResolver;
use LiturgicalCalendar\Api\Models\EventsPath\LiturgicalEventAbstract;
use LiturgicalCalendar\Api\Models\EventsPath\LiturgicalEventFixed;
use LiturgicalCalendar\Api\Models\EventsPath\LiturgicalEventMap;
use LiturgicalCalendar\Api\Models\EventsPath\LiturgicalEventMobile;
use LiturgicalCalendar\Api\Models\EventsPath\LiturgicalEventTemporale;
use LiturgicalCalendar\Api\Models\Metadata\MetadataDiocesanCalendarItem;
use LiturgicalCalendar\Api\Models\RegionalData\DiocesanData\DiocesanData;
use LiturgicalCalendar\Api\Models\RegionalData\NationalData\LitCalItemCreateNewFixed;
use LiturgicalCalendar\Api\Models\RegionalData\NationalData\LitCalItemCreateNewMobile;
use LiturgicalCalendar\Api\Models\RegionalData\NationalData\LitCalItemSetPropertyGrade;
use LiturgicalCalendar\Api\Models\RegionalData\NationalData\LitCalItemSetPropertyName;
use LiturgicalCalendar\Api\Models\RegionalData\DiocesanData\DiocesanLitCalItemCreateNewFixed;
use LiturgicalCalendar\Api\Models\RegionalData\DiocesanData\DiocesanLitCalItemCreateNewMobile;
use LiturgicalCalendar\Api\Models\RegionalData\NationalData\LitCalItemMakePatron;
use LiturgicalCalendar\Api\Models\RegionalData\NationalData\LitCalItemMoveEvent;
use LiturgicalCalendar\Api\Models\RegionalData\NationalData\NationalData;
use LiturgicalCalendar\Api\Models\RegionalData\WiderRegionData\WiderRegionData;
use LiturgicalCalendar\Api\Params\EventsParams;
use LiturgicalCalendar\Api\Router;
use LiturgicalCalendar\Api\Services\CalendarMetadataProvider;
use LiturgicalCalendar\Api\Services\LocaleConfigurator;
use LiturgicalCalendar\Api\Utilities;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * @phpstan-import-type DecreeItemFromObject from \LiturgicalCalendar\Api\Models\Decrees\DecreeItem
 */
final class EventsHandler extends AbstractHandler
{
    /** @var LiturgicalEventMap */
    private static LiturgicalEventMap $liturgicalEvents;

    /**
     * Temporale (Proprium de Tempore) catalog entries. Kept separate from the LiturgicalEventMap (which is
     * typed for dated Fixed/Mobile events) and merged into the response, since temporale events carry no
     * stored date.
     *
     * @var LiturgicalEventTemporale[]
     */
    private static array $temporaleEvents            = [];
    private static ?DiocesanData $DiocesanData       = null;
    private static ?NationalData $NationalData       = null;
    private static ?WiderRegionData $WiderRegionData = null;
    private EventsParams $EventsParams;
    private Rite $rite = Rite::ROMAN;

    /**
     * The request being handled, kept so the locale decision can be re-taken against the
     * requested calendar's own declared locales once that calendar is known — which happens
     * well after {@see self::handle()}'s first, calendar-agnostic negotiation. Only the
     * original `Accept-Language` header carries enough information to do that (#845);
     * see {@see self::resolveCalendarLocale()}.
     */
    private ?ServerRequestInterface $request = null;

    /**
     * True when this request carried an explicit `locale` parameter (query string or body),
     * as opposed to having its locale derived from the `Accept-Language` header. The two are
     * deliberately treated differently (#761): a header states a preference and is
     * negotiated, a parameter names a dataset and is matched exactly.
     */
    private bool $localeExplicitlyRequested = false;

    /**
     * Initializes the EventsHandler.
     *
     * Calls the parent constructor and initializes an empty LiturgicalEventMap
     * to hold the liturgical events that will be populated during request handling.
     *
     * @param string[] $requestPathParams The path parameters from the request.
     * @param Rite     $rite              The liturgical rite for which the events catalog should be
     *                                    built (ROMAN or AMBROSIAN). Mirrors {@see CalendarHandler::__construct()}.
     */
    public function __construct(array $requestPathParams = [], Rite $rite = Rite::ROMAN)
    {
        parent::__construct($requestPathParams);
        $this->rite = $rite;

        self::$liturgicalEvents = new LiturgicalEventMap();
        self::$temporaleEvents  = [];
    }


    /**
     * Validate the request path parameters.
     *
     * This method will validate the request path parameters as follows:
     * - The first path parameter must be either "nation" or "diocese".
     * - If the first path parameter is "nation", there must be a second path parameter which is a valid national calendar ID.
     * - If the first path parameter is "diocese", there must be a second path parameter which is a valid diocesan calendar ID.
     * - If the first path parameter is neither "nation" nor "diocese", it will produce an error response with a status code of 422 and a description of the error.
     * - If the number of path parameters is not 2, it will produce an error response with a status code of 422 and a description of the error.
     *
     * @return void
     */
    private function validateRequestPathParams(): void
    {
        /** @var array{locale?:string,national_calendar?:string,diocesan_calendar?:string,eternal_high_priest?:bool} */
        $params = [];
        if (false === in_array($this->requestPathParams[0], ['nation', 'diocese'])) {
            throw new UnprocessableContentException('Unknown resource path: ' . $this->requestPathParams[0] . ', expected either /nation/{nation} or /diocese/{diocese_id}');
        }
        if (count($this->requestPathParams) === 2) {
            if ($this->requestPathParams[0] === 'nation') {
                /** @var array{locale?:string,national_calendar:string,diocesan_calendar?:string,eternal_high_priest?:bool} */
                $params = [ 'national_calendar' => $this->requestPathParams[1] ];
                $this->EventsParams->setParams($params);
            } else {
                /** @var array{locale?:string,national_calendar?:string,diocesan_calendar:string,eternal_high_priest?:bool} */
                $params = [ 'diocesan_calendar' => $this->requestPathParams[1] ];
                $this->EventsParams->setParams($params);
            }
        } else {
            $description = 'Wrong number of path parameters, needed two but got ' . count($this->requestPathParams) . ': [' . implode(',', $this->requestPathParams) . ']';
            throw new UnprocessableContentException($description);
        }
    }



    /**
     * Loads the JSON data for the specified diocesan calendar.
     *
     * If the payload is not valid according to {@see \LiturgicalCalendar\Api\Enum\LitSchema::DIOCESAN}, the response will be a JSON error response with a status code of 422 Unprocessable Content.
     *
     * @return void
     */
    private function loadDiocesanData(): void
    {
        if ($this->EventsParams->DiocesanCalendar !== null) {
            $DiocesanData = array_find(
                $this->EventsParams->calendarsMetadata->diocesan_calendars,
                fn ($el) => $el->calendar_id === $this->EventsParams->DiocesanCalendar
            );
            if (null !== $DiocesanData) {
                if ($this->EventsParams->Rite === Rite::AMBROSIAN) {
                    $this->loadAmbrosianDiocesanData($DiocesanData);
                    return;
                }

                $this->EventsParams->NationalCalendar = $DiocesanData->nation;

                $diocesanDataFile = strtr(
                    JsonData::DIOCESAN_CALENDAR_FILE->path(),
                    [
                        '{nation}'       => $this->EventsParams->NationalCalendar,
                        '{diocese}'      => $this->EventsParams->DiocesanCalendar,
                        '{diocese_name}' => $DiocesanData->diocese
                    ]
                );

                $diocesanDataJson   = Utilities::jsonFileToObject($diocesanDataFile);
                self::$DiocesanData = DiocesanData::fromObject($diocesanDataJson);
                $resolvedLocale     = $this->resolveCalendarLocale(self::$DiocesanData->metadata->locales);
                if ($resolvedLocale !== $this->EventsParams->Locale) {
                    $this->EventsParams->Locale = $resolvedLocale;
                    $baseLocale                 = \Locale::getPrimaryLanguage($this->EventsParams->Locale);
                    if (null === $baseLocale) {
                        throw new ValidationException(
                            '"Names are not always the same among all men, but differ in each language;'
                            . ' yet all are trying to express the nature of things."'
                            . ' — Plato, Cratylus, 383a'
                        );
                    }

                    $this->EventsParams->baseLocale = $baseLocale;
                }
            } else {
                $description = "unknown diocese `{$this->EventsParams->DiocesanCalendar}`, supported values are: ["
                    . implode(',', $this->EventsParams->calendarsMetadata->diocesan_calendars_keys) . ']';
                throw new ValidationException($description);
            }
        }
    }

    /**
     * Ambrosian rite only: loads the JSON data for the specified Ambrosian diocesan calendar.
     *
     * Rite-scoped mirror of the Roman branch above ({@see self::loadDiocesanData()}): reads from the
     * Ambrosian diocesan tree ({@see JsonData::AMBROSIAN_DIOCESAN_CALENDAR_FILE}) instead of the
     * Roman one, and mirrors
     * {@see \LiturgicalCalendar\Api\Handlers\CalendarHandler::loadDiocesanCalendarData()}'s Ambrosian
     * branch: Ambrosian dioceses are not layered on top of a national calendar (the Ambrosian rite
     * has no national calendars, and `EventsParams::validateRiteCompatibility()` throws if
     * `NationalCalendar` is set for the Ambrosian rite), so `EventsParams->NationalCalendar` is
     * deliberately left null here rather than assigned from `$metadataItem->nation`.
     *
     * @param MetadataDiocesanCalendarItem $metadataItem The metadata entry for the requested diocese
     *                                                    (already confirmed to exist and to belong
     *                                                    to the Ambrosian rite by
     *                                                    {@see \LiturgicalCalendar\Api\Params\EventsParams::validateRiteCompatibility()}).
     */
    private function loadAmbrosianDiocesanData(MetadataDiocesanCalendarItem $metadataItem): void
    {
        $nation = strtoupper($metadataItem->nation);

        $diocesanDataFile = strtr(
            JsonData::AMBROSIAN_DIOCESAN_CALENDAR_FILE->path(),
            [
                '{nation}'       => $nation,
                '{diocese}'      => $this->EventsParams->DiocesanCalendar,
                '{diocese_name}' => $metadataItem->diocese
            ]
        );

        $diocesanDataJson   = Utilities::jsonFileToObject($diocesanDataFile);
        self::$DiocesanData = DiocesanData::fromObject($diocesanDataJson);

        $resolvedLocale = $this->resolveCalendarLocale(self::$DiocesanData->metadata->locales);
        if ($resolvedLocale !== $this->EventsParams->Locale) {
            $this->EventsParams->Locale = $resolvedLocale;
            $baseLocale                 = \Locale::getPrimaryLanguage($this->EventsParams->Locale);
            if (null === $baseLocale) {
                throw new ValidationException(
                    '"Names are not always the same among all men, but differ in each language;'
                    . ' yet all are trying to express the nature of things."'
                    . ' — Plato, Cratylus, 383a'
                );
            }

            $this->EventsParams->baseLocale = $baseLocale;
        }
    }

    /**
     * Loads the JSON data for the specified National and Wider Region calendars.
     *
     * If the National calendar is specified, it retrieves the corresponding JSON data file.
     * If the JSON data is valid, it extracts settings like locale and checks for wider region metadata.
     * If wider region metadata is present, it loads the corresponding wider region data and its internationalization file.
     * Updates liturgical event names in the wider region data using the internationalization file.
     *
     * @return void
     */
    private function loadNationalAndWiderRegionData(): void
    {
        if ($this->EventsParams->NationalCalendar !== null) {
            $NationalDataFile = strtr(
                JsonData::NATIONAL_CALENDAR_FILE->path(),
                [
                    '{nation}' => $this->EventsParams->NationalCalendar
                ]
            );

            $nationalDataJson   = Utilities::jsonFileToObject($NationalDataFile);
            self::$NationalData = NationalData::fromObject($nationalDataJson);

            $resolvedLocale = $this->resolveCalendarLocale(self::$NationalData->metadata->locales);
            if ($resolvedLocale !== $this->EventsParams->Locale) {
                $this->EventsParams->Locale = $resolvedLocale;
                $baseLocale                 = \Locale::getPrimaryLanguage($this->EventsParams->Locale);
                if (null === $baseLocale) {
                    throw new \RuntimeException(
                        '"Spoken words are the symbols of mental experience, and written words are the symbols of spoken words.'
                        . ' Just as all men have not the same speech sounds, so do they not all have the same written symbols.'
                        . ' But the mental experiences, which these directly symbolize, are the same for all."'
                        . ' — Aristotle, De Interpretatione, 1.16a'
                    );
                }

                $this->EventsParams->baseLocale = $baseLocale;
            }

            if (self::$NationalData->hasWiderRegion()) {
                $widerRegionDataFile = strtr(
                    JsonData::WIDER_REGION_FILE->path(),
                    [
                        '{wider_region}' => self::$NationalData->metadata->wider_region
                    ]
                );

                $widerRegionI18nFile = strtr(
                    JsonData::WIDER_REGION_I18N_FILE->path(),
                    [
                        '{wider_region}' => self::$NationalData->metadata->wider_region,
                        '{locale}'       => $this->EventsParams->Locale
                    ]
                );

                /** @var array<string,string> $widerRegionI18nData */
                $widerRegionI18nData   = Utilities::jsonFileToArray($widerRegionI18nFile);
                $widerRegionDataJson   = Utilities::jsonFileToObject($widerRegionDataFile);
                self::$WiderRegionData = WiderRegionData::fromObject($widerRegionDataJson);

                foreach (self::$WiderRegionData->litcal as $litCalItem) {
                    $event_key = $litCalItem->liturgical_event->event_key;
                    if (array_key_exists($event_key, $widerRegionI18nData)) {
                        $litCalItem->setName($widerRegionI18nData[$event_key]);
                    }
                }
            }
        }
    }

    /**
     * The locale to serve for a calendar that declares its own `metadata->locales`.
     *
     * Returns the locale already in hand whenever the calendar declares it. Otherwise the
     * request has asked for something this calendar has no dataset under, and what happens
     * next depends on *how* it asked (#845, and the #761 asymmetry the negotiation block in
     * {@see self::handle()} describes):
     *
     * - An explicit `locale` **parameter** is an exact selector naming a dataset. Regional
     *   calendars store their data under regional variants, so `fr` on its own does not name
     *   one of Canada's; it keeps its historical treatment and lands on the calendar's first
     *   declared locale.
     * - An **`Accept-Language` header** is a language *range*, and RFC 4647 §3.3.1 basic
     *   filtering applies: the range `fr` matches the tag `fr-CA`. The *original header* is
     *   re-negotiated here — not the tag it was already negotiated down to — because that
     *   earlier negotiation ran before the calendar was known, against the rite's candidate
     *   set (empty, i.e. the API-wide set, for the Roman rite), and widening its answer after
     *   the fact cannot recover the quality ordering or the alternatives it discarded.
     *
     * `Negotiator::pickLanguage()` answers null (rather than the fallback) when a non-empty
     * header matches nothing supported, so the coalesce below is what actually implements
     * "a range matching nothing the calendar declares lands on `locales[0]`".
     *
     * When a range matches several declared locales at equal quality — `fr` against
     * `['fr_CA', 'fr_FR']` — the negotiator keeps the first candidate to reach the winning
     * score, so the calendar's declaration order decides, the same order that supplies the
     * `locales[0]` default. An exact match still outranks any prefix match.
     *
     * @param string[] $declaredLocales The calendar's `metadata->locales`, in declaration order.
     * @return string One of `$declaredLocales`.
     */
    private function resolveCalendarLocale(array $declaredLocales): string
    {
        if (in_array($this->EventsParams->Locale, $declaredLocales, true)) {
            return $this->EventsParams->Locale;
        }

        if ($this->localeExplicitlyRequested || null === $this->request) {
            return $declaredLocales[0];
        }

        return Negotiator::pickLanguage($this->request, $declaredLocales, $declaredLocales[0])
            ?? $declaredLocales[0];
    }

    /**
     * Set up the process-global locale for this request via the shared
     * LocaleConfigurator (deterministic + leak-free, #745), then bind the gettext
     * text domain and configure the LiturgicalEventAbstract locale.
     *
     * The model receives the resolved `runtimeLocale`, not the raw request locale,
     * so that it matches what CalendarHandler::prepareL10N() hands to
     * LiturgicalEvent::setLocale() (#749). It matters for Latin: the model branches
     * on the strict primary-language form 'la', so the raw 'la_VA' would miss the
     * Latin branch of the Masses for Various Needs commons.
     */
    private function setLocale(): void
    {
        $configured = LocaleConfigurator::configure($this->EventsParams->Locale);
        bindtextdomain('litcal', Router::$apiFilePath . 'i18n');
        textdomain('litcal');
        LiturgicalEventAbstract::setLocale($configured->runtimeLocale);
    }

    /**
     * This function processes the data from the Sanctorale of the Latin Missal
     * and adds it to the LiturgicalEventCollection.
     *
     * The LiturgicalEventCollection is an array of liturgical event arrays, where each liturgical event
     * array has several keys: "event_key", "grade", "common", "missal", "grade_lcl",
     * and "common_lcl". "event_key" is the key for the liturgical event in the
     * LiturgicalEventCollection, "grade" is the grade of the liturgical event (i.e. solemnity,
     * feast, memorial, etc.), "common" is the common number of the liturgical event,
     * "missal" is the missal to which the liturgical event belongs, "grade_lcl" is the
     * localized grade of the liturgical event, and "common_lcl" is the localized common
     * number of the liturgical event.
     *
     * The function first retrieves the filename of the Sanctorale of the Latin
     * Missal. If the file does not exist, the function returns a 404 error.
     *
     * The function then reads the contents of the file into an array and decodes
     * it from JSON. If there is an error in decoding the JSON, the function returns
     * a 500 error.
     *
     * The function then loops through the array of liturgical event arrays and adds
     * each liturgical event to the LiturgicalEventCollection. It also adds the missal to which
     * the liturgical event belongs, the localized grade of the liturgical event, and the
     * localized common number of the liturgical event to the liturgical event array.
     *
     * Finally, the function checks if there is a related translation file for
     * the Sanctorale of the Latin Missal. If there is, the function reads the
     * contents of the file into an array and decodes it from JSON. If there is an
     * error in decoding the JSON, the function returns a 500 error.
     *
     * The function then loops through the array of liturgical event arrays and adds
     * the translated name of the liturgical event to the liturgical event array.
     */
    private function processSanctoraleEvents(): void
    {
        if ($this->EventsParams->Rite === Rite::AMBROSIAN) {
            $this->processAmbrosianSanctoraleEvents();
            return;
        }

        foreach (RomanMissal::getLatinMissalIds() as $LatinMissalId) {
            $MissalDataFile = RomanMissal::getSanctoraleFileName($LatinMissalId);
            $i18nPath       = RomanMissal::getSanctoraleI18nFilePath($LatinMissalId);

            if (false !== $MissalDataFile) {
                if (false === $i18nPath) {
                    throw new ServiceUnavailableException('Could not find translation file for Latin missal ' . $LatinMissalId);
                }
                $i18nFile   = "{$i18nPath}{$this->EventsParams->baseLocale}.json";
                $names      = Utilities::jsonFileToArray($i18nFile);
                $MissalData = Utilities::jsonFileToArray($MissalDataFile);

                /** @var array{event_key:string,month:integer,day:integer,grade:integer,color:string[],type:string,common?:string[],grade_display?:string} $liturgicalEvent */
                foreach ($MissalData as $liturgicalEvent) {
                    $key = $liturgicalEvent['event_key'];
                    if (array_key_exists($key, $names)) {
                        $liturgicalEvent['name'] = $names[$key];
                    }
                    if (false === isset($liturgicalEvent['name'])) {
                        throw new \RuntimeException('Could not find name for liturgical event ' . $key);
                    }
                    /** @var array{event_key:string,name:string,month:integer,day:integer,grade:integer,color:string[],type:string,common?:string[],grade_display?:string} $liturgicalEvent */
                    self::$liturgicalEvents->addEvent(LiturgicalEventFixed::fromArray($liturgicalEvent));
                }
            }
        }
    }

    /**
     * Ambrosian rite only: processes the comune Ambrosian Proprium de Sanctis (sanctorale) data
     * and adds it to the catalog.
     *
     * Rite-scoped mirror of the Roman branch of {@see self::processSanctoraleEvents()} above: same
     * raw-array read-and-name-lookup shape, but reading the single comune Ambrosian sanctorale
     * (`{@see JsonData::AMBROSIAN_SANCTORALE_FILE}` / `_I18N_FILE`) resolved for the request year via
     * {@see AmbrosianMissalResolver}, instead of looping {@see RomanMissal::getLatinMissalIds()}.
     * There is no per-key skip-on-collision here (unlike
     * `CalendarHandler::addAmbrosianSanctoraleToCalendar()`): temporale and sanctorale catalog
     * entries are kept in separate buckets (`self::$temporaleEvents` vs. `self::$liturgicalEvents`),
     * so the three keys shared between the two source files (`Christmas`, `Circoncisione`,
     * `Epiphany`) never collide here the way they would in a single dated collection.
     *
     * Only called for {@see Rite::AMBROSIAN} requests, which `EventsParams::validateRiteCompatibility()`
     * has already confirmed carry no national calendar.
     */
    private function processAmbrosianSanctoraleEvents(): void
    {
        $edition = ( new AmbrosianMissalResolver() )->resolve($this->EventsParams->Year)[0];

        $MissalDataFile = JsonData::AMBROSIAN_SANCTORALE_FILE->path();
        $i18nFile       = strtr(JsonData::AMBROSIAN_SANCTORALE_I18N_FILE->path(), ['{locale}' => $this->resolveAmbrosianLocale()]);

        $names      = Utilities::jsonFileToArray($i18nFile);
        $MissalData = Utilities::jsonFileToArray($MissalDataFile);

        /** @var array{event_key:string,month:integer,day:integer,grade:integer,color:string[],type:string,common?:string[],grade_display?:string} $liturgicalEvent */
        foreach ($MissalData as $liturgicalEvent) {
            $key = $liturgicalEvent['event_key'];
            if (array_key_exists($key, $names)) {
                $liturgicalEvent['name'] = $names[$key];
            }
            if (false === isset($liturgicalEvent['name'])) {
                throw new \RuntimeException('Could not find name for liturgical event ' . $key . ' in the ' . AmbrosianMissal::getName($edition) . '.');
            }
            /** @var array{event_key:string,name:string,month:integer,day:integer,grade:integer,color:string[],type:string,common?:string[],grade_display?:string} $liturgicalEvent */
            self::$liturgicalEvents->addEvent(LiturgicalEventFixed::fromArray($liturgicalEvent));
        }
    }

    /**
     * Ambrosian rite only: resolves the locale to use for the Ambrosian source data i18n files.
     *
     * The Ambrosian temporale and sanctorale i18n data only ship `it` and `la` locale files (see
     * `jsondata/sourcedata/rite/ambrosian/missals/propriumdetempore/i18n` and
     * `jsondata/sourcedata/rite/ambrosian/missals/propriumdesanctis_2024/i18n`). If the request's base
     * locale isn't one of those, fall back to Italian (`it`), mirroring
     * `CalendarHandler::loadAmbrosianPropriumDeTemporeData()` / `addAmbrosianSanctoraleToCalendar()`.
     */
    private function resolveAmbrosianLocale(): string
    {
        return in_array($this->EventsParams->baseLocale, ['it', 'la'], true)
            ? $this->EventsParams->baseLocale
            : 'it';
    }

    /**
     * Processes the Temporale (Proprium de Tempore) events and adds them to the catalog.
     *
     * Temporale events (Advent Sundays, Easter, Pentecost, Corpus Christi, …) are computed from Easter and
     * carry no stored date, so they are represented as date-less {@see LiturgicalEventTemporale} catalog
     * entries and merged into the response. They matter to the catalog because they are the anchors a
     * decree's relative `strtotime` references (e.g. "Monday after Pentecost"). Reads the source list and
     * the localized names for the request's base locale.
     *
     * Ambrosian requests read the Ambrosian Proprium de Tempore source
     * ({@see JsonData::AMBROSIAN_TEMPORALE_FILE} / `_I18N_FILE`) instead, with the same `it`/`la`
     * (fallback `it`) locale resolution as
     * `CalendarHandler::loadAmbrosianPropriumDeTemporeData()`. There is no per-edition resolution
     * for the temporale (it is rite-wide, not Missal-edition-specific), mirroring that handler.
     */
    private function processTemporaleEvents(): void
    {
        if ($this->EventsParams->Rite === Rite::AMBROSIAN) {
            $dataFile = JsonData::AMBROSIAN_TEMPORALE_FILE->path();
            $i18nFile = strtr(JsonData::AMBROSIAN_TEMPORALE_I18N_FILE->path(), ['{locale}' => $this->resolveAmbrosianLocale()]);
        } else {
            $dataFile = JsonData::TEMPORALE_FILE->path();
            $i18nFile = strtr(JsonData::TEMPORALE_I18N_FILE->path(), ['{locale}' => $this->EventsParams->baseLocale]);
        }
        if (false === file_exists($dataFile) || false === file_exists($i18nFile)) {
            return;
        }
        $temporaleData = Utilities::jsonFileToArray($dataFile);
        $names         = Utilities::jsonFileToArray($i18nFile);
        /** @var array{event_key:string,grade:int,type:string,color:string[]} $event */
        foreach ($temporaleData as $event) {
            $key = $event['event_key'];
            if (false === array_key_exists($key, $names) || false === is_string($names[$key]) || $names[$key] === '') {
                continue;
            }
            $event['name']           = $names[$key];
            self::$temporaleEvents[] = LiturgicalEventTemporale::fromArray($event);
        }
    }

    /**
     * Processes the Memorials from Decrees data and populates the LiturgicalEventCollection.
     *
     * This function reads the Memorials from Decrees data from a JSON file and its
     * internationalization (i18n) data from another JSON file. It decodes both files
     * and checks for JSON errors, producing appropriate error responses if any
     * issues are encountered.
     *
     * For each liturgical event in the Memorials from Decrees data, the function checks if
     * it is already present in the LiturgicalEventCollection. If not, it adds the liturgical event
     * to the collection with its localized name and default attributes such as
     * grade, common, common_lcl, and calendar. It also adds the URL of the decree
     * promulgating the liturgical event.
     *
     * If the liturgical event is already present in the LiturgicalEventCollection, the function
     * checks if the action attribute of the liturgical event is 'setProperty'. If so, it
     * updates the specified property of the liturgical event. If the action attribute is
     * 'makeDoctor', it updates the name of the liturgical event.
     *
     * @return void
     */
    private function processMemorialsFromDecreesData(): void
    {
        $I18nFile    = JsonData::DECREES_I18N_FOLDER->path() . "/{$this->EventsParams->baseLocale}.json";
        $names       = Utilities::jsonFileToArray($I18nFile);
        $decreesFile = JsonData::DECREES_FILE->path();
        $decrees     = Utilities::jsonFileToObjectArray($decreesFile);
        /** @var DecreeItemFromObject[] $decrees */
        /** @var array<string,string> $names */
        DecreeItemCollection::setNames($decrees, $names);
        $decreeItems = DecreeItemCollection::fromObject($decrees);
        foreach ($decreeItems as $decreeItem) {
            $key = $decreeItem->getEventKey();
            if (false === self::$liturgicalEvents->hasKey($key) && ( $decreeItem->liturgical_event instanceof DecreeItemCreateNewFixed || $decreeItem->liturgical_event instanceof DecreeItemCreateNewMobile )) {
                if ($decreeItem->liturgical_event instanceof DecreeItemCreateNewFixed) {
                    self::$liturgicalEvents->addEvent(LiturgicalEventFixed::fromObject($decreeItem->liturgical_event));
                } else {
                    self::$liturgicalEvents->addEvent(LiturgicalEventMobile::fromObject($decreeItem->liturgical_event));
                }
            } elseif ($decreeItem->liturgical_event instanceof DecreeItemSetPropertyName) {
                $existingLiturgicalEvent = self::$liturgicalEvents->getEvent($key);
                if (null === $existingLiturgicalEvent) {
                    throw new \RuntimeException('Thomas, called Didymus, one of the Twelve, was not with them when Jesus came. - John 20:24');
                }
                $existingLiturgicalEvent->name = $names[$key];
            } elseif ($decreeItem->liturgical_event instanceof DecreeItemSetPropertyGrade) {
                $existingLiturgicalEvent = self::$liturgicalEvents->getEvent($key);
                if (null === $existingLiturgicalEvent) {
                    throw new \RuntimeException('It would seem that Jonah has been swallowed by the whale.');
                }
                $existingLiturgicalEvent->grade = $decreeItem->liturgical_event->grade;
            } elseif ($decreeItem->liturgical_event instanceof DecreeItemMakeDoctor) {
                $existingLiturgicalEvent = self::$liturgicalEvents->getEvent($key);
                if (null === $existingLiturgicalEvent) {
                    throw new \RuntimeException('Is Ishmael lost in the desert again?');
                }
                $existingLiturgicalEvent->name = $names[$key];
            }
        }
    }


    /**
     * Processes the National Calendar data and populates the LiturgicalEventCollection.
     *
     * This function checks if the NationalCalendar parameter and NationalData are set.
     * If WiderRegionData contains a 'litcal' property, it processes each liturgicalevent with
     * the action 'createNew' and adds it to the LiturgicalEventCollection, setting localized
     * grade and common attributes.
     *
     * It also iterates through the NationalData 'litcal' property and adds new liturgical events
     * to the LiturgicalEventCollection with localized attributes.
     *
     * If NationalData metadata includes 'missals', it attempts to load liturgicalevent data
     * from the specified Roman Missals, adding them to the LiturgicalEventCollection with
     * localized attributes and associating the missal name.
     *
     * Produces error responses if required resource files are not found.
     *
     * @return void
     */
    private function processNationalCalendarData(): void
    {
        if ($this->EventsParams->NationalCalendar !== null && self::$NationalData !== null) {
            if (count(self::$NationalData->metadata->missals) > 0) {
                foreach (self::$NationalData->metadata->missals as $missalId) {
                    $missalDataFile = RomanMissal::getSanctoraleFileName($missalId);
                    $I18nPath       = RomanMissal::getSanctoraleI18nFilePath($missalId);
                    if ($missalDataFile !== false) {
                        $I18nFile   = "{$I18nPath}{$this->EventsParams->Locale}.json";
                        $names      = Utilities::jsonFileToArray($I18nFile);
                        $MissalData = Utilities::jsonFileToArray($missalDataFile);

                        /** @var array{event_key:string,day:integer,month:integer,grade:integer,color:string[],type:string,common?:string[],grade_display?:string} $liturgicalEvent */
                        foreach ($MissalData as $liturgicalEvent) {
                            $key = $liturgicalEvent['event_key'];
                            if (array_key_exists($key, $names)) {
                                $liturgicalEvent['name'] = $names[$key];
                            }
                            if (false === isset($liturgicalEvent['name'])) {
                                throw new \Exception('Missing name for liturgical event ' . $key . ', unable to process liturgical events.');
                            }
                            /** @var array{event_key:string,name:string,day:integer,month:integer,grade:integer,color:string[],type:string,common?:string[],grade_display?:string} $liturgicalEvent */
                            self::$liturgicalEvents->addEvent(LiturgicalEventFixed::fromArray($liturgicalEvent));
                        }
                    }
                }
            }

            if (self::$WiderRegionData !== null) {
                foreach (self::$WiderRegionData->litcal as $litCalItem) {
                    if ($litCalItem->liturgical_event instanceof LitCalItemCreateNewFixed) {
                        $event = LiturgicalEventFixed::fromObject($litCalItem->liturgical_event);
                        self::$liturgicalEvents->addEvent($event);
                    } elseif ($litCalItem->liturgical_event instanceof LitCalItemCreateNewMobile) {
                        $event = LiturgicalEventMobile::fromObject($litCalItem->liturgical_event);
                        self::$liturgicalEvents->addEvent($event);
                    } elseif ($litCalItem->liturgical_event instanceof LitCalItemSetPropertyGrade) {
                        $existingLiturgicalEvent = self::$liturgicalEvents->getEvent($litCalItem->liturgical_event->event_key);
                        if (null === $existingLiturgicalEvent) {
                            throw new \RuntimeException('“The goat that was sent away presented a type of Him who takes away the sins of men.” – Justin Martyr');
                        }
                        $existingLiturgicalEvent->grade = $litCalItem->liturgical_event->grade;
                    } elseif ($litCalItem->liturgical_event instanceof LitCalItemSetPropertyName) {
                        $existingLiturgicalEvent = self::$liturgicalEvents->getEvent($litCalItem->liturgical_event->event_key);
                        if (null === $existingLiturgicalEvent) {
                            throw new \RuntimeException('No dove on this ark, did Noah already set it out?');
                        }
                        $existingLiturgicalEvent->name = $litCalItem->liturgical_event->name;
                    } elseif ($litCalItem->liturgical_event instanceof LitCalItemMakePatron) {
                        $existingLiturgicalEvent = self::$liturgicalEvents->getEvent($litCalItem->liturgical_event->event_key);
                        if (null === $existingLiturgicalEvent) {
                            throw new \RuntimeException('“Son, why have you done this to us? Your father and I have been looking for you with great anxiety.” – Luke 2:48');
                        }
                        $existingLiturgicalEvent->name = $litCalItem->liturgical_event->name;
                        if (property_exists($litCalItem->liturgical_event, 'grade')) {
                            $existingLiturgicalEvent->grade = $litCalItem->liturgical_event->grade;
                        }
                    } else {
                        throw new \ValueError('Unknown LitCalItem->liturgical_event type: ' . get_class($litCalItem->liturgical_event));
                    }
                }
            }

            $NationalCalendarI18nFile = strtr(
                JsonData::NATIONAL_CALENDAR_I18N_FILE->path(),
                [
                    '{nation}' => $this->EventsParams->NationalCalendar,
                    '{locale}' => $this->EventsParams->Locale
                ]
            );

            /** @var array<string,string> $NationalCalendarI18nData */
            $NationalCalendarI18nData = Utilities::jsonFileToArray($NationalCalendarI18nFile);

            foreach (self::$NationalData->litcal as $litCalItem) {
                $key = $litCalItem->liturgical_event->event_key;
                if ($litCalItem->liturgical_event instanceof LitCalItemCreateNewFixed) {
                    $litCalItem->setName($NationalCalendarI18nData[$key]);
                    self::$liturgicalEvents->addEvent(LiturgicalEventFixed::fromObject($litCalItem->liturgical_event));
                } elseif ($litCalItem->liturgical_event instanceof LitCalItemCreateNewMobile) {
                    $litCalItem->setName($NationalCalendarI18nData[$key]);
                    self::$liturgicalEvents->addEvent(LiturgicalEventMobile::fromObject($litCalItem->liturgical_event));
                } elseif ($litCalItem->liturgical_event instanceof LitCalItemSetPropertyName) {
                    $existingLiturgicalEvent = self::$liturgicalEvents->getEvent($key);
                    if (null === $existingLiturgicalEvent) {
                        throw new \RuntimeException("Unknown event key '{$key}' when setting name from National calendar");
                    }
                    $existingLiturgicalEvent->name = $NationalCalendarI18nData[$key];
                } elseif ($litCalItem->liturgical_event instanceof LitCalItemSetPropertyGrade) {
                    $existingLiturgicalEvent = self::$liturgicalEvents->getEvent($key);
                    if (null === $existingLiturgicalEvent) {
                        throw new \RuntimeException("Unknown event key '{$key}' when setting grade from National calendar");
                    }
                    $existingLiturgicalEvent->grade = $litCalItem->liturgical_event->grade;
                } elseif ($litCalItem->liturgical_event instanceof LitCalItemMakePatron) {
                    $existingLiturgicalEvent = self::$liturgicalEvents->getEvent($key);
                    if (null === $existingLiturgicalEvent) {
                        throw new \RuntimeException('Rising very early before dawn, he left and went off to a deserted place, where he prayed. Simon and those who were with him pursued him and on finding him said, “Everyone is looking for you.” - Mark 1:35-37');
                    }
                    $existingLiturgicalEvent->name  = $NationalCalendarI18nData[$key];
                    $existingLiturgicalEvent->grade = $litCalItem->liturgical_event->grade;
                } elseif ($litCalItem->liturgical_event instanceof LitCalItemMoveEvent) {
                    // Do nothing
                } else {
                    throw new \ValueError('Unknown LitCalItem->liturgical_event type: ' . get_class($litCalItem->liturgical_event));
                }
            }
        }
    }

    /**
     * Processes the Diocesan Calendar data and populates the LiturgicalEventCollection.
     *
     * This function checks if the DiocesanCalendar parameter and DiocesanData are set.
     * If so, it iterates through the DiocesanData 'litcal' property and adds new liturgical events
     * to the LiturgicalEventCollection with localized attributes and a modified event_key
     * incorporating the DiocesanCalendar parameter.
     *
     * @return void
     */
    private function processDiocesanCalendarData(): void
    {
        if ($this->EventsParams->DiocesanCalendar !== null && self::$DiocesanData !== null) {
            $DiocesanCalendarI18nFile = strtr(
                JsonData::DIOCESAN_CALENDAR_I18N_FILE->path(),
                [
                    '{nation}'  => $this->EventsParams->NationalCalendar,
                    '{diocese}' => $this->EventsParams->DiocesanCalendar,
                    '{locale}'  => $this->EventsParams->Locale
                ]
            );

            /** @var array<string,string> $DiocesanCalendarI18nData */
            $DiocesanCalendarI18nData = Utilities::jsonFileToArray($DiocesanCalendarI18nFile);

            foreach (self::$DiocesanData->litcal as $diocesanLitCalItem) {
                $key  = $diocesanLitCalItem->liturgical_event->event_key;
                $name = $DiocesanCalendarI18nData[$key];
                $diocesanLitCalItem->setName('[ ' . self::$DiocesanData->metadata->diocese_name . ' ] ' . $name);
                if (
                    false === $diocesanLitCalItem->liturgical_event instanceof DiocesanLitCalItemCreateNewFixed
                    && false === $diocesanLitCalItem->liturgical_event instanceof DiocesanLitCalItemCreateNewMobile
                ) {
                    throw new \ValueError('diocesan calendar item `' . $key . '`: the `setProperty` action is not supported for Roman rite diocesan calendars');
                }
                $diocesanLitCalItem->liturgical_event->setKey($this->EventsParams->DiocesanCalendar . '_' . $key);
                if ($diocesanLitCalItem->liturgical_event instanceof DiocesanLitCalItemCreateNewFixed) {
                    self::$liturgicalEvents->addEvent(LiturgicalEventFixed::fromObject($diocesanLitCalItem->liturgical_event));
                } elseif ($diocesanLitCalItem->liturgical_event instanceof DiocesanLitCalItemCreateNewMobile) {
                    self::$liturgicalEvents->addEvent(LiturgicalEventMobile::fromObject($diocesanLitCalItem->liturgical_event));
                } else {
                    throw new \ValueError('Unknown DiocesanLitCalItem->liturgical_event type: ' . get_class($diocesanLitCalItem->liturgical_event));
                }
            }
        }
    }

    /**
     * Ambrosian rite only: processes the Ambrosian diocesan calendar data and merges it into the
     * catalog.
     *
     * Rite-scoped mirror of {@see self::processDiocesanCalendarData()} above: same event-object
     * construction and `[ {diocese_name} ] {name}` naming / `{diocese}_{key}` key-prefixing shape,
     * but reading the localized names from the Ambrosian diocesan i18n tree
     * ({@see JsonData::AMBROSIAN_DIOCESAN_CALENDAR_I18N_FILE}) instead of the Roman one. Unlike the
     * comune Ambrosian sanctorale/temporale i18n files (named `it.json`/`la.json`), the Ambrosian
     * diocesan i18n files reuse the Roman diocesan-calendar naming convention and are named after
     * the full locale (`it_IT.json`/`la_VA.json`); the locale is resolved the same way
     * {@see \LiturgicalCalendar\Api\Handlers\CalendarHandler::applyAmbrosianDiocesanCalendar()}
     * resolves it: {@see self::resolveAmbrosianLocale()}'s `it`/`la` mapped to `it_IT`/`la_VA`,
     * falling back to the diocese's first declared locale if the mapped one isn't among
     * `DiocesanData->metadata->locales`.
     *
     * Early-returns when no Ambrosian diocese was requested (or {@see self::loadAmbrosianDiocesanData()}
     * was never called), so the comune-only Ambrosian catalog is unaffected.
     *
     * @return void
     */
    private function processAmbrosianDiocesanCalendarData(): void
    {
        if ($this->EventsParams->DiocesanCalendar === null || self::$DiocesanData === null) {
            return;
        }

        $locale = match ($this->resolveAmbrosianLocale()) {
            'la'    => 'la_VA',
            default => 'it_IT',
        };
        if (false === in_array($locale, self::$DiocesanData->metadata->locales, true)) {
            $locale = self::$DiocesanData->metadata->locales[0];
        }

        $DiocesanCalendarI18nFile = strtr(
            JsonData::AMBROSIAN_DIOCESAN_CALENDAR_I18N_FILE->path(),
            [
                '{nation}'  => self::$DiocesanData->metadata->nation,
                '{diocese}' => self::$DiocesanData->metadata->diocese_id,
                '{locale}'  => $locale
            ]
        );

        /** @var array<string,string> $DiocesanCalendarI18nData */
        $DiocesanCalendarI18nData = Utilities::jsonFileToArray($DiocesanCalendarI18nFile);

        foreach (self::$DiocesanData->litcal as $diocesanLitCalItem) {
            $key  = $diocesanLitCalItem->liturgical_event->event_key;
            $name = $DiocesanCalendarI18nData[$key];
            $diocesanLitCalItem->setName('[ ' . self::$DiocesanData->metadata->diocese_name . ' ] ' . $name);
            if ($diocesanLitCalItem->liturgical_event instanceof DiocesanLitCalItemCreateNewFixed) {
                $diocesanLitCalItem->liturgical_event->setKey($this->EventsParams->DiocesanCalendar . '_' . $key);
                self::$liturgicalEvents->addEvent(LiturgicalEventFixed::fromObject($diocesanLitCalItem->liturgical_event));
            } elseif ($diocesanLitCalItem->liturgical_event instanceof DiocesanLitCalItemCreateNewMobile) {
                $diocesanLitCalItem->liturgical_event->setKey($this->EventsParams->DiocesanCalendar . '_' . $key);
                self::$liturgicalEvents->addEvent(LiturgicalEventMobile::fromObject($diocesanLitCalItem->liturgical_event));
            } else {
                // TODO(Task 6): DiocesanLitCalItemSetPropertyGrade/Name/Common items are legitimate
                // here (e.g. a suffragan Ambrosian diocese celebrating a comune feast at a different
                // grade/common/name) and this block will be replaced with the real setProperty
                // dispatch. Until then they fall through to this generic diagnostic instead of being
                // silently dropped.
                throw new \ValueError('Unknown DiocesanLitCalItem->liturgical_event type: ' . get_class($diocesanLitCalItem->liturgical_event));
            }
        }
    }


    /**
     * Initializes the Events class and processes the request.
     *
     * This method performs the following actions:
     * - Validates the Accept header.
     * - Sets the response Content-ype based on the request and the best type available.
     * - Retrieves and sets parameters from the request.
     * - Loads and processes various calendar and missal and decree data.
     * - Sets the locale for the response.
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $response = static::initResponse($request);

        $method = RequestMethod::from($request->getMethod());

        // OPTIONS method for CORS preflight requests is always allowed
        if ($method === RequestMethod::OPTIONS) {
            return $this->handlePreflightRequest($request, $response);
        } else {
            $response = $this->setAccessControlAllowOriginHeader($request, $response);
        }

        // First of all we validate that the Content-Type requested in the Accept header is supported by the endpoint:
        //   if set we negotiate the best Content-Type, if not set we default to the first supported by the current handler
        switch ($method) {
            case RequestMethod::GET:
                $mime = $this->validateAcceptHeader($request, AcceptabilityLevel::LAX);
                break;
            default:
                $mime = $this->validateAcceptHeader($request, AcceptabilityLevel::INTERMEDIATE);
        }

        $response = $response->withHeader('Content-Type', $mime);

        // Initialize any parameters set in the request.
        // If there are any:
        //   - for a GET request method, we expect them to be set in the URL
        //   - for any other request methods, we expect them to be set in the body of the request
        // Considering that this endpoint is both read and write:
        //   - for POST requests we will never have a payload in the request body,
        //       only request parameters
        //   - for PUT and PATCH requests we will have a payload in the request body
        //   - for DELETE requests we will have neither payload nor request parameters, only path parameters

        /** @var array{locale?:string,national_calendar?:string,diocesan_calendar?:string,eternal_high_priest?:bool} $params */
        $params = [];

        // Second of all, we check if an Accept-Language header was set in the request.
        // Negotiated against the locales the requested rite has liturgical books for
        // (empty for the Roman rite, which restricts nothing), so an unsupported
        // preference degrades to Latin rather than tripping the rejection in
        // EventsParams::validateRiteCompatibility() — see the equivalent block in
        // CalendarHandler::handle() for why headers and explicit params differ (#761).
        // This is a calendar-agnostic first pass: the requested national or diocesan
        // calendar is not known yet, so the header is re-negotiated against that
        // calendar's own declared locales once it loads (#845). The request is kept on
        // the handler for that second pass — see self::resolveCalendarLocale().
        $this->request = $request;
        $locale        = Negotiator::pickLanguage($request, CalendarMetadataProvider::negotiableLocalesForRite($this->rite), LitLocale::LATIN);
        if ($locale && LitLocale::isValid($locale)) {
            $params['locale'] = $locale;
        } else {
            $params['locale'] = LitLocale::LATIN;
        }

        /** @var array<string,scalar|null> $requestParams */
        $requestParams = [];
        if ($method === RequestMethod::GET) {
            $requestParams = $this->getScalarQueryParams($request);
        } elseif ($method === RequestMethod::POST) {
            $parsedBodyParams = $this->parseBodyParams($request, false);

            if (null !== $parsedBodyParams) {
                /** @var array<string,scalar|null> $requestParams */
                $requestParams = $parsedBodyParams;
            }
        }

        // Whether the locale about to be applied came from the client's own words or from
        // the header negotiation above, which decides how it is treated when the requested
        // calendar turns out not to declare it (#845/#761).
        $this->localeExplicitlyRequested = array_key_exists('locale', $requestParams);

        /** @var array{locale?:string,national_calendar?:string,diocesan_calendar?:string,eternal_high_priest?:bool} $params */
        $params = array_merge($params, $requestParams);

        $this->EventsParams = new EventsParams($params);
        $this->EventsParams->setRite($this->rite);
        if (count($this->requestPathParams)) {
            $this->validateRequestPathParams();
        }
        $this->EventsParams->validateRiteCompatibility();

        $this->validateRequestMethod($request);

        $this->loadNationalAndWiderRegionData();
        $this->loadDiocesanData();
        $this->setLocale();
        $this->processTemporaleEvents();
        $this->processSanctoraleEvents();
        if ($this->EventsParams->Rite === Rite::ROMAN) {
            // The Ambrosian rite has no national calendars or decrees data yet;
            // EventsParams::validateRiteCompatibility() above has already rejected any
            // Ambrosian request carrying a national calendar, so these two processors are
            // Roman-only. The diocesan overlay is rite-scoped: processDiocesanCalendarData()
            // (Roman) below, processAmbrosianDiocesanCalendarData() (Ambrosian) in the branch
            // beneath.
            $this->processMemorialsFromDecreesData();
            $this->processNationalCalendarData();
            $this->processDiocesanCalendarData();
        } else {
            // Ambrosian: merge the diocesan overlay (if a diocese was requested via
            // /events/ambrosian/diocese/{id}) into the comune catalog already built by
            // processTemporaleEvents()/processSanctoraleEvents() above. No-ops when no
            // diocese was requested (comune-only /events/ambrosian).
            $this->processAmbrosianDiocesanCalendarData();
        }

        $responseObj  = [
            // Temporale entries are date-less and live outside the (Fixed/Mobile) event map, so merge them in.
            'litcal_events' => array_merge(self::$temporaleEvents, self::$liturgicalEvents->toCollection()),
            'settings'      => [
                'locale'            => $this->EventsParams->Locale,
                'national_calendar' => $this->EventsParams->NationalCalendar,
                'diocesan_calendar' => $this->EventsParams->DiocesanCalendar,
                // Echoed for every rite, `roman` included, so consumers can branch on it without a
                // presence check. A rite-level /events/ambrosian request sets neither national_calendar
                // nor diocesan_calendar, so this is the only thing that distinguishes it from /events.
                'rite'              => $this->EventsParams->Rite
            ]
        ];
        $responseBody = json_encode($responseObj, JSON_THROW_ON_ERROR);
        $responseHash = md5($responseBody);
        $response     = $response->withHeader('ETag', "\"{$responseHash}\"");

        if (
            $request->getHeaderLine('If-None-Match') !== ''
            && trim($request->getHeaderLine('If-None-Match'), " \t\"") === $responseHash
        ) {
            return $response->withStatus(StatusCode::NOT_MODIFIED->value, StatusCode::NOT_MODIFIED->reason())
                            ->withHeader('Content-Length', '0');
        }

        return $this->encodeResponseBody($response, $responseObj);
    }
}
