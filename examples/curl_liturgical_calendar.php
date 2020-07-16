<?php

/**
 * Liturgical Calendar display script using CURL and PHP
 * Author: John Romano D'Orazio 
 * Email: priest@johnromanodorazio.com
 * Licensed under the Apache 2.0 License
 * Version 2.3
 * Date Created: 27 December 2017
 */

/**************************
 * DEFINE USEFUL FUNCTIONS
 * AND ARRAYS
 * 
 *************************/


function countSameDayEvents($currentKeyIndex, $EventsArray, &$cc)
{
    $Keys = array_keys($EventsArray);
    $currentFestivity = $EventsArray[$Keys[$currentKeyIndex]];
    if ($currentKeyIndex < count($Keys) - 1) {
        $nextFestivity = $EventsArray[$Keys[$currentKeyIndex + 1]];
        if ($nextFestivity->date == $currentFestivity->date) {
            $cc++;
            countSameDayEvents($currentKeyIndex + 1, $EventsArray, $cc);
        }
    }
}

/** 
 *  CLASS FESTIVITY
 *  SIMILAR TO THE CLASS USED IN THE LITCAL PHP ENGINE, 
 *  EXCEPT THAT IT CONVERTS PHP TIMESTAMP TO DATETIME OBJECT 
 *  AND DOES NOT IMPLEMENT JSONSERIALIZABLE OR COMPARATOR FUNCTION
 **/
class Festivity
{
    /**
     * @var string
     */
    public $name;

    /**
     * @var DateTime object
     */
    public $date;

    /**
     * @var string
     */
    public $color;

    /**
     * @var string
     */
    public $type;

    /**
     * @var int
     */
    public $grade;

    /**
     * @var string
     */
    public $common;

    /**
     * @var int
     */
    //public $hourOffset;

    function __construct($name, $date, $color, $type, $grade = 0, $common = '')
    {
        $this->name = (string) $name;
        $this->date = (object) DateTime::createFromFormat('U', $date, new DateTimeZone('UTC')); //
        //$hour = (int) $this->date->format('G');
        //if($hour !== 0){
        //    $this->hourOffset = $this->date->format('O'); //explode(':',
            //$this->date->add(new DateInterval("PT{$hourOffset}H"));
        //}
        //$this->date->setTime(0, 0);
        $this->color = (string) $color;
        $this->type = (string) $type;
        $this->grade = (int) $grade;
        $this->common = (string) $common;
    }
}


/**
 * StatusCodes provides named constants for
 * HTTP protocol status codes. Written for the
 * Recess Framework (http://www.recessframework.com/)
 *
 * @author Kris Jordan
 * @license MIT
 * @package recess.http
 */
class StatusCodes
{

    // [Informational 1xx]
    const HTTP_CONTINUE                        = 100;
    const HTTP_SWITCHING_PROTOCOLS             = 101;

    // [Successful 2xx]
    const HTTP_OK                              = 200;
    const HTTP_CREATED                         = 201;
    const HTTP_ACCEPTED                        = 202;
    const HTTP_NONAUTHORITATIVE_INFORMATION    = 203;
    const HTTP_NO_CONTENT                      = 204;
    const HTTP_RESET_CONTENT                   = 205;
    const HTTP_PARTIAL_CONTENT                 = 206;

    // [Redirection 3xx]
    const HTTP_MULTIPLE_CHOICES                = 300;
    const HTTP_MOVED_PERMANENTLY               = 301;
    const HTTP_FOUND                           = 302;
    const HTTP_SEE_OTHER                       = 303;
    const HTTP_NOT_MODIFIED                    = 304;
    const HTTP_USE_PROXY                       = 305;
    const HTTP_UNUSED                          = 306;
    const HTTP_TEMPORARY_REDIRECT              = 307;

    // [Client Error 4xx]
    const errorCodesBeginAt                    = 400;
    const HTTP_BAD_REQUEST                     = 400;
    const HTTP_UNAUTHORIZED                    = 401;
    const HTTP_PAYMENT_REQUIRED                = 402;
    const HTTP_FORBIDDEN                       = 403;
    const HTTP_NOT_FOUND                       = 404;
    const HTTP_METHOD_NOT_ALLOWED              = 405;
    const HTTP_NOT_ACCEPTABLE                  = 406;
    const HTTP_PROXY_AUTHENTICATION_REQUIRED   = 407;
    const HTTP_REQUEST_TIMEOUT                 = 408;
    const HTTP_CONFLICT                        = 409;
    const HTTP_GONE                            = 410;
    const HTTP_LENGTH_REQUIRED                 = 411;
    const HTTP_PRECONDITION_FAILED             = 412;
    const HTTP_REQUEST_ENTITY_TOO_LARGE        = 413;
    const HTTP_REQUEST_URI_TOO_LONG            = 414;
    const HTTP_UNSUPPORTED_MEDIA_TYPE          = 415;
    const HTTP_REQUESTED_RANGE_NOT_SATISFIABLE = 416;
    const HTTP_EXPECTATION_FAILED              = 417;

    // [Server Error 5xx]
    const HTTP_INTERNAL_SERVER_ERROR           = 500;
    const HTTP_NOT_IMPLEMENTED                 = 501;
    const HTTP_BAD_GATEWAY                     = 502;
    const HTTP_SERVICE_UNAVAILABLE             = 503;
    const HTTP_GATEWAY_TIMEOUT                 = 504;
    const HTTP_VERSION_NOT_SUPPORTED           = 505;

    private static $messages = [
        // [Informational 1xx]
        100 => '100 Continue',
        101 => '101 Switching Protocols',

        // [Successful 2xx]
        200 => '200 OK',
        201 => '201 Created',
        202 => '202 Accepted',
        203 => '203 Non-Authoritative Information',
        204 => '204 No Content',
        205 => '205 Reset Content',
        206 => '206 Partial Content',

        // [Redirection 3xx]
        300 => '300 Multiple Choices',
        301 => '301 Moved Permanently',
        302 => '302 Found',
        303 => '303 See Other',
        304 => '304 Not Modified',
        305 => '305 Use Proxy',
        306 => '306 (Unused)',
        307 => '307 Temporary Redirect',

        // [Client Error 4xx]
        400 => '400 Bad Request',
        401 => '401 Unauthorized',
        402 => '402 Payment Required',
        403 => '403 Forbidden',
        404 => '404 Not Found',
        405 => '405 Method Not Allowed',
        406 => '406 Not Acceptable',
        407 => '407 Proxy Authentication Required',
        408 => '408 Request Timeout',
        409 => '409 Conflict',
        410 => '410 Gone',
        411 => '411 Length Required',
        412 => '412 Precondition Failed',
        413 => '413 Request Entity Too Large',
        414 => '414 Request-URI Too Long',
        415 => '415 Unsupported Media Type',
        416 => '416 Requested Range Not Satisfiable',
        417 => '417 Expectation Failed',

        // [Server Error 5xx]
        500 => '500 Internal Server Error',
        501 => '501 Not Implemented',
        502 => '502 Bad Gateway',
        503 => '503 Service Unavailable',
        504 => '504 Gateway Timeout',
        505 => '505 HTTP Version Not Supported'
    ];

    public static function httpHeaderFor($code)
    {
        return 'HTTP/1.1 ' . self::$messages[$code];
    }


    public static function getMessageForCode($code)
    {
        return self::$messages[$code];
    }

    public static function isError($code)
    {
        return is_numeric($code) && $code >= self::HTTP_BAD_REQUEST;
    }

    public static function canHaveBody($code)
    {
        return
            // True if not in 100s
            ($code < self::HTTP_CONTINUE || $code >= self::HTTP_OK)
            && // and not 204 NO CONTENT
            $code != self::HTTP_NO_CONTENT
            && // and not 304 NOT MODIFIED
            $code != self::HTTP_NOT_MODIFIED;
    }
}


$YEAR = (isset($_GET["year"]) && is_numeric($_GET["year"]) && ctype_digit($_GET["year"]) && strlen($_GET["year"]) === 4) ? (int)$_GET["year"] : (int)date("Y");

$EPIPHANY = (isset($_GET["epiphany"]) && ($_GET["epiphany"] === "JAN6" || $_GET["epiphany"] === "SUNDAY_JAN2_JAN8")) ? $_GET["epiphany"] : "JAN6";
$ASCENSION = (isset($_GET["ascension"]) && ($_GET["ascension"] === "THURSDAY" || $_GET["ascension"] === "SUNDAY")) ? $_GET["ascension"] : "SUNDAY";
$CORPUSCHRISTI = (isset($_GET["corpuschristi"]) && ($_GET["corpuschristi"] === "THURSDAY" || $_GET["corpuschristi"] === "SUNDAY")) ? $_GET["corpuschristi"] : "SUNDAY";
$LOCALE = isset($_GET["locale"]) ? strtoupper($_GET["locale"]) : "LA"; //default to latin
ini_set('date.timezone', 'Europe/Vatican');
//ini_set('intl.default_locale', strtolower($LOCALE) . '_' . $LOCALE);
setlocale(LC_TIME, strtolower($LOCALE) . '_' . $LOCALE);

define("EPIPHANY", $EPIPHANY);
//define(EPIPHANY,"SUNDAY_JAN2_JAN8");
//define(EPIPHANY,"JAN6");

define("ASCENSION", $ASCENSION);
//define(ASCENSION,"THURSDAY");
//define(ASCENSION,"SUNDAY");

define("CORPUSCHRISTI", $CORPUSCHRISTI);
//define(CORPUSCHRISTI,"THURSDAY");
//define(CORPUSCHRISTI,"SUNDAY");

// 				I.
define("HIGHERSOLEMNITY", 7);        // HIGHER RANKING SOLEMNITIES, THAT HAVE PRECEDENCE OVER ALL OTHERS:
// 1. EASTER TRIDUUM
// 2. CHRISTMAS, EPIPHANY, ASCENSION, PENTECOST
//    SUNDAYS OF ADVENT, LENT AND EASTER
//    ASH WEDNESDAY
//    DAYS OF THE HOLY WEEK, FROM MONDAY TO THURSDAY
//    DAYS OF THE OCTAVE OF EASTER

define("SOLEMNITY", 6);            // 3. SOLEMNITIES OF THE LORD, OF THE BLESSED VIRGIN MARY, OF THE SAINTS LISTED IN THE GENERAL CALENDAR
//    COMMEMORATION OF THE FAITHFUL DEPARTED
// 4. PARTICULAR SOLEMNITIES:	
//		a) PATRON OF THE PLACE, OF THE COUNTRY OR OF THE CITY (CELEBRATION REQUIRED ALSO FOR RELIGIOUS COMMUNITIES);
//		b) SOLEMNITY OF THE DEDICATION AND OF THE ANNIVERSARY OF THE DEDICATION OF A CHURCH
//		c) SOLEMNITY OF THE TITLE OF A CHURCH
//		d) SOLEMNITY OF THE TITLE OR OF THE FOUNDER OR OF THE MAIN PATRON OF AN ORDER OR OF A CONGREGATION

// 				II.    								
define("FEASTLORD", 5);            // 5. FEASTS OF THE LORD LISTED IN THE GENERAL CALENDAR
// 6. SUNDAYS OF CHRISTMAS AND OF ORDINARY TIME
define("FEAST", 4);                // 7. FEASTS OF THE BLESSED VIRGIN MARY AND OF THE SAINTS IN THE GENERAL CALENDAR
// 8. PARTICULAR FEASTS:	
//		a) MAIN PATRON OF THE DIOCESE
//		b) FEAST OF THE ANNIVERSARY OF THE DEDICATION OF THE CATHEDRAL
//		c) FEAST OF THE MAIN PATRON OF THE REGION OR OF THE PROVINCE, OF THE NATION, OF A LARGER TERRITORY
//		d) FEAST OF THE TITLE, OF THE FOUNDER, OF THE MAIN PATRON OF AN ORDER OR OF A CONGREGATION AND OF A RELIGIOUS PROVINCE
//		e) OTHER PARTICULAR FEASTS OF SOME CHURCH
//		f) OTHER FEASTS LISTED IN THE CALENDAR OF EACH DIOCESE, ORDER OR CONGREGATION
// 9. WEEKDAYS OF ADVENT FROM THE 17th TO THE 24th OF DECEMBER
//    DAYS OF THE OCTAVE OF CHRISTMAS
//    WEEKDAYS OF LENT 

// 				III.    								
define("MEMORIAL", 3);            // 10. MEMORIALS OF THE GENERAL CALENDAR
// 11. PARTICULAR MEMORIALS:	
//		a) MEMORIALS OF THE SECONDARY PATRON OF A PLACE, OF A DIOCESE, OF A REGION OR A RELIGIOUS PROVINCE
//		b) OTHER MEMORIALS LISTED IN THE CALENDAR OF EACH DIOCESE, ORDER OR CONGREGATION
define("MEMORIALOPT", 2);            // 12. OPTIONAL MEMORIALS, WHICH CAN HOWEVER BE OBSERVED IN DAYS INDICATED AT N. 9, 
//     ACCORDING TO THE NORMS DESCRIBED IN "PRINCIPLES AND NORMS" FOR THE LITURGY OF THE HOURS AND THE USE OF THE MISSAL

define("COMMEMORATION", 1);            //     SIMILARLY MEMORIALS CAN BE OBSERVED AS OPTIONAL MEMORIALS THAT SHOULD FALL DURING THE WEEKDAYS OF LENT

define("WEEKDAY", 0);            // 13. WEEKDAYS OF ADVENT UNTIL DECEMBER 16th
//     WEEKDAYS OF CHRISTMAS, FROM JANUARY 2nd UNTIL THE SATURDAY AFTER EPIPHANY
//     WEEKDAYS OF THE EASTER SEASON, FROM THE MONDAY AFTER THE OCTAVE OF EASTER UNTIL THE SATURDAY BEFORE PENTECOST
//     WEEKDAYS OF ORDINARY TIME

//TODO: implement interface for adding Proper feasts and memorials...

$GRADE = ["", "COMMEMORATION", "OPTIONAL MEMORIAL", "MEMORIAL", "FEAST", "FEAST OF THE LORD", "SOLEMNITY", "HIGHER RANKING SOLEMNITY"];

$SUNDAY_CYCLE = ["A", "B", "C"];
$WEEKDAY_CYCLE = ["I", "II"];

if ($YEAR >= 1970) {
    //  Initiate curl for communication with the LitCal server
    $ch = curl_init();

    $prefix = $_SERVER['HTTPS'] ? 'https://' : 'http://';
    $domain = $_SERVER['HTTP_HOST'];
    $query = $_SERVER['PHP_SELF'];
    $path_info = pathinfo($query);
    $URL =  $prefix . $domain . "/" . dirname($path_info['dirname']) . "/LitCalEngine.php";

    // Disable SSL verification
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    // Will return the response, if false it print the response
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    // Set the url
    curl_setopt($ch, CURLOPT_URL, $URL);
    // Set request method to POST
    curl_setopt($ch, CURLOPT_POST, 1);
    // Define the POST field data    
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(["year" => $YEAR, "epiphany" => $EPIPHANY, "ascension" => $ASCENSION, "corpuschristi" => $CORPUSCHRISTI, "locale" => $LOCALE]));
    // Execute
    $result = curl_exec($ch);

    if (curl_errno($ch)) {
        // this would be your first hint that something went wrong
        die("Could not send request. Curl error: " . curl_error($ch));
    } else {
        // check the HTTP status code of the request
        $resultStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($resultStatus != 200) {
            // the request did not complete as expected. common errors are 4xx
            // (not found, bad request, etc.) and 5xx (usually concerning
            // errors/exceptions in the remote script execution)

            die("Request failed. HTTP status code: " . StatusCodes::getMessageForCode($resultStatus));
        }
    }

    // Closing
    curl_close($ch);


    // Gather the json results from the server into $LitCal array similar to the PHP Engine
    $LitCal = array();

    $LitCalData = json_decode($result, true); // decode as associative array rather than stdClass object
    if (isset($LitCalData["Settings"])) {
        $YEAR = $LitCalData["Settings"]["YEAR"];
    }
    if (isset($LitCalData["LitCal"])) {
        $LitCal = $LitCalData["LitCal"];
    } else {
        die("We do not have enough information. Returned data has no LitCal property:" . var_dump($LitCalData));
    }

    foreach ($LitCal as $key => $value) {
        // retransform each entry from an associative array to a Festivity class object
        $LitCal[$key] = new Festivity($LitCal[$key]["name"], $LitCal[$key]["date"], $LitCal[$key]["color"], $LitCal[$key]["type"], $LitCal[$key]["grade"], $LitCal[$key]["common"]);
    }
}

$messages = [
    "Generate Roman Calendar" => [
        "en" => "Generate Roman Calendar",
        "it" => "Genera Calendario Romano",
        "la" => "Calendarium Romanum Generare"
    ],
    "Liturgical Calendar Calculation for a Given Year" => [
        "en" => "Liturgical Calendar Calculation for a Given Year",
        "it" => "Calcolo del Calendario Liturgico per un dato anno",
        "la" => "Computus Calendarii Liturgici pro anno dedi"
    ],
    "HTML presentation elaborated by PHP using a CURL request to a %s" => [
        "en" => "HTML presentation elaborated by PHP using a CURL request to a %s",
        "it" => "Presentazione HTML elaborata con PHP usando una richiesta CURL al motore PHP %s",
        "la" => "Repraesentatio HTML elaborata cum PHP utendo petitionem CURL ad machinam PHP %s"
    ],
    "You are requesting a year prior to 1970: it is not possible to request years prior to 1970." => [
        "en" => "You are requesting a year prior to 1970: it is not possible to request years prior to 1970.",
        "it" => "Stai effettuando una richiesta per un anno che è precedente al 1970: non è possibile richiedere anni precedenti al 1970.",
        "la" => "Rogavisti annum ante 1970: non potest rogare annos ante annum 1970."
    ],
    "Customize options for generating the Roman Calendar" => [
        "en" => "Customize options for generating the Roman Calendar",
        "it" => "Personalizzare le opzioni per la generazione del Calendario Romano",
        "la" => "Eligere optiones per generationem Calendarii Romani"
    ],
    "Configurations being used to generate this calendar:" => [
        "en" => "Configurations being used to generate this calendar:",
        "it" => "Configurazioni utilizzate per la generazione di questo calendario:",
        "la" => "Optiones electuus ut generare hic calendarium:"
    ],
    "Date in Gregorian Calendar" => [
        "en" => "Date in Gregorian Calendar",
        "it" => "Data nel Calendario Gregoriano",
        "la" => "Dies in Calendario Gregoriano"
    ],
    "General Roman Calendar Festivity" => [
        "en" => "General Roman Calendar Festivity",
        "it" => "Festività nel Calendario Romano Generale",
        "la" => "Festivitas in Calendario Romano Generale"
    ],
    "Grade of the Festivity" => [
        "en" => "Grade of the Festivity",
        "it" => "Grado della Festività",
        "la" => "Gradum Festivitatis"
    ],
    "YEAR" => [
        "en" => "YEAR",
        "it" => "ANNO",
        "la" => "ANNUM"
    ],
    "EPIPHANY" => [
        "en" => "EPIPHANY",
        "it" => "EPIFANIA",
        "la" => "EPIPHANIA"
    ],
    "ASCENSION" => [
        "en" => "ASCENSION",
        "it" => "ASCENSIONE",
        "la" => "ASCENSIO",
    ],
    "From the Common" => [
        "en" => "From the Common",
        "it" => "Dal Comune",
        "la" => "De Communi"
    ],
    "of (SING_MASC)" => [
        "en" => "of",
        "it" => "del",
        "la" => ""
    ],
    "of (SING_FEMM)" => [
        "en" => "of the",
        "it" => "della",
        "la" => ""
    ],
    "of (PLUR_MASC)" => [
        "en" => "of",
        "it" => "dei",
        "la" => ""
    ],
    "of (PLUR_MASC_ALT)" => [
        "en" => "of",
        "it" => "degli",
        "la" => ""
    ],
    "of (PLUR_FEMM)" => [
        "en" => "of",
        "it" => "delle",
        "la" => ""
    ],
    /*translators: in reference to the Common of the Blessed Virgin Mary */
    "Blessed Virgin Mary" => [
        "en" => "Blessed Virgin Mary",
        "it" => "Beata Vergine Maria",
        "la" => "Beatæ Virginis Mariæ"
    ],
    "Martyrs" => [
        "en" => "Martyrs",
        "it" => "Martiri",
        "la" => "Martyrum"
    ],
    "Pastors" => [
        "en" => "Pastors",
        "it" => "Pastori",
        "la" => "Pastorum"
    ],
    "Doctors" => [
        "en" => "Doctors",
        "it" => "Dottori della Chiesa",
        "la" => "Doctorum Ecclesiae"
    ],
    "Virgins" => [
        "en" => "Virgins",
        "it" => "Vergini",
        "la" => "Virginum"
    ],
    "Holy Men and Women" => [
        "en" => "Holy Men and Women",
        "it" => "Santi e delle Sante",
        "la" => "Sanctorum et Sanctarum"
    ],
    "For One Martyr" => [
        "en" => "For One Martyr",
        "it" => "Per un martire",
        "la" => "Pro uno martyre"
    ],
    "For Several Martyrs" => [
        "en" => "For Several Martyrs",
        "it" => "Per più martiri",
        "la" => "Pro pluribus martyribus"
    ],
    "For Missionary Martyrs" => [
        "en" => "For Missionary Martyrs",
        "it" => "Per i martiri missionari",
        "la" => "Pro missionariis martyribus"
    ],
    "For a Virgin Martyr" => [
        "en" => "For a Virgin Martyr",
        "it" => "Per una vergine martire",
        "la" => "Pro virgine martyre"
    ],
    "For Several Pastors" => [
        "en" => "For Several Pastors",
        "it" => "Per i pastori",
        "la" => "Pro Pastoribus"
    ],
    "For a Pope" => [
        "en" => "For a Pope",
        "it" => "Per i papi",
        "la" => "Pro Papa"
    ],
    "For a Bishop" => [
        "en" => "For a Bishop",
        "it" => "Per i vescovi",
        "la" => "Pro Episcopo"
    ],
    "For One Pastor" => [
        "en" => "For One Pastor",
        "it" => "Per un Pastore",
        "la" => "Pro Pastoribus"
    ],
    "For Missionaries" => [
        "en" => "For Missionaries",
        "it" => "Per i missionari",
        "la" => "Pro missionariis"
    ],
    "For One Virgin" => [
        "en" => "For One Virgin",
        "it" => "Per una vergine",
        "la" => "Pro una virgine"
    ],
    "For Several Virgins" => [
        "en" => "For Several Virgins",
        "it" => "Per più vergini",
        "la" => "Pro pluribus virginibus"
    ],
    "For Religious" => [
        "en" => "For Religious",
        "it" => "Per i religiosi",
        "la" => "Pro Religiosis"
    ],
    "For Those Who Practiced Works of Mercy" => [
        "en" => "For Those Who Practiced Works of Mercy",
        "it" => "Per gli operatori di misericordia",
        "la" => "Pro iis qui opera Misericordiæ Exercuerunt"
    ],
    "For an Abbot" => [
        "en" => "For an Abbot",
        "it" => "Per un abate",
        "la" => "Pro abbate"
    ],
    "For a Monk" => [
        "en" => "For a Monk",
        "it" => "Per un monaco",
        "la" => "Pro monacho"
    ],
    "For a Nun" => [
        "en" => "For a Nun",
        "it" => "Per i religiosi",
        "la" => "Pro moniali"
    ],
    "For Educators" => [
        "en" => "For Educators",
        "it" => "Per gli educatori",
        "la" => "Pro Educatoribus"
    ],
    "For Holy Women" => [
        "en" => "For Holy Women",
        "it" => "Per le sante",
        "la" => "Pro Sanctis Mulieribus"
    ],
    "For One Saint" => [
        "en" => "For One Saint",
        "it" => "Per un Santo",
        "la" => "Pro uno Sancto"
    ],
    "or" => [
        "en" => "or",
        "it" => "oppure",
        "la" => "vel"
    ],
    "Proper" => [
        "en" => "Proper",
        "it" => "Proprio",
        "la" => "Proprium"
    ],
    "green" => [
        "en" => "green",
        "it" => "verde",
        "la" => "viridis"
    ],
    "purple" => [
        "en" => "purple",
        "it" => "viola",
        "la" => "purpura"
    ],
    "white" => [
        "en" => "white",
        "it" => "bianco",
        "la" => "albus"
    ],
    "red" => [
        "en" => "red",
        "it" => "rosso",
        "la" => "ruber"
    ],
    "pink" => [
        "en" => "pink",
        "it" => "rosa",
        "la" => "rosea"
    ]
];

$daysOfTheWeek = [
    "dies Solis",
    "dies Lunae",
    "dies Martis",
    "dies Mercurii",
    "dies Iovis",
    "dies Veneris",
    "dies Saturni"
];

$months = [
    "",
    "Ianuarius",
    "Februarius",
    "Martius",
    "Aprilis",
    "Maius",
    "Iunius",
    "Iulius",
    "Augustus",
    "September",
    "October",
    "November",
    "December"
];

/**************************
 * BEGIN DISPLAY LOGIC
 * 
 *************************/
function __($key, $locale)
{
    global $messages;
    $lcl = strtolower($locale);
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


?>
<!doctype html>

<head>
    <title><?php echo __("Generate Roman Calendar", $LOCALE) ?></title>
    <meta charset="UTF-8">
    <!-- <link rel="icon" type="image/x-icon" href="../favicon.ico"> -->
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.2.1/jquery.min.js"></script>
</head>

<body>

    <?php

    echo '<h1 style="text-align:center;">' . __("Liturgical Calendar Calculation for a Given Year", $LOCALE) . ' (' . $YEAR . ')</h1>';
    echo '<h2 style="text-align:center;">' . sprintf(__("HTML presentation elaborated by PHP using a CURL request to a %s", $LOCALE), '<a href="../LitCalEngine.php">PHP engine</a>') . '</h2>';

    if ($YEAR < 1970) {
        echo '<div style="text-align:center;border:3px ridge Green;background-color:LightBlue;width:75%;margin:10px auto;padding:10px;">';
        echo __('You are requesting a year prior to 1970: it is not possible to request years prior to 1970.', $LOCALE);
        echo '</div>';
    }


    echo '<fieldset style="margin-bottom:6px;"><legend>' . __('Customize options for generating the Roman Calendar',$LOCALE) . '</legend>';
    echo '<form method="GET">';
    echo '<table style="width:100%;"><tr>';
    echo '<td><label>' . __('YEAR', $LOCALE) . ': <input type="number" name="year" id="year" min="1969" value="' . $YEAR . '" /></label></td>';
    echo '<td><label>' . __('EPIPHANY', $LOCALE) . ': <select name="epiphany" id="epiphany"><option value="JAN6" ' . (EPIPHANY === "JAN6" ? " SELECTED" : "") . '>January 6</option><option value="SUNDAY_JAN2_JAN8" ' . (EPIPHANY === "SUNDAY_JAN2_JAN8" ? " SELECTED" : "") . '>Sunday between January 2 and January 8</option></select></label></td>';
    echo '<td><label>' . __('ASCENSION', $LOCALE) . ': <select name="ascension" id="ascension"><option value="THURSDAY" ' . (ASCENSION === "THURSDAY" ? " SELECTED" : "") . '>Thursday</option><option value="SUNDAY" ' . (ASCENSION === "SUNDAY" ? " SELECTED" : "") . '>Sunday</option></select></label></td>';
    echo '<td><label>CORPUS CHRISTI (CORPUS DOMINI): <select name="corpuschristi" id="corpuschristi"><option value="THURSDAY" ' . (CORPUSCHRISTI === "THURSDAY" ? " SELECTED" : "") . '>Thursday</option><option value="SUNDAY" ' . (CORPUSCHRISTI === "SUNDAY" ? " SELECTED" : "") . '>Sunday</option></select></label></td>';
    echo '<td><label>LOCALE: <select name="locale" id="locale"><option value="EN" ' . ($LOCALE === "EN" ? " SELECTED" : "") . '>EN</option><option value="IT" ' . ($LOCALE === "IT" ? " SELECTED" : "") . '>IT</option><option value="LA" ' . ($LOCALE === "LA" ? " SELECTED" : "") . '>LA</option></select></label></td>';
    echo '</tr><tr>';
    echo '<td colspan="5" style="text-align:center;"><input type="SUBMIT" value="GENERATE CALENDAR" /></td>';
    echo '</tr></table>';
    echo '</form>';
    echo '</fieldset>';

    echo '<div style="text-align:center;border:2px groove White;border-radius:6px;width:60%;margin:0px auto;padding-bottom:6px;">';

    echo '<h3>' . __('Configurations being used to generate this calendar:', $LOCALE) . '</h3>';
    echo '<span>' . __('YEAR', $LOCALE) . ' = ' . $YEAR . ', ' . __('EPIPHANY', $LOCALE) . ' = ' . EPIPHANY . ', ' . __('ASCENSION', $LOCALE) . ' = ' . ASCENSION . ', CORPUS CHRISTI = ' . CORPUSCHRISTI . ', LOCALE = ' . $LOCALE . '</span>';

    echo '</div>';

    if ($YEAR >= 1970) {
        echo '<table id="LitCalTable" style="width:75%;margin:30px auto;border:1px solid Blue;border-radius: 6px; padding:10px;background:LightBlue;">';
        echo '<thead><tr><th>' . __("Date in Gregorian Calendar", $LOCALE) . '</th><th>' . __("General Roman Calendar Festivity", $LOCALE) . '</th><th>' . __("Grade of the Festivity", $LOCALE) . '</th></tr></thead>';
        echo '<tbody>';


        $dayCnt = 0;
        //for($i=1997;$i<=2037;$i++){
        $highContrast = ['purple', 'red', 'green'];

        $LitCalKeys = array_keys($LitCal);
        //print_r($LitCalKeys);
        //echo count($LitCalKeys);
        for ($keyindex = 0; $keyindex < count($LitCalKeys); $keyindex++) {
            $dayCnt++;
            $keyname = $LitCalKeys[$keyindex];
            $festivity = $LitCal[$keyname];

            //LET'S CALCULATE THE LITURGICAL YEAR CYCLE
            $currentCycle = '';
            //if we're dealing with a Sunday or a Solemnity or a Feast of the Lord, then we calculate the Sunday/Festive Cycle
            if ((int)$festivity->date->format('N') === 7 || (int)$festivity->grade > FEAST) {
                if ($festivity->date < $LitCal["Advent1"]->date) { //$festivity->date >= DateTime::createFromFormat('!j-n-Y', '1-1-'.$YEAR) && 
                    $currentCycle = __("YEAR", $LOCALE) . " " . ($SUNDAY_CYCLE[($YEAR - 1) % 3]);
                } else if ($festivity->date >= $LitCal["Advent1"]->date) { // && $festivity->date <= DateTime::createFromFormat('!j-n-Y', '31-12-'.$YEAR)
                    $currentCycle = __("YEAR", $LOCALE) . " " . ($SUNDAY_CYCLE[$YEAR % 3]);
                }
            }
            //otherwise we calculate the weekday cycle
            else {
                if ($festivity->date < $LitCal["Advent1"]->date) { //$festivity->date >= DateTime::createFromFormat('!j-n-Y', '1-1-'.$YEAR) && 
                    $currentCycle = __("YEAR", $LOCALE) . " " . ($WEEKDAY_CYCLE[($YEAR - 1) % 2]);
                } else if ($festivity->date >= $LitCal["Advent1"]->date) { // && $festivity->date <= DateTime::createFromFormat('!j-n-Y', '31-12-'.$YEAR)
                    $currentCycle = __("YEAR", $LOCALE) . " " . ($WEEKDAY_CYCLE[$YEAR % 2]);
                }
            }


            //Let's check if we have more than one event on the same day, such as optional memorials...
            $cc = 0;
            countSameDayEvents($keyindex, $LitCal, $cc);
            if ($cc > 0) {

                for ($ev = 0; $ev <= $cc; $ev++) {
                    $keyname = $LitCalKeys[$keyindex];
                    $festivity = $LitCal[$keyname];
                    // LET'S DO SOME MORE MANIPULATION ON THE FESTIVITY->COMMON STRINGS AND THE FESTIVITY->COLOR...
                    if ($festivity->common !== "" && $festivity->common !== "Proper") {
                        $commons = explode("|", $festivity->common);
                        $commons = array_map(function ($txt) {
                            global $LOCALE;
                            $common = explode(":", $txt);
                            $commonGeneral = __($common[0], $LOCALE);
                            $commonSpecific = isset($common[1]) && $common[1] != "" ? __($common[1], $LOCALE) : "";
                            //$txt = str_replace(":", ": ", $txt);
                            switch ($commonGeneral) {
                                case __("Blessed Virgin Mary", $LOCALE):
                                    $commonKey = "of (SING_FEMM)";
                                    break;
                                case __("Virgins", $LOCALE):
                                    $commonKey = "of (PLUR_FEMM)";
                                    break;
                                case __("Martyrs", $LOCALE):
                                case __("Pastors", $LOCALE):
                                case __("Doctors", $LOCALE):
                                case __("Holy Men and Women", $LOCALE):
                                    $commonKey = "of (PLUR_MASC)";
                                    break;
                                default:
                                    $commonKey = "of (SING_MASC)";
                            }
                            return __("From the Common", $LOCALE) . " " . __($commonKey, $LOCALE) . " " . $commonGeneral . ($commonSpecific != "" ? ": " . $commonSpecific : "");
                        }, $commons);
                        $festivity->common = implode("; " . __("or", $LOCALE) . " ", $commons);
                    } else if ($festivity->common == "Proper") {
                        $festivity->common = __("Proper", $LOCALE);
                    }
                    $festivity->color = explode("|", $festivity->color)[0];

                    //check which liturgical season we are in, to use the right color for that season...
                    $color = "green";
                    if (($festivity->date > $LitCal["Advent1"]->date  && $festivity->date < $LitCal["Christmas"]->date) || ($festivity->date > $LitCal["AshWednesday"]->date && $festivity->date < $LitCal["Easter"]->date)) {
                        $color = "purple";
                    } else if ($festivity->date > $LitCal["Easter"]->date && $festivity->date < $LitCal["Pentecost"]->date) {
                        $color = "white";
                    } else if ($festivity->date > $LitCal["Christmas"]->date || $festivity->date < $LitCal["BaptismLord"]->date) {
                        $color = "white";
                    }


                    echo '<tr style="background-color:' . $color . ';' . (in_array($color, $highContrast) ? 'color:white;' : '') . '">';
                    if ($ev == 0) {
                        $rwsp = $cc + 1;
                        $dateString = "";
                        switch ($LOCALE) {
                            case "LA":
                                $dayOfTheWeek = (int)$festivity->date->format('w'); //w = 0-Sunday to 6-Saturday
                                $dayOfTheWeekLatin = $daysOfTheWeek[$dayOfTheWeek];
                                $month = (int)$festivity->date->format('n'); //n = 1-January to 12-December
                                $monthLatin = $months[$month];
                                $dateString = $dayOfTheWeekLatin . ' ' . $festivity->date->format('j') . ' ' . $monthLatin . ' ' . $festivity->date->format('Y');
                                break;
                            case "EN":
                                $dateString = $festivity->date->format('D, F jS, Y'); // G:i:s e') . "offset = " . $festivity->hourOffset;
                                break;
                            default:
                                $dateString = utf8_encode(strftime('%A %e %B %Y', $festivity->date->format('U')));
                        }
                        echo '<td rowspan="' . $rwsp . '" style="font-family:\'DejaVu Sans Mono\';font-size:.7em;font-weight:bold;">' . $dateString . '</td>';
                    }
                    echo '<td>' . $festivity->name . ' (' . $currentCycle . ') - <i>' . __($festivity->color, $LOCALE) . '</i><br /><i>' . $festivity->common . '</i></td>';
                    echo '<td>' . $GRADE[$festivity->grade] . '</td>';
                    echo '</tr>';
                    $keyindex++;
                }
                $keyindex--;
            } else {
                // LET'S DO SOME MORE MANIPULATION ON THE FESTIVITY->COMMON STRINGS AND THE FESTIVITY->COLOR...
                if ($festivity->common !== "" && $festivity->common !== "Proper") {
                    $commons = explode("|", $festivity->common);
                    $commons = array_map(function ($txt) {
                        global $LOCALE;
                        $common = explode(":", $txt);
                        $commonGeneral = __($common[0], $LOCALE);
                        $commonSpecific = isset($common[1]) && $common[1] != "" ? __($common[1], $LOCALE) : "";
                        //$txt = str_replace(":", ": ", $txt);
                        switch ($commonGeneral) {
                            case __("Blessed Virgin Mary", $LOCALE):
                                $commonKey = "of (SING_FEMM)";
                                break;
                            case __("Virgins", $LOCALE):
                                $commonKey = "of (PLUR_FEMM)";
                                break;
                            case __("Martyrs", $LOCALE):
                            case __("Pastors", $LOCALE):
                            case __("Doctors", $LOCALE):
                            case __("Holy Men and Women", $LOCALE):
                                $commonKey = "of (PLUR_MASC)";
                                break;
                            default:
                                $commonKey = "of (SING_MASC)";
                        }
                        return __("From the Common", $LOCALE) . " " . __($commonKey, $LOCALE) . " " . $commonGeneral . ($commonSpecific != "" ? ": " . $commonSpecific : "");
                    }, $commons);
                    $festivity->common = implode("; " . __("or", $LOCALE) . " ", $commons);
                } else if ($festivity->common == "Proper") {
                    $festivity->common = __("Proper", $LOCALE);
                }
                $festivity->color = explode("|", $festivity->color)[0];
                echo '<tr style="background-color:' . $festivity->color . ';' . (in_array($festivity->color, $highContrast) ? 'color:white;' : '') . '">';

                $dateString = "";
                switch ($LOCALE) {
                    case "LA":
                        $dayOfTheWeek = (int)$festivity->date->format('w'); //w = 0-Sunday to 6-Saturday
                        $dayOfTheWeekLatin = $daysOfTheWeek[$dayOfTheWeek];
                        $month = (int)$festivity->date->format('n'); //n = 1-January to 12-December
                        $monthLatin = $months[$month];
                        $dateString = $dayOfTheWeekLatin . ' ' . $festivity->date->format('j') . ' ' . $monthLatin . ' ' . $festivity->date->format('Y');
                        break;
                    case "EN":
                        $dateString = $festivity->date->format('D, F jS, Y'); //  G:i:s e') . "offset = " . $festivity->hourOffset;
                        break;
                    default:
                        $dateString = utf8_encode(strftime('%A %e %B %Y', $festivity->date->format('U')));
                }


                echo '<td style="font-family:\'DejaVu Sans Mono\';font-size:.7em;font-weight:bold;">' . $dateString . '</td>';
                echo '<td>' . $festivity->name . ' (' . $currentCycle . ') - <i>' . __($festivity->color, $LOCALE) . '</i><br /><i>' . $festivity->common . '</i></td>';
                echo '<td>' . $GRADE[$festivity->grade] . '</td>';
                echo '</tr>';
            }
        }

        echo '</tbody></table>';

        echo '<div style="text-align:center;border:3px ridge Green;background-color:LightBlue;width:75%;margin:10px auto;padding:10px;">' . $dayCnt . ' event days created</div>';
    }

    ?>
</body>