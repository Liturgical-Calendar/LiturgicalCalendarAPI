<?php

namespace LiturgicalCalendar\Api\Enum;

use LiturgicalCalendar\Api\Enum\JsonData;
use LiturgicalCalendar\Api\Http\Exception\ValidationException;
use LiturgicalCalendar\Api\Models\MissalsPath\MissalMetadata;
use LiturgicalCalendar\Api\Router;

/**
 * Enum class for the different Ambrosian Missals that are used in the Liturgical Calendar API.
 * This class provides methods to check if a given missal_id is valid, get the name of an Ambrosian Missal,
 * get the path to the JSON file containing the sanctorale data for an Ambrosian Missal,
 * get the path to the i18n directory for the sanctorale of an Ambrosian Missal,
 * and get the year limits for an Ambrosian Missal.
 *
 * Mirrors the shape of {@see \LiturgicalCalendar\Api\Enum\RomanMissal}. Both post-conciliar editions are
 * declared: `EDITIO_TYPICA_1976` (data-less) and `EDITIO_TYPICA_2024`.
 *
 * ## Edition history
 *
 * | year | what it is                                                                                                    |
 * | ---- | -------------------------------------------------------------------------------------------------------------- |
 * | 1976 | **I edizione, italiana** — the first post-conciliar Ambrosian Missal (Card. Giovanni Colombo), with the new Ambrosian Calendar. The authority. |
 * | 1981 | the **Latin translation** of the 1976 edition — same contents, different language. NOT a separate edition.      |
 * | 1990 | *aggiornamento* under Card. Carlo Maria Martini: a revised reprint of the Italian, still the FIRST edition       |
 * | 2024 | **II edizione, italiana** (Mario Delpini), in force from 17 November                                             |
 * | 2026 | the **Latin translation** of the 2024 edition (*editio altera*), superseding the 1981 Latin                     |
 *
 * Four things this history means for the ids this class declares:
 *
 * - **The Ambrosian rite is the inverse of the Roman rite.** In the Roman rite the Latin *editio
 *   typica* is the authority, and vernacular editions are national/bishops'-conference adaptations
 *   that carry local memorials the Latin does not. In the Ambrosian rite the **Italian** edition is
 *   the authority, and the Latin is its translation, with identical contents.
 * - **A Latin edition is therefore NOT its own `missal_id` — it is an i18n sidecar.** This is
 *   already how the shipped data is structured: `propriumdesanctis_2024/i18n/it.json` and `la.json`
 *   are the same edition in two languages. The 1981 Latin belongs to the 1976 edition; the
 *   forthcoming 2026 Latin will belong to the 2024 edition. Coining `EDITIO_TYPICA_1981` as a
 *   missal would model a translation as a delta layer, which it is not.
 * - **There is no national tier in the Ambrosian rite.** There is no Ambrosian equivalent of
 *   `US_2011` or `IT_1983`. Every Ambrosian missal is a rite-level edition — which is why
 *   {@see \LiturgicalCalendar\Api\Enum\AmbrosianMissalSource::isEditioTypica()} is true for every
 *   valid Ambrosian id, and why the `national_calendar` branch of
 *   {@see \LiturgicalCalendar\Api\Http\Middleware\OpenFgaAuthorizationMiddleware::forMissals()}
 *   never PRODUCES a national-calendar object for this rite (an invalid id still reaches that
 *   branch and throws `ValidationException` there, rather than returning one).
 * - **1990 must never be coined as its own `missal_id`.** It is a revised reprint within the FIRST
 *   (Italian) edition under Card. Martini — not a new edition — which is exactly why 2024 is
 *   technically the SECOND. A `missal_id` identifies a delta layer merged by `event_key`
 *   ({@see \LiturgicalCalendar\Api\Enum\JsonData}); a reprint that changes no liturgical content is
 *   not a layer and has nothing to be keyed as.
 *
 * The only two ids this rite will ever need for the editions known today are `EDITIO_TYPICA_1976`
 * and `EDITIO_TYPICA_2024`, and both are now declared (#957). The 1976 edition ships no source data
 * yet; see {@see \LiturgicalCalendar\Api\Models\Calendar\Missal\AmbrosianMissalResolver} for how a
 * year it governs is served in the meantime.
 *
 * @phpstan-type AmbrosianMissalMetadata array{
 *     missal_id:string,
 *     name:string,
 *     region:string,
 *     locales:string[],
 *     year_limits:array{since_year:int,until_year?:int},
 *     year_published:int,
 *     api_path:?string
 * }
 */
class AmbrosianMissal
{
    /**
     * The I edizione, italiana of the Ambrosian Missal, promulgated by Card. Giovanni Colombo in 1976 together
     * with the new Ambrosian Calendar — see the "Edition history" table on this class's docblock.
     *
     * The authority for this edition is the ITALIAN text. Its 1981 Latin counterpart (*Missale Ambrosianum*) is
     * a translation with identical contents, so it is an i18n sidecar, not a `missal_id` of its own; and the
     * 1990 Martini *aggiornamento* is a revised reprint WITHIN this edition, which is precisely why 2024 is the
     * SECOND edition. Neither is coined.
     *
     * Declared with no source files, exactly as {@see \LiturgicalCalendar\Api\Enum\RomanMissal} declares
     * `EDITIO_TYPICA_1971`, `ITALY_EDITION_2020`, `NETHERLANDS_EDITION_1978` and the two Canadian editions.
     * `MissalMetadataMap::buildIndex()` skips any id whose structure file is absent, so this edition cannot
     * appear under `/missals/ambrosian` until it has data, and `produceMetadata()` gives it a null `api_path`.
     */
    public const EDITIO_TYPICA_1976 = 'EDITIO_TYPICA_1976';

    /**
     * The II edizione, italiana of the Missale Ambrosianum, promulgated by Mario Delpini and in
     * force from 17 November 2024 — see the "Edition history" table on this class's docblock.
     *
     * The `EDITIO_TYPICA_` prefix is used loosely across this codebase for a rite-level edition
     * (as with {@see \LiturgicalCalendar\Api\Enum\RomanMissal}), not as a claim about a Latin
     * *editio typica* specifically. Unlike the Roman rite, the Ambrosian rite has no Latin edition
     * with content of its own to name that way: the Italian IS the authority, and its Latin
     * counterpart (1981 for the 1976 edition; a forthcoming 2026 translation of this one) is a
     * translation with identical contents, not a separate `missal_id`. There is also no national
     * tier for this rite — every Ambrosian missal is a rite-level edition.
     */
    public const EDITIO_TYPICA_2024 = 'EDITIO_TYPICA_2024';

    /**
     * The region every Ambrosian edition is filed under.
     *
     * Not `IT`: filing it under Italy would place it beside IT_1983 and IT_2020 as though it were
     * another Italian Roman-rite national missal, and `region` is what decides which national
     * calendars a missal layer applies to. Not `VA` either, which would misattribute Milan's
     * missal to the Vatican.
     */
    public const REGION = 'AMBROSIAN';

    /**
     * The locale an editio typica falls back to when the requested locale is not one this Missal
     * supports — Italian, not Latin, because the Italian edition IS the authority for this rite
     * (see {@see self::EDITIO_TYPICA_2024}'s docblock). `AmbrosianMissalSource::editioTypicaFallbackLocale()`
     * is the one caller; kept here rather than inline so the fact lives beside the docblock that
     * argues for it.
     */
    public const PRIMARY_LOCALE = 'it';

    /**
     * The values of the Ambrosian Missal enumeration constants.
     * This array is used to check if a given missal_id is a valid Ambrosian Missal enumeration constant.
     * @static
     * @var string[]
     * @see \LiturgicalCalendar\Api\Enum\AmbrosianMissal::isValid()
     */
    private static array $values = [ 'EDITIO_TYPICA_2024', 'EDITIO_TYPICA_1976' ];

    /**
     * An associative array of the Ambrosian Missal names, where the key is the value of an Ambrosian Missal constant.
     * This array is used to get the name of an Ambrosian Missal given its id.
     * @static
     * @var array<string,string>
     * @see \LiturgicalCalendar\Api\Enum\AmbrosianMissal::getName()
     */
    private static array $names = [
        self::EDITIO_TYPICA_2024 => 'Messale Ambrosiano, Editio 2024',
        self::EDITIO_TYPICA_1976 => 'Messale Ambrosiano, I edizione italiana, 1976'
    ];

    /**
     * An associative array of the JSON file paths, where the key is the value of an Ambrosian Missal constant.
     * This array is used to get the path to the JSON file containing the sanctorale data for an Ambrosian Missal.
     *
     * Paths are relative to {@see JsonData::AMBROSIAN_MISSALS_FOLDER} and MUST carry the edition's own folder
     * segment. They used to be relative to `AMBROSIAN_SANCTORALE_FOLDER`, which is hard-wired to
     * `propriumdesanctis_2024` — fine while one edition existed, and silently wrong for the second, whose file
     * would have resolved inside the 2024 edition's folder. `RomanMissal` has always been keyed this way.
     *
     * @static
     * @var array<string,string|false>
     * @see \LiturgicalCalendar\Api\Enum\AmbrosianMissal::getSanctoraleFileName()
     */
    private static array $jsonFiles = [
        self::EDITIO_TYPICA_2024 => '/propriumdesanctis_2024/propriumdesanctis_2024.json',
        self::EDITIO_TYPICA_1976 => false
    ];

    /**
     * An associative array of the i18n file paths, where the key is the value of an Ambrosian Missal constant.
     * This array is used to get the path to the i18n directory for the sanctorale of an Ambrosian Missal.
     *
     * Paths are relative to {@see JsonData::AMBROSIAN_MISSALS_FOLDER} and MUST carry the edition's own folder
     * segment. They used to be relative to `AMBROSIAN_SANCTORALE_FOLDER`, which is hard-wired to
     * `propriumdesanctis_2024` — fine while one edition existed, and silently wrong for the second, whose file
     * would have resolved inside the 2024 edition's folder. `RomanMissal` has always been keyed this way.
     *
     * @static
     * @var array<string,string|false>
     * @see \LiturgicalCalendar\Api\Enum\AmbrosianMissal::getSanctoraleI18nFilePath()
     */
    private static array $i18nPath = [
        self::EDITIO_TYPICA_2024 => '/propriumdesanctis_2024/i18n/',
        self::EDITIO_TYPICA_1976 => false
    ];

    /**
     * An associative array of the lectionary directory paths, where the key is the value of an Ambrosian Missal
     * constant. Mirrors {@see \LiturgicalCalendar\Api\Enum\RomanMissal::$lectionaryPath}, and paths are relative
     * to {@see JsonData::AMBROSIAN_MISSALS_FOLDER} including the edition's own folder segment.
     *
     * Both entries are `false`: no Ambrosian lectionary data ships yet. The map exists anyway because for THIS
     * rite the lectionary is genuinely per-edition — the renewed Lezionario appeared in 2008, between the two
     * editions — so it cannot be a per-rite constant the way the Roman `sanctorum` corpus is. See
     * {@see \LiturgicalCalendar\Api\Enum\AmbrosianMissalSource::riteLectionaryFolder()}, which stays `false`
     * and must never fall back to the Roman corpus (101 of the 254 Ambrosian event_keys collide with Roman
     * lectionary keys).
     *
     * @static
     * @var array<string,string|false>
     * @see \LiturgicalCalendar\Api\Enum\AmbrosianMissal::getLectionaryFilePath()
     */
    private static array $lectionaryPath = [
        self::EDITIO_TYPICA_2024 => false,
        self::EDITIO_TYPICA_1976 => false
    ];

    /**
     * An associative array of the year limits, where the key is the value of an Ambrosian Missal constant
     * and the value is an associative array with the properties 'since_year' and optionally 'until_year'.
     * This array is used to get the year limits for an Ambrosian Missal.
     * @static
     * @var array<string,array{since_year:int,until_year?:int}>
     * @see \LiturgicalCalendar\Api\Enum\AmbrosianMissal::getYearLimits()
     */
    private static array $yearLimits = [
        self::EDITIO_TYPICA_2024 => [ 'since_year' => 2024 ],
        self::EDITIO_TYPICA_1976 => [ 'since_year' => 1976, 'until_year' => 2024 ]
    ];

    /**
     * The editions that ARE typical editions: the normative bases from which any future national
     * or diocesan delta of the Ambrosian rite would be computed.
     *
     * Declared, not inferred from `isValid()`. Every Ambrosian id declared today happens to be
     * typical, so the two would agree today regardless — but a future national Ambrosian delta
     * would be a valid id that is NOT typical, and inferring the tier from validity would silently
     * misreport it, picking the wrong region and the wrong `calendar` label (#953).
     *
     * @var string[]
     */
    private static array $editioTypicaIds = [ self::EDITIO_TYPICA_2024, self::EDITIO_TYPICA_1976 ];

    /**
     * Check if a given missal_id is a valid Ambrosian Missal enumeration constant.
     *
     * @param string $missal_id the missal_id to check
     * @return bool true if the missal_id is a valid Ambrosian Missal enumeration constant, false otherwise
     */
    public static function isValid(string $missal_id): bool
    {
        return in_array($missal_id, self::$values);
    }

    /**
     * Checks if a given value is an editio typica: the normative base edition of the Ambrosian
     * rite, from which any future national or diocesan delta would be computed. This is a
     * statement about authority, not validity — see {@see AmbrosianMissal::$editioTypicaIds}.
     *
     * @param string $missal_id the value to check
     * @return bool true if the value is an editio typica, false otherwise
     */
    public static function isEditioTypica(string $missal_id): bool
    {
        return in_array($missal_id, self::$values, true) && in_array($missal_id, self::$editioTypicaIds, true);
    }

    /**
     * Gets the name of the Ambrosian Missal corresponding to the given Missal id.
     *
     * @param string $missal_id the id of the Ambrosian Missal
     * @return string the name of the Ambrosian Missal
     * @throws ValidationException if missal_id is not valid
     */
    public static function getName(string $missal_id): string
    {
        if (false === self::isValid($missal_id)) {
            throw new ValidationException('Invalid missal_id: ' . $missal_id);
        }
        return self::$names[$missal_id];
    }

    /**
     * Gets the path to the JSON file containing the sanctorale data for the given Ambrosian Missal.
     *
     * @param string $missal_id the id of the Ambrosian Missal
     * @return string|false the path to the JSON file, or false if the Ambrosian Missal does not have any JSON data
     * @throws ValidationException if missal_id is not valid
     */
    public static function getSanctoraleFileName(string $missal_id): string|false
    {
        if (false === self::isValid($missal_id)) {
            throw new ValidationException('Invalid missal_id: ' . $missal_id);
        }
        return is_string(self::$jsonFiles[$missal_id])
            ? JsonData::AMBROSIAN_MISSALS_FOLDER->path() . self::$jsonFiles[$missal_id]
            : false;
    }

    /**
     * Gets the path to the i18n directory for the sanctorale of the given Ambrosian Missal.
     *
     * @param string $missal_id the id of the Ambrosian Missal
     * @return string|false the path to the i18n directory, or false if the Ambrosian Missal does not have any i18n data
     * @throws ValidationException if missal_id is not valid
     */
    public static function getSanctoraleI18nFilePath(string $missal_id): string|false
    {
        if (false === self::isValid($missal_id)) {
            throw new ValidationException('Invalid missal_id: ' . $missal_id);
        }
        return is_string(self::$i18nPath[$missal_id])
            ? JsonData::AMBROSIAN_MISSALS_FOLDER->path() . self::$i18nPath[$missal_id]
            : false;
    }

    /**
     * Gets the path to the lectionary directory for the given Ambrosian Missal.
     *
     * @param string $missal_id the id of the Ambrosian Missal
     * @return string|false the path to the lectionary directory, or false if this edition ships no lectionary data
     * @throws ValidationException if missal_id is not valid
     */
    public static function getLectionaryFilePath(string $missal_id): string|false
    {
        if (false === self::isValid($missal_id)) {
            throw new ValidationException('Invalid missal_id: ' . $missal_id);
        }
        return is_string(self::$lectionaryPath[$missal_id])
            ? JsonData::AMBROSIAN_MISSALS_FOLDER->path() . self::$lectionaryPath[$missal_id]
            : false;
    }

    /**
     * Gets the year limits for the given Ambrosian Missal.
     *
     * @param string $missal_id the id of the Ambrosian Missal
     * @return array{since_year:int,until_year?:int} an associative array of the year limits
     *   for the given Ambrosian Missal, with properties named 'since_year' and optionally 'until_year'
     * @throws ValidationException if missal_id is not valid
     */
    public static function getYearLimits(string $missal_id): array
    {
        if (false === self::isValid($missal_id)) {
            throw new ValidationException('Invalid missal_id: ' . $missal_id);
        }
        return self::$yearLimits[$missal_id];
    }

    /**
     * Gets an array of all the valid Ambrosian Missal enumeration constants.
     *
     * @return string[] an array of all the valid Ambrosian Missal enumeration constants
     */
    public static function getMissalIds(): array
    {
        return self::$values;
    }

    /**
     * Mirrors {@see \LiturgicalCalendar\Api\Enum\RomanMissal::produceMetadata()} for the Ambrosian
     * rite: an array of metadata objects (or associative arrays) describing every declared
     * Ambrosian Missal, keyed by `missal_id`.
     *
     * @param bool $obj whether to return an array of metadata objects or an array of associative arrays
     *
     * @return array<string,MissalMetadata|AmbrosianMissalMetadata> an array of metadata objects or associative arrays each describing an Ambrosian Missal
     */
    public static function produceMetadata($obj = true): array
    {
        $metadata = [];
        foreach (self::getMissalIds() as $missal_id) {
            $i18n_path = self::getSanctoraleI18nFilePath($missal_id);
            $locales   = [];
            if ($i18n_path) {
                $iterator = new \DirectoryIterator("glob://$i18n_path*.json");
                foreach ($iterator as $file) {
                    $locales[] = $file->getBasename('.json');
                }
            }

            $metadata[$missal_id] = [
                'missal_id'      => $missal_id,
                'name'           => self::getName($missal_id),
                'region'         => self::REGION,
                'locales'        => $locales,
                'year_limits'    => self::$yearLimits[$missal_id],
                'year_published' => self::$yearLimits[$missal_id]['since_year'],
                'api_path'       => self::getSanctoraleFileName($missal_id) ? Router::$apiPath . "/missals/ambrosian/$missal_id" : null
            ];

            if ($obj) {
                $metadata[$missal_id] = MissalMetadata::fromArray($metadata[$missal_id]);
            }
        }
        return $metadata;
    }
}
