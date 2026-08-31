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
 * Mirrors the shape of {@see \LiturgicalCalendar\Api\Enum\RomanMissal}. Only the 2024 edition is defined for
 * now; the 1976 edition (with its own `since_year`/`until_year` historical gating) is deferred to a later plan.
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
     * The values of the Ambrosian Missal enumeration constants.
     * This array is used to check if a given missal_id is a valid Ambrosian Missal enumeration constant.
     * @static
     * @var string[]
     * @see \LiturgicalCalendar\Api\Enum\AmbrosianMissal::isValid()
     */
    private static array $values = [ 'EDITIO_TYPICA_2024' ];

    /**
     * An associative array of the Ambrosian Missal names, where the key is the value of an Ambrosian Missal constant.
     * This array is used to get the name of an Ambrosian Missal given its id.
     * @static
     * @var array<string,string>
     * @see \LiturgicalCalendar\Api\Enum\AmbrosianMissal::getName()
     */
    private static array $names = [
        self::EDITIO_TYPICA_2024 => 'Messale Ambrosiano, Editio 2024'
    ];

    /**
     * An associative array of the JSON file paths, where the key is the value of an Ambrosian Missal constant.
     * This array is used to get the path to the JSON file containing the sanctorale data for an Ambrosian Missal.
     * @static
     * @var array<string,string|false>
     * @see \LiturgicalCalendar\Api\Enum\AmbrosianMissal::getSanctoraleFileName()
     */
    private static array $jsonFiles = [
        self::EDITIO_TYPICA_2024 => '/propriumdesanctis_2024.json'
    ];

    /**
     * An associative array of the i18n file paths, where the key is the value of an Ambrosian Missal constant.
     * This array is used to get the path to the i18n directory for the sanctorale of an Ambrosian Missal.
     * @static
     * @var array<string,string|false>
     * @see \LiturgicalCalendar\Api\Enum\AmbrosianMissal::getSanctoraleI18nFilePath()
     */
    private static array $i18nPath = [
        self::EDITIO_TYPICA_2024 => '/i18n/'
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
        self::EDITIO_TYPICA_2024 => [ 'since_year' => 2024 ]
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
    private static array $editioTypicaIds = [ self::EDITIO_TYPICA_2024 ];

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
            ? JsonData::AMBROSIAN_SANCTORALE_FOLDER->path() . self::$jsonFiles[$missal_id]
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
            ? JsonData::AMBROSIAN_SANCTORALE_FOLDER->path() . self::$i18nPath[$missal_id]
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
