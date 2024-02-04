<?php

error_reporting(E_ALL);
ini_set("display_errors", 1);
ini_set('date.timezone', 'Europe/Vatican');

include_once( 'includes/enums/LitLocale.php' );
$LOCALE = isset($_GET["locale"]) && LitLocale::isValid($_GET["locale"]) ? $_GET["locale"] : LitLocale::LATIN;

if(file_exists('engineCache/easter/' . $LOCALE . '.json') ){
    header('Content-Type: application/json');
    echo file_get_contents('engineCache/easter/' . $LOCALE . '.json');
    die();
}

include_once( 'includes/LitFunc.php' );
include_once( 'includes/LitMessages.php' );

$baseLocale = Locale::getPrimaryLanguage($LOCALE);
$localeArray = [
    $LOCALE . '.utf8',
    $LOCALE . '.UTF-8',
    $LOCALE,
    $baseLocale . '_' . strtoupper( $baseLocale ) . '.utf8',
    $baseLocale . '_' . strtoupper( $baseLocale ) . '.UTF-8',
    $baseLocale . '_' . strtoupper( $baseLocale ),
    $baseLocale . '.utf8',
    $baseLocale . '.UTF-8',
    $baseLocale
];
setlocale( LC_ALL, $localeArray );
$dayOfTheWeekDayMonthYear   = IntlDateFormatter::create( $LOCALE, IntlDateFormatter::FULL, IntlDateFormatter::NONE, 'UTC', IntlDateFormatter::GREGORIAN, "EEEE d MMMM yyyy" );
$dayMonthYear               = IntlDateFormatter::create( $LOCALE, IntlDateFormatter::FULL, IntlDateFormatter::NONE, 'UTC', IntlDateFormatter::GREGORIAN, "d MMMM yyyy" );
$dayOfTheWeek               = IntlDateFormatter::create( $LOCALE, IntlDateFormatter::FULL, IntlDateFormatter::NONE, 'UTC', IntlDateFormatter::GREGORIAN, "EEEE" );

$EasterDates                = new stdClass();
$EasterDates->DatesArray    = [];
$last_coincidence           = "";
$dateLastCoincidence        = null;

for($i=1583;$i<=9999;$i++){
    $EasterDates->DatesArray[$i-1583]   = new stdClass();
    $gregorian_easter                   = LitFunc::calcGregEaster( $i ); 
    $julian_easter                      = LitFunc::calcJulianEaster( $i );
    $western_julian_easter              = LitFunc::calcJulianEaster( $i, true );
    $same_easter                        = false;

    if($gregorian_easter->format( 'l, F jS, Y' ) === $western_julian_easter->format( 'l, F jS, Y' ) ) {
        $same_easter                    = true;
        $last_coincidence               = $gregorian_easter->format( 'l, F jS, Y' );
        $dateLastCoincidence            = $gregorian_easter;
    }

    $gregDateString                     = "";
    $julianDateString                   = "";
    $westernJulianDateString            = "";
    switch (strtoupper($baseLocale)) {
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
            $gregDateString             = $dayOfTheWeekDayMonthYear->format( $gregorian_easter->format('U') );
            $julianDateString           = $dayOfTheWeek->format( $gregorian_easter->format('U') ) . ', ' . $dayMonthYear->format( $julian_easter->format('U') );
            $westernJulianDateString    = $dayOfTheWeekDayMonthYear->format( $western_julian_easter->format('U') );
    }

    $EasterDates->DatesArray[$i-1583]->gregorianEaster          = $gregorian_easter->format('U');
    $EasterDates->DatesArray[$i-1583]->julianEaster             = $julian_easter->format('U');
    $EasterDates->DatesArray[$i-1583]->westernJulianEaster      = $western_julian_easter->format('U');
    $EasterDates->DatesArray[$i-1583]->coinciding               = $same_easter;
    $EasterDates->DatesArray[$i-1583]->gregorianDateString      = $gregDateString;
    $EasterDates->DatesArray[$i-1583]->julianDateString         = $julianDateString;
    $EasterDates->DatesArray[$i-1583]->westernJulianDateString  = $westernJulianDateString;

}

$EasterDates->lastCoincidenceString     = $dateLastCoincidence->format( 'l, F jS, Y' );
$EasterDates->lastCoincidence           = $dateLastCoincidence->format( 'U' );

if ( !is_dir( 'engineCache/easter/' ) ) {
    mkdir( 'engineCache/easter/', 0774, true );
}
file_put_contents( 'engineCache/easter/' . $LOCALE . '.json', json_encode( $EasterDates ) );

header('Content-Type: application/json');
echo json_encode($EasterDates);
die();
?>
