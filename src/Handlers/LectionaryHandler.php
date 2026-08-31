<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Handlers;

use LiturgicalCalendar\Api\Enum\JsonData;
use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Enum\RomanMissal;
use LiturgicalCalendar\Api\Http\Enum\AcceptabilityLevel;
use LiturgicalCalendar\Api\Http\Enum\AcceptHeader;
use LiturgicalCalendar\Api\Http\Enum\RequestMethod;
use LiturgicalCalendar\Api\Http\Exception\MethodNotAllowedException;
use LiturgicalCalendar\Api\Http\Exception\NotFoundException;
use LiturgicalCalendar\Api\Router;
use LiturgicalCalendar\Api\Utilities;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Handles the `/lectionary` path of the API: a read surface over the curated lectionary
 * source data, addressed by `event_key` rather than by computed date (issue #942).
 *
 * Only the `sanctorale` section is served for now:
 *
 * - `GET /lectionary/sanctorale` — an index of every `event_key` that has an entry in any
 *   lectionary source, naming for each the sources that carry it.
 * - `GET /lectionary/sanctorale/{event_key}` — every locale's readings for that event, from
 *   every source that carries it.
 *
 * Both accept the optional leading rite segment (`/lectionary/roman/sanctorale`,
 * `/lectionary/ambrosian/sanctorale`), exactly as `/calendar`, `/events` and `/data` do.
 *
 * ## Two tiers, and saying which one answered
 *
 * The sanctorale readings live in two places, and an `event_key` may be carried by both:
 *
 * 1. the **rite**-level corpus, `jsondata/sourcedata/rite/roman/lectionary/sanctorum/{locale}.json`;
 * 2. a **missal**-level corpus, one folder per national missal, resolved through
 *    {@see RomanMissal::getLectionaryFilePath()}.
 *
 * A client editing readings has to know which file it is looking at, so every entry returned
 * names its `tier` and its `source_id` rather than being merged into a single answer. The
 * missal tier is enumerated from `RomanMissal`'s explicit lectionary-path map, not by globbing
 * the missals folder, so it does not depend on the `{dir}/{dir}.json` folder-naming convention.
 *
 * ## Absent is not empty
 *
 * A reading field that is the empty string is an established convention in this corpus: it
 * means "the structure is in place, the reading is not written yet". That is a different state
 * from "this locale's file has no entry for this key at all", and both are legitimate. The
 * response therefore reports, per source, three disjoint-ish sets over the source's declared
 * locales: `locales_with_entry`, `locales_without_entry`, and — as a subset of the first —
 * `locales_with_empty_entry`. The raw `entries` map carries every entry verbatim, empty strings
 * included, so nothing is flattened away.
 *
 * ## Rites with no lectionary
 *
 * There is no Ambrosian lectionary. Rather than a 500 or an empty object indistinguishable from
 * "nothing curated yet", such a request is answered `200` with `lectionary_available: false`, no
 * sources, and a message saying so. An unknown `event_key` under a rite that *does* have a
 * lectionary is a `404`, so the two states never collide.
 *
 * ## Locale
 *
 * This route is deliberately locale-agnostic: it aggregates every locale a source declares, which
 * is the whole point of it, so no `Accept-Language` negotiation takes place.
 */
final class LectionaryHandler extends AbstractHandler
{
    /** The only lectionary section addressable through this route so far. */
    public const string SECTION_SANCTORALE = 'sanctorale';

    /** Sources whose readings belong to the rite as a whole rather than to one missal. */
    private const string TIER_RITE = 'rite';

    /** Sources whose readings belong to a single Roman Missal edition. */
    private const string TIER_MISSAL = 'missal';

    private Rite $rite;

    /**
     * @param string[] $requestPathParams the path segments following `/lectionary`, rite segment already stripped
     */
    public function __construct(array $requestPathParams = [], Rite $rite = Rite::ROMAN)
    {
        parent::__construct($requestPathParams);
        $this->rite = $rite;
        $this->setAllowedRequestMethods([RequestMethod::GET]);
        $this->setAllowedAcceptHeaders([AcceptHeader::JSON, AcceptHeader::YAML]);
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $response = static::initResponse($request);

        $method = RequestMethod::from($request->getMethod());

        if ($method === RequestMethod::OPTIONS) {
            return $this->handlePreflightRequest($request, $response);
        }

        $response = $this->setAccessControlAllowOriginHeader($request, $response);

        $mime     = $this->validateAcceptHeader($request, AcceptabilityLevel::LAX);
        $response = $response->withHeader('Content-Type', $mime);

        $this->validateRequestMethod($request);

        if ($method !== RequestMethod::GET) {
            throw new MethodNotAllowedException();
        }

        $numPathParams = count($this->requestPathParams);

        if ($numPathParams === 0) {
            throw new NotFoundException(
                'The `/lectionary` path is not a resource of its own; address a section of the lectionary, '
                . 'e.g. ' . Router::$apiPath . '/lectionary/' . $this->rite->value . '/' . self::SECTION_SANCTORALE
            );
        }

        if ($numPathParams > 2) {
            throw new NotFoundException(
                'At most two path parameters are expected on the `/lectionary` path (a section, and optionally an event_key), instead '
                . $numPathParams . ' were found'
            );
        }

        $section = $this->requestPathParams[0];
        if ($section !== self::SECTION_SANCTORALE) {
            throw new NotFoundException(
                "Unknown lectionary section '" . $section . "', the only supported value is: " . self::SECTION_SANCTORALE
            );
        }

        $sources = $this->sanctoraleSources();

        if ($numPathParams === 1) {
            return $this->encodeResponseBody($response, $this->buildIndex($sources));
        }

        return $this->encodeResponseBody($response, $this->buildEventReadings($sources, $this->requestPathParams[1]));
    }

    /**
     * The folder holding the rite-level sanctorale lectionary, or null when the rite has none.
     *
     * The Ambrosian rite currently has no lectionary data at all — no folder, not an empty one —
     * which is why this is a match on the rite rather than a path built from it.
     */
    private function riteLectionaryFolder(): ?string
    {
        return match ($this->rite) {
            Rite::ROMAN     => JsonData::LECTIONARY_SAINTS_FOLDER->path(),
            Rite::AMBROSIAN => null,
        };
    }

    /**
     * Every lectionary source that can carry a sanctorale entry for the requested rite,
     * in resolution order: the rite-level corpus first, then each missal that declares one.
     *
     * @return list<array{tier:string,source_id:string,folder:string,locales:list<string>}>
     */
    private function sanctoraleSources(): array
    {
        $sources = [];

        $riteFolder = $this->riteLectionaryFolder();
        if (null !== $riteFolder) {
            $locales = self::localesInFolder($riteFolder);
            if ([] !== $locales) {
                $sources[] = [
                    'tier'      => self::TIER_RITE,
                    'source_id' => $this->rite->value,
                    'folder'    => $riteFolder,
                    'locales'   => $locales
                ];
            }
        }

        // The per-missal lectionaries belong to the Roman Missal editions, so they only apply
        // to the Roman rite. `RomanMissal::$lectionaryPath` maps only the national editions:
        // the editiones typicae take their sanctorale readings from the rite-level corpus.
        if ($this->rite === Rite::ROMAN) {
            foreach (RomanMissal::getMissalIds() as $missalId) {
                $folder = RomanMissal::getLectionaryFilePath($missalId);
                if (false === $folder) {
                    continue;
                }
                $locales = self::localesInFolder($folder);
                if ([] === $locales) {
                    continue;
                }
                $sources[] = [
                    'tier'      => self::TIER_MISSAL,
                    'source_id' => $missalId,
                    'folder'    => $folder,
                    'locales'   => $locales
                ];
            }
        }

        return $sources;
    }

    /**
     * The locales a lectionary folder declares, i.e. the basenames of the JSON files it holds.
     *
     * @return list<string>
     */
    private static function localesInFolder(string $folder): array
    {
        $folder = rtrim($folder, '/\\');
        if (false === is_dir($folder)) {
            return [];
        }
        $files = glob($folder . DIRECTORY_SEPARATOR . '*.json');
        if (false === $files) {
            return [];
        }
        sort($files);
        return array_values(array_map(static fn (string $file): string => basename($file, '.json'), $files));
    }

    /**
     * Decode one locale file of one source, or null when it is unreadable or is not a JSON object.
     *
     * A malformed or missing file must not take the whole route down: it is one locale of one
     * source, and the well-formed answer is that the locale carries no entry.
     */
    private static function readLocaleFile(string $folder, string $locale): ?\stdClass
    {
        $file = rtrim($folder, '/\\') . DIRECTORY_SEPARATOR . $locale . '.json';
        if (false === is_readable($file)) {
            return null;
        }
        try {
            return Utilities::jsonFileToObject($file);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Whether a readings entry carries anything at all, as opposed to being the all-empty-string
     * placeholder that means "structure in place, readings not written yet".
     *
     * Recurses into nested objects because source-data readings may nest (a vigil Mass's readings
     * belong to the event that has the vigil).
     */
    private static function hasReadingsContent(\stdClass $readings): bool
    {
        foreach (get_object_vars($readings) as $value) {
            if (is_string($value) && $value !== '') {
                return true;
            }
            if ($value instanceof \stdClass && self::hasReadingsContent($value)) {
                return true;
            }
            if (is_array($value) && $value !== []) {
                return true;
            }
        }
        return false;
    }

    /**
     * The `GET /lectionary/{rite}/sanctorale` index: every `event_key` any source carries,
     * with the sources that carry it.
     *
     * @param list<array{tier:string,source_id:string,folder:string,locales:list<string>}> $sources
     */
    private function buildIndex(array $sources): \stdClass
    {
        $out                       = new \stdClass();
        $out->rite                 = $this->rite->value;
        $out->section              = self::SECTION_SANCTORALE;
        $out->lectionary_available = [] !== $sources;

        $sourceSummaries = [];
        /** @var array<string,list<array{tier:string,source_id:string}>> $eventKeys keyed by event_key, first-seen order preserved */
        $eventKeys = [];

        foreach ($sources as $source) {
            $keysInSource = [];
            foreach ($source['locales'] as $locale) {
                $obj = self::readLocaleFile($source['folder'], $locale);
                if (null === $obj) {
                    continue;
                }
                foreach (array_keys(get_object_vars($obj)) as $eventKey) {
                    $keysInSource[$eventKey] = true;
                    if (false === array_key_exists($eventKey, $eventKeys)) {
                        $eventKeys[$eventKey] = [];
                    }
                }
            }

            foreach (array_keys($keysInSource) as $eventKey) {
                $eventKeys[$eventKey][] = ['tier' => $source['tier'], 'source_id' => $source['source_id']];
            }

            $summary                  = new \stdClass();
            $summary->tier            = $source['tier'];
            $summary->source_id       = $source['source_id'];
            $summary->locales         = $source['locales'];
            $summary->event_key_count = count($keysInSource);
            $sourceSummaries[]        = $summary;
        }

        $out->sources = $sourceSummaries;

        $items = [];
        foreach ($eventKeys as $eventKey => $carriedBy) {
            $item            = new \stdClass();
            $item->event_key = (string) $eventKey;
            $item->sources   = array_map(
                static function (array $carrier): \stdClass {
                    $obj            = new \stdClass();
                    $obj->tier      = $carrier['tier'];
                    $obj->source_id = $carrier['source_id'];
                    return $obj;
                },
                $carriedBy
            );
            $item->api_path  = Router::$apiPath . '/lectionary/' . $this->rite->value . '/' . self::SECTION_SANCTORALE . '/' . $eventKey;
            $items[]         = $item;
        }
        $out->litcal_lectionary = $items;

        if ([] === $sources) {
            $out->message = $this->noLectionaryMessage();
        }

        return $out;
    }

    /**
     * The `GET /lectionary/{rite}/sanctorale/{event_key}` item: every locale's readings for the
     * event, from every source that carries it.
     *
     * @param list<array{tier:string,source_id:string,folder:string,locales:list<string>}> $sources
     * @throws NotFoundException when the rite has a lectionary but no source in it carries the event
     */
    private function buildEventReadings(array $sources, string $eventKey): \stdClass
    {
        $out                       = new \stdClass();
        $out->rite                 = $this->rite->value;
        $out->section              = self::SECTION_SANCTORALE;
        $out->event_key            = $eventKey;
        $out->lectionary_available = [] !== $sources;

        if ([] === $sources) {
            $out->readings = [];
            $out->message  = $this->noLectionaryMessage();
            return $out;
        }

        $readings = [];
        foreach ($sources as $source) {
            $entries      = new \stdClass();
            $withEntry    = [];
            $withoutEntry = [];
            $emptyEntry   = [];

            foreach ($source['locales'] as $locale) {
                $obj = self::readLocaleFile($source['folder'], $locale);
                if (null === $obj || false === property_exists($obj, $eventKey) || false === ( $obj->{$eventKey} instanceof \stdClass )) {
                    $withoutEntry[] = $locale;
                    continue;
                }
                $entry              = $obj->{$eventKey};
                $entries->{$locale} = $entry;
                $withEntry[]        = $locale;
                if (false === self::hasReadingsContent($entry)) {
                    $emptyEntry[] = $locale;
                }
            }

            if ([] === $withEntry) {
                // This source does not answer for this event_key at all; saying so for every
                // source would bury the ones that do.
                continue;
            }

            $item                           = new \stdClass();
            $item->tier                     = $source['tier'];
            $item->source_id                = $source['source_id'];
            $item->locales                  = $source['locales'];
            $item->locales_with_entry       = $withEntry;
            $item->locales_without_entry    = $withoutEntry;
            $item->locales_with_empty_entry = $emptyEntry;
            $item->entries                  = $entries;
            $readings[]                     = $item;
        }

        if ([] === $readings) {
            throw new NotFoundException(
                "No sanctorale lectionary entry found for event_key '" . $eventKey . "' in the " . $this->rite->value
                . ' rite. The full list of available event_key values is at '
                . Router::$apiPath . '/lectionary/' . $this->rite->value . '/' . self::SECTION_SANCTORALE
            );
        }

        $out->readings = $readings;

        return $out;
    }

    private function noLectionaryMessage(): string
    {
        return 'No sanctorale lectionary data is defined for the ' . $this->rite->value . ' rite.';
    }
}
