<?php

namespace LiturgicalCalendar\Api\Paths;

use LiturgicalCalendar\Api\Utilities;
use LiturgicalCalendar\Api\LatinUtils;
use LiturgicalCalendar\Api\Enum\LitLocale;

final class EasterPath
{
    private static string $Locale     = LitLocale::LATIN;
    private static string $baseLocale = LitLocale::LATIN_PRIMARY_LANGUAGE;
    private static \IntlDateFormatter $dayOfTheWeekDayMonthYear;
    private static \IntlDateFormatter $dayMonthYear;
    private static \IntlDateFormatter $dayOfTheWeek;
    private static object $EasterDates;
    private const ALLOWED_METHODS = [ 'GET', 'OPTIONS' ];

    private static function enforceAllowedMethods(): void
    {
        if (!in_array($_SERVER['REQUEST_METHOD'], self::ALLOWED_METHODS)) {
            header($_SERVER['SERVER_PROTOCOL'] . ' 405 Method Not Allowed', true, 405);
            die();
        }
    }

    private static function handleRequestParams(): void
    {
        self::$Locale = isset($_GET['locale']) && LitLocale::isValid($_GET['locale']) ? $_GET['locale'] : LitLocale::LATIN;
    }

    private static function serveCachedFileIfExists(): void
    {
        if (file_exists('engineCache/easter/' . self::$baseLocale . '.json')) {
            header('Content-Type: application/json');
            echo Utilities::rawContentsFromFile('engineCache/easter/' . self::$baseLocale . '.json');
            die();
        }
    }

    private static function setLocale(): void
    {
        $baseLocale = self::$Locale !== LitLocale::LATIN ? \Locale::getPrimaryLanguage(self::$Locale) : LitLocale::LATIN_PRIMARY_LANGUAGE;
        if (null === $baseLocale) {
            throw new \RuntimeException(
                '“It is therefore a very appropriate punishment that falls on Zacharias.'
                . ' For his want of faith with regard to the birth of the voice, he is himself deprived of his voice.”'
                . ' — Origen of Alexandria, Commentary on the Gospel of John'
            );
        }
        self::$baseLocale = $baseLocale;

        $localeArray = [
            self::$Locale . '.utf8',
            self::$Locale . '.UTF-8',
            self::$Locale,
            self::$baseLocale . '_' . strtoupper(self::$baseLocale) . '.utf8',
            self::$baseLocale . '_' . strtoupper(self::$baseLocale) . '.UTF-8',
            self::$baseLocale . '_' . strtoupper(self::$baseLocale),
            self::$baseLocale . '.utf8',
            self::$baseLocale . '.UTF-8',
            self::$baseLocale
        ];
        setlocale(LC_ALL, $localeArray);

        $dayOfTheWeekDayMonthYear = \IntlDateFormatter::create(
            self::$Locale,
            \IntlDateFormatter::FULL,
            \IntlDateFormatter::NONE,
            'UTC',
            \IntlDateFormatter::GREGORIAN,
            'EEEE d MMMM yyyy'
        );
        $dayMonthYear             = \IntlDateFormatter::create(
            self::$Locale,
            \IntlDateFormatter::FULL,
            \IntlDateFormatter::NONE,
            'UTC',
            \IntlDateFormatter::GREGORIAN,
            'd MMMM yyyy'
        );
        $dayOfTheWeek             = \IntlDateFormatter::create(
            self::$Locale,
            \IntlDateFormatter::FULL,
            \IntlDateFormatter::NONE,
            'UTC',
            \IntlDateFormatter::GREGORIAN,
            'EEEE'
        );

        if (
            null === $dayOfTheWeekDayMonthYear
            || null === $dayMonthYear
            || null === $dayOfTheWeek
        ) {
            throw new \RuntimeException('"Fugit irreparabile tempus." — Virgil, Georgics, Book III');
        }

        self::$dayOfTheWeekDayMonthYear = $dayOfTheWeekDayMonthYear;
        self::$dayMonthYear             = $dayMonthYear;
        self::$dayOfTheWeek             = $dayOfTheWeek;
    }

    private static function calculateEasterDates(): void
    {
        self::$EasterDates                = new \stdClass();
        self::$EasterDates->litcal_easter = [];
        $dateLastCoincidence              = null;
        $gregDateString                   = '';
        $julianDateString                 = '';
        $westernJulianDateString          = '';

        for ($i = 1583; $i <= 9999; $i++) {
            self::$EasterDates->litcal_easter[$i - 1583] = new \stdClass();
            $gregorian_easter                            = Utilities::calcGregEaster($i);
            $julian_easter                               = Utilities::calcJulianEaster($i);
            $western_julian_easter                       = Utilities::calcJulianEaster($i, true);
            $same_easter                                 = false;

            if ($gregorian_easter->format('l, F jS, Y') === $western_julian_easter->format('l, F jS, Y')) {
                $same_easter         = true;
                $dateLastCoincidence = $gregorian_easter;
            }

            switch (self::$baseLocale) {
                case LitLocale::LATIN_PRIMARY_LANGUAGE:
                    $month                   = (int) $gregorian_easter->format('n'); //n      = 1-January to 12-December
                    $monthLatin              = LatinUtils::LATIN_MONTHS[$month];
                    $gregDateString          = 'Dies Domini, ' . $gregorian_easter->format('j') . ' ' . $monthLatin . ' ' . $gregorian_easter->format('Y');
                    $month                   = (int) $julian_easter->format('n'); //n         = 1-January to 12-December
                    $monthLatin              = LatinUtils::LATIN_MONTHS[$month];
                    $julianDateString        = 'Dies Domini, ' . $julian_easter->format('j') . ' ' . $monthLatin . ' ' . $julian_easter->format('Y');
                    $month                   = (int) $western_julian_easter->format('n'); //n = 1-January to 12-December
                    $monthLatin              = LatinUtils::LATIN_MONTHS[$month];
                    $westernJulianDateString = 'Dies Domini, ' . $western_julian_easter->format('j') . ' ' . $monthLatin . ' ' . $western_julian_easter->format('Y');
                    break;
                case 'en':
                    $gregDateString          = $gregorian_easter->format('l, F jS, Y');
                    $julianDateString        = 'Sunday' . $julian_easter->format(', F jS, Y');
                    $westernJulianDateString = $western_julian_easter->format('l, F jS, Y');
                    break;
                default:
                    $gregDateString          = self::$dayOfTheWeekDayMonthYear->format($gregorian_easter->format('U'));
                    $julianDateString        = self::$dayOfTheWeek->format($gregorian_easter->format('U'))
                                                    . ', ' . self::$dayMonthYear->format($julian_easter->format('U'));
                    $westernJulianDateString = self::$dayOfTheWeekDayMonthYear->format($western_julian_easter->format('U'));
            }

            self::$EasterDates->litcal_easter[$i - 1583]->gregorianEaster         = (int) $gregorian_easter->format('U');
            self::$EasterDates->litcal_easter[$i - 1583]->julianEaster            = (int) $julian_easter->format('U');
            self::$EasterDates->litcal_easter[$i - 1583]->westernJulianEaster     = (int) $western_julian_easter->format('U');
            self::$EasterDates->litcal_easter[$i - 1583]->coinciding              = $same_easter;
            self::$EasterDates->litcal_easter[$i - 1583]->gregorianDateString     = $gregDateString;
            self::$EasterDates->litcal_easter[$i - 1583]->julianDateString        = $julianDateString;
            self::$EasterDates->litcal_easter[$i - 1583]->westernJulianDateString = $westernJulianDateString;
        }

        if (null === $dateLastCoincidence) {
            throw new \RuntimeException(
                'Although all events, even the most trifling, are disposed according to God’s plan, as we have shown,'
                . ' there is nothing to prevent some things from happening by chance or accident.'
                . ' An occurrence may be accidental or fortuitous with respect to a lower cause when an effect not intended is brought about,'
                . ' and yet not be accidental or fortuitous with respect to a higher cause, inasmuch as the effect does not take place apart from the latter’s intention.'
                . ' — St. Thomas Aquinas, Opuscula I Treatises Compendium Theologiæ, Book I On Faith, Chapter 137'
            );
        }
        self::$EasterDates->lastCoincidenceString = $dateLastCoincidence->format('l, F jS, Y');
        self::$EasterDates->lastCoincidence       = (int) $dateLastCoincidence->format('U');
    }

    /**
     * Save the calculated dates of Easter to cache and return them in JSON format.
     * This function is never intended to return normally, so it's marked with a never return type.
     * @return never
     */
    private static function produceResponse(): never
    {
        if (!is_dir('engineCache/easter/')) {
            mkdir('engineCache/easter/', 0774, true);
        }
        file_put_contents('engineCache/easter/' . self::$baseLocale . '.json', json_encode(self::$EasterDates));

        header('Content-Type: application/json');
        echo json_encode(self::$EasterDates);
        die();
    }

    /**
     * Initialize the EasterPath process.
     *
     * This method performs the following steps:
     * 1. Enforces that the HTTP request method is allowed.
     * 2. Handles request parameters to set the appropriate locale.
     * 3. Serves cached Easter date data if available.
     * 4. Sets the locale for date formatting.
     * 5. Calculates the dates of Easter for the specified range.
     * 6. Produces a JSON response with the calculated data.
     *
     * @return never
     */
    public static function init(): never
    {
        self::enforceAllowedMethods();
        self::handleRequestParams();
        self::serveCachedFileIfExists();
        self::setLocale();
        self::calculateEasterDates();
        self::produceResponse();
    }
}
