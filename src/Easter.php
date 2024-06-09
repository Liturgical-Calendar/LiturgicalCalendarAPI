<?php

namespace Johnrdorazio\LitCal;

use Johnrdorazio\LitCal\enum\LitLocale;
use Johnrdorazio\LitCal\LitFunc;
use Johnrdorazio\LitCal\LitMessages;

class Easter
{
    private static $Locale                                       = LitLocale::LATIN;
    private static $baseLocale                                   = null;
    private static ?\IntlDateFormatter $dayOfTheWeekDayMonthYear = null;
    private static ?\IntlDateFormatter $dayMonthYear             = null;
    private static ?\IntlDateFormatter $dayOfTheWeek             = null;
    private static ?object $EasterDates                          = null;
    private const ALLOWED_METHODS                                = [ "GET", "OPTIONS" ];

    private static function enforceAllowedMethods(): void
    {
        if (!in_array($_SERVER['REQUEST_METHOD'], self::ALLOWED_METHODS)) {
            header($_SERVER[ "SERVER_PROTOCOL" ] . " 405 Method Not Allowed", true, 405);
            die();
        }
    }

    private static function handleRequestParams(): void
    {
        self::$Locale = isset($_GET["locale"]) && LitLocale::isValid($_GET["locale"]) ? $_GET["locale"] : LitLocale::LATIN;
    }

    private static function serveCachedFileIfExists(): void
    {
        if (file_exists('engineCache/easter/' . self::$baseLocale . '.json')) {
            header('Content-Type: application/json');
            echo file_get_contents('engineCache/easter/' . self::$baseLocale . '.json');
            die();
        }
    }

    private static function setLocale(): void
    {
        self::$baseLocale = self::$Locale !== LitLocale::LATIN ? \Locale::getPrimaryLanguage(self::$Locale) : LitLocale::LATIN;
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
        self::$dayOfTheWeekDayMonthYear   = \IntlDateFormatter::create(
            self::$Locale,
            \IntlDateFormatter::FULL,
            \IntlDateFormatter::NONE,
            'UTC',
            \IntlDateFormatter::GREGORIAN,
            "EEEE d MMMM yyyy"
        );
        self::$dayMonthYear               = \IntlDateFormatter::create(
            self::$Locale,
            \IntlDateFormatter::FULL,
            \IntlDateFormatter::NONE,
            'UTC',
            \IntlDateFormatter::GREGORIAN,
            "d MMMM yyyy"
        );
        self::$dayOfTheWeek               = \IntlDateFormatter::create(
            self::$Locale,
            \IntlDateFormatter::FULL,
            \IntlDateFormatter::NONE,
            'UTC',
            \IntlDateFormatter::GREGORIAN,
            "EEEE"
        );
    }

    private static function calculateEasterDates(): void
    {
        self::$EasterDates              = new \stdClass();
        self::$EasterDates->EasterDates = [];
        $dateLastCoincidence            = null;
        $gregDateString                 = "";
        $julianDateString               = "";
        $westernJulianDateString        = "";

        for ($i = 1583; $i <= 9999; $i++) {
            self::$EasterDates->EasterDates[$i - 1583] = new \stdClass();
            $gregorian_easter                    = LitFunc::calcGregEaster($i);
            $julian_easter                       = LitFunc::calcJulianEaster($i);
            $western_julian_easter               = LitFunc::calcJulianEaster($i, true);
            $same_easter                         = false;

            if ($gregorian_easter->format('l, F jS, Y') === $western_julian_easter->format('l, F jS, Y')) {
                $same_easter                    = true;
                $dateLastCoincidence            = $gregorian_easter;
            }

            switch (strtoupper(self::$baseLocale)) {
                case LitLocale::LATIN:
                    $month                      = (int)$gregorian_easter->format('n'); //n      = 1-January to 12-December
                    $monthLatin                 = LitMessages::LATIN_MONTHS[$month];
                    $gregDateString             = 'Dies Domini, ' . $gregorian_easter->format('j') . ' ' . $monthLatin . ' ' . $gregorian_easter->format('Y');
                    $month                      = (int)$julian_easter->format('n'); //n         = 1-January to 12-December
                    $monthLatin                 = LitMessages::LATIN_MONTHS[$month];
                    $julianDateString           = 'Dies Domini, ' . $julian_easter->format('j') . ' ' . $monthLatin . ' ' . $julian_easter->format('Y');
                    $month                      = (int)$western_julian_easter->format('n'); //n = 1-January to 12-December
                    $monthLatin                 = LitMessages::LATIN_MONTHS[$month];
                    $westernJulianDateString    = 'Dies Domini, ' . $western_julian_easter->format('j') . ' ' . $monthLatin . ' ' . $western_julian_easter->format('Y');
                    break;
                case 'EN':
                    $gregDateString             = $gregorian_easter->format('l, F jS, Y');
                    $julianDateString           = 'Sunday' . $julian_easter->format(', F jS, Y');
                    $westernJulianDateString    = $western_julian_easter->format('l, F jS, Y');
                    break;
                default:
                    $gregDateString             = self::$dayOfTheWeekDayMonthYear->format($gregorian_easter->format('U'));
                    $julianDateString           = self::$dayOfTheWeek->format($gregorian_easter->format('U'))
                                                    . ', ' . self::$dayMonthYear->format($julian_easter->format('U'));
                    $westernJulianDateString    = self::$dayOfTheWeekDayMonthYear->format($western_julian_easter->format('U'));
            }

            self::$EasterDates->EasterDates[$i - 1583]->gregorianEaster          = (int) $gregorian_easter->format('U');
            self::$EasterDates->EasterDates[$i - 1583]->julianEaster             = (int) $julian_easter->format('U');
            self::$EasterDates->EasterDates[$i - 1583]->westernJulianEaster      = (int) $western_julian_easter->format('U');
            self::$EasterDates->EasterDates[$i - 1583]->coinciding               = $same_easter;
            self::$EasterDates->EasterDates[$i - 1583]->gregorianDateString      = $gregDateString;
            self::$EasterDates->EasterDates[$i - 1583]->julianDateString         = $julianDateString;
            self::$EasterDates->EasterDates[$i - 1583]->westernJulianDateString  = $westernJulianDateString;
        }

        self::$EasterDates->lastCoincidenceString     = $dateLastCoincidence->format('l, F jS, Y');
        self::$EasterDates->lastCoincidence           = (int) $dateLastCoincidence->format('U');
    }

    private static function produceResponse(): void
    {
        if (!is_dir('engineCache/easter/')) {
            mkdir('engineCache/easter/', 0774, true);
        }
        file_put_contents('engineCache/easter/' . self::$baseLocale . '.json', json_encode(self::$EasterDates));

        header('Content-Type: application/json');
        echo json_encode(self::$EasterDates);
    }

    public static function init(): void
    {
        self::enforceAllowedMethods();
        self::handleRequestParams();
        self::serveCachedFileIfExists();
        self::setLocale();
        self::calculateEasterDates();
        self::produceResponse();
    }
}
