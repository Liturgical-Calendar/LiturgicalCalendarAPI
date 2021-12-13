<?php

error_reporting(E_ALL);
ini_set("display_errors", 1);
ini_set('date.timezone', 'Europe/Vatican');
//ini_set('intl.default_locale', strtolower($LOCALE) . '_' . $LOCALE);
$LOCALE = isset($_GET["locale"]) ? strtoupper($_GET["locale"]) : "LA"; //default to latin
setlocale(LC_TIME, strtolower($LOCALE) . '_' . $LOCALE);

include_once( 'includes/LitCalFunctions.php' );
include_once( 'includes/LitCalMessages.php' );

if(file_exists('engineCache/easter/' . $LOCALE . '.json') ){
    header('Content-Type: application/json');
    echo file_get_contents('engineCache/easter/' . $LOCALE . '.json');
    die();
}

function __($key) {
    global $messages;
    global $LOCALE;
    $lcl = strtolower($LOCALE);
    if (isset($messages)) {
        if (isset($messages[$key])) {
            if (isset($messages[$key][$lcl])) {
                return $messages[$key][$lcl];
            } else {
                return $messages[$key]["en"];
            }
        } else {
            return $key;
        }
    } else {
        return $key;
    }
}

function _e($key) {
    echo __($key);
}

$messages = [
    'Date of Easter from 1583 to 9999' => [
        "en" => "Date of Easter from 1583 to 9999",
        "it" => "Data della Pasqua dal 1583 al 9999",
        "la" => "Diem Paschae a MDLXXXIII ad I&#773;X&#773;CMXCIX"
    ],
    "Go back" => [
        "en" => "Go back",
        "it" => "Torna indietro",
        "la" => "Reverte"
    ],
    "Easter Day Calculation in PHP (Years in which Julian and Gregorian easter coincide are marked in yellow)" => [
        "en" => "Easter Day Calculation in PHP (Years in which Julian and Gregorian easter coincide are marked in yellow)",
        "it" => "Calcolo del Giorno della Pasqua usando PHP (marcati in giallo gli anni nei quali coincide la pasqua giuliana con quella gregoriana)",
        "la" => "Computatio Diei Paschae cum PHP (notati sunt in flavo anni quibus coincidit Pascha gregoriana cum Pascha Iuliana)"
    ],
    "Note how they gradually drift further apart, then from the year 2698 there is a gap until 4102 (1404 years); again a gap from 4197 to 5006 (809 years); from 5096 to 5902 (806 years); after 6095 there are no more coincidences until the end of the calculable year 9999" => [
        "en" => "Note how they gradually drift further apart, then from the year 2698 there is a gap until 4102 (1404 years); again a gap from 4197 to 5006 (809 years); from 5096 to 5902 (806 years); after 6095 there are no more coincidences until the end of the calculable year 9999",
        "it" => "Da notare il graduale distanziamento, poi dall'anno 2698 c'è un vuoto fino al 4102 (1404 anni); di nuovo un vuoto dal 4197 al 5006 (809 anni); dal 5096 al 5902 (806 anni); dopo il 6095 non ci sono più coincidenze registrate fino all'ultimo anno calcolabile 9999",
        "la" => "Nota intervallum crescente, post annum 2698 vacuum est usque ad anno 4102 (anni 1404); rursus vacuum est post annum 4197 usque ad anno 5006 (anni 809); post annum 5096 usque ad anno 5902 (anni 806); post annum 6095 non accidunt usque ad finem calendarii computabilis in anno 9999"
    ],
    "Gregorian Easter" => [
        "en" => "Gregorian Easter",
        "it" => "Pasqua Gregoriana",
        "la" => "Pascha Gregoriana"
    ],
    "Julian Easter" => [
        "en" => "Julian Easter",
        "it" => "Pasqua Giuliana",
        "la" => "Pascha Iuliana"
    ],
    "Julian Easter in Gregorian Calendar" => [
        "en" => "Julian Easter in Gregorian Calendar",
        "it" => "Pasqua Giuliana nel Calendario Gregoriano",
        "la" => "Pascha Iuliana in Calendario Gregoriano"
    ],
    "Century" => [
        "en" => "Century",
        "it" => "Secolo",
        "la" => "Saeculum"
    ],
    "Sunday" => [
        "en" => "Sunday",
        "it" => "Domenica",
        "la" => "Dies Domini"
    ]
];


$EasterDates                = new stdClass();
$EasterDates->DatesArray    = [];
$last_coincidence           = "";
$dateLastCoincidence        = null;

for($i=1583;$i<=9999;$i++){
    $EasterDates->DatesArray[$i-1583]   = new stdClass();
    $gregorian_easter                   = LitCalFf::calcGregEaster( $i ); 
    $julian_easter                      = LitCalFf::calcJulianEaster( $i );
    $western_julian_easter              = LitCalFf::calcJulianEaster( $i, true );
    $same_easter                        = false;

    if($gregorian_easter->format( 'l, F jS, Y' ) === $western_julian_easter->format( 'l, F jS, Y' ) ) {
        $same_easter                    = true;
        $last_coincidence               = $gregorian_easter->format( 'l, F jS, Y' );
        $dateLastCoincidence            = $gregorian_easter;
    }

    $gregDateString                     = "";
    $julianDateString                   = "";
    $westernJulianDateString            = "";
    switch ($LOCALE) {
        case "LA":
            $month                      = (int)$gregorian_easter->format('n'); //n      = 1-January to 12-December
            $monthLatin                 = LITCAL_MESSAGES::LATIN_MONTHS[$month];
            $gregDateString             = 'Dies Domini, ' . $gregorian_easter->format('j') . ' ' . $monthLatin . ' ' . $gregorian_easter->format('Y');
            $month                      = (int)$julian_easter->format('n'); //n         = 1-January to 12-December
            $monthLatin                 = LITCAL_MESSAGES::LATIN_MONTHS[$month];
            $julianDateString           = 'Dies Domini, ' . $julian_easter->format('j') . ' ' . $monthLatin . ' ' . $julian_easter->format('Y');
            $month                      = (int)$western_julian_easter->format('n'); //n = 1-January to 12-December
            $monthLatin                 = LITCAL_MESSAGES::LATIN_MONTHS[$month];
            $westernJulianDateString    = 'Dies Domini, ' . $western_julian_easter->format('j') . ' ' . $monthLatin . ' ' . $western_julian_easter->format('Y');
            break;
        case "EN":
            $gregDateString             = $gregorian_easter->format('l, F jS, Y');
            $julianDateString           = __('Sunday') . $julian_easter->format(', F jS, Y');
            $westernJulianDateString    = $western_julian_easter->format('l, F jS, Y');
            break;
        default:
            $gregDateString             = utf8_encode(strftime('%A %e %B %Y', $gregorian_easter->format('U')));
            $julianDateString           = strtolower(__('Sunday')) . utf8_encode(strftime(' %e %B %Y', $julian_easter->format('U')));
            $westernJulianDateString    = utf8_encode(strftime('%A %e %B %Y', $western_julian_easter->format('U')));
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