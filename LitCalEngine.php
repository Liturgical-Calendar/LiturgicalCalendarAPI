<?php

/**
 * Liturgical Calendar PHP engine script
 * Author: John Romano D'Orazio
 * Email: priest@johnromanodorazio.com
 * Licensed under the Apache 2.0 License
 * Version 2.9
 * Date Created: 27 December 2017
 * Note: it is necessary to set up the MySQL liturgy tables prior to using this script
 */


/**********************************************************************************
 *                          ABBREVIATIONS                                         *
 * CB     Cerimonial of Bishops                                                   *
 * CCL    Code of Canon Law                                                       *
 * IM     General Instruction of the Roman Missal                                 *
 * IH     General Instruction of the Liturgy of the Hours                         *
 * LH     Liturgy of the Hours                                                    *
 * LY     Universal Norms for the Liturgical Year and the Calendar (Roman Missal) *
 * OM     Order of Matrimony                                                      *
 * PC     Instruction regarding Proper Calendars                                  *
 * RM     Roman Missal                                                            *
 * SC     Sacrosanctum Concilium, Conciliar Constitution on the Sacred Liturgy    *
 *                                                                                *
 *********************************************************************************/


/**********************************************************************************
 *         EDITIONS OF THE ROMAN MISSAL AND OF THE GENERAL ROMAN CALENDAR         *
 *                                                                                *
 * Editio typica, 1970                                                            *
 * Reimpressio emendata, 1971                                                     *
 * Editio typica secunda, 1975                                                    *
 * Editio typica tertia, 2002                                                     *
 * Editio typica tertia emendata, 2008                                            *
 *                                                                                *
 *********************************************************************************/

error_reporting(E_ALL);
ini_set('display_errors', 1);

define("VERSION","2.9");

define("CACHEDURATION","MONTH"); //possible values: DAY, WEEK, MONTH, YEAR
$CacheDurationID;
switch(CACHEDURATION){
    case "DAY":
        $CacheDurationID = "_" . CACHEDURATION . date("z"); //The day of the year (starting from 0 through 365)
    break;
    case "WEEK":
        $CacheDurationID = "_" . CACHEDURATION . date("W"); //ISO-8601 week number of year, weeks starting on Monday
    break;
    case "MONTH":
        $CacheDurationID = "_" . CACHEDURATION . date("m"); //Numeric representation of a month, with leading zeros
    break;
    case "YEAR":
        $CacheDurationID = "_" . CACHEDURATION . date("Y"); //A full numeric representation of a year, 4 digits
    break;
}

/**
 *  CHECK PARAMETERS REQUESTED SO AS TO PROCESS THE CORRECT REPONSE
 *  SUCH AS THOSE REGARDING EPIPHANY, ASCENSION, CORPUS CHRISTI
 *  EACH EPISCOPAL CONFERENCE HAS THE FACULTY OF CHOOSING SUNDAY BETWEEN JAN 2 AND JAN 8 INSTEAD OF JAN 6 FOR EPIPHANY, AND SUNDAY INSTEAD OF THURSDAY FOR ASCENSION AND CORPUS CHRISTI
 *  DEFAULTS TO UNIVERSAL ROMAN CALENDAR: EPIPHANY = JAN 6, ASCENSION = THURSDAY, CORPUS CHRISTI = THURSDAY
 *  AND IN WHICH FORMAT TO RETURN THE PROCESSED DATA (JSON, XML, OR ICS)
 *  WE ALSO CHECK AGAINST HEADERS BEING SENT TO HELP DETERMINE THE FORMAT IN WHICH TO RETURN THE PROCESSED DATA (JSON, XML, OR ICS)
 */

$allowed_returntypes = array("JSON", "XML", "ICS");
$allowed_accept_headers = array("application/json", "application/xml", "text/calendar");

$requestHeaders = getallheaders();
$acceptHeader = isset($requestHeaders["Accept"]) && in_array($requestHeaders["Accept"],$allowed_accept_headers) ? $allowed_returntypes[array_search($requestHeaders["Accept"],$allowed_accept_headers)] : "";

$supportedNationalPresets = ["ITALY","USA","VATICAN"];

$LITSETTINGS = new stdClass();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $LITSETTINGS->YEAR = (isset($_POST["year"]) && is_numeric($_POST["year"]) && ctype_digit($_POST["year"]) && strlen($_POST["year"]) === 4) ? (int)$_POST["year"] : (int)date("Y");

    $LITSETTINGS->EPIPHANY = (isset($_POST["epiphany"]) && (strtoupper($_POST["epiphany"]) === "JAN6" || strtoupper($_POST["epiphany"]) === "SUNDAY_JAN2_JAN8")) ? strtoupper($_POST["epiphany"]) : "JAN6";
    $LITSETTINGS->ASCENSION = (isset($_POST["ascension"]) && (strtoupper($_POST["ascension"]) === "THURSDAY" || strtoupper($_POST["ascension"]) === "SUNDAY")) ? strtoupper($_POST["ascension"]) : "SUNDAY";
    $LITSETTINGS->CORPUSCHRISTI = (isset($_POST["corpuschristi"]) && (strtoupper($_POST["corpuschristi"]) === "THURSDAY" || strtoupper($_POST["corpuschristi"]) === "SUNDAY")) ? strtoupper($_POST["corpuschristi"]) : "SUNDAY";

    $LITSETTINGS->LOCALE = isset($_POST["locale"]) ? strtoupper($_POST["locale"]) : "LA"; //default to latin if not otherwise indicated
    $LITSETTINGS->RETURNTYPE = isset($_POST["returntype"]) && in_array(strtoupper($_POST["returntype"]), $allowed_returntypes) ? strtoupper($_POST["returntype"]) : ($acceptHeader !== "" ? $acceptHeader : $allowed_returntypes[0]); // default to JSON

    $LITSETTINGS->NATIONAL = isset($_POST["nationalpreset"]) && in_array(strtoupper($_POST["nationalpreset"]), $supportedNationalPresets) ? strtoupper($_POST["nationalpreset"]) : false;

    $LITSETTINGS->DIOCESAN = isset($_POST["diocesanpreset"]) ? strtoupper($_POST["diocesanpreset"]) : false;

} else if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $LITSETTINGS->YEAR = (isset($_GET["year"]) && is_numeric($_GET["year"]) && ctype_digit($_GET["year"]) && strlen($_GET["year"]) === 4) ? (int)$_GET["year"] : (int)date("Y");

    $LITSETTINGS->EPIPHANY = (isset($_GET["epiphany"]) && (strtoupper($_GET["epiphany"]) === "JAN6" || strtoupper($_GET["epiphany"]) === "SUNDAY_JAN2_JAN8")) ? strtoupper($_GET["epiphany"]) : "JAN6";
    $LITSETTINGS->ASCENSION = (isset($_GET["ascension"]) && (strtoupper($_GET["ascension"]) === "THURSDAY" || strtoupper($_GET["ascension"]) === "SUNDAY")) ? strtoupper($_GET["ascension"]) : "SUNDAY";
    $LITSETTINGS->CORPUSCHRISTI = (isset($_GET["corpuschristi"]) && (strtoupper($_GET["corpuschristi"]) === "THURSDAY" || strtoupper($_GET["corpuschristi"]) === "SUNDAY")) ? strtoupper($_GET["corpuschristi"]) : "SUNDAY";

    $LITSETTINGS->LOCALE = isset($_GET["locale"]) ? strtoupper($_GET["locale"]) : "LA"; //default to latin if not otherwise indicated
    $LITSETTINGS->RETURNTYPE = isset($_GET["returntype"]) && in_array(strtoupper($_GET["returntype"]), $allowed_returntypes) ? strtoupper($_GET["returntype"]) : ($acceptHeader !== "" ? $acceptHeader : $allowed_returntypes[0]); // default to JSON

    $LITSETTINGS->NATIONAL = isset($_GET["nationalpreset"]) && in_array(strtoupper($_GET["nationalpreset"]), $supportedNationalPresets) ? strtoupper($_GET["nationalpreset"]) : false;

    $LITSETTINGS->DIOCESAN = isset($_GET["diocesanpreset"]) ? strtoupper($_GET["diocesanpreset"]) : false;

}

$DiocesanData = null;
$index = null;
if($LITSETTINGS->DIOCESAN !== false){
    switch($LITSETTINGS->DIOCESAN){
        case 'DIOCESIDIROMA':
        case 'DIOCESILAZIO':
            $LITSETTINGS->NATIONAL = "ITALY";
        break;
        default:
            //since a Diocesan calendar is being requested, we need to retrieve the JSON data
            //first we need to discover the path, so let's retrieve our index file
            if(file_exists("nations/index.json")){
                $index = json_decode(file_get_contents("nations/index.json"));
                if(property_exists($index,$LITSETTINGS->DIOCESAN)){
                    $diocesanDataFile = $index->{$LITSETTINGS->DIOCESAN}->path;
                    $LITSETTINGS->NATIONAL = $index->{$LITSETTINGS->DIOCESAN}->nation;
                    if(file_exists($diocesanDataFile) ){
                        $DiocesanData = json_decode(file_get_contents($diocesanDataFile));
                    }
                }
            }
        break;
    }

}

if($LITSETTINGS->NATIONAL !== false){
    switch($LITSETTINGS->NATIONAL){
        case 'VATICAN':
            $LITSETTINGS->EPIPHANY = "JAN6";
            $LITSETTINGS->ASCENSION = "THURSDAY";
            $LITSETTINGS->CORPUSCHRISTI = "THURSDAY";
            $LITSETTINGS->LOCALE = "LA";
        break;
        case "ITALY":
            $LITSETTINGS->EPIPHANY = "JAN6";
            $LITSETTINGS->ASCENSION = "SUNDAY";
            $LITSETTINGS->CORPUSCHRISTI = "SUNDAY";
            $LITSETTINGS->LOCALE = "IT";
        break;
        case "USA":
            $LITSETTINGS->EPIPHANY = "SUNDAY_JAN2_JAN8";
            $LITSETTINGS->ASCENSION = "SUNDAY";
            $LITSETTINGS->CORPUSCHRISTI = "SUNDAY";
            $LITSETTINGS->LOCALE = "EN";
        break;
    }
}

$cacheFile = md5(serialize($LITSETTINGS)) . $CacheDurationID . "." . strtolower($LITSETTINGS->RETURNTYPE);
if(file_exists("engineCache/v" . str_replace(".","_",VERSION) . "/" . $cacheFile)){
    switch($LITSETTINGS->RETURNTYPE){
        case "JSON":
            header('Content-Type: application/json');
        break;
        case "XML":
            header('Content-Type: application/xml; charset=utf-8');
        break;
        case "ICS":
            header('Content-Type: text/calendar; charset=UTF-8');
            header('Content-Disposition: attachment; filename="LiturgicalCalendar.ics"');
        break;
    }
    echo file_get_contents("engineCache/v" . str_replace(".","_",VERSION) . "/" . $cacheFile);
    die();
}

include "Festivity.php"; //this defines a "Festivity" class that can hold all the useful information about a single celebration

/**
 *  THE ENTIRE LITURGICAL CALENDAR DEPENDS MAINLY ON THE DATE OF EASTER
 *  THE FOLLOWING LITCALFUNCTIONS.PHP DEFINES AMONG OTHER THINGS THE FUNCTION
 *  FOR CALCULATING GREGORIAN EASTER FOR A GIVEN YEAR AS USED BY THE LATIN RITE
 */

include "LitCalFunctions.php"; //a few useful functions e.g. calculate Easter...
include "LitCalMessages.php";  //translation strings and functions

/**
 * INITIATE CONNECTION TO THE DATABASE
 * AND CHECK FOR CONNECTION ERRORS
 * THE DATABASECONNECT() FUNCTION IS DEFINED IN LITCALFUNCTIONS.PHP
 * WHICH IN TURN LOADS DATABASE CONNECTION INFORMATION FROM LITCALCONFIG.PHP
 * IF THE CONNECTION SUCCEEDS, THE FUNCTION WILL RETURN THE MYSQLI CONNECTION RESOURCE
 * IN THE MYSQLI PROPERTY OF THE RETURNED OBJECT
 */

$dbConnect = databaseConnect();
if ($dbConnect->retString != "" && preg_match("/^Connected to MySQL Database:/", $dbConnect->retString) == 0) {
    die("There was an error in the database connection: \n" . $dbConnect->retString);
} else {
    $mysqli = $dbConnect->mysqli;
}




ini_set('date.timezone', 'Europe/Vatican');
//ini_set('intl.default_locale', strtolower($LITSETTINGS->LOCALE) . '_' . $LITSETTINGS->LOCALE);
setlocale(LC_TIME, strtolower($LITSETTINGS->LOCALE) . '_' . $LITSETTINGS->LOCALE);
$formatter = new NumberFormatter(strtolower($LITSETTINGS->LOCALE), NumberFormatter::SPELLOUT);
switch($LITSETTINGS->LOCALE){
    case 'EN':
        $formatter->setTextAttribute(NumberFormatter::DEFAULT_RULESET, "%spellout-ordinal");
        $formatterFem = $formatter;
    break;
    default:
        $formatter->setTextAttribute(NumberFormatter::DEFAULT_RULESET, "%spellout-ordinal-masculine");
        $formatterFem = new NumberFormatter(strtolower($LITSETTINGS->LOCALE), NumberFormatter::SPELLOUT);
        $formatterFem->setTextAttribute(NumberFormatter::DEFAULT_RULESET, "%spellout-ordinal-feminine");
}


define("EPIPHANY", $LITSETTINGS->EPIPHANY); //possible values "SUNDAY_JAN2_JAN8" and "JAN6"
define("ASCENSION", $LITSETTINGS->ASCENSION); //possible values "THURSDAY" and "SUNDAY"
define("CORPUSCHRISTI", $LITSETTINGS->CORPUSCHRISTI); //possible values "THURSDAY" and "SUNDAY"


/**
 *	DEFINE THE ORDER OF PRECEDENCE OF THE LITURGICAL DAYS AS INDICATED IN THE
 *  UNIVERSAL NORMS FOR THE LITURGICAL YEAR AND THE GENERAL ROMAN CALENDAR
 *  PROMULGATED BY THE MOTU PROPRIO "MYSTERII PASCHALIS" BY POPE PAUL VI ON FEBRUARY 14 1969
 *	https://w2.vatican.va/content/paul-vi/en/motu_proprio/documents/hf_p-vi_motu-proprio_19690214_mysterii-paschalis.html
 *  A COPY OF THE DOCUMENT IS INCLUDED ALONGSIDE THIS ENGINE, SEEING THAT THERE IS NO DIRECT ONLINE LINK TO THE ACTUAL NORMS
 */

/*****************************************************
 * DEFINE THE ORDER OF IMPORTANCE OF THE FESTIVITIES *
 ****************************************************/

// 				I.
define("HIGHERSOLEMNITY", 7);
// HIGHER RANKING SOLEMNITIES, THAT HAVE PRECEDENCE OVER ALL OTHERS:
// 1. EASTER TRIDUUM
// 2. CHRISTMAS, EPIPHANY, ASCENSION, PENTECOST
//    SUNDAYS OF ADVENT, LENT AND EASTER
//    ASH WEDNESDAY
//    DAYS OF THE HOLY WEEK, FROM MONDAY TO THURSDAY
//    DAYS OF THE OCTAVE OF EASTER

define("SOLEMNITY", 6);
// 3. SOLEMNITIES OF THE LORD, OF THE BLESSED VIRGIN MARY, OF THE SAINTS LISTED IN THE GENERAL CALENDAR
//    COMMEMORATION OF THE FAITHFUL DEPARTED
// 4. PARTICULAR SOLEMNITIES:
//		a) PATRON OF THE PLACE, OF THE COUNTRY OR OF THE CITY (CELEBRATION REQUIRED ALSO FOR RELIGIOUS COMMUNITIES);
//		b) SOLEMNITY OF THE DEDICATION AND OF THE ANNIVERSARY OF THE DEDICATION OF A CHURCH
//		c) SOLEMNITY OF THE TITLE OF A CHURCH
//		d) SOLEMNITY OF THE TITLE OR OF THE FOUNDER OR OF THE MAIN PATRON OF AN ORDER OR OF A CONGREGATION

// 				II.
define("FEASTLORD", 5);
// 5. FEASTS OF THE LORD LISTED IN THE GENERAL CALENDAR
// 6. SUNDAYS OF CHRISTMAS AND OF ORDINARY TIME
define("FEAST", 4);
// 7. FEASTS OF THE BLESSED VIRGIN MARY AND OF THE SAINTS IN THE GENERAL CALENDAR
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
define("MEMORIAL", 3);
// 10. MEMORIALS OF THE GENERAL CALENDAR
// 11. PARTICULAR MEMORIALS:
//		a) MEMORIALS OF THE SECONDARY PATRON OF A PLACE, OF A DIOCESE, OF A REGION OR A RELIGIOUS PROVINCE
//		b) OTHER MEMORIALS LISTED IN THE CALENDAR OF EACH DIOCESE, ORDER OR CONGREGATION
define("MEMORIALOPT", 2);
// 12. OPTIONAL MEMORIALS, WHICH CAN HOWEVER BE OBSERVED IN DAYS INDICATED AT N. 9,
//     ACCORDING TO THE NORMS DESCRIBED IN "PRINCIPLES AND NORMS" FOR THE LITURGY OF THE HOURS AND THE USE OF THE MISSAL

define("COMMEMORATION", 1);
//     SIMILARLY MEMORIALS CAN BE OBSERVED AS OPTIONAL MEMORIALS THAT SHOULD FALL DURING THE WEEKDAYS OF LENT

define("WEEKDAY", 0);
// 13. WEEKDAYS OF ADVENT UNTIL DECEMBER 16th
//     WEEKDAYS OF CHRISTMAS, FROM JANUARY 2nd UNTIL THE SATURDAY AFTER EPIPHANY
//     WEEKDAYS OF THE EASTER SEASON, FROM THE MONDAY AFTER THE OCTAVE OF EASTER UNTIL THE SATURDAY BEFORE PENTECOST
//     WEEKDAYS OF ORDINARY TIME

//TODO: implement interface for adding Proper feasts and memorials...


/**
 *  LET'S DEFINE SOME GLOBAL VARIABLES
 *  THAT WILL BE NEEDED THROUGHOUT THE ENGINE
 */

$LitCal = array();

$PROPRIUM_DE_TEMPORE = array(); //will retrieve translated info for recurrences in the Proprium de Tempore table
$SOLEMNITIES = array(); //will index defined solemnities and feasts of the Lord
$FEASTS_MEMORIALS = array(); //will index feasts and obligatory memorials that suppress or influence other lesser liturgical recurrences...
$WEEKDAYS_ADVENT_CHRISTMAS_LENT = array(); //will index weekdays of advent from 17 Dec. to 24 Dec., of the Octave of Christmas and weekdays of Lent
$WEEKDAYS_EPIPHANY = array(); //useful to be able to remove a weekday of Epiphany that is overriden by a memorial
$SUNDAYS_ADVENT_LENT_EASTER = array();
$SOLEMNITIES_LORD_BVM = array();

$Messages = array();

//for the time being, we cannot accept a year any earlier than 1970, since this engine is based on the liturgical reform from Vatican II
//with the Prima Editio Typica of the Roman Missal and the General Norms promulgated with the Motu Proprio "Mysterii Paschali" in 1969
if ($LITSETTINGS->YEAR < 1970) {
    $Messages[] = sprintf(__("Only years from 1970 and after are supported. You tried requesting the year %d.",$LITSETTINGS->LOCALE),$LITSETTINGS->YEAR);
    GenerateResponseToRequest($LitCal,$LITSETTINGS,$Messages,$SOLEMNITIES,$FEASTS_MEMORIALS);
}


/**
 * Retrieve Higher Ranking Solemnities from Proprium de Tempore
 */
if ($result = $mysqli->query("SELECT * FROM LITURGY__calendar_propriumdetempore")) {
    while ($row = mysqli_fetch_assoc($result)) {
        $PROPRIUM_DE_TEMPORE[$row["TAG"]] = array("NAME_" . $LITSETTINGS->LOCALE => $row["NAME_" . $LITSETTINGS->LOCALE]);
    }
}

/**
 *  START FILLING OUR FESTIVITY OBJECT BASED ON THE ORDER OF PRECEDENCE OF LITURGICAL DAYS (LY 59)
 */

// I.
//1. Easter Triduum of the Lord's Passion and Resurrection
$LitCal["HolyThurs"]        = new Festivity($PROPRIUM_DE_TEMPORE["HolyThurs"]["NAME_" . $LITSETTINGS->LOCALE],    calcGregEaster($LITSETTINGS->YEAR)->sub(new DateInterval('P3D')), "white", "mobile", HIGHERSOLEMNITY);
$LitCal["GoodFri"]          = new Festivity($PROPRIUM_DE_TEMPORE["GoodFri"]["NAME_" . $LITSETTINGS->LOCALE],      calcGregEaster($LITSETTINGS->YEAR)->sub(new DateInterval('P2D')), "red",   "mobile", HIGHERSOLEMNITY);
$LitCal["EasterVigil"]      = new Festivity($PROPRIUM_DE_TEMPORE["EasterVigil"]["NAME_" . $LITSETTINGS->LOCALE],  calcGregEaster($LITSETTINGS->YEAR)->sub(new DateInterval('P1D')), "white", "mobile", HIGHERSOLEMNITY);
$LitCal["Easter"]           = new Festivity($PROPRIUM_DE_TEMPORE["Easter"]["NAME_" . $LITSETTINGS->LOCALE],       calcGregEaster($LITSETTINGS->YEAR),                               "white", "mobile", HIGHERSOLEMNITY);

$SOLEMNITIES["HolyThurs"]   = $LitCal["HolyThurs"]->date;
$SOLEMNITIES["GoodFri"]     = $LitCal["GoodFri"]->date;
$SOLEMNITIES["EasterVigil"] = $LitCal["EasterVigil"]->date;
$SOLEMNITIES["Easter"]      = $LitCal["Easter"]->date;

//2. Christmas, Epiphany, Ascension, and Pentecost
$LitCal["Christmas"]        = new Festivity($PROPRIUM_DE_TEMPORE["Christmas"]["NAME_" . $LITSETTINGS->LOCALE],    DateTime::createFromFormat('!j-n-Y', '25-12-' . $LITSETTINGS->YEAR, new DateTimeZone('UTC')), "white", "fixed",  HIGHERSOLEMNITY);
$SOLEMNITIES["Christmas"]   = $LitCal["Christmas"]->date;

if (EPIPHANY === "JAN6") {

    $LitCal["Epiphany"]     = new Festivity($PROPRIUM_DE_TEMPORE["Epiphany"]["NAME_" . $LITSETTINGS->LOCALE],     DateTime::createFromFormat('!j-n-Y', '6-1-' . $LITSETTINGS->YEAR, new DateTimeZone('UTC')),  "white", "fixed",  HIGHERSOLEMNITY);

    //If a Sunday occurs on a day from Jan. 2 through Jan. 5, it is called the "Second Sunday of Christmas"
    //Weekdays from Jan. 2 through Jan. 5 are called "*day before Epiphany"
    $nth = 0;
    for ($i = 2; $i <= 5; $i++) {
        if ((int)DateTime::createFromFormat('!j-n-Y', $i . '-1-' . $LITSETTINGS->YEAR, new DateTimeZone('UTC'))->format('N') === 7) {
            $LitCal["Christmas2"]       = new Festivity($PROPRIUM_DE_TEMPORE["Christmas2"]["NAME_" . $LITSETTINGS->LOCALE], DateTime::createFromFormat('!j-n-Y', $i . '-1-' . $LITSETTINGS->YEAR, new DateTimeZone('UTC')), "white",     "mobile", FEASTLORD);
            $SOLEMNITIES["Christmas2"]  = $LitCal["Christmas2"]->date;
        } else {
            $nth++;
            $LitCal["DayBeforeEpiphany" . $nth] = new Festivity(sprintf(__("%s day before Epiphany", $LITSETTINGS->LOCALE), ( $LITSETTINGS->LOCALE == 'LA' ? $LATIN_ORDINAL[$nth] : ucfirst($formatter->format($nth)) ) ), DateTime::createFromFormat('!j-n-Y', $i . '-1-' . $LITSETTINGS->YEAR, new DateTimeZone('UTC')), "white",     "mobile");
            $WEEKDAYS_EPIPHANY["DayBeforeEpiphany" . $nth] = $LitCal["DayBeforeEpiphany" . $nth]->date;
        }
    }

    //Weekdays from Jan. 7 until the following Sunday are called "*day after Epiphany"
    $SundayAfterEpiphany = (int) DateTime::createFromFormat('!j-n-Y', '6-1-' . $LITSETTINGS->YEAR, new DateTimeZone('UTC'))->modify('next Sunday')->format('j');
    if ($SundayAfterEpiphany !== 7) {
        $nth = 0;
        for ($i = 7; $i < $SundayAfterEpiphany; $i++) {
            $nth++;
            $LitCal["DayAfterEpiphany" . $nth] = new Festivity(sprintf(__("%s day after Epiphany", $LITSETTINGS->LOCALE), ( $LITSETTINGS->LOCALE == 'LA' ? $LATIN_ORDINAL[$nth] : ucfirst($formatter->format($nth)) ) ), DateTime::createFromFormat('!j-n-Y', $i . '-1-' . $LITSETTINGS->YEAR, new DateTimeZone('UTC')), "white",     "mobile");
            $WEEKDAYS_EPIPHANY["DayAfterEpiphany" . $nth] = $LitCal["DayAfterEpiphany" . $nth]->date;
        }
    }
} else if (EPIPHANY === "SUNDAY_JAN2_JAN8") {
    //If January 2nd is a Sunday, then go with Jan 2nd
    if ((int)DateTime::createFromFormat('!j-n-Y', '2-1-' . $LITSETTINGS->YEAR, new DateTimeZone('UTC'))->format('N') === 7) {
        $LitCal["Epiphany"] = new Festivity($PROPRIUM_DE_TEMPORE["Epiphany"]["NAME_" . $LITSETTINGS->LOCALE],      DateTime::createFromFormat('!j-n-Y', '2-1-' . $LITSETTINGS->YEAR, new DateTimeZone('UTC')), "white",    "mobile",    HIGHERSOLEMNITY);
    }
    //otherwise find the Sunday following Jan 2nd
    else {
        $SundayOfEpiphany = DateTime::createFromFormat('!j-n-Y', '2-1-' . $LITSETTINGS->YEAR, new DateTimeZone('UTC'))->modify('next Sunday');
        $LitCal["Epiphany"] = new Festivity($PROPRIUM_DE_TEMPORE["Epiphany"]["NAME_" . $LITSETTINGS->LOCALE],      $SundayOfEpiphany,                                    "white",    "mobile",    HIGHERSOLEMNITY);

        //Weekdays from Jan. 2 until the following Sunday are called "*day before Epiphany"
        //echo $SundayOfEpiphany->format('j');
        $DayOfEpiphany = (int) $SundayOfEpiphany->format('j');

        $nth = 0;

        for ($i = 2; $i < $DayOfEpiphany; $i++) {
            $nth++;
            $LitCal["DayBeforeEpiphany" . $nth] = new Festivity(sprintf(__("%s day before Epiphany", $LITSETTINGS->LOCALE), ( $LITSETTINGS->LOCALE == 'LA' ? $LATIN_ORDINAL[$nth] : ucfirst($formatter->format($nth)) ) ), DateTime::createFromFormat('!j-n-Y', $i . '-1-' . $LITSETTINGS->YEAR, new DateTimeZone('UTC')), "white",     "mobile");
            $WEEKDAYS_EPIPHANY["DayBeforeEpiphany" . $nth] = $LitCal["DayBeforeEpiphany" . $nth]->date;
        }

        //If Epiphany occurs on or before Jan. 6, then the days of the week following Epiphany are called "*day after Epiphany" and the Sunday following Epiphany is the Baptism of the Lord.
        if ($DayOfEpiphany < 7) {
            $SundayAfterEpiphany = (int)DateTime::createFromFormat('!j-n-Y', '2-1-' . $LITSETTINGS->YEAR, new DateTimeZone('UTC'))->modify('next Sunday')->modify('next Sunday')->format('j');
            $nth = 0;
            for ($i = $DayOfEpiphany + 1; $i < $SundayAfterEpiphany; $i++) {
                $nth++;
                $LitCal["DayAfterEpiphany" . $nth] = new Festivity(sprintf(__("%s day after Epiphany", $LITSETTINGS->LOCALE), ( $LITSETTINGS->LOCALE == 'LA' ? $LATIN_ORDINAL[$nth] : ucfirst($formatter->format($nth)) ) ), DateTime::createFromFormat('!j-n-Y', $i . '-1-' . $LITSETTINGS->YEAR, new DateTimeZone('UTC')), "white",     "mobile");
                $WEEKDAYS_EPIPHANY["DayAfterEpiphany" . $nth] = $LitCal["DayAfterEpiphany" . $nth]->date;
            }
        }
    }
}

$SOLEMNITIES["Epiphany"]        = $LitCal["Epiphany"]->date;

if (ASCENSION === "THURSDAY") {
    $LitCal["Ascension"]    = new Festivity($PROPRIUM_DE_TEMPORE["Ascension"]["NAME_" . $LITSETTINGS->LOCALE],    calcGregEaster($LITSETTINGS->YEAR)->add(new DateInterval('P39D')),           "white",    "mobile", HIGHERSOLEMNITY);
    $LitCal["Easter7"]      = new Festivity($PROPRIUM_DE_TEMPORE["Easter7"]["NAME_" . $LITSETTINGS->LOCALE],      calcGregEaster($LITSETTINGS->YEAR)->add(new DateInterval('P' . (7 * 6) . 'D')),    "white",    "mobile", HIGHERSOLEMNITY);
} else if (ASCENSION === "SUNDAY") {
    $LitCal["Ascension"]    = new Festivity($PROPRIUM_DE_TEMPORE["Ascension"]["NAME_" . $LITSETTINGS->LOCALE],    calcGregEaster($LITSETTINGS->YEAR)->add(new DateInterval('P' . (7 * 6) . 'D')),    "white",    "mobile", HIGHERSOLEMNITY);
}
$SOLEMNITIES["Ascension"]       = $LitCal["Ascension"]->date;

$LitCal["Pentecost"]        = new Festivity($PROPRIUM_DE_TEMPORE["Pentecost"]["NAME_" . $LITSETTINGS->LOCALE],    calcGregEaster($LITSETTINGS->YEAR)->add(new DateInterval('P' . (7 * 7) . 'D')),    "red",      "mobile", HIGHERSOLEMNITY);
$SOLEMNITIES["Pentecost"]       = $LitCal["Pentecost"]->date;

//Sundays of Advent, Lent, and Easter Time
$LitCal["Advent1"]          = new Festivity($PROPRIUM_DE_TEMPORE["Advent1"]["NAME_" . $LITSETTINGS->LOCALE],      DateTime::createFromFormat('!j-n-Y', '25-12-' . $LITSETTINGS->YEAR, new DateTimeZone('UTC'))->modify('last Sunday')->sub(new DateInterval('P' . (3 * 7) . 'D')),    "purple",   "mobile", HIGHERSOLEMNITY);
$LitCal["Advent2"]          = new Festivity($PROPRIUM_DE_TEMPORE["Advent2"]["NAME_" . $LITSETTINGS->LOCALE],      DateTime::createFromFormat('!j-n-Y', '25-12-' . $LITSETTINGS->YEAR, new DateTimeZone('UTC'))->modify('last Sunday')->sub(new DateInterval('P' . (2 * 7) . 'D')),    "purple",   "mobile", HIGHERSOLEMNITY);
$LitCal["Advent3"]          = new Festivity($PROPRIUM_DE_TEMPORE["Advent3"]["NAME_" . $LITSETTINGS->LOCALE],      DateTime::createFromFormat('!j-n-Y', '25-12-' . $LITSETTINGS->YEAR, new DateTimeZone('UTC'))->modify('last Sunday')->sub(new DateInterval('P7D')),            "pink",     "mobile", HIGHERSOLEMNITY);
$LitCal["Advent4"]          = new Festivity($PROPRIUM_DE_TEMPORE["Advent4"]["NAME_" . $LITSETTINGS->LOCALE],      DateTime::createFromFormat('!j-n-Y', '25-12-' . $LITSETTINGS->YEAR, new DateTimeZone('UTC'))->modify('last Sunday'),                                          "purple",   "mobile", HIGHERSOLEMNITY);
$LitCal["Lent1"]            = new Festivity($PROPRIUM_DE_TEMPORE["Lent1"]["NAME_" . $LITSETTINGS->LOCALE],        calcGregEaster($LITSETTINGS->YEAR)->sub(new DateInterval('P' . (6 * 7) . 'D')),    "purple",   "mobile", HIGHERSOLEMNITY);
$LitCal["Lent2"]            = new Festivity($PROPRIUM_DE_TEMPORE["Lent2"]["NAME_" . $LITSETTINGS->LOCALE],        calcGregEaster($LITSETTINGS->YEAR)->sub(new DateInterval('P' . (5 * 7) . 'D')),    "purple",   "mobile", HIGHERSOLEMNITY);
$LitCal["Lent3"]            = new Festivity($PROPRIUM_DE_TEMPORE["Lent3"]["NAME_" . $LITSETTINGS->LOCALE],        calcGregEaster($LITSETTINGS->YEAR)->sub(new DateInterval('P' . (4 * 7) . 'D')),    "purple",   "mobile", HIGHERSOLEMNITY);
$LitCal["Lent4"]            = new Festivity($PROPRIUM_DE_TEMPORE["Lent4"]["NAME_" . $LITSETTINGS->LOCALE],        calcGregEaster($LITSETTINGS->YEAR)->sub(new DateInterval('P' . (3 * 7) . 'D')),    "pink",     "mobile", HIGHERSOLEMNITY);
$LitCal["Lent5"]            = new Festivity($PROPRIUM_DE_TEMPORE["Lent5"]["NAME_" . $LITSETTINGS->LOCALE],        calcGregEaster($LITSETTINGS->YEAR)->sub(new DateInterval('P' . (2 * 7) . 'D')),    "purple",   "mobile", HIGHERSOLEMNITY);
$LitCal["PalmSun"]          = new Festivity($PROPRIUM_DE_TEMPORE["PalmSun"]["NAME_" . $LITSETTINGS->LOCALE],      calcGregEaster($LITSETTINGS->YEAR)->sub(new DateInterval('P7D')),            "red",      "mobile", HIGHERSOLEMNITY);
$LitCal["Easter2"]          = new Festivity($PROPRIUM_DE_TEMPORE["Easter2"]["NAME_" . $LITSETTINGS->LOCALE],      calcGregEaster($LITSETTINGS->YEAR)->add(new DateInterval('P7D')),            "white",    "mobile", HIGHERSOLEMNITY);
$LitCal["Easter3"]          = new Festivity($PROPRIUM_DE_TEMPORE["Easter3"]["NAME_" . $LITSETTINGS->LOCALE],      calcGregEaster($LITSETTINGS->YEAR)->add(new DateInterval('P' . (7 * 2) . 'D')),    "white",    "mobile", HIGHERSOLEMNITY);
$LitCal["Easter4"]          = new Festivity($PROPRIUM_DE_TEMPORE["Easter4"]["NAME_" . $LITSETTINGS->LOCALE],      calcGregEaster($LITSETTINGS->YEAR)->add(new DateInterval('P' . (7 * 3) . 'D')),    "white",    "mobile", HIGHERSOLEMNITY);
$LitCal["Easter5"]          = new Festivity($PROPRIUM_DE_TEMPORE["Easter5"]["NAME_" . $LITSETTINGS->LOCALE],      calcGregEaster($LITSETTINGS->YEAR)->add(new DateInterval('P' . (7 * 4) . 'D')),    "white",    "mobile", HIGHERSOLEMNITY);
$LitCal["Easter6"]          = new Festivity($PROPRIUM_DE_TEMPORE["Easter6"]["NAME_" . $LITSETTINGS->LOCALE],      calcGregEaster($LITSETTINGS->YEAR)->add(new DateInterval('P' . (7 * 5) . 'D')),    "white",    "mobile", HIGHERSOLEMNITY);
$LitCal["Trinity"]          = new Festivity($PROPRIUM_DE_TEMPORE["Trinity"]["NAME_" . $LITSETTINGS->LOCALE],      calcGregEaster($LITSETTINGS->YEAR)->add(new DateInterval('P' . (7 * 8) . 'D')),    "white",    "mobile", HIGHERSOLEMNITY);

$SOLEMNITIES["Advent1"]         = $LitCal["Advent1"]->date;
$SOLEMNITIES["Advent2"]         = $LitCal["Advent2"]->date;
$SOLEMNITIES["Advent3"]         = $LitCal["Advent3"]->date;
$SOLEMNITIES["Advent4"]         = $LitCal["Advent4"]->date;
$SOLEMNITIES["Lent1"]           = $LitCal["Lent1"]->date;
$SOLEMNITIES["Lent2"]           = $LitCal["Lent2"]->date;
$SOLEMNITIES["Lent3"]           = $LitCal["Lent3"]->date;
$SOLEMNITIES["Lent4"]           = $LitCal["Lent4"]->date;
$SOLEMNITIES["Lent5"]           = $LitCal["Lent5"]->date;
$SOLEMNITIES["PalmSun"]         = $LitCal["PalmSun"]->date;
$SOLEMNITIES["Easter2"]         = $LitCal["Easter2"]->date;
$SOLEMNITIES["Easter3"]         = $LitCal["Easter3"]->date;
$SOLEMNITIES["Easter4"]         = $LitCal["Easter4"]->date;
$SOLEMNITIES["Easter5"]         = $LitCal["Easter5"]->date;
$SOLEMNITIES["Easter6"]         = $LitCal["Easter6"]->date;
$SOLEMNITIES["Trinity"]         = $LitCal["Trinity"]->date;
array_push( $SUNDAYS_ADVENT_LENT_EASTER,
    $LitCal["Advent1"]->date,
    $LitCal["Advent2"]->date,
    $LitCal["Advent3"]->date,
    $LitCal["Advent4"]->date,
    $LitCal["Lent1"]->date,
    $LitCal["Lent2"]->date,
    $LitCal["Lent3"]->date,
    $LitCal["Lent4"]->date,
    $LitCal["Lent5"]->date,
    $LitCal["Easter2"]->date,
    $LitCal["Easter3"]->date,
    $LitCal["Easter4"]->date,
    $LitCal["Easter5"]->date,
    $LitCal["Easter6"]->date
);

if (CORPUSCHRISTI === "THURSDAY") {
    $LitCal["CorpusChristi"] = new Festivity($PROPRIUM_DE_TEMPORE["CorpusChristi"]["NAME_" . $LITSETTINGS->LOCALE], calcGregEaster($LITSETTINGS->YEAR)->add(new DateInterval('P' . (7 * 8 + 4) . 'D')),  "white",    "mobile", HIGHERSOLEMNITY);
} else if (CORPUSCHRISTI === "SUNDAY") {
    $LitCal["CorpusChristi"] = new Festivity($PROPRIUM_DE_TEMPORE["CorpusChristi"]["NAME_" . $LITSETTINGS->LOCALE], calcGregEaster($LITSETTINGS->YEAR)->add(new DateInterval('P' . (7 * 9) . 'D')),    "white",    "mobile", HIGHERSOLEMNITY);
}
$SOLEMNITIES["CorpusChristi"]   = $LitCal["CorpusChristi"]->date;

//Ash Wednesday
$LitCal["AshWednesday"]     = new Festivity($PROPRIUM_DE_TEMPORE["AshWednesday"]["NAME_" . $LITSETTINGS->LOCALE], calcGregEaster($LITSETTINGS->YEAR)->sub(new DateInterval('P46D')),           "purple",   "mobile", HIGHERSOLEMNITY);
$SOLEMNITIES["AshWednesday"]    = $LitCal["AshWednesday"]->date;

//Weekdays of Holy Week from Monday to Thursday inclusive (that is, thursday morning chrism mass... the In Coena Domini mass begins the Easter Triduum)
$LitCal["MonHolyWeek"]      = new Festivity($PROPRIUM_DE_TEMPORE["MonHolyWeek"]["NAME_" . $LITSETTINGS->LOCALE], calcGregEaster($LITSETTINGS->YEAR)->sub(new DateInterval('P6D')),            "purple",   "mobile", HIGHERSOLEMNITY);
$LitCal["TueHolyWeek"]      = new Festivity($PROPRIUM_DE_TEMPORE["TueHolyWeek"]["NAME_" . $LITSETTINGS->LOCALE], calcGregEaster($LITSETTINGS->YEAR)->sub(new DateInterval('P5D')),            "purple",   "mobile", HIGHERSOLEMNITY);
$LitCal["WedHolyWeek"]      = new Festivity($PROPRIUM_DE_TEMPORE["WedHolyWeek"]["NAME_" . $LITSETTINGS->LOCALE], calcGregEaster($LITSETTINGS->YEAR)->sub(new DateInterval('P4D')),            "purple",   "mobile", HIGHERSOLEMNITY);
$SOLEMNITIES["MonHolyWeek"]         = $LitCal["MonHolyWeek"]->date;
$SOLEMNITIES["TueHolyWeek"]         = $LitCal["TueHolyWeek"]->date;
$SOLEMNITIES["WedHolyWeek"]         = $LitCal["WedHolyWeek"]->date;

//Days within the octave of Easter
$LitCal["MonOctaveEaster"]  = new Festivity($PROPRIUM_DE_TEMPORE["MonOctaveEaster"]["NAME_" . $LITSETTINGS->LOCALE], calcGregEaster($LITSETTINGS->YEAR)->add(new DateInterval('P1D')),            "white",    "mobile", HIGHERSOLEMNITY);
$LitCal["TueOctaveEaster"]  = new Festivity($PROPRIUM_DE_TEMPORE["TueOctaveEaster"]["NAME_" . $LITSETTINGS->LOCALE], calcGregEaster($LITSETTINGS->YEAR)->add(new DateInterval('P2D')),            "white",    "mobile", HIGHERSOLEMNITY);
$LitCal["WedOctaveEaster"]  = new Festivity($PROPRIUM_DE_TEMPORE["WedOctaveEaster"]["NAME_" . $LITSETTINGS->LOCALE], calcGregEaster($LITSETTINGS->YEAR)->add(new DateInterval('P3D')),            "white",    "mobile", HIGHERSOLEMNITY);
$LitCal["ThuOctaveEaster"]  = new Festivity($PROPRIUM_DE_TEMPORE["ThuOctaveEaster"]["NAME_" . $LITSETTINGS->LOCALE], calcGregEaster($LITSETTINGS->YEAR)->add(new DateInterval('P4D')),            "white",    "mobile", HIGHERSOLEMNITY);
$LitCal["FriOctaveEaster"]  = new Festivity($PROPRIUM_DE_TEMPORE["FriOctaveEaster"]["NAME_" . $LITSETTINGS->LOCALE], calcGregEaster($LITSETTINGS->YEAR)->add(new DateInterval('P5D')),            "white",    "mobile", HIGHERSOLEMNITY);
$LitCal["SatOctaveEaster"]  = new Festivity($PROPRIUM_DE_TEMPORE["SatOctaveEaster"]["NAME_" . $LITSETTINGS->LOCALE], calcGregEaster($LITSETTINGS->YEAR)->add(new DateInterval('P6D')),            "white",    "mobile", HIGHERSOLEMNITY);

$SOLEMNITIES["MonOctaveEaster"] = $LitCal["MonOctaveEaster"]->date;
$SOLEMNITIES["TueOctaveEaster"] = $LitCal["TueOctaveEaster"]->date;
$SOLEMNITIES["WedOctaveEaster"] = $LitCal["WedOctaveEaster"]->date;
$SOLEMNITIES["ThuOctaveEaster"] = $LitCal["ThuOctaveEaster"]->date;
$SOLEMNITIES["FriOctaveEaster"] = $LitCal["FriOctaveEaster"]->date;
$SOLEMNITIES["SatOctaveEaster"] = $LitCal["SatOctaveEaster"]->date;


//3. Solemnities of the Lord, of the Blessed Virgin Mary, and of saints listed in the General Calendar
$LitCal["SacredHeart"]      = new Festivity($PROPRIUM_DE_TEMPORE["SacredHeart"]["NAME_" . $LITSETTINGS->LOCALE],    calcGregEaster($LITSETTINGS->YEAR)->add(new DateInterval('P' . (7 * 9 + 5) . 'D')),  "red",      "mobile", SOLEMNITY);
$SOLEMNITIES["SacredHeart"] = $LitCal["SacredHeart"]->date;

//Christ the King is calculated backwards from the first sunday of advent
$LitCal["ChristKing"]       = new Festivity($PROPRIUM_DE_TEMPORE["ChristKing"]["NAME_" . $LITSETTINGS->LOCALE],     DateTime::createFromFormat('!j-n-Y', '25-12-' . $LITSETTINGS->YEAR, new DateTimeZone('UTC'))->modify('last Sunday')->sub(new DateInterval('P' . (4 * 7) . 'D')),    "red",  "mobile", SOLEMNITY);
$SOLEMNITIES["ChristKing"]  = $LitCal["ChristKing"]->date;

//END MOBILE SOLEMNITIES

//START FIXED SOLEMNITIES
//even though Mary Mother of God is a fixed date solemnity, however it is found in the Proprium de Tempore and not in the Proprium de Sanctis
$LitCal["MotherGod"]        = new Festivity($PROPRIUM_DE_TEMPORE["MotherGod"]["NAME_" . $LITSETTINGS->LOCALE], DateTime::createFromFormat('!j-n-Y', '1-1-' . $LITSETTINGS->YEAR, new DateTimeZone('UTC')),      "white",    "fixed", SOLEMNITY);
$SOLEMNITIES["MotherGod"]           = $LitCal["MotherGod"]->date;


//all the other fixed date solemnities are found in the Proprium de Sanctis
//so we will look them up in the MySQL table of festivities of the Roman Calendar from the Proper of Saints
if ($result = $mysqli->query("SELECT * FROM LITURGY__calendar_propriumdesanctis WHERE GRADE = " . SOLEMNITY)) {
    while ($row = mysqli_fetch_assoc($result)) {
        $currentFeastDate = DateTime::createFromFormat('!j-n-Y', $row["DAY"] . '-' . $row["MONTH"] . '-' . $LITSETTINGS->YEAR, new DateTimeZone('UTC'));
        $LitCal[$row["TAG"]] = new Festivity($row["NAME_" . $LITSETTINGS->LOCALE], $currentFeastDate, $row["COLOR"], "fixed", $row["GRADE"], $row["COMMON"]);

        //A Solemnity impeded in any given year is transferred to the nearest day following designated in nn. 1-8 of the Tables given above (LY 60)
        //However if a solemnity is impeded by a Sunday of Advent, Lent or Easter Time, the solemnity is transferred to the Monday following,
        //or to the nearest free day, as laid down by the General Norms.
        //This affects Joseph, Husband of Mary (Mar 19), Annunciation (Mar 25), and Immaculate Conception (Dec 8).
        //It is not possible for a fixed date Solemnity to fall on a Sunday of Easter.

        //However, if a solemnity is impeded by Palm Sunday or by Easter Sunday, it is transferred to the first free day (Monday?)
        //after the Second Sunday of Easter (decision of the Congregation of Divine Worship, dated 22 April 1990, in Notitiæ vol. 26 [1990] num. 3/4, p. 160, Prot. CD 500/89).
        //Any other celebrations that are impeded are omitted for that year.

        /**
         * <<
         *   Quando vero sollemnitates in his dominicis (i.e. Adventus, Quadragesimae et Paschae), iuxta n.5 "Normarum universalium de anno liturgico et de calendario"
         * sabbato anticipari debent. Experientia autem pastoralis ostendit quod solutio huiusmodi nonnullas praebet difficultates praesertim quoad occurrentiam
         * celebrationis Missae vespertinae et II Vesperarum Liturgiae Horarum cuiusdam sollemnitatis cum celebratione Missae vespertinae et I Vesperarum diei dominicae.
         * [... Perciò facciamo la seguente modifica al n. 5 delle norme universali: ]
         * Sollemnitates autem in his dominicis occurrentes ad feriam secundam sequentem transferuntur, nisi agatur de occurrentia in Dominica in Palmis
         * aut in Dominica Resurrectionis Domini.
         *  >>
         *
         * http://www.cultodivino.va/content/cultodivino/it/rivista-notitiae/indici-annate/1990/284-285.html
         */

        if(in_array($currentFeastDate,$SOLEMNITIES)){

            //if Joseph, Husband of Mary (Mar 19) falls on Palm Sunday or during Holy Week, it is moved to the Saturday preceding Palm Sunday
            //this is correct and the reason for this is that, in this case, Annunciation will also fall during Holy Week,
            //and the Annunciation will be transferred to the Monday following the Second Sunday of Easter
            //Notitiæ vol. 42 [2006] num. 3/4, 475-476, p. 96
            //http://www.cultodivino.va/content/cultodivino/it/rivista-notitiae/indici-annate/2006/475-476.html
            if($row["TAG"] === "StJoseph" && $currentFeastDate >= $LitCal["PalmSun"]->date && $currentFeastDate <= $LitCal["Easter"]->date){
                $LitCal["StJoseph"]->date = calcGregEaster($LITSETTINGS->YEAR)->sub(new DateInterval('P8D'));
                $Messages[] = sprintf(
                    __("The Solemnity '%s' falls on %s in the year %d, the celebration has been transferred to %s (%s) as per the %s.", $LITSETTINGS->LOCALE),
                    $LitCal["StJoseph"]->name,
                    $LitCal[array_search($currentFeastDate,$SOLEMNITIES)]->name,
                    $LITSETTINGS->YEAR,
                    __("the Saturday preceding Palm Sunday",$LITSETTINGS->LOCALE),
                    $LITSETTINGS->LOCALE === 'LA' ? ( $LitCal["StJoseph"]->date->format('j') . ' ' . $LATIN_MONTHS[(int)$LitCal["StJoseph"]->date->format('n')] ) :
                        ( $LITSETTINGS->LOCALE === 'EN' ? $LitCal["StJoseph"]->date->format('F jS') :
                            trim(utf8_encode(strftime('%e %B', $LitCal["StJoseph"]->date->format('U'))))
                        ),
                    '<a href="http://www.cultodivino.va/content/cultodivino/it/rivista-notitiae/indici-annate/2006/475-476.html">' . __('Decree of the Congregation for Divine Worship', $LITSETTINGS->LOCALE) . '</a>'
                );
            }
            else if($row["TAG"] === "Annunciation" && $currentFeastDate >= $LitCal["PalmSun"]->date && $currentFeastDate <= $LitCal["Easter2"]->date){
                //if the Annunciation falls during Holy Week or within the Octave of Easter, it is transferred to the Monday after the Second Sunday of Easter.
                $LitCal["Annunciation"]->date = calcGregEaster($LITSETTINGS->YEAR)->add(new DateInterval('P8D'));
                $Messages[] = sprintf(
                    __("The Solemnity '%s' falls on %s in the year %d, the celebration has been transferred to %s (%s) as per the %s.", $LITSETTINGS->LOCALE),
                    $LitCal["Annunciation"]->name,
                    $LitCal[array_search($currentFeastDate,$SOLEMNITIES)]->name,
                    $LITSETTINGS->YEAR,
                    __('the Monday following the Second Sunday of Easter',$LITSETTINGS->LOCALE),
                    $LITSETTINGS->LOCALE === 'LA' ? ( $LitCal["Annunciation"]->date->format('j') . ' ' . $LATIN_MONTHS[(int)$LitCal["Annunciation"]->date->format('n')] ) :
                        ( $LITSETTINGS->LOCALE === 'EN' ? $LitCal["Annunciation"]->date->format('F jS') :
                            trim(utf8_encode(strftime('%e %B', $LitCal["Annunciation"]->date->format('U'))))
                        ),
                    '<a href="http://www.cultodivino.va/content/cultodivino/it/rivista-notitiae/indici-annate/2006/475-476.html">' . __('Decree of the Congregation for Divine Worship', $LITSETTINGS->LOCALE) . '</a>'
                );

                //In some German churches it was the custom to keep the office of the Annunciation on the Saturday before Palm Sunday if the 25th of March fell in Holy Week.
                //source: http://www.newadvent.org/cathen/01542a.htm
                /*
                    else if($LitCal["Annunciation"]->date == $LitCal["PalmSun"]->date){
                    $LitCal["Annunciation"]->date->add(new DateInterval('P15D'));
                    //$LitCal["Annunciation"]->date->sub(new DateInterval('P1D'));
                    }
                */

            }
            else if(in_array($row["TAG"],["Annunciation","StJoseph","ImmaculateConception"]) && in_array($currentFeastDate,$SUNDAYS_ADVENT_LENT_EASTER)){
                $LitCal[$row["TAG"]]->date = clone($currentFeastDate);
                $LitCal[$row["TAG"]]->date->add(new DateInterval('P1D'));
                $Messages[] = sprintf(
                    __("The Solemnity '%s' falls on %s in the year %d, the celebration has been transferred to %s (%s) as per the %s.", $LITSETTINGS->LOCALE),
                    $LitCal[$row["TAG"]]->name,
                    $LitCal[array_search($currentFeastDate,$SOLEMNITIES)]->name,
                    $LITSETTINGS->YEAR,
                    __("the following Monday",$LITSETTINGS->LOCALE),
                    $LITSETTINGS->LOCALE === 'LA' ? ( $LitCal[$row["TAG"]]->date->format('j') . ' ' . $LATIN_MONTHS[(int)$LitCal[$row["TAG"]]->date->format('n')] ) :
                        ( $LITSETTINGS->LOCALE === 'EN' ? $LitCal[$row["TAG"]]->date->format('F jS') :
                            trim(utf8_encode(strftime('%e %B', $LitCal[$row["TAG"]]->date->format('U'))))
                        ),
                    '<a href="http://www.cultodivino.va/content/cultodivino/it/rivista-notitiae/indici-annate/1990/284-285.html">' . __('Decree of the Congregation for Divine Worship', $LITSETTINGS->LOCALE) . '</a>'
                );
            }
            else{
                //In all other cases, let's make a note of what's happening and ask the Congegation for Divine Worship
                $Messages[] = '<span style="padding:3px 6px; font-weight: bold; background-color: #FFC;color:Red;border-radius:6px;">IMPORTANT</span> ' . sprintf(
                    __("The Solemnity '%s' coincides with the Solemnity '%s' in the year %d. We should ask the Congregation for Divine Worship what to do about this!", $LITSETTINGS->LOCALE),
                    $row["NAME_" . $LITSETTINGS->LOCALE],
                    $LitCal[array_search($currentFeastDate,$SOLEMNITIES)]->name,
                    $LITSETTINGS->YEAR
                );
            }

            //In the year 2022, the Solemnity Nativity of John the Baptist coincides with the Solemnity of the Sacred Heart
            //Nativity of John the Baptist anticipated by one day to June 23
            //(except in cases where John the Baptist is patron of a nation, diocese, city or religious community, then the Sacred Heart can be anticipated by one day to June 23)
            //http://www.cultodivino.va/content/cultodivino/it/documenti/responsa-ad-dubia/2020/de-calendario-liturgico-2022.html
            //This will happen again in 2033 and 2044
            if($row["TAG"] === "NativityJohnBaptist" && array_search($currentFeastDate,$SOLEMNITIES) === "SacredHeart" ){
                $NativityJohnBaptistNewDate = clone($LitCal["NativityJohnBaptist"]->date);
                if( !in_array( $NativityJohnBaptistNewDate->sub(new DateInterval('P1D')), $SOLEMNITIES ) ){
                    $LitCal["NativityJohnBaptist"]->date->sub(new DateInterval('P1D'));
                    $Messages[] = '<span style="padding:3px 6px; font-weight: bold; background-color: #FFC;color:Red;border-radius:6px;">IMPORTANT</span> ' . sprintf(
                        __("Seeing that the Solemnity '%s' coincides with the Solemnity '%s' in the year %d, it has been anticipated by one day as per %s.", $LITSETTINGS->LOCALE),
                        $LitCal["NativityJohnBaptist"]->name,
                        $LitCal["SacredHeart"]->name,
                        $LITSETTINGS->YEAR,
                        '<a href="http://www.cultodivino.va/content/cultodivino/it/documenti/responsa-ad-dubia/2020/de-calendario-liturgico-2022.html">' . __('Decree of the Congregation for Divine Worship', $LITSETTINGS->LOCALE) . '</a>'
                    );
                }
            }
        }
    }
}


$SOLEMNITIES["NativityJohnBaptist"] = $LitCal["NativityJohnBaptist"]->date;
$SOLEMNITIES["StsPeterPaulAp"]      = $LitCal["StsPeterPaulAp"]->date;
$SOLEMNITIES["Assumption"]          = $LitCal["Assumption"]->date;
$SOLEMNITIES["AllSaints"]           = $LitCal["AllSaints"]->date;
$SOLEMNITIES["AllSouls"]            = $LitCal["AllSouls"]->date;
$SOLEMNITIES["StJoseph"]            = $LitCal["StJoseph"]->date;
$SOLEMNITIES["Annunciation"]        = $LitCal["Annunciation"]->date;
$SOLEMNITIES["ImmaculateConception"]= $LitCal["ImmaculateConception"]->date;

//let's add a displayGrade property for AllSouls so applications don't have to worry about fixing it
$LitCal["AllSouls"]->displayGrade = strip_tags(__("COMMEMORATION",$LITSETTINGS->LOCALE));

$SOLEMNITIES_LORD_BVM = [
    "Easter",
    "Christmas",
    "Ascension",
    "Pentecost",
    "Trinity",
    "CorpusChristi",
    "SacredHeart",
    "ChristKing",
    "MotherGod",
    "Annunciation",
    "ImmaculateConception",
    "Assumption",
    "StJoseph",
    "NativityJohnBaptist"
];

//4. Proper solemnities
//TODO: Intregrate proper solemnities

// END SOLEMNITIES, BOTH MOBILE AND FIXED

//II.
//5. FEASTS OF THE LORD IN THE GENERAL CALENDAR

//Baptism of the Lord is celebrated the Sunday after Epiphany, for exceptions see immediately below...
$BaptismLordFmt = '6-1-' . $LITSETTINGS->YEAR;
$BaptismLordMod = 'next Sunday';
//If Epiphany is celebrated on Sunday between Jan. 2 - Jan 8, and Jan. 7 or Jan. 8 is Sunday, then Baptism of the Lord is celebrated on the Monday immediately following that Sunday
if (EPIPHANY === "SUNDAY_JAN2_JAN8") {
    if ((int)DateTime::createFromFormat('!j-n-Y', '7-1-' . $LITSETTINGS->YEAR, new DateTimeZone('UTC'))->format('N') === 7) {
        $BaptismLordFmt = '7-1-' . $LITSETTINGS->YEAR;
        $BaptismLordMod = 'next Monday';
    } else if ((int)DateTime::createFromFormat('!j-n-Y', '8-1-' . $LITSETTINGS->YEAR, new DateTimeZone('UTC'))->format('N') === 7) {
        $BaptismLordFmt = '8-1-' . $LITSETTINGS->YEAR;
        $BaptismLordMod = 'next Monday';
    }
}
$LitCal["BaptismLord"]      = new Festivity($PROPRIUM_DE_TEMPORE["BaptismLord"]["NAME_" . $LITSETTINGS->LOCALE], DateTime::createFromFormat('!j-n-Y', $BaptismLordFmt, new DateTimeZone('UTC'))->modify($BaptismLordMod), "white", "mobile", FEASTLORD);
$SOLEMNITIES["BaptismLord"]     = $LitCal["BaptismLord"]->date;

//the other feasts of the Lord (Presentation, Transfiguration and Triumph of the Holy Cross) are fixed date feasts
//and are found in the Proprium de Sanctis
//so we will look them up in the MySQL table of festivities of the Roman Calendar from the Proper of Saints
if ($result = $mysqli->query("SELECT * FROM LITURGY__calendar_propriumdesanctis WHERE GRADE = " . FEASTLORD)) {
    while ($row = mysqli_fetch_assoc($result)) {
        $currentFeastDate = DateTime::createFromFormat('!j-n-Y', $row["DAY"] . '-' . $row["MONTH"] . '-' . $LITSETTINGS->YEAR, new DateTimeZone('UTC'));
        $LitCal[$row["TAG"]] = new Festivity($row["NAME_" . $LITSETTINGS->LOCALE], $currentFeastDate, $row["COLOR"], "fixed", $row["GRADE"], $row["COMMON"]);
    }
}
$SOLEMNITIES["Presentation"]    = $LitCal["Presentation"]->date;
$SOLEMNITIES["Transfiguration"] = $LitCal["Transfiguration"]->date;
$SOLEMNITIES["ExaltationCross"] = $LitCal["ExaltationCross"]->date;

//Holy Family is celebrated the Sunday after Christmas, unless Christmas falls on a Sunday, in which case it is celebrated Dec. 30
if ((int)DateTime::createFromFormat('!j-n-Y', '25-12-' . $LITSETTINGS->YEAR, new DateTimeZone('UTC'))->format('N') === 7) {
    $LitCal["HolyFamily"]   = new Festivity($PROPRIUM_DE_TEMPORE["HolyFamily"]["NAME_" . $LITSETTINGS->LOCALE], DateTime::createFromFormat('!j-n-Y', '30-12-' . $LITSETTINGS->YEAR, new DateTimeZone('UTC')),           "white",    "mobile", FEASTLORD);
    $Messages[] = sprintf(
        __("'%s' falls on a Sunday in the year %d, therefore the Feast '%s' is celebrated on %s rather than on the Sunday after Christmas.", $LITSETTINGS->LOCALE),
        $LitCal["Christmas"]->name,
        $LITSETTINGS->YEAR,
        $LitCal["HolyFamily"]->name,
        $LITSETTINGS->LOCALE === 'LA' ? ( $LitCal["HolyFamily"]->date->format('j') . ' ' . $LATIN_MONTHS[(int)$LitCal["HolyFamily"]->date->format('n')] ) :
            ( $LITSETTINGS->LOCALE === 'EN' ? $LitCal["HolyFamily"]->date->format('F jS') :
                trim(utf8_encode(strftime('%e %B', $LitCal["HolyFamily"]->date->format('U'))))
            )
    );
} else {
    $LitCal["HolyFamily"]   = new Festivity($PROPRIUM_DE_TEMPORE["HolyFamily"]["NAME_" . $LITSETTINGS->LOCALE], DateTime::createFromFormat('!j-n-Y', '25-12-' . $LITSETTINGS->YEAR, new DateTimeZone('UTC'))->modify('next Sunday'),                                          "white", "mobile", FEASTLORD);
}
$SOLEMNITIES["HolyFamily"]      = $LitCal["HolyFamily"]->date;
//END FEASTS OF OUR LORD


//If a fixed date Solemnity occurs on a Sunday of Ordinary Time or on a Sunday of Christmas, the Solemnity is celebrated in place of the Sunday. (e.g., Birth of John the Baptist, 1990)
//If a fixed date Feast of the Lord occurs on a Sunday in Ordinary Time, the feast is celebrated in place of the Sunday


//6. SUNDAYS OF CHRISTMAS TIME AND SUNDAYS IN ORDINARY TIME

//Sundays of Ordinary Time in the First part of the year are numbered from after the Baptism of the Lord (which begins the 1st week of Ordinary Time) until Ash Wednesday
$firstOrdinary = DateTime::createFromFormat('!j-n-Y', $BaptismLordFmt, new DateTimeZone('UTC'))->modify($BaptismLordMod);
//Basically we take Ash Wednesday as the limit...
//Here is (Ash Wednesday - 7) since one more cycle will complete...
$firstOrdinaryLimit = calcGregEaster($LITSETTINGS->YEAR)->sub(new DateInterval('P53D'));
$ordSun = 1;
while ($firstOrdinary >= $LitCal["BaptismLord"]->date && $firstOrdinary < $firstOrdinaryLimit) {
    $firstOrdinary = DateTime::createFromFormat('!j-n-Y', $BaptismLordFmt, new DateTimeZone('UTC'))->modify($BaptismLordMod)->modify('next Sunday')->add(new DateInterval('P' . (($ordSun - 1) * 7) . 'D'));
    $ordSun++;
    if (!in_array($firstOrdinary, $SOLEMNITIES)) {
        $LitCal["OrdSunday" . $ordSun] = new Festivity($PROPRIUM_DE_TEMPORE["OrdSunday" . $ordSun]["NAME_" . $LITSETTINGS->LOCALE], $firstOrdinary, "green", "mobile", FEASTLORD);
        //add Sundays to our priority list for next checking against ordinary Feasts not of Our Lord
        $SOLEMNITIES["OrdSunday" . $ordSun]      = $firstOrdinary;

    } else {
        $Messages[] = sprintf(
            __("'%s' is superseded by the %s '%s' in the year %d.",$LITSETTINGS->LOCALE),
            $PROPRIUM_DE_TEMPORE["OrdSunday" . $ordSun]["NAME_" . $LITSETTINGS->LOCALE],
            $LitCal[array_search($firstOrdinary,$SOLEMNITIES)]->grade > SOLEMNITY ? '<i>' . _G($LitCal[array_search($firstOrdinary,$SOLEMNITIES)]->grade,$LITSETTINGS->LOCALE,false) . '</i>' : _G($LitCal[array_search($firstOrdinary,$SOLEMNITIES)]->grade,$LITSETTINGS->LOCALE,false),
            $LitCal[array_search($firstOrdinary,$SOLEMNITIES)]->name,
            $LITSETTINGS->YEAR
        );
    }
}


//Sundays of Ordinary Time in the Latter part of the year are numbered backwards from Christ the King (34th) to Pentecost
$lastOrdinary = DateTime::createFromFormat('!j-n-Y', '25-12-' . $LITSETTINGS->YEAR, new DateTimeZone('UTC'))->modify('last Sunday')->sub(new DateInterval('P' . (4 * 7) . 'D'));
//We take Trinity Sunday as the limit...
//Here is (Trinity Sunday + 7) since one more cycle will complete...
$lastOrdinaryLowerLimit = calcGregEaster($LITSETTINGS->YEAR)->add(new DateInterval('P' . (7 * 9) . 'D'));
$ordSun = 34;
$ordSunCycle = 4;

while ($lastOrdinary <= $LitCal["ChristKing"]->date && $lastOrdinary > $lastOrdinaryLowerLimit) {
    $lastOrdinary = DateTime::createFromFormat('!j-n-Y', '25-12-' . $LITSETTINGS->YEAR, new DateTimeZone('UTC'))->modify('last Sunday')->sub(new DateInterval('P' . (++$ordSunCycle * 7) . 'D'));
    $ordSun--;
    if (!in_array($lastOrdinary, $SOLEMNITIES)) {
        $LitCal["OrdSunday" . $ordSun] = new Festivity($PROPRIUM_DE_TEMPORE["OrdSunday" . $ordSun]["NAME_" . $LITSETTINGS->LOCALE], $lastOrdinary, "green", "mobile", FEASTLORD);
        //add Sundays to our priority list for next checking against ordinary Feasts not of Our Lord
        $SOLEMNITIES["OrdSunday" . $ordSun]      = $lastOrdinary;
    } else {
        $Messages[] = sprintf(
            __("'%s' is superseded by the %s '%s' in the year %d.",$LITSETTINGS->LOCALE),
            $PROPRIUM_DE_TEMPORE["OrdSunday" . $ordSun]["NAME_" . $LITSETTINGS->LOCALE],
            $LitCal[array_search($lastOrdinary,$SOLEMNITIES)]->grade > SOLEMNITY ? '<i>' . _G($LitCal[array_search($lastOrdinary,$SOLEMNITIES)]->grade,$LITSETTINGS->LOCALE,false) . '</i>' : _G($LitCal[array_search($lastOrdinary,$SOLEMNITIES)]->grade,$LITSETTINGS->LOCALE,false),
            $LitCal[array_search($lastOrdinary,$SOLEMNITIES)]->name,
            $LITSETTINGS->YEAR
        );
    }
}

//END SUNDAYS OF CHRISTMAS TIME AND SUNDAYS IN ORDINARY TIME


//7. FEASTS OF THE BLESSED VIRGIN MARY AND OF THE SAINTS IN THE GENERAL CALENDAR

//We will look up Feasts from the MySQL table of festivities of the General Roman Calendar
//First we get the Calendarium Romanum Generale from the Missale Romanum Editio Typica 1970
if ($result = $mysqli->query("SELECT * FROM LITURGY__calendar_propriumdesanctis WHERE GRADE = " . FEAST)) {
    while ($row = mysqli_fetch_assoc($result)) {

        //If a Feast (not of the Lord) occurs on a Sunday in Ordinary Time, the Sunday is celebrated.  (e.g., St. Luke, 1992)
        //obviously solemnities also have precedence
        $currentFeastDate = DateTime::createFromFormat('!j-n-Y', $row["DAY"] . '-' . $row["MONTH"] . '-' . $LITSETTINGS->YEAR, new DateTimeZone('UTC'));
        if ((int)$currentFeastDate->format('N') !== 7 && !in_array($currentFeastDate, $SOLEMNITIES)) {
            $LitCal[$row["TAG"]] = new Festivity($row["NAME_" . $LITSETTINGS->LOCALE], $currentFeastDate, $row["COLOR"], "fixed", $row["GRADE"], $row["COMMON"]);
            $FEASTS_MEMORIALS[$row["TAG"]]      = $currentFeastDate;
        } else {
            $coincidingFestivity = $LitCal[array_search($currentFeastDate,$SOLEMNITIES)];
            $coincidingFestivity_grade = '';
            if((int)$currentFeastDate->format('N') === 7 && $coincidingFestivity->grade < SOLEMNITY ){
                //it's a Sunday
                $coincidingFestivity_grade = $LITSETTINGS->LOCALE === 'LA' ? 'Die Domini' : ucfirst(utf8_encode(strftime('%A',$currentFeastDate->format('U'))));
            } else{
                //it's a Feast of the Lord or a Solemnity
                $coincidingFestivity_grade = ($coincidingFestivity->grade > SOLEMNITY ? '<i>' . _G($coincidingFestivity->grade,$LITSETTINGS->LOCALE,false) . '</i>' : _G($coincidingFestivity->grade,$LITSETTINGS->LOCALE,false));
            }

            $Messages[] = sprintf(
                __("The %s '%s', usually celebrated on %s, is suppressed by the %s '%s' in the year %d.",$LITSETTINGS->LOCALE),
                _G($row["GRADE"],$LITSETTINGS->LOCALE,false),
                $row["NAME_" . $LITSETTINGS->LOCALE],
                $LITSETTINGS->LOCALE === 'LA' ? ( $currentFeastDate->format('j') . ' ' . $LATIN_MONTHS[(int)$currentFeastDate->format('n')] ) :
                    ( $LITSETTINGS->LOCALE === 'EN' ? $currentFeastDate->format('F jS') :
                        trim(utf8_encode(strftime('%e %B', $currentFeastDate->format('U'))))
                    ),
                $coincidingFestivity_grade,
                $coincidingFestivity->name,
                $LITSETTINGS->YEAR
            );
        }
    }
}

//With the decree Apostolorum Apostola (June 3rd 2016), the Congregation for Divine Worship
//with the approval of Pope Francis elevated the memorial of Saint Mary Magdalen to a Feast
//source: http://www.vatican.va/roman_curia/congregations/ccdds/documents/articolo-roche-maddalena_it.pdf
//This is taken care of ahead when the memorials are created, see comment tag MARYMAGDALEN:


//END FEASTS OF THE BLESSED VIRGIN MARY AND OF THE SAINTS IN THE GENERAL CALENDAR

//TODO: implement the following section 8
//8. PROPER FEASTS:
//a) feast of the principal patron of the Diocese - for pastoral reasons can be celebrated as a solemnity (PC 8, 9)
//b) feast of the anniversary of the Dedication of the cathedral church
//c) feast of the principal Patron of the region or province, of a nation or a wider territory - for pastoral reasons can be celebrated as a solemnity (PC 8, 9)
//d) feast of the titular, of the founder, of the principal patron of an Order or Congregation and of the religious province, without prejudice to the prescriptions of n. 4 d
//e) other feasts proper to an individual church
//f) other feasts inscribed in the calendar of a diocese or of a religious order or congregation

//9. WEEKDAYS of ADVENT FROM 17 DECEMBER TO 24 DECEMBER INCLUSIVE
//  Here we are calculating all weekdays of Advent, but we are giving a certain importance to the weekdays of Advent from 17 Dec. to 24 Dec.
//	(the same will be true of the Octave of Christmas and weekdays of Lent)
//  on which days obligatory memorials can only be celebrated in partial form

$DoMAdvent1 = $LitCal["Advent1"]->date->format('j'); //DoM == Day of Month
$MonthAdvent1 = $LitCal["Advent1"]->date->format('n');
$weekdayAdvent = DateTime::createFromFormat('!j-n-Y', $DoMAdvent1 . '-' . $MonthAdvent1 . '-' . $LITSETTINGS->YEAR, new DateTimeZone('UTC'));
$weekdayAdventCnt = 1;
while ($weekdayAdvent >= $LitCal["Advent1"]->date && $weekdayAdvent < $LitCal["Christmas"]->date) {
    $weekdayAdvent = DateTime::createFromFormat('!j-n-Y', $DoMAdvent1 . '-' . $MonthAdvent1 . '-' . $LITSETTINGS->YEAR, new DateTimeZone('UTC'))->add(new DateInterval('P' . $weekdayAdventCnt . 'D'));

    //if we're not dealing with a sunday or a solemnity, then create the weekday
    if (!in_array($weekdayAdvent, $SOLEMNITIES) && !in_array($weekdayAdvent, $FEASTS_MEMORIALS) && (int)$weekdayAdvent->format('N') !== 7) {
        $upper = (int)$weekdayAdvent->format('z');
        $diff = $upper - (int)$LitCal["Advent1"]->date->format('z'); //day count between current day and First Sunday of Advent
        $currentAdvWeek = (($diff - $diff % 7) / 7) + 1; //week count between current day and First Sunday of Advent

        $ordinal = ucfirst(getOrdinal($currentAdvWeek,$LITSETTINGS->LOCALE,$formatterFem,$LATIN_ORDINAL_FEM_GEN));
        $LitCal["AdventWeekday" . $weekdayAdventCnt] = new Festivity(($LITSETTINGS->LOCALE == 'LA' ? $LATIN_DAYOFTHEWEEK[$weekdayAdvent->format('w')] : ucfirst(utf8_encode(strftime('%A',$weekdayAdvent->format('U'))))) . " " . sprintf(__("of the %s Week of Advent",$LITSETTINGS->LOCALE),$ordinal), $weekdayAdvent, "purple", "mobile");
        // Weekday of Advent from 17 to 24 Dec.
        if ($LitCal["AdventWeekday" . $weekdayAdventCnt]->date->format('j') >= 17 && $LitCal["AdventWeekday" . $weekdayAdventCnt]->date->format('j') <= 24) {
            array_push($WEEKDAYS_ADVENT_CHRISTMAS_LENT, $LitCal["AdventWeekday" . $weekdayAdventCnt]->date);
        }
    }

    $weekdayAdventCnt++;
}

//WEEKDAYS of the Octave of Christmas
$weekdayChristmas = DateTime::createFromFormat('!j-n-Y', '25-12-' . $LITSETTINGS->YEAR, new DateTimeZone('UTC'));
$weekdayChristmasCnt = 1;
while ($weekdayChristmas >= $LitCal["Christmas"]->date && $weekdayChristmas < DateTime::createFromFormat('!j-n-Y', '31-12-' . $LITSETTINGS->YEAR, new DateTimeZone('UTC'))) {
    $weekdayChristmas = DateTime::createFromFormat('!j-n-Y', '25-12-' . $LITSETTINGS->YEAR, new DateTimeZone('UTC'))->add(new DateInterval('P' . $weekdayChristmasCnt . 'D'));

    if (!in_array($weekdayChristmas, $SOLEMNITIES) && !in_array($weekdayChristmas, $FEASTS_MEMORIALS) && (int)$weekdayChristmas->format('N') !== 7) {

        //$upper = (int)$weekdayChristmas->format('z');
        //$diff = $upper - (int)$LitCal["Easter"]->date->format('z'); //day count between current day and Easter Sunday
        //$currentEasterWeek = (($diff - $diff % 7) / 7) + 1; //week count between current day and Easter Sunday

        //($weekdayChristmasCnt + 1) . ordSuffix($weekdayChristmasCnt + 1)
        $ordinal = ucfirst(getOrdinal(($weekdayChristmasCnt + 1),$LITSETTINGS->LOCALE,$formatter,$LATIN_ORDINAL));
        $LitCal["ChristmasWeekday" . $weekdayChristmasCnt] = new Festivity(sprintf(__("%s Day of the Octave of Christmas",$LITSETTINGS->LOCALE),$ordinal), $weekdayChristmas, "white", "mobile");
        array_push($WEEKDAYS_ADVENT_CHRISTMAS_LENT, $LitCal["ChristmasWeekday" . $weekdayChristmasCnt]->date);
    }

    $weekdayChristmasCnt++;
}

//WEEKDAYS of LENT

$DoMAshWednesday = $LitCal["AshWednesday"]->date->format('j');
$MonthAshWednesday = $LitCal["AshWednesday"]->date->format('n');
$weekdayLent = DateTime::createFromFormat('!j-n-Y', $DoMAshWednesday . '-' . $MonthAshWednesday . '-' . $LITSETTINGS->YEAR, new DateTimeZone('UTC'));
$weekdayLentCnt = 1;
while ($weekdayLent >= $LitCal["AshWednesday"]->date && $weekdayLent < $LitCal["PalmSun"]->date) {
    $weekdayLent = DateTime::createFromFormat('!j-n-Y', $DoMAshWednesday . '-' . $MonthAshWednesday . '-' . $LITSETTINGS->YEAR, new DateTimeZone('UTC'))->add(new DateInterval('P' . $weekdayLentCnt . 'D'));

    if (!in_array($weekdayLent, $SOLEMNITIES) && (int)$weekdayLent->format('N') !== 7) {

        if ($weekdayLent > $LitCal["Lent1"]->date) {
            $upper = (int)$weekdayLent->format('z');
            $diff = $upper - (int)$LitCal["Lent1"]->date->format('z'); //day count between current day and First Sunday of Lent
            $currentLentWeek = (($diff - $diff % 7) / 7) + 1; //week count between current day and First Sunday of Lent
            $ordinal = ucfirst(getOrdinal($currentLentWeek,$LITSETTINGS->LOCALE,$formatterFem,$LATIN_ORDINAL_FEM_GEN));
            $LitCal["LentWeekday" . $weekdayLentCnt] = new Festivity(($LITSETTINGS->LOCALE == 'LA' ? $LATIN_DAYOFTHEWEEK[$weekdayLent->format('w')] : ucfirst(utf8_encode(strftime('%A',$weekdayLent->format('U'))))) . " ".  sprintf(__("of the %s Week of Lent",$LITSETTINGS->LOCALE),$ordinal), $weekdayLent, "purple", "mobile");
        } else {
            $LitCal["LentWeekday" . $weekdayLentCnt] = new Festivity(($LITSETTINGS->LOCALE == 'LA' ? $LATIN_DAYOFTHEWEEK[$weekdayLent->format('w')] : ucfirst(utf8_encode(strftime('%A',$weekdayLent->format('U'))))) . " ". __("after Ash Wednesday",$LITSETTINGS->LOCALE), $weekdayLent, "purple", "mobile");
        }
        array_push($WEEKDAYS_ADVENT_CHRISTMAS_LENT, $LitCal["LentWeekday" . $weekdayLentCnt]->date);
    }

    $weekdayLentCnt++;
}

//III.
//10. Obligatory memorials in the General Calendar
$ImmaculateHeart_date = calcGregEaster($LITSETTINGS->YEAR)->add(new DateInterval('P' . (7 * 9 + 6) . 'D'));
if (!in_array($ImmaculateHeart_date, $SOLEMNITIES) && !in_array($ImmaculateHeart_date, $FEASTS_MEMORIALS) ) {
    //Immaculate Heart of Mary fixed on the Saturday following the second Sunday after Pentecost
    //(see Calendarium Romanum Generale in Missale Romanum Editio Typica 1970)
    //Pentecost = calcGregEaster($LITSETTINGS->YEAR)->add(new DateInterval('P'.(7*7).'D'))
    //Second Sunday after Pentecost = calcGregEaster($LITSETTINGS->YEAR)->add(new DateInterval('P'.(7*9).'D'))
    //Following Saturday = calcGregEaster($LITSETTINGS->YEAR)->add(new DateInterval('P'.(7*9+6).'D'))
    $LitCal["ImmaculateHeart"]  = new Festivity($PROPRIUM_DE_TEMPORE["ImmaculateHeart"]["NAME_" . $LITSETTINGS->LOCALE],       $ImmaculateHeart_date,  "white",      "mobile", MEMORIAL);
    $FEASTS_MEMORIALS["ImmaculateHeart"]      = $LitCal["ImmaculateHeart"]->date;

    //In years when this memorial coincides with another obligatory memorial, as happened in 2014 [28 June, Saint Irenaeus] and 2015 [13 June, Saint Anthony of Padua], both must be considered optional for that year
    //source: http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20000630_memoria-immaculati-cordis-mariae-virginis_lt.html
    //This is taken care of in the next code cycle, see tag IMMACULATEHEART: in the code comments ahead
} else {
    $coincidingFeast_grade = '';
    if(in_array($ImmaculateHeart_date, $SOLEMNITIES)){
        $coincidingFeast = $LitCal[array_search($ImmaculateHeart_date,$SOLEMNITIES)];
        if((int)$ImmaculateHeart_date->format('N') === 7 && $coincidingFeast->grade < SOLEMNITY ){
            $coincidingFestivity_grade = $LITSETTINGS->LOCALE === 'LA' ? 'Die Domini' : ucfirst(utf8_encode(strftime('%A',$currentFeastDate->format('U'))));
        } else {
            $coincidingFeast_grade = _G($coincidingFeast->grade,$LITSETTINGS->LOCALE);
        }
    }
    else if(in_array($ImmaculateHeart_date, $FEASTS_MEMORIALS)){
        $coincidingFeast = $LitCal[array_search($ImmaculateHeart_date,$FEASTS_MEMORIALS)];
        $coincidingFeast_grade = _G($coincidingFeast->grade,$LITSETTINGS->LOCALE);
    }
    $Messages[] = sprintf(
        __("The %s '%s', usually celebrated on %s, is suppressed by the %s '%s' in the year %d.",$LITSETTINGS->LOCALE),
        _G("MEMORIAL",$LITSETTINGS->LOCALE),
        $PROPRIUM_DE_TEMPORE["ImmaculateHeart"]["NAME_" . $LITSETTINGS->LOCALE],
        $LITSETTINGS->LOCALE === 'LA' ? ( $ImmaculateHeart_date->format('j') . ' ' . $LATIN_MONTHS[(int)$ImmaculateHeart_date->format('n')] ) :
            ( $LITSETTINGS->LOCALE === 'EN' ? $ImmaculateHeart_date->format('F jS') :
                trim(utf8_encode(strftime('%e %B', $ImmaculateHeart_date->format('U'))))
            ),
        $coincidingFeast_grade,
        $coincidingFeast->name,
        $LITSETTINGS->YEAR
    );
}

if ($result = $mysqli->query("SELECT * FROM LITURGY__calendar_propriumdesanctis WHERE GRADE = " . MEMORIAL)) {
    while ($row = mysqli_fetch_assoc($result)) {

        //If it doesn't occur on a Sunday or a Solemnity or a Feast of the Lord, then go ahead and create the Memorial
        $currentFeastDate = DateTime::createFromFormat('!j-n-Y', $row["DAY"] . '-' . $row["MONTH"] . '-' . $LITSETTINGS->YEAR, new DateTimeZone('UTC'));
        if ((int)$currentFeastDate->format('N') !== 7 && !in_array($currentFeastDate, $SOLEMNITIES) && !in_array($currentFeastDate, $FEASTS_MEMORIALS) ) {
            $LitCal[$row["TAG"]] = new Festivity($row["NAME_" . $LITSETTINGS->LOCALE], $currentFeastDate, $row["COLOR"], "fixed", $row["GRADE"], $row["COMMON"]);

            //If a fixed date Memorial falls within the Lenten season, it is reduced in rank to a Commemoration.
            if ($currentFeastDate > $LitCal["AshWednesday"]->date && $currentFeastDate < $LitCal["HolyThurs"]->date) {
                $LitCal[$row["TAG"]]->grade = COMMEMORATION;
                $Messages[] = sprintf(
                    __("The %s '%s' falls within the Lenten season in the year %d, rank reduced to Commemoration.",$LITSETTINGS->LOCALE),
                    _G($row["GRADE"],$LITSETTINGS->LOCALE),
                    $row["NAME_" . $LITSETTINGS->LOCALE],
                    $LITSETTINGS->YEAR
                );
            }

            //We can now add, for logical reasons, Feasts and Memorials to the $FEASTS_MEMORIALS array
            if ($LitCal[$row["TAG"]]->grade > MEMORIALOPT) {
                $FEASTS_MEMORIALS[$row["TAG"]]      = $currentFeastDate;

                //Also, while we're add it, let's remove the weekdays of Epiphany that get overriden by memorials
                if (false !== $key = array_search($LitCal[$row["TAG"]]->date, $WEEKDAYS_EPIPHANY)) {
                    $Messages[] = sprintf(
                        __("'%s' is superseded by the %s '%s' in the year %d.",$LITSETTINGS->LOCALE),
                        $LitCal[$key]->name,
                        _G($LitCal[$row["TAG"]]->grade,$LITSETTINGS->LOCALE,false),
                        $LitCal[$row["TAG"]]->name,
                        $LITSETTINGS->YEAR
                    );
                    unset($LitCal[$key]);
                }
                //IMMACULATEHEART: in years when the memorial of the Immaculate Heart of Mary coincides with another obligatory memorial,
                //as happened in 2014 [28 June, Saint Irenaeus] and 2015 [13 June, Saint Anthony of Padua], both must be considered optional for that year
                //source: http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20000630_memoria-immaculati-cordis-mariae-virginis_lt.html
                if (isset($LitCal["ImmaculateHeart"]) && $currentFeastDate == $LitCal["ImmaculateHeart"]->date) {
                    $LitCal["ImmaculateHeart"]->grade = MEMORIALOPT;
                    $LitCal[$row["TAG"]]->grade = MEMORIALOPT;
                    //unset($LitCal[$key]); $FEASTS_MEMORIALS ImmaculateHeart
                    $Messages[] = sprintf(
                        __("The Memorial '%s' coincides with another Memorial '%s' in the year %d. They are both reduced in rank to optional memorials (%s).",$LITSETTINGS->LOCALE),
                        $LitCal["ImmaculateHeart"]->name,
                        $LitCal[$row["TAG"]]->name,
                        $LITSETTINGS->YEAR,
                        '<a href="http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20000630_memoria-immaculati-cordis-mariae-virginis_lt.html">' . __('Decree of the Congregation for Divine Worship', $LITSETTINGS->LOCALE) . '</a>'
                    );
                }
            }


        } else {
            $coincidingFestivity_grade = '';
            if((int)$currentFeastDate->format('N') === 7 && $LitCal[array_search($currentFeastDate,$SOLEMNITIES)]->grade < SOLEMNITY ){
                //it's a Sunday
                $coincidingFestivity = $LitCal[array_search($currentFeastDate,$SOLEMNITIES)];
                $coincidingFestivity_grade = $LITSETTINGS->LOCALE === 'LA' ? 'Die Domini' : ucfirst(utf8_encode(strftime('%A',$currentFeastDate->format('U'))));
            } else if (in_array($currentFeastDate, $SOLEMNITIES)){
                //it's a Feast of the Lord or a Solemnity
                $coincidingFestivity = $LitCal[array_search($currentFeastDate,$SOLEMNITIES)];
                $coincidingFestivity_grade = ($coincidingFestivity->grade > SOLEMNITY ? '<i>' . _G($coincidingFestivity->grade,$LITSETTINGS->LOCALE,false) . '</i>' : _G($coincidingFestivity->grade,$LITSETTINGS->LOCALE,false));
            } else if(in_array($currentFeastDate, $FEASTS_MEMORIALS)){
                $coincidingFestivity = $LitCal[array_search($currentFeastDate,$FEASTS_MEMORIALS)];
                $coincidingFestivity_grade = _G($coincidingFestivity->grade,$LITSETTINGS->LOCALE,false);
            }

            $Messages[] = sprintf(
                __("The %s '%s', usually celebrated on %s, is suppressed by the %s '%s' in the year %d.",$LITSETTINGS->LOCALE),
                _G($row["GRADE"],$LITSETTINGS->LOCALE,false),
                $row["NAME_" . $LITSETTINGS->LOCALE],
                $LITSETTINGS->LOCALE === 'LA' ? ( $currentFeastDate->format('j') . ' ' . $LATIN_MONTHS[(int)$currentFeastDate->format('n')] ) :
                    ( $LITSETTINGS->LOCALE === 'EN' ? $currentFeastDate->format('F jS') :
                        trim(utf8_encode(strftime('%e %B', $currentFeastDate->format('U'))))
                    ),
                $coincidingFestivity_grade,
                $coincidingFestivity->name,
                $LITSETTINGS->YEAR
            );
        }
    }
}

//MARYMAGDALEN: With the decree Apostolorum Apostola (June 3rd 2016), the Congregation for Divine Worship
//with the approval of Pope Francis elevated the memorial of Saint Mary Magdalen to a Feast
//source: http://www.vatican.va/roman_curia/congregations/ccdds/documents/articolo-roche-maddalena_it.pdf
if ($LITSETTINGS->YEAR >= 2016) {
    if (array_key_exists("StMaryMagdalene",$LitCal)) {
        if ($LitCal["StMaryMagdalene"]->grade == MEMORIAL) {
            $Messages[] = sprintf(
                __("The %s '%s' has been raised to the rank of %s since the year %d, applicable to the year %d (%s).",$LITSETTINGS->LOCALE),
                _G($LitCal["StMaryMagdalene"]->grade,$LITSETTINGS->LOCALE),
                $LitCal["StMaryMagdalene"]->name,
                _G(FEAST,$LITSETTINGS->LOCALE),
                2016,
                $LITSETTINGS->YEAR,
                '<a href="http://www.vatican.va/roman_curia/congregations/ccdds/documents/articolo-roche-maddalena_it.pdf">' . __("Decree of the Congregation for Divine Worship", $LITSETTINGS->LOCALE) . '</a>'
            );
            $LitCal["StMaryMagdalene"]->grade = FEAST;
        }
    }
}

//St Therese of the Child Jesus was proclaimed a Doctor of the Church in 1998
if(array_key_exists("StThereseChildJesus",$LitCal) && $LITSETTINGS->YEAR >= 1998){
    $etDoctor = '';
    switch($LITSETTINGS->LOCALE){
        case 'LA':
            $etDoctor = " et doctoris";
        break;
        case 'EN':
            $etDoctor = " and doctor of the Church";
        break;
        case 'IT':
            $etDoctor = " e dottore della Chiesa";
        break;
    }
    $LitCal['StThereseChildJesus']->name .= $etDoctor;
}


/*if we are dealing with a calendar from the year 2002 onwards we need to add the new obligatory memorials from the Tertia Editio Typica:
	14 augusti:  S. Maximiliani Mariæ Kolbe, presbyteri et martyris;
	20 septembris:  Ss. Andreæ Kim Taegon, presbyteri, et Pauli Chong Hasang et sociorum, martyrum;
	24 novembris:  Ss. Andreæ Dung-Lac, presbyteri, et sociorum, martyrum.
	source: http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20020327_card-medina-estevez_it.html
    */
if ($LITSETTINGS->YEAR >= 2002) {
    if ($result = $mysqli->query("SELECT * FROM LITURGY__calendar_propriumdesanctis_2002 WHERE GRADE = " . MEMORIAL)) {
        while ($row = mysqli_fetch_assoc($result)) {

            //If it doesn't occur on a Sunday or a Solemnity or a Feast of the Lord, then go ahead and create the Festivity
            $currentFeastDate = DateTime::createFromFormat('!j-n-Y', $row["DAY"] . '-' . $row["MONTH"] . '-' . $LITSETTINGS->YEAR, new DateTimeZone('UTC'));
            if ((int)$currentFeastDate->format('N') !== 7 && !in_array($currentFeastDate, $SOLEMNITIES) && !in_array($currentFeastDate, $FEASTS_MEMORIALS) ) {
                $LitCal[$row["TAG"]] = new Festivity($row["NAME_" . $LITSETTINGS->LOCALE], $currentFeastDate, $row["COLOR"], "fixed", $row["GRADE"], $row["COMMON"]);
                $Messages[] = sprintf(
                    __("The %s '%s' has been added on %s since the year %d (%s), applicable to the year %d.",$LITSETTINGS->LOCALE),
                    _G($row["GRADE"],$LITSETTINGS->LOCALE),
                    $row["NAME_" . $LITSETTINGS->LOCALE],
                    $LITSETTINGS->LOCALE === 'LA' ? ( $currentFeastDate->format('j') . ' ' . $LATIN_MONTHS[(int)$currentFeastDate->format('n')] ) :
                        ( $LITSETTINGS->LOCALE === 'EN' ? $currentFeastDate->format('F jS') :
                            trim(utf8_encode(strftime('%e %B', $currentFeastDate->format('U'))))
                        ),
                    2002,
                    '<a href="http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20020327_card-medina-estevez_it.html">' . __('Decree of the Congregation for Divine Worship', $LITSETTINGS->LOCALE) . '</a>',
                    $LITSETTINGS->YEAR
                );

                //If a fixed date Memorial falls within the Lenten season, it is reduced in rank to a Commemoration.
                if ($currentFeastDate > $LitCal["AshWednesday"]->date && $currentFeastDate < $LitCal["HolyThurs"]->date) {
                    $LitCal[$row["TAG"]]->grade = COMMEMORATION;
                    $Messages[] = sprintf(
                        __("The %s '%s', added on %s since the year %d (%s), falls within the Lenten season in the year %d, rank reduced to Commemoration.",$LITSETTINGS->LOCALE),
                        _G($row["GRADE"],$LITSETTINGS->LOCALE),
                        $row["NAME_" . $LITSETTINGS->LOCALE],
                        $LITSETTINGS->LOCALE === 'LA' ? ( $currentFeastDate->format('j') . ' ' . $LATIN_MONTHS[(int)$currentFeastDate->format('n')] ) :
                            ( $LITSETTINGS->LOCALE === 'EN' ? $currentFeastDate->format('F jS') :
                                trim(utf8_encode(strftime('%e %B', $currentFeastDate->format('U'))))
                            ),
                        2002,
                        '<a href="http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20020327_card-medina-estevez_it.html">' . __('Decree of the Congregation for Divine Worship', $LITSETTINGS->LOCALE) . '</a>',
                        $LITSETTINGS->YEAR
                    );
                }

                //We can now add, for logical reasons, Feasts and Memorials to the $FEASTS_MEMORIALS array
                if ($LitCal[$row["TAG"]]->grade > MEMORIALOPT) {
                    $FEASTS_MEMORIALS[$row["TAG"]]      = $currentFeastDate;

                    //Also, while we're add it, let's remove the weekdays of Epiphany that get overriden by memorials
                    if (false !== $key = array_search($LitCal[$row["TAG"]]->date, $WEEKDAYS_EPIPHANY)) {
                        $Messages[] = sprintf(
                            __("In the year %d '%s' is superseded by the %s '%s', added on %s since the year %d (%s).",$LITSETTINGS->LOCALE),
                            $LITSETTINGS->YEAR,
                            $LitCal[$key]->name,
                            _G($LitCal[$row["TAG"]]->grade,$LITSETTINGS->LOCALE),
                            $LitCal[$row["TAG"]]->name,
                            $LITSETTINGS->LOCALE === 'LA' ? ( $currentFeastDate->format('j') . ' ' . $LATIN_MONTHS[(int)$currentFeastDate->format('n')] ) :
                                ( $LITSETTINGS->LOCALE === 'EN' ? $currentFeastDate->format('F jS') :
                                    trim(utf8_encode(strftime('%e %B', $currentFeastDate->format('U'))))
                                ),
                            2002,
                            '<a href="http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20020327_card-medina-estevez_it.html">' . __('Decree of the Congregation for Divine Worship', $LITSETTINGS->LOCALE) . '</a>'
                        );
                        unset($LitCal[$key]);
                    }
                    //IMMACULATEHEART: in years when the memorial of the Immaculate Heart of Mary coincides with another obligatory memorial,
                    //as happened in 2014 [28 June, Saint Irenaeus] and 2015 [13 June, Saint Anthony of Padua], both must be considered optional for that year
                    //source: http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20000630_memoria-immaculati-cordis-mariae-virginis_lt.html
                    if (isset($LitCal["ImmaculateHeart"]) && $currentFeastDate == $LitCal["ImmaculateHeart"]->date) {
                        $LitCal["ImmaculateHeart"]->grade = MEMORIALOPT;
                        $LitCal[$row["TAG"]]->grade = MEMORIALOPT;
                        //unset($LitCal[$key]); $FEASTS_MEMORIALS ImmaculateHeart
                        $Messages[] = sprintf(
                            __("The Memorial '%s' coincides with another Memorial '%s' in the year %d. They are both reduced in rank to optional memorials (%s).",$LITSETTINGS->LOCALE),
                            $LitCal["ImmaculateHeart"]->name,
                            $LitCal[$row["TAG"]]->name,
                            $LITSETTINGS->YEAR,
                            '<a href="http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20000630_memoria-immaculati-cordis-mariae-virginis_lt.html">' . __('Decree of the Congregation for Divine Worship', $LITSETTINGS->LOCALE) . '</a>'
                        );
                    }
                }
            } else {
                $coincidingFestivity_grade = '';
                if((int)$currentFeastDate->format('N') === 7 && $LitCal[array_search($currentFeastDate,$SOLEMNITIES)]->grade < SOLEMNITY ){
                    //it's a Sunday
                    $coincidingFestivity = $LitCal[array_search($currentFeastDate,$SOLEMNITIES)];
                    $coincidingFestivity_grade = $LITSETTINGS->LOCALE === 'LA' ? 'Die Domini' : ucfirst(utf8_encode(strftime('%A',$currentFeastDate->format('U'))));
                } else if (in_array($currentFeastDate, $SOLEMNITIES)){
                    //it's a Feast of the Lord or a Solemnity
                    $coincidingFestivity = $LitCal[array_search($currentFeastDate,$SOLEMNITIES)];
                    $coincidingFestivity_grade = ($coincidingFestivity->grade > SOLEMNITY ? '<i>' . _G($coincidingFestivity->grade,$LITSETTINGS->LOCALE,false) . '</i>' : _G($coincidingFestivity->grade,$LITSETTINGS->LOCALE,false));
                } else if(in_array($currentFeastDate, $FEASTS_MEMORIALS)){
                    $coincidingFestivity = $LitCal[array_search($currentFeastDate,$SOLEMNITIES)];
                    $coincidingFestivity_grade = _G($coincidingFestivity->grade,$LITSETTINGS->LOCALE,false);
                }

                $Messages[] = sprintf(
                    __("The %s '%s', added in the Tertia Editio Typica of the Roman Missal since the year 2002 (%s) and usually celebrated on %s, is suppressed by the %s '%s' in the year %d.",$LITSETTINGS->LOCALE),
                    _G($row["GRADE"],$LITSETTINGS->LOCALE,false),
                    $row["NAME_" . $LITSETTINGS->LOCALE],
                    '<a href="http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20020327_card-medina-estevez_it.html">' . __('Decree of the Congregation for Divine Worship', $LITSETTINGS->LOCALE) . '</a>',
                    $LITSETTINGS->LOCALE === 'LA' ? ( $currentFeastDate->format('j') . ' ' . $LATIN_MONTHS[(int)$currentFeastDate->format('n')] ) :
                        ( $LITSETTINGS->LOCALE === 'EN' ? $currentFeastDate->format('F jS') :
                            trim(utf8_encode(strftime('%e %B', $currentFeastDate->format('U'))))
                        ),
                    $coincidingFestivity_grade,
                    $coincidingFestivity->name,
                    $LITSETTINGS->YEAR
                );
            }
        }
    }
}


//With the Decree of the Congregation of Divine Worship on March 24, 2018,
//the Obligatory Memorial of the Blessed Virgin Mary, Mother of the Church was added on the Monday after Pentecost
//http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20180211_decreto-mater-ecclesiae_la.html
if($LITSETTINGS->YEAR >= 2018){
    $MaryMotherChurch_tag = ["LA" => "Beatæ Mariæ Virginis, Ecclesiæ Matris", "IT" => "Beata Vergine Maria, Madre della Chiesa", "EN" => "Blessed Virgin Mary, Mother of the Church"];
    $MaryMotherChurch_date = calcGregEaster($LITSETTINGS->YEAR)->add(new DateInterval('P' . (7 * 7 + 1) . 'D'));
    //The Memorial is superseded by Solemnities and Feasts, but not by Memorials of Saints
    if(!in_array($MaryMotherChurch_date,$SOLEMNITIES) && !in_array($MaryMotherChurch_date,$FEASTS_MEMORIALS)){
        $LitCal["MaryMotherChurch"] = new Festivity($MaryMotherChurch_tag[$LITSETTINGS->LOCALE], $MaryMotherChurch_date, "white", "mobile", MEMORIAL, "Proper");
        $Messages[] = sprintf(
            __("The %s '%s' has been added on %s since the year %d (%s), applicable to the year %d.",$LITSETTINGS->LOCALE),
            _G($LitCal["MaryMotherChurch"]->grade,$LITSETTINGS->LOCALE),
            $LitCal["MaryMotherChurch"]->name,
            __('the Monday after Pentecost',$LITSETTINGS->LOCALE),
            2018,
            '<a href="http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20180211_decreto-mater-ecclesiae_' . strtolower($LITSETTINGS->LOCALE) . '.html">' . __('Decree of the Congregation for Divine Worship', $LITSETTINGS->LOCALE) . '</a>',
            $LITSETTINGS->YEAR
        );
    }
    else if (in_array($MaryMotherChurch_date,$FEASTS_MEMORIALS) ){
        //we have to find out what it coincides with. If it's a feast, it is superseded by the feast. If a memorial, it will suppress the memorial
        $coincidingFestivityKey = array_search($MaryMotherChurch_date,$FEASTS_MEMORIALS);
        $coincidingFestivity = $LitCal[$coincidingFestivityKey];

        if($coincidingFestivity->grade <= MEMORIAL){
            $LitCal["MaryMotherChurch"] = new Festivity($MaryMotherChurch_tag[$LITSETTINGS->LOCALE], $MaryMotherChurch_date, "white", "mobile", MEMORIAL, "Proper");
            $Messages[] = sprintf(
                __("The %s '%s' has been added on %s since the year %d (%s), applicable to the year %d.",$LITSETTINGS->LOCALE),
                _G($LitCal["MaryMotherChurch"]->grade,$LITSETTINGS->LOCALE),
                $LitCal["MaryMotherChurch"]->name,
                __('the Monday after Pentecost',$LITSETTINGS->LOCALE),
                2018,
                '<a href="http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20180211_decreto-mater-ecclesiae_' . strtolower($LITSETTINGS->LOCALE) . '.html">' . __('Decree of the Congregation for Divine Worship', $LITSETTINGS->LOCALE) . '</a>',
                $LITSETTINGS->YEAR
            );
            $Messages[] = sprintf(
                __("The %s '%s' has been suppressed by the Memorial '%s', added on %s since the year %d (%s).",$LITSETTINGS->LOCALE),
                _G($LitCal[$coincidingFestivityKey]->grade,$LITSETTINGS->LOCALE,false),
                '<i>' . $LitCal[$coincidingFestivityKey]->name . '</i>',
                $LitCal["MaryMotherChurch"]->name,
                __('the Monday after Pentecost',$LITSETTINGS->LOCALE),
                2018,
                '<a href="http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20180211_decreto-mater-ecclesiae_' . strtolower($LITSETTINGS->LOCALE) . '.html">' . __('Decree of the Congregation for Divine Worship', $LITSETTINGS->LOCALE) . '</a>'
            );
            unset($LitCal[$coincidingFestivityKey]);
        }else{
            $Messages[] = sprintf(
                __("The Memorial '%s', added on %s since the year %d (%s), is however superseded by a Solemnity or a Feast '%s' in the year %d.", $LITSETTINGS->LOCALE),
                $MaryMotherChurch_tag[$LITSETTINGS->LOCALE],
                __('the Monday after Pentecost',$LITSETTINGS->LOCALE),
                2018,
                '<a href="http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20180211_decreto-mater-ecclesiae_' . strtolower($LITSETTINGS->LOCALE) . '.html">' . __('Decree of the Congregation for Divine Worship', $LITSETTINGS->LOCALE) . '</a>',
                $coincidingFestivity->name,
                $LITSETTINGS->YEAR
            );
        }
    }
    else if(in_array($MaryMotherChurch_date,$SOLEMNITIES)){
        $coincidingFestivityKey = array_search($MaryMotherChurch_date,$SOLEMNITIES);
        $coincidingFestivity = $LitCal[$coincidingFestivityKey];
        $Messages[] = sprintf(
            __("The Memorial '%s', added on %s since the year %d (%s), is however superseded by a Solemnity or a Feast '%s' in the year %d.", $LITSETTINGS->LOCALE),
            $LitCal["MaryMotherChurch"]->name,
            __('the Monday after Pentecost',$LITSETTINGS->LOCALE),
            2018,
            '<a href="http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20180211_decreto-mater-ecclesiae_' . strtolower($LITSETTINGS->LOCALE) . '.html">' . __('Decree of the Congregation for Divine Worship', $LITSETTINGS->LOCALE) . '</a>',
            $coincidingFestivity->name,
            $LITSETTINGS->YEAR
        );
    }
}


//TODO: implement number 11 !!!
//11. Proper obligatory memorials, and that is:
//a) obligatory memorial of the seconday Patron of a place, of a diocese, of a region or religious province
//b) other obligatory memorials in the calendar of a single diocese, order or congregation

//12. Optional memorials (a proper memorial is to be preferred to a general optional memorial (PC, 23 c) )
//	which however can be celebrated even in those days listed at n. 9,
//  in the special manner described by the General Instructions of the Roman Missal and of the Liturgy of the Hours (cf pp. 26-27, n. 10)
if ($result = $mysqli->query("SELECT * FROM LITURGY__calendar_propriumdesanctis WHERE GRADE = " . MEMORIALOPT)) {
    while ($row = mysqli_fetch_assoc($result)) {

        //If it doesn't occur on a Sunday or a Solemnity or a Feast of the Lord or a Feast or an obligatory memorial, then go ahead and create the optional memorial
        $currentFeastDate = DateTime::createFromFormat('!j-n-Y', $row["DAY"] . '-' . $row["MONTH"] . '-' . $LITSETTINGS->YEAR, new DateTimeZone('UTC'));
        if ((int)$currentFeastDate->format('N') !== 7 && !in_array($currentFeastDate, $SOLEMNITIES) && !in_array($currentFeastDate, $FEASTS_MEMORIALS)) {
            $LitCal[$row["TAG"]] = new Festivity($row["NAME_" . $LITSETTINGS->LOCALE], $currentFeastDate, $row["COLOR"], "fixed", $row["GRADE"], $row["COMMON"]);

            //If a fixed date optional memorial falls between 17 Dec. to 24 Dec., the Octave of Christmas or weekdays of the Lenten season,
            //it is reduced in rank to a Commemoration (only the collect can be used
            if (in_array($currentFeastDate, $WEEKDAYS_ADVENT_CHRISTMAS_LENT)) {
                $LitCal[$row["TAG"]]->grade = COMMEMORATION;
                $Messages[] = sprintf(
                    __("The optional memorial '%s' either falls between 17 Dec. and 24 Dec., or during the Octave of Christmas, or on the weekdays of the Lenten season in the year %d, rank reduced to Commemoration.",$LITSETTINGS->LOCALE),
                    $row["NAME_" . $LITSETTINGS->LOCALE],
                    $LITSETTINGS->YEAR
                );
            }
        } else {
            $coincidingFestivity_grade = '';
            if((int)$currentFeastDate->format('N') === 7 && $LitCal[array_search($currentFeastDate,$SOLEMNITIES)]->grade < SOLEMNITY ){
                //it's a Sunday
                $coincidingFestivity = $LitCal[array_search($currentFeastDate,$SOLEMNITIES)];
                $coincidingFestivity_grade = $LITSETTINGS->LOCALE === 'LA' ? 'Die Domini' : ucfirst(utf8_encode(strftime('%A',$currentFeastDate->format('U'))));
            } else if (in_array($currentFeastDate, $SOLEMNITIES)){
                //it's a Feast of the Lord or a Solemnity
                $coincidingFestivity = $LitCal[array_search($currentFeastDate,$SOLEMNITIES)];
                $coincidingFestivity_grade = ($coincidingFestivity->grade > SOLEMNITY ? '<i>' . _G($coincidingFestivity->grade,$LITSETTINGS->LOCALE,false) . '</i>' : _G($coincidingFestivity->grade,$LITSETTINGS->LOCALE,false));
            } else if(in_array($currentFeastDate, $FEASTS_MEMORIALS)){
                $coincidingFestivity = $LitCal[array_search($currentFeastDate,$FEASTS_MEMORIALS)];
                $coincidingFestivity_grade = _G($coincidingFestivity->grade,$LITSETTINGS->LOCALE,false);
            }

            $Messages[] = sprintf(
                __("The %s '%s', usually celebrated on %s, is suppressed by the %s '%s' in the year %d.",$LITSETTINGS->LOCALE),
                _G($row["GRADE"],$LITSETTINGS->LOCALE,false),
                $row["NAME_" . $LITSETTINGS->LOCALE],
                $LITSETTINGS->LOCALE === 'LA' ? ( $currentFeastDate->format('j') . ' ' . $LATIN_MONTHS[(int)$currentFeastDate->format('n')] ) :
                    ( $LITSETTINGS->LOCALE === 'EN' ? $currentFeastDate->format('F jS') :
                        trim(utf8_encode(strftime('%e %B', $currentFeastDate->format('U'))))
                    ),
                $coincidingFestivity_grade,
                $coincidingFestivity->name,
                $LITSETTINGS->YEAR
            );
        }
    }
}

/*if we are dealing with a calendar from the year 2002 onwards we need to add the optional memorials from the Tertia Editio Typica:
	23 aprilis:  S. Adalberti, episcopi et martyris
	28 aprilis:  S. Ludovici Mariæ Grignion de Montfort, presbyteri
	2 augusti:  S. Petri Iuliani Eymard, presbyteri
	9 septembris:  S. Petri Claver, presbyteri
	28 septembris:  Ss. Laurentii Ruiz et sociorum, martyrum

	11 new celebrations (I believe all considered optional memorials?)
	3 ianuarii:  SS.mi Nominis Iesu
	8 februarii:  S. Iosephinæ Bakhita, virginis
	13 maii:  Beatæ Mariæ Virginis de Fatima
	21 maii:  Ss. Christophori Magallanes, presbyteri, et sociorum, martyrum
	22 maii:  S. Ritæ de Cascia, religiosæ
	9 iulii:  Ss. Augustini Zhao Rong, presbyteri et sociorum, martyrum
	20 iulii:  S. Apollinaris, episcopi et martyris
	24 iulii:  S. Sarbelii Makhluf, presbyteri
	9 augusti:  S. Teresiæ Benedictæ a Cruce, virginis et martyris
	12 septembris:  SS.mi Nominis Mariæ
	25 novembris:  S. Catharinæ Alexandrinæ, virginis et martyris
	source: http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20020327_card-medina-estevez_it.html
    */
if ($LITSETTINGS->YEAR >= 2002) {
    if ($result = $mysqli->query("SELECT * FROM LITURGY__calendar_propriumdesanctis_2002 WHERE GRADE = " . MEMORIALOPT)) {
        while ($row = mysqli_fetch_assoc($result)) {

            //If it doesn't occur on a Sunday or a Solemnity or a Feast of the Lord or a Feast or an obligatory memorial, then go ahead and create the optional memorial
            $currentFeastDate = DateTime::createFromFormat('!j-n-Y', $row["DAY"] . '-' . $row["MONTH"] . '-' . $LITSETTINGS->YEAR, new DateTimeZone('UTC'));
            if ((int)$currentFeastDate->format('N') !== 7 && !in_array($currentFeastDate, $SOLEMNITIES) && !in_array($currentFeastDate, $FEASTS_MEMORIALS)) {
                $LitCal[$row["TAG"]] = new Festivity($row["NAME_" . $LITSETTINGS->LOCALE], $currentFeastDate, $row["COLOR"], "fixed", $row["GRADE"], $row["COMMON"]);
                /**
                 * TRANSLATORS:
                 * 1. Grade or rank of the festivity
                 * 2. Name of the festivity
                 * 3. Day of the festivity
                 * 4. Year from which the festivity has been added
                 * 5. Source of the information
                 * 6. Current year
                 */
                $Messages[] = sprintf(
                    __("The %s '%s' has been added on %s since the year %d (%s), applicable to the year %d.",$LITSETTINGS->LOCALE),
                    _G($row["GRADE"],$LITSETTINGS->LOCALE),
                    $row["NAME_" . $LITSETTINGS->LOCALE],
                    $LITSETTINGS->LOCALE === 'LA' ? ( $currentFeastDate->format('j') . ' ' . $LATIN_MONTHS[(int)$currentFeastDate->format('n')] ) :
                        ( $LITSETTINGS->LOCALE === 'EN' ? $currentFeastDate->format('F jS') :
                            trim(utf8_encode(strftime('%e %B', $currentFeastDate->format('U'))))
                        ),
                    2002,
                    '<a href="http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20020327_card-medina-estevez_' . strtolower($LITSETTINGS->LOCALE) . '.html">' . __('Decree of the Congregation for Divine Worship', $LITSETTINGS->LOCALE) . '</a>',
                    $LITSETTINGS->YEAR
                );

                //If a fixed date optional memorial falls between 17 Dec. to 24 Dec., the Octave of Christmas or weekdays of the Lenten season,
                //it is reduced in rank to a Commemoration (only the collect can be used
                if (in_array($currentFeastDate, $WEEKDAYS_ADVENT_CHRISTMAS_LENT)) {
                    $LitCal[$row["TAG"]]->grade = COMMEMORATION;
                    $Messages[] = sprintf(
                        __("The optional memorial '%s', added in the Tertia Editio Typica of the Roman Missal since the year 2002 (%s), either falls between 17 Dec. and 24 Dec., during the Octave of Christmas, or on a weekday of the Lenten season in the year %d, rank reduced to Commemoration.",$LITSETTINGS->LOCALE),
                        $LitCal[$row["TAG"]]->name,
                        '<a href="http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20020327_card-medina-estevez_' . strtolower($LITSETTINGS->LOCALE) . '.html">' . __('Decree of the Congregation for Divine Worship', $LITSETTINGS->LOCALE) . '</a>',
                        $LITSETTINGS->YEAR
                    );
                }
            } else {
                $coincidingFestivity_grade = '';
                if((int)$currentFeastDate->format('N') === 7 && $LitCal[array_search($currentFeastDate,$SOLEMNITIES)]->grade < SOLEMNITY ){
                    //it's a Sunday
                    $coincidingFestivity = $LitCal[array_search($currentFeastDate,$SOLEMNITIES)];
                    $coincidingFestivity_grade = $LITSETTINGS->LOCALE === 'LA' ? 'Die Domini' : ucfirst(utf8_encode(strftime('%A',$currentFeastDate->format('U'))));
                } else if (in_array($currentFeastDate, $SOLEMNITIES)){
                    //it's a Feast of the Lord or a Solemnity
                    $coincidingFestivity = $LitCal[array_search($currentFeastDate,$SOLEMNITIES)];
                    $coincidingFestivity_grade = ($coincidingFestivity->grade > SOLEMNITY ? '<i>' . _G($coincidingFestivity->grade,$LITSETTINGS->LOCALE,false) . '</i>' : _G($coincidingFestivity->grade,$LITSETTINGS->LOCALE,false));
                } else if(in_array($currentFeastDate, $FEASTS_MEMORIALS)){
                    $coincidingFestivity = $LitCal[array_search($currentFeastDate,$FEASTS_MEMORIALS)];
                    $coincidingFestivity_grade = _G($coincidingFestivity->grade,$LITSETTINGS->LOCALE,false);
                }
                $Messages[] = sprintf(
                    __("The %s '%s', added in the Tertia Editio Typica of the Roman Missal since the year 2002 (%s) and usually celebrated on %s, is suppressed by the %s '%s' in the year %d.",$LITSETTINGS->LOCALE),
                    _G($row["GRADE"],$LITSETTINGS->LOCALE,false),
                    $row["NAME_" . $LITSETTINGS->LOCALE],
                    '<a href="http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20020327_card-medina-estevez_' . strtolower($LITSETTINGS->LOCALE) . '.html">' . __('Decree of the Congregation for Divine Worship', $LITSETTINGS->LOCALE) . '</a>',
                    $LITSETTINGS->LOCALE === 'LA' ? ( $currentFeastDate->format('j') . ' ' . $LATIN_MONTHS[(int)$currentFeastDate->format('n')] ) :
                        ( $LITSETTINGS->LOCALE === 'EN' ? $currentFeastDate->format('F jS') :
                            trim(utf8_encode(strftime('%e %B', $currentFeastDate->format('U'))))
                        ),
                    $coincidingFestivity_grade,
                    $coincidingFestivity->name,
                    $LITSETTINGS->YEAR
                );
            }
        }
    }

    //Also, Saint Jane Frances de Chantal was moved from December 12 to August 12,
    //probably to allow local bishop's conferences to insert Our Lady of Guadalupe as an optional memorial on December 12
    //seeing that with the decree of March 25th 1999 of the Congregation of Divine Worship
    //Our Lady of Guadalupe was granted as a Feast day for all dioceses and territories of the Americas
    //source: http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20000628_guadalupe_lt.html
    //TODO: check if Our Lady of Guadalupe became an optional memorial in the Universal Calendar in the 2008 edition of the Roman Missal
    $StJaneFrancesNewDate = DateTime::createFromFormat('!j-n-Y', '12-8-' . $LITSETTINGS->YEAR, new DateTimeZone('UTC'));
    if ( (int)$StJaneFrancesNewDate->format('N') !== 7 && !in_array($StJaneFrancesNewDate, $SOLEMNITIES) && !in_array($StJaneFrancesNewDate, $FEASTS_MEMORIALS) ) {
        if( array_key_exists("StJaneFrancesDeChantal", $LitCal) ){
            $LitCal["StJaneFrancesDeChantal"]->date = $StJaneFrancesNewDate;
            $Messages[] = sprintf(
                __("The optional memorial '%s' has been transferred from Dec. 12 to Aug. 12 since the year 2002 (%s), applicable to the year %d.",$LITSETTINGS->LOCALE),
                $LitCal["StJaneFrancesDeChantal"]->name,
                '<a href="http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20000628_guadalupe_' . strtolower($LITSETTINGS->LOCALE) . '.html">' . __('Decree of the Congregation for Divine Worship', $LITSETTINGS->LOCALE) . '</a>',
                $LITSETTINGS->YEAR
            );
        } else {
            //perhaps it wasn't created on December 12th because it was superseded by a Sunday, Solemnity or Feast
            //but seeing that there is no problem for August 12th, let's go ahead and try creating it again
            if ($result = $mysqli->query("SELECT * FROM LITURGY__calendar_propriumdesanctis WHERE TAG = 'StJaneFrancesDeChantal'")) {
                $row = mysqli_fetch_assoc($result);
                $LitCal["StJaneFrancesDeChantal"] = new Festivity( $row["NAME_" . $LITSETTINGS->LOCALE], $StJaneFrancesNewDate, $row["COLOR"], 'fixed', $row["GRADE"], $row["COMMON"] );
                $Messages[] = sprintf(
                    __("The optional memorial '%s', which would have been superseded this year by a Sunday or Solemnity were it on Dec. 12, has however been transferred to Aug. 12 since the year 2002 (%s), applicable to the year %d.",$LITSETTINGS->LOCALE),
                    $LitCal["StJaneFrancesDeChantal"]->name,
                    '<a href="http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20000628_guadalupe_' . strtolower($LITSETTINGS->LOCALE) . '.html">' . __('Decree of the Congregation for Divine Worship', $LITSETTINGS->LOCALE) . '</a>',
                    $LITSETTINGS->YEAR
                );
            }
        }
    } else {
        if(in_array($StJaneFrancesNewDate,$SOLEMNITIES) ){
            $coincidingFestivityKey = array_search($StJaneFrancesNewDate,$SOLEMNITIES);
        }
        else if(in_array($StJaneFrancesNewDate,$FEASTS_MEMORIALS) ){
            $coincidingFestivityKey = array_search($StJaneFrancesNewDate,$FEASTS_MEMORIALS);
        }
        $coincidingFestivity = $LitCal[$coincidingFestivityKey];
        //we can't move it, but we still need to remove it from Dec 12th if it's there!!!
        if( array_key_exists("StJaneFrancesDeChantal", $LitCal) ){
            $Messages[] = sprintf(
                __('The optional memorial \'%1$s\' has been transferred from Dec. 12 to Aug. 12 since the year 2002 (%2$s), applicable to the year %3$d. However, it is superseded by a Sunday, a Solemnity, or a Feast \'%4$s\' in the year %3$d.',$LITSETTINGS->LOCALE),
                $LitCal["StJaneFrancesDeChantal"]->name,
                '<a href="http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20000628_guadalupe_' . strtolower($LITSETTINGS->LOCALE) . '.html">' . __('Decree of the Congregation for Divine Worship', $LITSETTINGS->LOCALE) . '</a>',
                $LITSETTINGS->YEAR,
                $coincidingFestivity->name
            );
            unset($LitCal["StJaneFrancesDeChantal"]);
        } else {
            //in order to give any kind of feedback message about what is going on, we will need to at least re-acquire the Name of this festivity,
            //which has already been removed from our LitCal array
            if ($result = $mysqli->query("SELECT * FROM LITURGY__calendar_propriumdesanctis WHERE TAG = 'StJaneFrancesDeChantal'")) {
                $row = mysqli_fetch_assoc($result);
                $Messages[] = sprintf(
                    __('The optional memorial \'%1$s\' has been transferred from Dec. 12 to Aug. 12 since the year 2002 (%2$s), applicable to the year %3$d. However, it is superseded by a Sunday, a Solemnity, or a Feast \'%4$s\' in the year %3$d.',$LITSETTINGS->LOCALE),
                    $row["NAME_" . $LITSETTINGS->LOCALE],
                    '<a href="http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20000628_guadalupe_' . strtolower($LITSETTINGS->LOCALE) . '.html">' . __('Decree of the Congregation for Divine Worship', $LITSETTINGS->LOCALE) . '</a>',
                    $LITSETTINGS->YEAR,
                    $coincidingFestivity->name
                );
            }
        }
    }

    //Sure seems to me that both Our Lady of Guadalupe and Saint Juan Diego were added as Optional memorials in the Universal Calendar
    //the USA Missal 2011 has Juan Diego as optional memorial without specifying "USA", so it seems universal
    //also the ORDO (Guida-liturgico pastorale) of the Diocese of Rome has both Juan Diego and Guadalupe as optional memorials, without specifying "ROME"
    $Guadalupe_tag = ["LA" => "Beatæ Mariæ Virginis Guadalupensis", "EN" => "Our Lady of Guadalupe", "IT" => "Beata Vergine Maria di Guadalupe"];
    $Guadalupe_date = DateTime::createFromFormat('!j-n-Y', '12-12-' . $LITSETTINGS->YEAR, new DateTimeZone('UTC'));

    if ( (int)$Guadalupe_date->format('N') !== 7 && !in_array($Guadalupe_date, $SOLEMNITIES) && !in_array($Guadalupe_date, $FEASTS_MEMORIALS) ) {
        $LitCal["LadyGuadalupe"] = new Festivity( $Guadalupe_tag[$LITSETTINGS->LOCALE], $Guadalupe_date, 'white', 'fixed', MEMORIALOPT, "Blessed Virgin Mary" );
        /**
         * TRANSLATORS:
         * 1. Grade or rank of the festivity
         * 2. Name of the festivity
         * 3. Day of the festivity
         * 4. Year from which the festivity has been added
         * 5. Source of the information
         * 6. Current year
         */
        $Messages[] = sprintf(
            __("The %s '%s' has been added on %s since the year %d (%s), applicable to the year %d.",$LITSETTINGS->LOCALE),
            _G($LitCal["LadyGuadalupe"]->name,$LITSETTINGS->LOCALE),
            $LitCal["LadyGuadalupe"]->name,
            $LITSETTINGS->LOCALE === 'LA' ? ( $Guadalupe_date->format('j') . ' ' . $LATIN_MONTHS[(int)$Guadalupe_date->format('n')] ) :
                ( $LITSETTINGS->LOCALE === 'EN' ? $Guadalupe_date->format('F jS') :
                    trim(utf8_encode(strftime('%e %B', $Guadalupe_date->format('U'))))
                ),
            2002,
            '<a href="http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20000628_guadalupe_' . strtolower($LITSETTINGS->LOCALE) . '.html">' . __('Decree of the Congregation for Divine Worship', $LITSETTINGS->LOCALE) . '</a>',
            $LITSETTINGS->YEAR
        );
    } else {
        if(in_array($Guadalupe_date,$SOLEMNITIES) ){
            $coincidingFestivityKey = array_search($Guadalupe_date,$SOLEMNITIES);
        }
        else if(in_array($Guadalupe_date,$FEASTS_MEMORIALS) ){
            $coincidingFestivityKey = array_search($Guadalupe_date,$FEASTS_MEMORIALS);
        }
        $coincidingFestivity = $LitCal[$coincidingFestivityKey];
        $Messages[] = sprintf(
            __("The optional memorial '%s', added on %s since the year %d (%s), is however superseded by a Sunday, a Solemnity or a Feast '%s' in the year %d.",$LITSETTINGS->LOCALE),
            $Guadalupe_tag[$LITSETTINGS->LOCALE],
            $LITSETTINGS->LOCALE === 'LA' ? ( $Guadalupe_date->format('j') . ' ' . $LATIN_MONTHS[(int)$Guadalupe_date->format('n')] ) :
                ( $LITSETTINGS->LOCALE === 'EN' ? $Guadalupe_date->format('F jS') :
                    trim(utf8_encode(strftime('%e %B', $Guadalupe_date->format('U'))))
                ),
            2002,
            '<a href="http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20000628_guadalupe_' . strtolower($LITSETTINGS->LOCALE) . '.html">' . __('Decree of the Congregation for Divine Worship', $LITSETTINGS->LOCALE) . '</a>',
            $coincidingFestivity->name,
            $LITSETTINGS->YEAR
        );
    }

    $JuanDiego_tag = ["LA" => "Sancti Ioannis Didaci Cuauhtlatoatzin", "EN" => "Saint Juan Diego Cuauhtlatoatzin", "IT" => "San Juan Diego Cuauhtlatouatzin"];
    $JuanDiego_date = DateTime::createFromFormat('!j-n-Y', '9-12-' . $LITSETTINGS->YEAR, new DateTimeZone('UTC'));
    if ( (int)$JuanDiego_date->format('N') !== 7 && !in_array($JuanDiego_date, $SOLEMNITIES) && !in_array($JuanDiego_date, $FEASTS_MEMORIALS) ) {
        $LitCal["JuanDiego"] = new Festivity( $JuanDiego_tag[$LITSETTINGS->LOCALE], $JuanDiego_date, 'white', 'fixed', MEMORIALOPT, "Holy Men and Women:For One Saint" );
        /**
         * TRANSLATORS:
         * 1. Grade or rank of the festivity
         * 2. Name of the festivity
         * 3. Day of the festivity
         * 4. Year from which the festivity has been added
         * 5. Source of the information
         * 6. Current year
         */
        $Messages[] = sprintf(
            __("The %s '%s' has been added on %s since the year %d (%s), applicable to the year %d.",$LITSETTINGS->LOCALE),
            _G($LitCal["JuanDiego"]->grade,$LITSETTINGS->LOCALE),
            $LitCal["JuanDiego"]->name,
            $LITSETTINGS->LOCALE === 'LA' ? ( $JuanDiego_date->format('j') . ' ' . $LATIN_MONTHS[(int)$JuanDiego_date->format('n')] ) :
                ( $LITSETTINGS->LOCALE === 'EN' ? $JuanDiego_date->format('F jS') :
                    trim(utf8_encode(strftime('%e %B', $JuanDiego_date->format('U'))))
                ),
            2002,
            '<a href="http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20000628_guadalupe_' . strtolower($LITSETTINGS->LOCALE) . '.html">' . __('Decree of the Congregation for Divine Worship', $LITSETTINGS->LOCALE) . '</a>',
            $LITSETTINGS->YEAR
        );
    } else {
        if(in_array($JuanDiego_date,$SOLEMNITIES) ){
            $coincidingFestivityKey = array_search($JuanDiego_date,$SOLEMNITIES);
        }
        else if(in_array($JuanDiego_date,$FEASTS_MEMORIALS) ){
            $coincidingFestivityKey = array_search($JuanDiego_date,$FEASTS_MEMORIALS);
        }
        $coincidingFestivity = $LitCal[$coincidingFestivityKey];
        $Messages[] = sprintf(
            __("The optional memorial '%s', added on %s since the year %d (%s), is however superseded by a Sunday, a Solemnity or a Feast '%s' in the year %d.",$LITSETTINGS->LOCALE),
            $JuanDiego_tag[$LITSETTINGS->LOCALE],
            $LITSETTINGS->LOCALE === 'LA' ? ( $JuanDiego_date->format('j') . ' ' . $LATIN_MONTHS[(int)$JuanDiego_date->format('n')] ) :
                ( $LITSETTINGS->LOCALE === 'EN' ? $JuanDiego_date->format('F jS') :
                    trim(utf8_encode(strftime('%e %B', $JuanDiego_date->format('U'))))
                ),
            2002,
            '<a href="http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20000628_guadalupe_' . strtolower($LITSETTINGS->LOCALE) . '.html">' . __('Decree of the Congregation for Divine Worship', $LITSETTINGS->LOCALE) . '</a>',
            $coincidingFestivity->name,
            $LITSETTINGS->YEAR
        );
    }

    //TODO: Saint Pio of Pietrelcina "Padre Pio" was canonized on June 16 2002,
    //so did not make it for the Calendar of the 2002 editio typica III
    //check if his memorial added in the 2008 editio typica III emendata
    //StPadrePio:
    if ($LITSETTINGS->YEAR >= 2008) {
        $StPioPietrelcina_tag = array("LA" => "S. Pii de Pietrelcina, presbyteri", "IT" => "San Pio da Pietrelcina, presbitero", "EN" => "Saint Pius of Pietrelcina, Priest");
        $StPioPietrelcina_date = DateTime::createFromFormat('!j-n-Y', '23-9-' . $LITSETTINGS->YEAR, new DateTimeZone('UTC'));
        if(!in_array($StPioPietrelcina_date,$SOLEMNITIES) && !in_array($StPioPietrelcina_date,$FEASTS_MEMORIALS)){
            $LitCal["StPioPietrelcina"] = new Festivity($StPioPietrelcina_tag[$LITSETTINGS->LOCALE], $StPioPietrelcina_date, "white", "fixed", MEMORIALOPT, "Pastors:For One Pastor,Holy Men and Women:For Religious");
            /**
             * TRANSLATORS:
             * 1. Grade or rank of the festivity
             * 2. Name of the festivity
             * 3. Day of the festivity
             * 4. Year from which the festivity has been added
             * 5. Source of the information
             * 6. Current year
             */
            $Messages[] = sprintf(
                __("The %s '%s' has been added on %s since the year %d (%s), applicable to the year %d.",$LITSETTINGS->LOCALE),
                _G($LitCal["StPioPietrelcina"]->grade,$LITSETTINGS->LOCALE),
                $LitCal["StPioPietrelcina"]->name,
                $LITSETTINGS->LOCALE === 'LA' ? ( $LitCal["StPioPietrelcina"]->date->format('j') . ' ' . $LATIN_MONTHS[(int)$LitCal["StPioPietrelcina"]->date->format('n')] ) :
                    ( $LITSETTINGS->LOCALE === 'EN' ? $LitCal["StPioPietrelcina"]->date->format('F jS') :
                        trim(utf8_encode(strftime('%e %B', $LitCal["StPioPietrelcina"]->date->format('U'))))
                    ),
                2008,
                'Missale Romanum, ed. Typica Tertia Emendata 2008',
                $LITSETTINGS->YEAR
            );
        }
        else{
            if(in_array($StPioPietrelcina_date,$SOLEMNITIES) ){
                $coincidingFestivityKey = array_search($StPioPietrelcina_date,$SOLEMNITIES);
            }
            else if(in_array($StPioPietrelcina_date,$FEASTS_MEMORIALS) ){
                $coincidingFestivityKey = array_search($StPioPietrelcina_date,$FEASTS_MEMORIALS);
            }
            $coincidingFestivity = $LitCal[$coincidingFestivityKey];
            $Messages[] = sprintf(
                __("The optional memorial '%s', added on %s since the year %d (%s), is however superseded by a Sunday, a Solemnity or a Feast '%s' in the year %d.",$LITSETTINGS->LOCALE),
                $StPioPietrelcina_tag[$LITSETTINGS->LOCALE],
                $LITSETTINGS->LOCALE === 'LA' ? ( $StPioPietrelcina_date->format('j') . ' ' . $LATIN_MONTHS[(int)$StPioPietrelcina_date->format('n')] ) :
                    ( $LITSETTINGS->LOCALE === 'EN' ? $StPioPietrelcina_date->format('F jS') :
                        trim(utf8_encode(strftime('%e %B', $StPioPietrelcina_date->format('U'))))
                    ),
                2008,
                'Missale Romanum, ed. Typica Tertia Emendata 2008',
                $coincidingFestivity->name,
                $LITSETTINGS->YEAR
            );
        }
    }

    if($LITSETTINGS->YEAR === 2009){
        //The Conversion of St. Paul falls on a Sunday this year. However, considering that it is the Year of Saint Paul,
        //with decree of Jan 25 2008 the Congregation for Divine Worship gave faculty to the single churches
        //to celebrate the Conversion of St. Paul anyways. So let's re-insert it as an optional memorial?
        //http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20080125_san-paolo_la.html
        if(!array_key_exists("ConversionStPaul",$LitCal)){
            if ($result = $mysqli->query("SELECT * FROM LITURGY__calendar_propriumdesanctis WHERE TAG = 'ConversionStPaul'")) {
                $row = mysqli_fetch_assoc($result);
                $LitCal["ConversionStPaul"] = new Festivity($row["NAME_".$LITSETTINGS->LOCALE], DateTime::createFromFormat('!j-n-Y', '25-1-2009', new DateTimeZone('UTC')), "white", "fixed", MEMORIALOPT, "Proper" );
                $Messages[] = sprintf(
                    __('The Feast \'%s\' would have been suppressed this year (2009) since it falls on a Sunday, however being the Year of the Apostle Paul, as per the %s it has been reinstated so that local churches can optionally celebrate the memorial.',$LITSETTINGS->LOCALE),
                    '<i>' . $row["NAME_" . $LITSETTINGS->LOCALE] . '</i>',
                    '<a href="http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20080125_san-paolo_' . strtolower($LITSETTINGS->LOCALE) . '.html">' . __('Decree of the Congregation for Divine Worship', $LITSETTINGS->LOCALE) . '</a>'
                );
            }
        }
    }

    //After the canonization of Pope Saint John XXIII and Pope Saint John Paul II
    //with decree of May 29 2014 the Congregation for Divine Worship
    //inserted the optional memorials for each in the Universal Calendar
    //on October 11 and October 22 respectively
    //source: http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20140529_decreto-calendario-generale-gxxiii-gpii_la.html
    if ($LITSETTINGS->YEAR >= 2014) {
        $StJohnXXIII_tag = array("LA" => "S. Ioannis XXIII, papæ", "IT" => "San Giovanni XXIII, papa", "EN" => "Saint John XXIII, pope");
        $StJohnXXIII_date = DateTime::createFromFormat('!j-n-Y', '11-10-' . $LITSETTINGS->YEAR, new DateTimeZone('UTC'));
        if(!in_array($StJohnXXIII_date,$SOLEMNITIES) && !in_array($StJohnXXIII_date,$FEASTS_MEMORIALS)){
            $LitCal["StJohnXXIII"] = new Festivity($StJohnXXIII_tag[$LITSETTINGS->LOCALE], $StJohnXXIII_date, "white", "fixed", MEMORIALOPT, "Pastors:For a Pope");
            /**
             * TRANSLATORS:
             * 1. Grade or rank of the festivity
             * 2. Name of the festivity
             * 3. Day of the festivity
             * 4. Year from which the festivity has been added
             * 5. Source of the information
             * 6. Current year
             */
            $Messages[] = sprintf(
                __("The %s '%s' has been added on %s since the year %d (%s), applicable to the year %d.",$LITSETTINGS->LOCALE),
                _G($LitCal["StJohnXXIII"]->grade,$LITSETTINGS->LOCALE),
                $LitCal["StJohnXXIII"]->name,
                $LITSETTINGS->LOCALE === 'LA' ? ( $LitCal["StJohnXXIII"]->date->format('j') . ' ' . $LATIN_MONTHS[(int)$LitCal["StJohnXXIII"]->date->format('n')] ) :
                    ( $LITSETTINGS->LOCALE === 'EN' ? $LitCal["StJohnXXIII"]->date->format('F jS') :
                        trim(utf8_encode(strftime('%e %B', $LitCal["StJohnXXIII"]->date->format('U'))))
                    ),
                2014,
                '<a href="http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20140529_decreto-calendario-generale-gxxiii-gpii_' . strtolower($LITSETTINGS->LOCALE) . '.html">' . __('Decree of the Congregation for Divine Worship', $LITSETTINGS->LOCALE) . '</a>',
                $LITSETTINGS->YEAR
            );
        }
        else{
            if(in_array($StJohnXXIII_date,$SOLEMNITIES) ){
                $coincidingFestivityKey = array_search($StJohnXXIII_date,$SOLEMNITIES);
            }
            else if(in_array($StJohnXXIII_date,$FEASTS_MEMORIALS) ){
                $coincidingFestivityKey = array_search($StJohnXXIII_date,$FEASTS_MEMORIALS);
            }
            $coincidingFestivity = $LitCal[$coincidingFestivityKey];
            $Messages[] = sprintf(
                __("The optional memorial '%s', added on %s since the year %d (%s), is however superseded by a Sunday, a Solemnity or a Feast '%s' in the year %d.",$LITSETTINGS->LOCALE),
                $StJohnXXIII_tag[$LITSETTINGS->LOCALE],
                $LITSETTINGS->LOCALE === 'LA' ? ( $StJohnXXIII_date->format('j') . ' ' . $LATIN_MONTHS[(int)$StJohnXXIII_date->format('n')] ) :
                    ( $LITSETTINGS->LOCALE === 'EN' ? $StJohnXXIII_date->format('F jS') :
                        trim(utf8_encode(strftime('%e %B', $StJohnXXIII_date->format('U'))))
                    ),
                2014,
                '<a href="http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20140529_decreto-calendario-generale-gxxiii-gpii_' . strtolower($LITSETTINGS->LOCALE) . '.html">' . __('Decree of the Congregation for Divine Worship', $LITSETTINGS->LOCALE) . '</a>',
                $coincidingFestivity->name,
                $LITSETTINGS->YEAR
            );
        }

        $StJohnPaulII_tag = array("LA" => "S. Ioannis Pauli II, papæ", "IT" => "San Giovanni Paolo II, papa", "EN" => "Saint John Paul II, pope");
        $StJohnPaulII_date = DateTime::createFromFormat('!j-n-Y', '22-10-' . $LITSETTINGS->YEAR, new DateTimeZone('UTC'));
        if(!in_array($StJohnPaulII_date,$SOLEMNITIES) && !in_array($StJohnPaulII_date,$FEASTS_MEMORIALS)){
            $LitCal["StJohnPaulII"] = new Festivity($StJohnPaulII_tag[$LITSETTINGS->LOCALE], $StJohnPaulII_date, "white", "fixed", MEMORIALOPT, "Pastors:For a Pope");
            /**
             * TRANSLATORS:
             * 1. Grade or rank of the festivity
             * 2. Name of the festivity
             * 3. Day of the festivity
             * 4. Year from which the festivity has been added
             * 5. Source of the information
             * 6. Current year
             */
            $Messages[] = sprintf(
                __("The %s '%s' has been added on %s since the year %d (%s), applicable to the year %d.",$LITSETTINGS->LOCALE),
                _G($LitCal["StJohnPaulII"]->grade,$LITSETTINGS->LOCALE),
                $LitCal["StJohnPaulII"]->name,
                $LITSETTINGS->LOCALE === 'LA' ? ( $LitCal["StJohnPaulII"]->date->format('j') . ' ' . $LATIN_MONTHS[(int)$LitCal["StJohnPaulII"]->date->format('n')] ) :
                    ( $LITSETTINGS->LOCALE === 'EN' ? $LitCal["StJohnPaulII"]->date->format('F jS') :
                        trim(utf8_encode(strftime('%e %B', $LitCal["StJohnPaulII"]->date->format('U'))))
                    ),
                2014,
                '<a href="http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20140529_decreto-calendario-generale-gxxiii-gpii_' . strtolower($LITSETTINGS->LOCALE) . '.html">' . __('Decree of the Congregation for Divine Worship', $LITSETTINGS->LOCALE) . '</a>',
                $LITSETTINGS->YEAR
            );
        }
        else{
            if(in_array($StJohnPaulII_date,$SOLEMNITIES) ){
                $coincidingFestivityKey = array_search($StJohnPaulII_date,$SOLEMNITIES);
            }
            else if(in_array($StJohnPaulII_date,$FEASTS_MEMORIALS) ){
                $coincidingFestivityKey = array_search($StJohnPaulII_date,$FEASTS_MEMORIALS);
            }
            $coincidingFestivity = $LitCal[$coincidingFestivityKey];
            $Messages[] = sprintf(
                __("The optional memorial '%s', added on %s since the year %d (%s), is however superseded by a Sunday, a Solemnity or a Feast '%s' in the year %d.",$LITSETTINGS->LOCALE),
                $StJohnPaulII_tag[$LITSETTINGS->LOCALE],
                $LITSETTINGS->LOCALE === 'LA' ? ( $StJohnPaulII_date->format('j') . ' ' . $LATIN_MONTHS[(int)$StJohnPaulII_date->format('n')] ) :
                    ( $LITSETTINGS->LOCALE === 'EN' ? $StJohnPaulII_date->format('F jS') :
                        trim(utf8_encode(strftime('%e %B', $StJohnPaulII_date->format('U'))))
                    ),
                2014,
                '<a href="http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20140529_decreto-calendario-generale-gxxiii-gpii_' . strtolower($LITSETTINGS->LOCALE) . '.html">' . __('Decree of the Congregation for Divine Worship', $LITSETTINGS->LOCALE) . '</a>',
                $coincidingFestivity->name,
                $LITSETTINGS->YEAR
            );
        }
    }

    //With the Decree of the Congregation of Divine Worship of Oct 7, 2019,
    //the optional memorial of the Blessed Virgin Mary of Loreto was added on Dec 10
    //http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20191007_decreto-celebrazione-verginediloreto_la.html
    if($LITSETTINGS->YEAR >= 2019){
        $LadyLoreto_tag = ["LA" => "Beatæ Mariæ Virginis de Loreto", "IT" => "Beata Maria Vergine di Loreto", "EN" => "Blessed Virgin Mary of Loreto"];
        $LadyLoreto_date = DateTime::createFromFormat('!j-n-Y', '10-12-' . $LITSETTINGS->YEAR, new DateTimeZone('UTC'));
        if(!in_array($LadyLoreto_date,$SOLEMNITIES) && !in_array($LadyLoreto_date,$FEASTS_MEMORIALS) ){
            $LitCal["LadyLoreto"] = new Festivity($LadyLoreto_tag[$LITSETTINGS->LOCALE], $LadyLoreto_date, "white", "fixed", MEMORIALOPT, "Blessed Virgin Mary");
            /**
             * TRANSLATORS:
             * 1. Grade or rank of the festivity
             * 2. Name of the festivity
             * 3. Day of the festivity
             * 4. Year from which the festivity has been added
             * 5. Source of the information
             * 6. Current year
             */
            $Messages[] = sprintf(
                __("The %s '%s' has been added on %s since the year %d (%s), applicable to the year %d.",$LITSETTINGS->LOCALE),
                _G($LitCal["LadyLoreto"]->grade,$LITSETTINGS->LOCALE),
                $LitCal["LadyLoreto"]->name,
                $LITSETTINGS->LOCALE === 'LA' ? ( $LitCal["LadyLoreto"]->date->format('j') . ' ' . $LATIN_MONTHS[(int)$LitCal["LadyLoreto"]->date->format('n')] ) :
                    ( $LITSETTINGS->LOCALE === 'EN' ? $LitCal["LadyLoreto"]->date->format('F jS') :
                        trim(utf8_encode(strftime('%e %B', $LitCal["LadyLoreto"]->date->format('U'))))
                    ),
                2019,
                '<a href="http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20191007_decreto-celebrazione-verginediloreto_' . strtolower($LITSETTINGS->LOCALE) . '.html">' . __('Decree of the Congregation for Divine Worship', $LITSETTINGS->LOCALE) . '</a>',
                $LITSETTINGS->YEAR
            );
        }
        else{
            if(in_array($LadyLoreto_date,$SOLEMNITIES) ){
                $coincidingFestivityKey = array_search($LadyLoreto_date,$SOLEMNITIES);
            }
            else if(in_array($LadyLoreto_date,$FEASTS_MEMORIALS) ){
                $coincidingFestivityKey = array_search($LadyLoreto_date,$FEASTS_MEMORIALS);
            }
            $coincidingFestivity = $LitCal[$coincidingFestivityKey];
            $Messages[] = sprintf(
                __("The optional memorial '%s', added on %s since the year %d (%s), is however superseded by a Sunday, a Solemnity or a Feast '%s' in the year %d.",$LITSETTINGS->LOCALE),
                $LadyLoreto_tag[$LITSETTINGS->LOCALE],
                $LITSETTINGS->LOCALE === 'LA' ? ( $LadyLoreto_date->format('j') . ' ' . $LATIN_MONTHS[(int)$LadyLoreto_date->format('n')] ) :
                    ( $LITSETTINGS->LOCALE === 'EN' ? $LadyLoreto_date->format('F jS') :
                        trim(utf8_encode(strftime('%e %B', $LadyLoreto_date->format('U'))))
                    ),
                2019,
                '<a href="http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20191007_decreto-celebrazione-verginediloreto_' . strtolower($LITSETTINGS->LOCALE) . '.html">' . __('Decree of the Congregation for Divine Worship', $LITSETTINGS->LOCALE) . '</a>',
                $coincidingFestivity->name,
                $LITSETTINGS->YEAR
            );
        }

        //With the Decree of the Congregation of Divine Worship of January 25 2019,
        //the optional memorial of Saint Paul VI, Pope was added on May 29
        //http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20190125_decreto-celebrazione-paolovi_la.html
        $PaulVI_tag = ["LA" => "Sancti Pauli VI, Papæ", "IT" => "San Paolo VI, Papa", "EN" => "Saint Paul VI, Pope"];
        $PaulVI_date = DateTime::createFromFormat('!j-n-Y', '29-5-' . $LITSETTINGS->YEAR, new DateTimeZone('UTC'));
        if(!in_array($PaulVI_date,$SOLEMNITIES) && !in_array($PaulVI_date,$FEASTS_MEMORIALS) ){
            $LitCal["StPaulVI"] = new Festivity($PaulVI_tag[$LITSETTINGS->LOCALE], $PaulVI_date, "white", "fixed", MEMORIALOPT, "Pastors:For a Pope");
            /**
             * TRANSLATORS:
             * 1. Grade or rank of the festivity
             * 2. Name of the festivity
             * 3. Day of the festivity
             * 4. Year from which the festivity has been added
             * 5. Source of the information
             * 6. Current year
             */
            $Messages[] = sprintf(
                __("The %s '%s' has been added on %s since the year %d (%s), applicable to the year %d.",$LITSETTINGS->LOCALE),
                _G($LitCal["StPaulVI"]->grade,$LITSETTINGS->LOCALE),
                $LitCal["StPaulVI"]->name,
                $LITSETTINGS->LOCALE === 'LA' ? ( $LitCal["StPaulVI"]->date->format('j') . ' ' . $LATIN_MONTHS[(int)$LitCal["StPaulVI"]->date->format('n')] ) :
                    ( $LITSETTINGS->LOCALE === 'EN' ? $LitCal["StPaulVI"]->date->format('F jS') :
                        trim(utf8_encode(strftime('%e %B', $LitCal["StPaulVI"]->date->format('U'))))
                    ),
                2019,
                '<a href="http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20190125_decreto-celebrazione-paolovi_' . strtolower($LITSETTINGS->LOCALE) . '.html">' . __('Decree of the Congregation for Divine Worship', $LITSETTINGS->LOCALE) . '</a>',
                $LITSETTINGS->YEAR
            );
        }
        else{
            if(in_array($PaulVI_date,$SOLEMNITIES) ){
                $coincidingFestivityKey = array_search($PaulVI_date,$SOLEMNITIES);
            }
            else if(in_array($PaulVI_date,$FEASTS_MEMORIALS) ){
                $coincidingFestivityKey = array_search($PaulVI_date,$FEASTS_MEMORIALS);
            }
            $coincidingFestivity = $LitCal[$coincidingFestivityKey];
            $Messages[] = sprintf(
                __("The optional memorial '%s', added on %s since the year %d (%s), is however superseded by a Sunday, a Solemnity or a Feast '%s' in the year %d.",$LITSETTINGS->LOCALE),
                $PaulVI_tag[$LITSETTINGS->LOCALE],
                $LITSETTINGS->LOCALE === 'LA' ? ( $PaulVI_date->format('j') . ' ' . $LATIN_MONTHS[(int)$PaulVI_date->format('n')] ) :
                    ( $LITSETTINGS->LOCALE === 'EN' ? $PaulVI_date->format('F jS') :
                        trim(utf8_encode(strftime('%e %B', $PaulVI_date->format('U'))))
                    ),
                2019,
                '<a href="http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20200518_decreto-celebrazione-santafaustina_' . strtolower($LITSETTINGS->LOCALE) . '.html">' . __('Decree of the Congregation for Divine Worship', $LITSETTINGS->LOCALE) . '</a>',
                $coincidingFestivity->name,
                $LITSETTINGS->YEAR
            );
        }
    }

    //With the Decree of the Congregation of Divine Worship of May 20, 2020, the optional memorial of St. Faustina was added on Oct 5
    //http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20200518_decreto-celebrazione-santafaustina_la.html
    if($LITSETTINGS->YEAR >= 2020){
        $StFaustina_tag = ["LA" => "Sanctæ Faustinæ Kowalska", "IT" => "Santa Faustina Kowalska", "EN" => "Saint Faustina Kowalska"];
        $StFaustina_date = DateTime::createFromFormat('!j-n-Y', '5-10-' . $LITSETTINGS->YEAR, new DateTimeZone('UTC'));
        if(!in_array($StFaustina_date,$SOLEMNITIES) && !in_array($StFaustina_date,$FEASTS_MEMORIALS)){
            $LitCal["StFaustinaKowalska"] = new Festivity($StFaustina_tag[$LITSETTINGS->LOCALE], $StFaustina_date, "white", "fixed", MEMORIALOPT, "Holy Men and Women:For Religious");
            /**
             * TRANSLATORS:
             * 1. Grade or rank of the festivity
             * 2. Name of the festivity
             * 3. Day of the festivity
             * 4. Year from which the festivity has been added
             * 5. Source of the information
             * 6. Current year
             */
            $Messages[] = sprintf(
                __("The %s '%s' has been added on %s since the year %d (%s), applicable to the year %d.",$LITSETTINGS->LOCALE),
                _G($LitCal["StFaustinaKowalska"]->grade,$LITSETTINGS->LOCALE),
                $LitCal["StFaustinaKowalska"]->name,
                $LITSETTINGS->LOCALE === 'LA' ? ( $LitCal["StFaustinaKowalska"]->date->format('j') . ' ' . $LATIN_MONTHS[(int)$LitCal["StFaustinaKowalska"]->date->format('n')] ) :
                    ( $LITSETTINGS->LOCALE === 'EN' ? $LitCal["StFaustinaKowalska"]->date->format('F jS') :
                        trim(utf8_encode(strftime('%e %B', $LitCal["StFaustinaKowalska"]->date->format('U'))))
                    ),
                2020,
                '<a href="http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20200518_decreto-celebrazione-santafaustina_' . strtolower($LITSETTINGS->LOCALE) . '.html">' . __('Decree of the Congregation for Divine Worship', $LITSETTINGS->LOCALE) . '</a>',
                $LITSETTINGS->YEAR
            );
        }
        else{
            if(in_array($StFaustina_date,$SOLEMNITIES) ){
                $coincidingFestivityKey = array_search($StFaustina_date,$SOLEMNITIES);
            }
            else if(in_array($StFaustina_date,$FEASTS_MEMORIALS) ){
                $coincidingFestivityKey = array_search($StFaustina_date,$FEASTS_MEMORIALS);
            }
            $coincidingFestivity = $LitCal[$coincidingFestivityKey];
            $Messages[] = sprintf(
                __("The optional memorial '%s', added on %s since the year %d (%s), is however superseded by a Sunday, a Solemnity or a Feast '%s' in the year %d.",$LITSETTINGS->LOCALE),
                $StFaustina_tag[$LITSETTINGS->LOCALE],
                $LITSETTINGS->LOCALE === 'LA' ? ( $StFaustina_date->format('j') . ' ' . $LATIN_MONTHS[(int)$StFaustina_date->format('n')] ) :
                    ( $LITSETTINGS->LOCALE === 'EN' ? $StFaustina_date->format('F jS') :
                        trim(utf8_encode(strftime('%e %B', $StFaustina_date->format('U'))))
                    ),
                2020,
                '<a href="http://www.vatican.va/roman_curia/congregations/ccdds/documents/rc_con_ccdds_doc_20200518_decreto-celebrazione-santafaustina_' . strtolower($LITSETTINGS->LOCALE) . '.html">' . __('Decree of the Congregation for Divine Worship', $LITSETTINGS->LOCALE) . '</a>',
                $coincidingFestivity->name,
                $LITSETTINGS->YEAR
            );
        }
    }


} //END LITSETTINGS->YEAR > 2002

//    From the General Norms for the Liturgical Year and the Calendar (issued on Feb. 14 1969)
//    15. On Saturdays in Ordinary Time when there is no obligatory memorial, an optional memorial of the Blessed Virgin Mary is allowed.
//    So we have to cycle through all Saturdays of the year checking if there isn't an obligatory memorial
//    First we'll find the first Saturday of the year (to do this we actually have to find the last Saturday of the previous year,
//      so that our cycle using "next Saturday" logic will actually start from the first Saturday of the year),
//    and then continue for every next Saturday until we reach the last Saturday of the year
$currentSaturday = new DateTime("previous Saturday January $LITSETTINGS->YEAR",new DateTimeZone('UTC'));
$lastSatDT = new DateTime("last Saturday December $LITSETTINGS->YEAR",new DateTimeZone('UTC'));
$SatMemBVM_cnt = 0;
while($currentSaturday <= $lastSatDT){
    $currentSaturday = DateTime::createFromFormat('!j-n-Y', $currentSaturday->format('j-n-Y'),new DateTimeZone('UTC'))->modify('next Saturday');
    if(!in_array($currentSaturday, $SOLEMNITIES) && !in_array( $currentSaturday, $FEASTS_MEMORIALS)){
        $memID = "SatMemBVM" . ++$SatMemBVM_cnt;
        $LitCal[$memID] = new Festivity(__("Saturday Memorial of the Blessed Virgin Mary",$LITSETTINGS->LOCALE), $currentSaturday, "white", "mobile", MEMORIALOPT, "Blessed Virgin Mary" );
    }
}

//13. Weekdays of Advent up until Dec. 16 included (already calculated and defined together with weekdays 17 Dec. - 24 Dec.)
//    Weekdays of Christmas season from 2 Jan. until the Saturday after Epiphany
//    Weekdays of the Easter season, from the Monday after the Octave of Easter to the Saturday before Pentecost
//    Weekdays of Ordinary time
$DoMEaster = $LitCal["Easter"]->date->format('j');      //day of the month of Easter
$MonthEaster = $LitCal["Easter"]->date->format('n');    //month of Easter

//let's start cycling dates one at a time starting from Easter itself
$weekdayEaster = DateTime::createFromFormat('!j-n-Y', $DoMEaster . '-' . $MonthEaster . '-' . $LITSETTINGS->YEAR, new DateTimeZone('UTC'));
$weekdayEasterCnt = 1;
while ($weekdayEaster >= $LitCal["Easter"]->date && $weekdayEaster < $LitCal["Pentecost"]->date) {
    $weekdayEaster = DateTime::createFromFormat('!j-n-Y', $DoMEaster . '-' . $MonthEaster . '-' . $LITSETTINGS->YEAR, new DateTimeZone('UTC'))->add(new DateInterval('P' . $weekdayEasterCnt . 'D'));

    if (!in_array($weekdayEaster, $SOLEMNITIES) && !in_array($weekdayEaster, $FEASTS_MEMORIALS) && (int)$weekdayEaster->format('N') !== 7) {

        $upper = (int)$weekdayEaster->format('z');
        $diff = $upper - (int)$LitCal["Easter"]->date->format('z'); //day count between current day and Easter Sunday
        $currentEasterWeek = (($diff - $diff % 7) / 7) + 1;         //week count between current day and Easter Sunday
        $ordinal = ucfirst(getOrdinal($currentEasterWeek,$LITSETTINGS->LOCALE,$formatterFem,$LATIN_ORDINAL_FEM_GEN));
        $LitCal["EasterWeekday" . $weekdayEasterCnt] = new Festivity(($LITSETTINGS->LOCALE == 'LA' ? $LATIN_DAYOFTHEWEEK[$weekdayEaster->format('w')] : ucfirst(utf8_encode(strftime('%A',$weekdayEaster->format('U'))))) . " " . sprintf(__("of the %s Week of Easter",$LITSETTINGS->LOCALE),$ordinal), $weekdayEaster, "white", "mobile");
    }

    $weekdayEasterCnt++;
}



//WEEKDAYS of ORDINARY TIME
//In the first part of the year, weekdays of ordinary time begin the day after the Baptism of the Lord
$FirstWeekdaysLowerLimit = $LitCal["BaptismLord"]->date;
//and end with Ash Wednesday
$FirstWeekdaysUpperLimit = $LitCal["AshWednesday"]->date;

$ordWeekday = 1;
$currentOrdWeek = 1;
$firstOrdinary = DateTime::createFromFormat('!j-n-Y', $BaptismLordFmt, new DateTimeZone('UTC'))->modify($BaptismLordMod);
$firstSunday = DateTime::createFromFormat('!j-n-Y', $BaptismLordFmt, new DateTimeZone('UTC'))->modify($BaptismLordMod)->modify('next Sunday');
$dayFirstSunday = (int)$firstSunday->format('z');

while ($firstOrdinary >= $FirstWeekdaysLowerLimit && $firstOrdinary < $FirstWeekdaysUpperLimit) {
    $firstOrdinary = DateTime::createFromFormat('!j-n-Y', $BaptismLordFmt, new DateTimeZone('UTC'))->modify($BaptismLordMod)->add(new DateInterval('P' . $ordWeekday . 'D'));
    if (!in_array($firstOrdinary, $SOLEMNITIES) && !in_array($firstOrdinary, $FEASTS_MEMORIALS)) {
        //The Baptism of the Lord is the First Sunday, so the weekdays following are of the First Week of Ordinary Time
        //After the Second Sunday, let's calculate which week of Ordinary Time we're in
        if ($firstOrdinary > $firstSunday) {
            $upper = (int) $firstOrdinary->format('z');
            $diff = $upper - $dayFirstSunday;
            $currentOrdWeek = (($diff - $diff % 7) / 7) + 2;
        }
        $ordinal = ucfirst(getOrdinal($currentOrdWeek,$LITSETTINGS->LOCALE,$formatterFem,$LATIN_ORDINAL_FEM_GEN));
        $LitCal["FirstOrdWeekday" . $ordWeekday] = new Festivity(($LITSETTINGS->LOCALE == 'LA' ? $LATIN_DAYOFTHEWEEK[$firstOrdinary->format('w')] : ucfirst(utf8_encode(strftime('%A',$firstOrdinary->format('U')))) ) . " " . sprintf(__("of the %s Week of Ordinary Time",$LITSETTINGS->LOCALE), $ordinal ), $firstOrdinary, "green", "mobile");
    }
    $ordWeekday++;
}


//In the second part of the year, weekdays of ordinary time begin the day after Pentecost
$SecondWeekdaysLowerLimit = $LitCal["Pentecost"]->date;
//and end with the Feast of Christ the King
$SecondWeekdaysUpperLimit = DateTime::createFromFormat('!j-n-Y', '25-12-' . $LITSETTINGS->YEAR, new DateTimeZone('UTC'))->modify('last Sunday')->sub(new DateInterval('P' . (3 * 7) . 'D'));

$ordWeekday = 1;
//$currentOrdWeek = 1;
$lastOrdinary = calcGregEaster($LITSETTINGS->YEAR)->add(new DateInterval('P' . (7 * 7) . 'D'));
$dayLastSunday = (int)DateTime::createFromFormat('!j-n-Y', '25-12-' . $LITSETTINGS->YEAR, new DateTimeZone('UTC'))->modify('last Sunday')->sub(new DateInterval('P' . (3 * 7) . 'D'))->format('z');

while ($lastOrdinary >= $SecondWeekdaysLowerLimit && $lastOrdinary < $SecondWeekdaysUpperLimit) {
    $lastOrdinary = calcGregEaster($LITSETTINGS->YEAR)->add(new DateInterval('P' . (7 * 7 + $ordWeekday) . 'D'));
    if (!in_array($lastOrdinary, $SOLEMNITIES) && !in_array($lastOrdinary, $FEASTS_MEMORIALS)) {
        $lower = (int) $lastOrdinary->format('z');
        $diff = $dayLastSunday - $lower; //day count between current day and Christ the King Sunday
        $weekDiff = (($diff - $diff % 7) / 7); //week count between current day and Christ the King Sunday;
        $currentOrdWeek = 34 - $weekDiff;

        $ordinal = ucfirst(getOrdinal($currentOrdWeek,$LITSETTINGS->LOCALE,$formatterFem,$LATIN_ORDINAL_FEM_GEN));
        $LitCal["LastOrdWeekday" . $ordWeekday] = new Festivity(($LITSETTINGS->LOCALE == 'LA' ? $LATIN_DAYOFTHEWEEK[$lastOrdinary->format('w')] : ucfirst(utf8_encode(strftime('%A',$lastOrdinary->format('U')))) ) . " " . sprintf(__("of the %s Week of Ordinary Time",$LITSETTINGS->LOCALE), $ordinal ), $lastOrdinary, "green", "mobile");
    }
    $ordWeekday++;
}

//END WEEKDAYS of ORDINARY TIME

//ADD NATIONAL CALENDARS IF REQUESTED
if($LITSETTINGS->NATIONAL !== false){
    switch($LITSETTINGS->NATIONAL){
        case 'ITALY':

            //Insert or elevate the Patron Saints of Europe

            if(array_key_exists("StBenedict",$LitCal) ){
                $LitCal["StBenedict"]->grade = FEAST;
                $LitCal["StBenedict"]->name .= ", patrono d'Europa";
                $LitCal["StBenedict"]->common = "Proper";
            } else {
                //check what's going on, for example, if it's a Sunday or Solemnity
                $currentFeastDate = DateTime::createFromFormat('!j-n-Y', '11-7-' . $LITSETTINGS->YEAR, new DateTimeZone('UTC'));
                if(in_array($currentFeastDate,$SOLEMNITIES) || in_array($currentFeastDate,$FEASTS_MEMORIALS) || (int)$currentFeastDate->format('N') === 7 ){
                    $coincidingFestivity_grade = '';
                    if((int)$currentFeastDate->format('N') === 7 && $LitCal[array_search($currentFeastDate,$SOLEMNITIES)]->grade < SOLEMNITY ){
                        //it's a Sunday
                        $coincidingFestivity = $LitCal[array_search($currentFeastDate,$SOLEMNITIES)];
                        $coincidingFestivity_grade = $LITSETTINGS->LOCALE === 'LA' ? 'Die Domini' : ucfirst(utf8_encode(strftime('%A',$currentFeastDate->format('U'))));
                    } else if (in_array($currentFeastDate, $SOLEMNITIES)){
                        //it's a Feast of the Lord or a Solemnity
                        $coincidingFestivity = $LitCal[array_search($currentFeastDate,$SOLEMNITIES)];
                        $coincidingFestivity_grade = ($coincidingFestivity->grade > SOLEMNITY ? '<i>' . _G($coincidingFestivity->grade,$LITSETTINGS->LOCALE,false) . '</i>' : _G($coincidingFestivity->grade,$LITSETTINGS->LOCALE,false));
                    } else if(in_array($currentFeastDate, $FEASTS_MEMORIALS)){
                        //we should probably be able to create it anyways in this case?
                        $result = $mysqli->query("SELECT * FROM LITURGY__calendar_propriumdesanctis WHERE TAG = 'StBenedict'");
                        $row = mysqli_fetch_assoc($result);
                        $LitCal["StBenedict"] = new Festivity($row["NAME_" . $LITSETTINGS->LOCALE] . ", patrono d'Europa", $currentFeastDate,"white","fixed",FEAST,"Proper");
                        $coincidingFestivity = $LitCal[array_search($currentFeastDate,$FEASTS_MEMORIALS)];
                        $coincidingFestivity_grade = _G($coincidingFestivity->grade,$LITSETTINGS->LOCALE,false);
                    }

                    $Messages[] =  '<span style="padding:3px 6px; font-weight: bold; background-color: #FFC;color:Red;border-radius:6px;">IMPORTANT</span> ' . sprintf(
                        "La Festa del patrono d'Europa <i>'San Benedetto abate'</i> è soppressa nell'anno %d dalla %s <i>'%s'</i>.",
                        $LITSETTINGS->YEAR,
                        $coincidingFestivity_grade,
                        $coincidingFestivity->name
                    );

                }
            }

            if(array_key_exists("StBridget",$LitCal) ){
                $LitCal["StBridget"]->grade = FEAST;
                $LitCal["StBridget"]->name .= ", patrona d'Europa";
                $LitCal["StBridget"]->common = "Proper";
            } else {
                //check what's going on, for example, if it's a Sunday or Solemnity
                $currentFeastDate = DateTime::createFromFormat('!j-n-Y', '23-7-' . $LITSETTINGS->YEAR, new DateTimeZone('UTC'));
                if(in_array($currentFeastDate,$SOLEMNITIES) || in_array($currentFeastDate,$FEASTS_MEMORIALS) || (int)$currentFeastDate->format('N') === 7 ){
                    $coincidingFestivity_grade = '';
                    if((int)$currentFeastDate->format('N') === 7 && $LitCal[array_search($currentFeastDate,$SOLEMNITIES)]->grade < SOLEMNITY ){
                        //it's a Sunday
                        $coincidingFestivity = $LitCal[array_search($currentFeastDate,$SOLEMNITIES)];
                        $coincidingFestivity_grade = $LITSETTINGS->LOCALE === 'LA' ? 'Die Domini' : ucfirst(utf8_encode(strftime('%A',$currentFeastDate->format('U'))));
                    } else if (in_array($currentFeastDate, $SOLEMNITIES)){
                        //it's a Feast of the Lord or a Solemnity
                        $coincidingFestivity = $LitCal[array_search($currentFeastDate,$SOLEMNITIES)];
                        $coincidingFestivity_grade = ($coincidingFestivity->grade > SOLEMNITY ? '<i>' . _G($coincidingFestivity->grade,$LITSETTINGS->LOCALE,false) . '</i>' : _G($coincidingFestivity->grade,$LITSETTINGS->LOCALE,false));
                    } else if(in_array($currentFeastDate, $FEASTS_MEMORIALS)){
                        //we should probably be able to create it anyways in this case?
                        $result = $mysqli->query("SELECT * FROM LITURGY__calendar_propriumdesanctis WHERE TAG = 'StBridget'");
                        $row = mysqli_fetch_assoc($result);
                        $LitCal["StBridget"] = new Festivity($row["NAME_" . $LITSETTINGS->LOCALE] . ", patrona d'Europa", $currentFeastDate,"white","fixed",FEAST,"Proper");
                        $coincidingFestivity = $LitCal[array_search($currentFeastDate,$FEASTS_MEMORIALS)];
                        $coincidingFestivity_grade = _G($coincidingFestivity->grade,$LITSETTINGS->LOCALE,false);
                    }

                    $Messages[] =  '<span style="padding:3px 6px; font-weight: bold; background-color: #FFC;color:Red;border-radius:6px;">IMPORTANT</span> ' . sprintf(
                        "La Festa della patrona d'Europa <i>'Santa Brigida'</i> è soppressa nell'anno %d dalla %s <i>'%s'</i>.",
                        $LITSETTINGS->YEAR,
                        $coincidingFestivity_grade,
                        $coincidingFestivity->name
                    );

                }
            }

            if(array_key_exists("StEdithStein",$LitCal) ){
                $LitCal["StEdithStein"]->grade = FEAST;
                $LitCal["StEdithStein"]->name .= ", patrona d'Europa";
                $LitCal["StEdithStein"]->common = "Proper";
            } else {
                //check what's going on, for example, if it's a Sunday or Solemnity
                $currentFeastDate = DateTime::createFromFormat('!j-n-Y', '9-8-' . $LITSETTINGS->YEAR, new DateTimeZone('UTC'));
                if(in_array($currentFeastDate,$SOLEMNITIES) || in_array($currentFeastDate,$FEASTS_MEMORIALS) || (int)$currentFeastDate->format('N') === 7 ){
                    $coincidingFestivity_grade = '';
                    if((int)$currentFeastDate->format('N') === 7 && $LitCal[array_search($currentFeastDate,$SOLEMNITIES)]->grade < SOLEMNITY ){
                        //it's a Sunday
                        $coincidingFestivity = $LitCal[array_search($currentFeastDate,$SOLEMNITIES)];
                        $coincidingFestivity_grade = $LITSETTINGS->LOCALE === 'LA' ? 'Die Domini' : ucfirst(utf8_encode(strftime('%A',$currentFeastDate->format('U'))));
                    } else if (in_array($currentFeastDate, $SOLEMNITIES)){
                        //it's a Feast of the Lord or a Solemnity
                        $coincidingFestivity = $LitCal[array_search($currentFeastDate,$SOLEMNITIES)];
                        $coincidingFestivity_grade = ($coincidingFestivity->grade > SOLEMNITY ? '<i>' . _G($coincidingFestivity->grade,$LITSETTINGS->LOCALE,false) . '</i>' : _G($coincidingFestivity->grade,$LITSETTINGS->LOCALE,false));
                    } else if(in_array($currentFeastDate, $FEASTS_MEMORIALS)){
                        //we should probably be able to create it anyways in this case?
                        $result = $mysqli->query("SELECT * FROM LITURGY__calendar_propriumdesanctis_2002 WHERE TAG = 'StEdithStein'");
                        $row = mysqli_fetch_assoc($result);
                        $LitCal["StEdithStein"] = new Festivity($row["NAME_" . $LITSETTINGS->LOCALE] . ", patrona d'Europa", $currentFeastDate,"white","fixed",FEAST,"Proper");
                        $coincidingFestivity = $LitCal[array_search($currentFeastDate,$FEASTS_MEMORIALS)];
                        $coincidingFestivity_grade = _G($coincidingFestivity->grade,$LITSETTINGS->LOCALE,false);
                    }

                    $Messages[] =  '<span style="padding:3px 6px; font-weight: bold; background-color: #FFC;color:Red;border-radius:6px;">IMPORTANT</span> ' . sprintf(
                        "La Festa della patrona d'Europa <i>'Santa Edith Stein'</i> è soppressa nell'anno %d dalla %s <i>'%s'</i>.",
                        $LITSETTINGS->YEAR,
                        $coincidingFestivity_grade,
                        $coincidingFestivity->name
                    );

                }
            }

            if(array_key_exists("StsCyrilMethodius",$LitCal) ){
                $LitCal["StsCyrilMethodius"]->grade = FEAST;
                $LitCal["StsCyrilMethodius"]->name .= ", patroni d'Europa";
                $LitCal["StsCyrilMethodius"]->common = "Proper";
            } else {
                //check what's going on, for example, if it's a Sunday or Solemnity
                $currentFeastDate = DateTime::createFromFormat('!j-n-Y', '14-2-' . $LITSETTINGS->YEAR, new DateTimeZone('UTC'));
                if(in_array($currentFeastDate,$SOLEMNITIES) || in_array($currentFeastDate,$FEASTS_MEMORIALS) || (int)$currentFeastDate->format('N') === 7 ){
                    $coincidingFestivity_grade = '';
                    if((int)$currentFeastDate->format('N') === 7 && $LitCal[array_search($currentFeastDate,$SOLEMNITIES)]->grade < SOLEMNITY ){
                        //it's a Sunday
                        $coincidingFestivity = $LitCal[array_search($currentFeastDate,$SOLEMNITIES)];
                        $coincidingFestivity_grade = $LITSETTINGS->LOCALE === 'LA' ? 'Die Domini' : ucfirst(utf8_encode(strftime('%A',$currentFeastDate->format('U'))));
                    } else if (in_array($currentFeastDate, $SOLEMNITIES)){
                        //it's a Feast of the Lord or a Solemnity
                        $coincidingFestivity = $LitCal[array_search($currentFeastDate,$SOLEMNITIES)];
                        $coincidingFestivity_grade = ($coincidingFestivity->grade > SOLEMNITY ? '<i>' . _G($coincidingFestivity->grade,$LITSETTINGS->LOCALE,false) . '</i>' : _G($coincidingFestivity->grade,$LITSETTINGS->LOCALE,false));
                    } else if(in_array($currentFeastDate, $FEASTS_MEMORIALS)){
                        //we should probably be able to create it anyways in this case?
                        $result = $mysqli->query("SELECT * FROM LITURGY__calendar_propriumdesanctis WHERE TAG = 'StsCyrilMethodius'");
                        $row = mysqli_fetch_assoc($result);
                        $LitCal["StsCyrilMethodius"] = new Festivity($row["NAME_" . $LITSETTINGS->LOCALE] . ", patroni d'Europa", $currentFeastDate,"white","fixed",FEAST,"Proper");
                        $coincidingFestivity = $LitCal[array_search($currentFeastDate,$FEASTS_MEMORIALS)];
                        $coincidingFestivity_grade = _G($coincidingFestivity->grade,$LITSETTINGS->LOCALE,false);
                    }

                    $Messages[] =  '<span style="padding:3px 6px; font-weight: bold; background-color: #FFC;color:Red;border-radius:6px;">IMPORTANT</span> ' . sprintf(
                        "La Festa dei patroni d'Europa <i>'Santi Cirillo e Metodio'</i> è soppressa nell'anno %d dalla %s <i>'%s'</i>.",
                        $LITSETTINGS->YEAR,
                        $coincidingFestivity_grade,
                        $coincidingFestivity->name
                    );

                }
            }

            //Insert or elevate the Patron Saints of Italy
            if(array_key_exists("StCatherineSiena",$LitCal)){
                $LitCal["StCatherineSiena"]->grade = FEAST;
                //Nel 1999, Papa Giovanni Paolo II elevò Caterina da Siena a patrona d'Europa oltre che d'Italia
                if($LITSETTINGS->YEAR >= 1999){
                    $LitCal["StCatherineSiena"]->name .= ", patrona d'Italia e d'Europa";
                } else {
                    $LitCal["StCatherineSiena"]->name .= ", patrona d'Italia";
                }
                $LitCal["StCatherineSiena"]->common = "Proper";
            }
            else{
                //check what's going on, for example, if it's a Sunday or Solemnity
                $currentFeastDate = DateTime::createFromFormat('!j-n-Y', '29-4-' . $LITSETTINGS->YEAR, new DateTimeZone('UTC'));
                if(in_array($currentFeastDate,$SOLEMNITIES) || in_array($currentFeastDate,$FEASTS_MEMORIALS) || (int)$currentFeastDate->format('N') === 7 ){
                    $coincidingFestivity_grade = '';
                    if((int)$currentFeastDate->format('N') === 7 && $LitCal[array_search($currentFeastDate,$SOLEMNITIES)]->grade < SOLEMNITY ){
                        //it's a Sunday
                        $coincidingFestivity = $LitCal[array_search($currentFeastDate,$SOLEMNITIES)];
                        $coincidingFestivity_grade = $LITSETTINGS->LOCALE === 'LA' ? 'Die Domini' : ucfirst(utf8_encode(strftime('%A',$currentFeastDate->format('U'))));
                    } else if (in_array($currentFeastDate, $SOLEMNITIES)){
                        //it's a Feast of the Lord or a Solemnity
                        $coincidingFestivity = $LitCal[array_search($currentFeastDate,$SOLEMNITIES)];
                        $coincidingFestivity_grade = ($coincidingFestivity->grade > SOLEMNITY ? '<i>' . _G($coincidingFestivity->grade,$LITSETTINGS->LOCALE,false) . '</i>' : _G($coincidingFestivity->grade,$LITSETTINGS->LOCALE,false));
                    } else if(in_array($currentFeastDate, $FEASTS_MEMORIALS)){
                        //we should probably be able to create it anyways in this case?
                        $result = $mysqli->query("SELECT * FROM LITURGY__calendar_propriumdesanctis WHERE TAG = 'StCatherineSiena'");
                        $row = mysqli_fetch_assoc($result);
                        $LitCal["StCatherineSiena"] = new Festivity($row["NAME_" . $LITSETTINGS->LOCALE], $currentFeastDate,"white","fixed",FEAST,"Proper");
                        if($LITSETTINGS->YEAR >= 1999){
                            $LitCal["StCatherineSiena"]->name .= ", patrona d'Italia e d'Europa";
                        } else {
                            $LitCal["StCatherineSiena"]->name .= ", patrona d'Italia";
                        }
                        $coincidingFestivity = $LitCal[array_search($currentFeastDate,$FEASTS_MEMORIALS)];
                        $coincidingFestivity_grade = _G($coincidingFestivity->grade,$LITSETTINGS->LOCALE,false);
                    }

                    $StCatherineSienaName = 'Santa Caterina da Siena';
                    if($LITSETTINGS->YEAR >= 1999){
                        $StCatherineSienaName .= ", patrona d'Italia e d'Europa";
                    } else {
                        $StCatherineSienaName .= ", patrona d'Italia";
                    }
                    $Messages[] =  '<span style="padding:3px 6px; font-weight: bold; background-color: #FFC;color:Red;border-radius:6px;">IMPORTANT</span> ' . sprintf(
                        "La Festa di <i>'%s'</i> è soppressa nell'anno %d dalla %s <i>'%s'</i>.",
                        $StCatherineSienaName,
                        $LITSETTINGS->YEAR,
                        $coincidingFestivity_grade,
                        $coincidingFestivity->name
                    );

                }
            }

            if(array_key_exists("StFrancisAssisi",$LitCal)){
                $LitCal["StFrancisAssisi"]->grade = FEAST;
                $LitCal["StFrancisAssisi"]->name .= ", patrono d'Italia";
                $LitCal["StFrancisAssisi"]->common = "Proper";
            }
            else{
                //check what's going on, for example, if it's a Sunday or Solemnity
                $currentFeastDate = DateTime::createFromFormat('!j-n-Y', '4-10-' . $LITSETTINGS->YEAR, new DateTimeZone('UTC'));
                if(in_array($currentFeastDate,$SOLEMNITIES) || in_array($currentFeastDate,$FEASTS_MEMORIALS) || (int)$currentFeastDate->format('N') === 7 ){
                    $coincidingFestivity_grade = '';
                    if((int)$currentFeastDate->format('N') === 7 && $LitCal[array_search($currentFeastDate,$SOLEMNITIES)]->grade < SOLEMNITY ){
                        //it's a Sunday
                        $coincidingFestivity = $LitCal[array_search($currentFeastDate,$SOLEMNITIES)];
                        $coincidingFestivity_grade = $LITSETTINGS->LOCALE === 'LA' ? 'Die Domini' : ucfirst(utf8_encode(strftime('%A',$currentFeastDate->format('U'))));
                    } else if (in_array($currentFeastDate, $SOLEMNITIES)){
                        //it's a Feast of the Lord or a Solemnity
                        $coincidingFestivity = $LitCal[array_search($currentFeastDate,$SOLEMNITIES)];
                        $coincidingFestivity_grade = ($coincidingFestivity->grade > SOLEMNITY ? '<i>' . _G($coincidingFestivity->grade,$LITSETTINGS->LOCALE,false) . '</i>' : _G($coincidingFestivity->grade,$LITSETTINGS->LOCALE,false));
                    } else if(in_array($currentFeastDate, $FEASTS_MEMORIALS)){
                        //we should probably be able to create it anyways in this case?
                        $result = $mysqli->query("SELECT * FROM LITURGY__calendar_propriumdesanctis WHERE TAG = 'StFrancisAssisi'");
                        $row = mysqli_fetch_assoc($result);
                        $LitCal["StFrancisAssisi"] = new Festivity($row["NAME_" . $LITSETTINGS->LOCALE] . ", patrono d'Italia", $currentFeastDate,"white","fixed",FEAST,"Proper");
                        $coincidingFestivity = $LitCal[array_search($currentFeastDate,$FEASTS_MEMORIALS)];
                        $coincidingFestivity_grade = _G($coincidingFestivity->grade,$LITSETTINGS->LOCALE,false);
                    }

                    $Messages[] =  '<span style="padding:3px 6px; font-weight: bold; background-color: #FFC;color:Red;border-radius:6px;">IMPORTANT</span> ' . sprintf(
                        "La Festa del patrono d'Italia <i>'San Francesco di Assisi'</i> è soppressa nell'anno %d dalla %s <i>'%s'</i>.",
                        $LITSETTINGS->YEAR,
                        $coincidingFestivity_grade,
                        $coincidingFestivity->name
                    );

                }
            }

            //The extra liturgical events found in the 1983 edition of the Roman Missal in Italian,
            //were then incorporated into the Latin edition in 2002 (effectively being incorporated into the General Roman Calendar)
            //so when dealing with Italy, we only need to add them from 1983 until 2002, after which it's taken care of by the General Calendar
            if($LITSETTINGS->YEAR >= 1983 && $LITSETTINGS->YEAR < 2002){
                if ($result = $mysqli->query("SELECT * FROM LITURGY__ITALY_calendar_propriumdesanctis_1983")) {
                    while ($row = mysqli_fetch_assoc($result)) {
                        $currentFeastDate = DateTime::createFromFormat('!j-n-Y', $row['DAY'] . '-' . $row['MONTH'] . '-' . $LITSETTINGS->YEAR, new DateTimeZone('UTC'));
                        if(!in_array($currentFeastDate,$SOLEMNITIES)){
                            $LitCal[$row["TAG"]] = new Festivity("[ITALIA] " . $row["NAME_" . $LITSETTINGS->LOCALE], $currentFeastDate, $row["COLOR"], "fixed", $row["GRADE"], $row["COMMON"], $row["DISPLAYGRADE"]);
                        }
                        else{
                            $Messages[] = sprintf(
                                "ITALIA: la %s '%s' (%s), aggiunta al calendario nell'edizione del Messale Romano del 1983 pubblicata dalla CEI, è soppressa da una Domenica o una Solennità nell'anno %d",
                                $row["DISPLAYGRADE"] !== "" ? $row["DISPLAYGRADE"] : _G($row["GRADE"],$LITSETTINGS->LOCALE,false),
                                '<i>' . $row["NAME_" . $LITSETTINGS->LOCALE] . '</i>',
                                trim(utf8_encode(strftime('%e %B', $currentFeastDate->format('U')))),
                                $LITSETTINGS->YEAR
                            );
                        }
                    }
                }
            }

            //At least in Italy, according to the ORDO (Guida Liturgico-Pastorale) della Diocesi di Roma, Saint Pio is an obligatory memorial throughout Italy
            //The September 2020 edition of the Roman Missal in Italian confirms this
            if( array_key_exists("StPioPietrelcina",$LitCal) ){
                $LitCal["StPioPietrelcina"]->grade = MEMORIAL;
                $LitCal["StPioPietrelcina"]->common = "Pastors:For One Pastor";
            }

        break;
        case 'USA':

            if($LITSETTINGS->YEAR >= 2011){
                //move Saint Vincent Deacon from Jan 22 to Jan 23 in order to allow for National Day of Prayer for the Unborn on Jan 22
                //however if Jan 22 is a Sunday, National Day of Prayer for the Unborn is moved to Jan 23 (in place of Saint Vincent Deacon)
                if(array_key_exists("StVincentDeacon",$LitCal)){
                    //I believe we don't have to worry about suppressing, because if it's on a Sunday it won't exist already
                    //so if the National Day of Prayer happens on a Sunday and must be moved to Monday, Saint Vincent will be already gone anyways
                    $LitCal["StVincentDeacon"]->date->add(new DateInterval('P1D'));
                    //let's not worry about translating these messages, just leave them in English
                    $Messages[] = sprintf(
                        "USA: The Memorial '%s' was moved from Jan 22 to Jan 23 to make room for the National Day of Prayer for the Unborn, as per the 2011 Roman Missal issued by the USCCB",
                        '<i>' . $LitCal["StVincentDeacon"]->name . '</i>'
                    );
                    $LitCal["StVincentDeacon"]->name = "[USA] " . $LitCal["StVincentDeacon"]->name;
                }

                if(array_key_exists("StsJeanBrebeuf",$LitCal)){
                    //if it exists, it means it's not on a Sunday, so we can go ahead and elevate it to Memorial
                    $LitCal["StsJeanBrebeuf"]->grade = MEMORIAL;
                    $Messages[] = sprintf(
                        "USA: The optional memorial '%s' is elevated to Memorial on Oct 19 as per the 2011 Roman Missal issued by the USCCB, applicable to the year %d",
                        '<i>' . $LitCal["StsJeanBrebeuf"]->name . '</i>',
                        $LITSETTINGS->YEAR
                    );
                    $LitCal["StsJeanBrebeuf"]->name = "[USA] " . $LitCal["StsJeanBrebeuf"]->name;
                    
                    if(array_key_exists("StPaulCross",$LitCal)){ //of course it will exist if StsJeanBrebeuf exists, they are originally on the same day
                        $LitCal["StPaulCross"]->date->add(new DateInterval('P1D'));
                        if(in_array($LitCal["StPaulCross"]->date,$SOLEMNITIES) || in_array($LitCal["StPaulCross"]->date,$FEASTS_MEMORIALS)){
                            $Messages[] = sprintf(
                                "USA: The optional memorial '%s' is transferred from Oct 19 to Oct 20 as per the 2011 Roman Missal issued by the USCCB, to make room for '%s' elevated to the rank of Memorial, however in the year %d it is superseded by a higher ranking liturgical event",
                                '<i>' . $LitCal["StPaulCross"]->name . '</i>',
                                '<i>' . $LitCal["StsJeanBrebeuf"]->name . '</i>',
                                $LITSETTINGS->YEAR
                            );
                            unset($LitCal["StPaulCross"]);
                        }else{
                            $Messages[] = sprintf(
                                "USA: The optional memorial '%s' is transferred from Oct 19 to Oct 20 as per the 2011 Roman Missal issued by the USCCB, to make room for '%s' elevated to the rank of Memorial, applicable to the year %d",
                                '<i>' . $LitCal["StPaulCross"]->name . '</i>',
                                '<i>' . $LitCal["StsJeanBrebeuf"]->name . '</i>',
                                $LITSETTINGS->YEAR
                            );
                            $LitCal["StPaulCross"]->name = "[USA] " . $LitCal["StPaulCross"]->name;
                        }
                    }
                }
                else{
                    //if Oct 19 is a Sunday or Solemnity, Saint Paul of the Cross won't exist. But it still needs to be moved to Oct 20 so we must create it again
                    $currentFeastDate = DateTime::createFromFormat('!j-n-Y', '20-10-' . $LITSETTINGS->YEAR, new DateTimeZone('UTC'));
                    if(!in_array($currentFeastDate,$SOLEMNITIES) && !array_key_exists("StPaulCross",$LitCal) ){
                        if ($result = $mysqli->query("SELECT * FROM LITURGY__calendar_propriumdesanctis WHERE TAG = 'StPaulCross'")) {
                            $row = mysqli_fetch_assoc($result);
                            $LitCal["StPaulCross"] = new Festivity("[USA] " . $row["NAME_" . $LITSETTINGS->LOCALE], $currentFeastDate, $row["COLOR"], "fixed", $row["GRADE"], $row["COMMON"]);
                            $Messages[] = sprintf(
                                'USA: The optional memorial \'%1$s\' is transferred from Oct 19 to Oct 20 as per the 2011 Roman Missal issued by the USCCB, to make room for \'%2$s\' elevated to the rank of Memorial: applicable to the year %3$d.',
                                $row["NAME_" . $LITSETTINGS->LOCALE],
                                '<i>' . $LitCal["StsJeanBrebeuf"]->name . '</i>',
                                $LITSETTINGS->YEAR
                            );
                        }
                    }
                }

                //The fourth Thursday of November is Thanksgiving
                $thanksgivingDateTS = strtotime('fourth thursday of november ' . $LITSETTINGS->YEAR . ' UTC');
                $thanksgivingDate = new DateTime("@$thanksgivingDateTS", new DateTimeZone('UTC'));
                $LitCal["ThanksgivingDay"] = new Festivity("[USA] Thanksgiving", $thanksgivingDate, "white", "mobile", MEMORIAL, '', 'National Holiday');


                if ($result = $mysqli->query("SELECT * FROM LITURGY__USA_calendar_propriumdesanctis_2011")) {
                    while ($row = mysqli_fetch_assoc($result)) {
                        $currentFeastDate = DateTime::createFromFormat('!j-n-Y', $row['DAY'] . '-' . $row['MONTH'] . '-' . $LITSETTINGS->YEAR, new DateTimeZone('UTC'));
                        if(!in_array($currentFeastDate,$SOLEMNITIES)){
                            $LitCal[$row["TAG"]] = new Festivity("[USA] " . $row["NAME_" . $LITSETTINGS->LOCALE], $currentFeastDate, $row["COLOR"], "fixed", $row["GRADE"], $row["COMMON"], $row["DISPLAYGRADE"]);
                        }
                        else if((int)$currentFeastDate->format('N') === 7 && $row["TAG"] === "PrayerUnborn" ){
                            $LitCal[$row["TAG"]] = new Festivity("[USA] " . $row["NAME_" . $LITSETTINGS->LOCALE], $currentFeastDate->add(new DateInterval('P1D')), $row["COLOR"], "fixed", $row["GRADE"], $row["COMMON"], $row["DISPLAYGRADE"]);
                            $Messages[] = sprintf(
                                "USA: The National Day of Prayer for the Unborn is set to Jan 22 as per the 2011 Roman Missal issued by the USCCB, however since it coincides with a Sunday or a Solemnity in the year %d, it has been moved to Jan 23",
                                $LITSETTINGS->YEAR
                            );
                        }
                        else{
                            $Messages[] = sprintf(
                                "USA: the %s '%s', added to the calendar as per the 2011 Roman Missal issued by the USCCB, is superseded by a Sunday or a Solemnity in the year %d",
                                $row["DISPLAYGRADE"] !== "" ? $row["DISPLAYGRADE"] : _G($row["GRADE"],$LITSETTINGS->LOCALE,false),
                                '<i>' . $row["NAME_" . $LITSETTINGS->LOCALE] . '</i>',
                                $LITSETTINGS->YEAR
                            );
                        }
                    }
                }

                $currentFeastDate = DateTime::createFromFormat('!j-n-Y', '18-7-' . $LITSETTINGS->YEAR, new DateTimeZone('UTC'));
                if(!in_array($currentFeastDate,$SOLEMNITIES)){
                    if(array_key_exists("StCamillusDeLellis",$LitCal)){
                        //Move Camillus De Lellis from July 14 to July 18, to make room for Kateri Tekakwitha
                        $LitCal["StCamillusDeLellis"]->date = $currentFeastDate;
                    }
                    else{
                        //if it was suppressed on July 14 because of higher ranking celebration, we should recreate it on July 18 if possible
                        if ($result = $mysqli->query("SELECT * FROM LITURGY__calendar_propriumdesanctis WHERE TAG = 'StCamillusDeLellis'")) {
                            $row = mysqli_fetch_assoc($result);
                            $LitCal["StCamillusDeLellis"] = new Festivity($row["NAME_" . $LITSETTINGS->LOCALE], $currentFeastDate, $row["COLOR"], "fixed", $row["GRADE"], $row["COMMON"]);
                        }
                    }
                    $Messages[] = sprintf(
                        'USA: The optional memorial \'%1$s\' is transferred from July 14 to July 18 as per the 2011 Roman Missal issued by the USCCB, to make room for the Memorial \'%2$s\': applicable to the year %3$d.',
                        '<i>' . $LitCal["StCamillusDeLellis"]->name . '</i>',
                        '<i>' . "Blessed Kateri Tekakwitha" . '</i>', //can't use $LitCal["KateriTekakwitha"], might not exist!
                        $LITSETTINGS->YEAR
                    );
                    $LitCal["StCamillusDeLellis"]->name = "[USA] " . $LitCal["StCamillusDeLellis"]->name;
                }
                else{
                    if(array_key_exists("StCamillusDeLellis",$LitCal)){
                        //Can't move Camillus De Lellis from July 14 to July 18, so simply suppress to make room for Kateri Tekakwitha
                        $Messages[] = sprintf(
                            'USA: The optional memorial \'%1$s\' is transferred from July 14 to July 18 as per the 2011 Roman Missal issued by the USCCB, to make room for the Memorial \'%2$s\', however it is superseded by a higher ranking festivity in the year %3$d.',
                            '<i>' . $LitCal["StCamillusDeLellis"]->name . '</i>',
                            '<i>' . "Blessed Kateri Tekakwitha" . '</i>', //can't use $LitCal["KateriTekakwitha"], might not exist!
                            $LITSETTINGS->YEAR
                        );
                        unset($LitCal["StCamillusDeLellis"]);
                    }
                }

                $currentFeastDate = DateTime::createFromFormat('!j-n-Y', '5-7-' . $LITSETTINGS->YEAR, new DateTimeZone('UTC'));
                if(!in_array($currentFeastDate,$SOLEMNITIES)){
                    if(array_key_exists("StElizabethPortugal",$LitCal)){
                        //Move Elizabeth of Portugal from July 4 to July 5 to make room for Independence Day
                        $LitCal["StElizabethPortugal"]->date = $currentFeastDate;
                    }
                    else{
                        //if it was suppressed on July 4 because of higher ranking celebration, we should recreate on July 5 if possible
                        if ($result = $mysqli->query("SELECT * FROM LITURGY__calendar_propriumdesanctis WHERE TAG = 'StElizabethPortugal'")) {
                            $row = mysqli_fetch_assoc($result);
                            $LitCal["StElizabethPortugal"] = new Festivity($row["NAME_" . $LITSETTINGS->LOCALE], $currentFeastDate, $row["COLOR"], "fixed", $row["GRADE"], $row["COMMON"]);
                        }
                    }
                    $Messages[] = sprintf(
                        'USA: The optional memorial \'%1$s\' is transferred from July 4 to July 5 as per the 2011 Roman Missal issued by the USCCB, to make room for the Holiday \'%2$s\': applicable to the year %3$d.',
                        '<i>' . $LitCal["StElizabethPortugal"]->name . '</i>',
                        '<i>' . "Independence Day" . '</i>', //can't use $LitCal["IndependenceDay"], might not exist!
                        $LITSETTINGS->YEAR
                    );
                    $LitCal["StElizabethPortugal"]->name = "[USA] " . $LitCal["StElizabethPortugal"]->name;
                }
                else{
                    if(array_key_exists("StElizabethPortugal",$LitCal)){
                        //Can't move Elizabeth of Portugal to July 5, so simply suppress to make room for Independence Day
                        $Messages[] = sprintf(
                            'USA: The optional memorial \'%1$s\' is transferred from July 4 to July 5 as per the 2011 Roman Missal issued by the USCCB, to make room for the holiday \'%2$s\', however it is superseded by a higher ranking festivity in the year %3$d.',
                            '<i>' . $LitCal["StElizabethPortugal"]->name . '</i>',
                            '<i>' . "Independence Day" . '</i>', //can't use $LitCal["IndependenceDay"], might not exist!
                            $LITSETTINGS->YEAR
                        );
                        unset($LitCal["StElizabethPortugal"]);
                    }
                }

            }

        break;
        case 'VATICAN':
        break;
    }
}

if($LITSETTINGS->DIOCESAN !== false){
    switch($LITSETTINGS->DIOCESAN){
        case "DIOCESIDIROMA":

            if(array_key_exists("StJohnPaulII",$LitCal) ){
                //In the diocese of Rome, StJohnPaulII is celebrated as a Memorial instead of an optional memorial
                $LitCal["DIOCESIDIROMA_StJohnPaulII"] = clone($LitCal["StJohnPaulII"]);
                $LitCal["DIOCESIDIROMA_StJohnPaulII"]->grade = MEMORIAL;
                $LitCal["DIOCESIDIROMA_StJohnPaulII"]->name = "[DIOCESI DI ROMA] " . $LitCal["DIOCESIDIROMA_StJohnPaulII"]->name;
            }

            if($LITSETTINGS->YEAR >= 1973){

                if ($result = $mysqli->query("SELECT * FROM LITURGY__DIOCESILAZIO_calendar_propriumdesanctis_1973 WHERE CALENDAR = 'DIOCESIDIROMA'")) {
                    while ($row = mysqli_fetch_assoc($result)) {
                        $currentFeastDate = DateTime::createFromFormat('!j-n-Y', $row['DAY'] . '-' . $row['MONTH'] . '-' . $LITSETTINGS->YEAR, new DateTimeZone('UTC'));
                        if($row["GRADE"] <= FEAST){
                            if(!in_array($currentFeastDate,$SOLEMNITIES)){
                                $LitCal["DIOCESIDIROMA_".$row["TAG"]] = new Festivity("[Diocesi di Roma] " . $row["NAME_" . $LITSETTINGS->LOCALE], $currentFeastDate, $row["COLOR"], "fixed", $row["GRADE"], $row["COMMON"], $row["DISPLAYGRADE"]);
                            } else {
                                $Messages[] = sprintf(
                                    "DIOCESIDIROMA: la %s '%s', propria del calendario della Diocesi di Roma pubblicato nel 1973 e prevista per il giorno %s, è soppressa dalla Domenica o dalla Solennità %s nell'anno %d",
                                    $row["DISPLAYGRADE"] !== "" ? $row["DISPLAYGRADE"] : _G($row["GRADE"],$LITSETTINGS->LOCALE,false),
                                    '<i>' . $row["NAME_" . $LITSETTINGS->LOCALE] . '</i>',
                                    '<b>' . trim(utf8_encode(strftime('%e %B', $currentFeastDate->format('U')))) . '</b>',
                                    '<i>' . $LitCal[array_search($currentFeastDate,$SOLEMNITIES)]->name . '</i>',
                                    $LITSETTINGS->YEAR
                                );
                            }
                        }
                        else{ //GRADE IS SOLEMNITY
                            //Let's create it in any case, so we can maybe implement a useable interface to fix this in an intuitive manner?
                            $LitCal["DIOCESIDIROMA_".$row["TAG"]] = new Festivity("[Diocesi di Roma] " . $row["NAME_" . $LITSETTINGS->LOCALE], $currentFeastDate, $row["COLOR"], "fixed", $row["GRADE"], $row["COMMON"], $row["DISPLAYGRADE"]);
                            if(in_array($currentFeastDate,$SOLEMNITIES) && $row['TAG'] != array_search($currentFeastDate,$SOLEMNITIES) ){
                                //there seems to be a coincidence with a different Solemnity on the same day!
                                //Let's attempt to move to the next open
                                $Messages[] = '<span style="padding:3px 6px; font-weight: bold; background-color: #FFC;color:Red;border-radius:6px;">IMPORTANT</span> ' . sprintf(
                                    "DIOCESIDIROMA: la Solennità '%s', propria del calendario della Diocesi di Roma pubblicato nel 1973 e prevista per il giorno %s, coincide con la Domenica o la Solennità '%s' nell'anno %d! Come ci dobbiamo comportare?",
                                    '<i>' . $row["NAME_" . $LITSETTINGS->LOCALE] . '</i>',
                                    '<b>' . trim(utf8_encode(strftime('%e %B', $currentFeastDate->format('U')))) . '</b>',
                                    '<i>' . $LitCal[array_search($currentFeastDate,$SOLEMNITIES)]->name . '</i>',
                                    $LITSETTINGS->YEAR
                                );
                            } else{
                                //This is the sure case in which we know we can create the Solemnity, but we've already done so anyways
                                // so it should show up in a calendar even in a conflicting case, to allow for solving through the interface...
                                //$LitCal["DIOCESIDIROMA_".$row["TAG"]] = new Festivity("[Diocesi di Roma] " . $row["NAME_" . $LITSETTINGS->LOCALE], $currentFeastDate, $row["COLOR"], "fixed", $row["GRADE"], $row["COMMON"], $row["DISPLAYGRADE"]);
                            }
                        }
                    }
                }
            }
        break;
        default:
            if($DiocesanData !== null){
                foreach($DiocesanData->LitCal as $key => $obj){
                    $currentFeastDate = DateTime::createFromFormat('!j-n-Y', $obj->day . '-' . $obj->month . '-' . $LITSETTINGS->YEAR, new DateTimeZone('UTC'));
                    if($obj->grade > FEAST){
                        $LitCal[$LITSETTINGS->DIOCESAN . "_" . $key] = new Festivity("[" . $index->{$LITSETTINGS->DIOCESAN}->diocese . "] " . $obj->name, $currentFeastDate, strtolower($obj->color), "fixed", $obj->grade, $obj->common);
                        if(in_array($currentFeastDate,$SOLEMNITIES) && $key != array_search($currentFeastDate,$SOLEMNITIES)){
                            //there seems to be a coincidence with a different Solemnity on the same day!
                            //should we attempt to move to the next open slot?
                            $Messages[] = '<span style="padding:3px 6px; font-weight: bold; background-color: #FFC;color:Red;border-radius:6px;">IMPORTANT</span> ' . sprintf(
                                $LITSETTINGS->DIOCESAN . ": the Solemnity '%s', proper to the calendar of the " . $index->{$LITSETTINGS->DIOCESAN}->diocese . " and usually celebrated on %s, coincides with the Sunday or Solemnity '%s' in the year %d! Does something need to be done about this?",
                                '<i>' . $obj->name . '</i>',
                                '<b>' . trim(utf8_encode(strftime('%e %B', $currentFeastDate->format('U')))) . '</b>',
                                '<i>' . $LitCal[array_search($currentFeastDate,$SOLEMNITIES)]->name . '</i>',
                                $LITSETTINGS->YEAR
                            );
                        }
                    } else if ($obj->grade <= FEAST && !in_array($currentFeastDate,$SOLEMNITIES)){
                        $LitCal[$LITSETTINGS->DIOCESAN . "_" . $key] = new Festivity("[" . $index->{$LITSETTINGS->DIOCESAN}->diocese . "] " . $obj->name, $currentFeastDate, strtolower($obj->color), "fixed", $obj->grade, $obj->common);
                    } else {
                        $Messages[] = sprintf(
                            $LITSETTINGS->DIOCESAN . ": the %s '%s', proper to the calendar of the " . $index->{$LITSETTINGS->DIOCESAN}->diocese . " and usually celebrated on %s, is suppressed by the Sunday or Solemnity %s in the year %d",
                            _G($obj->grade,$LITSETTINGS->LOCALE,false),
                            '<i>' . $obj->name . '</i>',
                            '<b>' . trim(utf8_encode(strftime('%e %B', $currentFeastDate->format('U')))) . '</b>',
                            '<i>' . $LitCal[array_search($currentFeastDate,$SOLEMNITIES)]->name . '</i>',
                            $LITSETTINGS->YEAR
                        );
                    }
                }
            }
    }
}


//LAST WE CYCLE THROUGH ALL EVENTS CREATED TO CALCULATE THE LITURGICAL YEAR, WHETHER FESTIVE (A,B,C) OR WEEKDAY (I,II)
//This property will only be set if we're dealing with a Sunday, a Solemnity, a Feast of the Lord, or a weekday
//In all other cases it is not needed because there aren't choices of liturgical texts
$SUNDAY_CYCLE = ["A", "B", "C"];
$WEEKDAY_CYCLE = ["I", "II"];
foreach($LitCal as $key => $festivity){
    //first let's deal with weekdays we calculate the weekday cycle
    if ((int)$festivity->grade === WEEKDAY && (int)$festivity->date->format('N') !== 7) {
        if ($festivity->date < $LitCal["Advent1"]->date) {
            $LitCal[$key]->liturgicalyear = __("YEAR", $LITSETTINGS->LOCALE) . " " . ($WEEKDAY_CYCLE[($LITSETTINGS->YEAR - 1) % 2]);
        } else if ($festivity->date >= $LitCal["Advent1"]->date) {
            $LitCal[$key]->liturgicalyear = __("YEAR", $LITSETTINGS->LOCALE) . " " . ($WEEKDAY_CYCLE[$LITSETTINGS->YEAR % 2]);
        }
    }
    //if we're dealing with a Sunday or a Solemnity or a Feast of the Lord, then we calculate the Sunday/Festive Cycle
    else if((int)$festivity->date->format('N') === 7 || (int)$festivity->grade > FEAST) {
        if ($festivity->date < $LitCal["Advent1"]->date) {
            $LitCal[$key]->liturgicalyear = __("YEAR", $LITSETTINGS->LOCALE) . " " . ($SUNDAY_CYCLE[($LITSETTINGS->YEAR - 1) % 3]);
        } else if ($festivity->date >= $LitCal["Advent1"]->date) {
            $LitCal[$key]->liturgicalyear = __("YEAR", $LITSETTINGS->LOCALE) . " " . ($SUNDAY_CYCLE[$LITSETTINGS->YEAR % 3]);
        }

        //Let's calculate Vigil Masses while we're at it
        //TODO: For now we are creating new events, but perhaps we should be adding metadata to the festivities themselves? hasVigilMass = true/false?
        //perhaps we can even do both for the time being...
        $VigilDate = clone($festivity->date);
        $VigilDate->sub(new DateInterval('P1D'));

        $festivityGrade = '';
        if((int)$festivity->date->format('N') === 7 && $coincidingFestivity->grade < SOLEMNITY ){
            $festivityGrade = $LITSETTINGS->LOCALE === 'LA' ? 'Die Domini' : ucfirst(utf8_encode(strftime('%A',$festivity->date->format('U'))));
        } else {
            $festivityGrade = ($festivity->grade > SOLEMNITY ? '<i>' . _G($festivity->grade,$LITSETTINGS->LOCALE,false) . '</i>' : _G($festivity->grade,$LITSETTINGS->LOCALE,false));
        }

        //conditions for which the festivity SHOULD have a vigil
        if(true === ($festivity->grade >= SOLEMNITY) || true ===  ((int)$festivity->date->format('N') === 7) ){
            //filter out cases in which the festivity should NOT have a vigil
            if(
                false === ($key === 'AllSouls')
                && false === ($key === 'AshWednesday')
                && false === ($festivity->date > $LitCal["PalmSun"]->date && $festivity->date < $LitCal["Easter"]->date)
                && false === ($festivity->date > $LitCal["Easter"]->date && $festivity->date < $LitCal["Easter2"]->date)
            ){
                $LitCal[$key . "_vigil"] = new Festivity($festivity->name . " " . __("Vigil Mass",$LITSETTINGS->LOCALE), $VigilDate, $festivity->color, $festivity->type, $festivity->grade, $festivity->common );
                $LitCal[$key]->hasVigilMass = true;
                $LitCal[$key]->hasVesperI = true;
                $LitCal[$key]->hasVesperII = true;
                $LitCal[$key . "_vigil"]->liturgicalyear = $LitCal[$key]->liturgicalyear;
                //if however the Vigil coincides with another Solemnity let's make a note of it!
                if(in_array($VigilDate,$SOLEMNITIES)){
                    $coincidingFestivity_grade = '';
                    $coincidingFestivityKey = array_search($VigilDate,$SOLEMNITIES);
                    $coincidingFestivity = $LitCal[$coincidingFestivityKey];
                    if((int)$VigilDate->format('N') === 7 && $coincidingFestivity->grade < SOLEMNITY ){
                        //it's a Sunday
                        $coincidingFestivity_grade = $LITSETTINGS->LOCALE === 'LA' ? 'Die Domini' : ucfirst(utf8_encode(strftime('%A',$VigilDate->format('U'))));
                    } else{
                        //it's a Feast of the Lord or a Solemnity
                        $coincidingFestivity_grade = ($coincidingFestivity->grade > SOLEMNITY ? '<i>' . _G($coincidingFestivity->grade,$LITSETTINGS->LOCALE,false) . '</i>' : _G($coincidingFestivity->grade,$LITSETTINGS->LOCALE,false));
                    }

                    //suppress warning messages for known situations, like the Octave of Easter
                    if($festivity->grade !== HIGHERSOLEMNITY ){
                        if( $festivity->grade < $coincidingFestivity->grade ){
                            $festivity->hasVigilMass = false;
                            $festivity->hasVesperI = false;
                            $coincidingFestivity->hasVesperII = true;
                            $Messages[] = '<span style="padding:3px 6px; font-weight: bold; background-color: #FFC;color:Red;border-radius:6px;">IMPORTANT</span> ' . sprintf(
                                __("The Vigil Mass for the %s '%s' coincides with the %s '%s' in the year %d. This last Solemnity takes precedence, therefore it will maintain Vespers II and an evening Mass, while  the first Solemnity will not have a Vigil Mass or Vespers I.", $LITSETTINGS->LOCALE),
                                $festivityGrade,
                                $festivity->name,
                                $coincidingFestivity_grade,
                                $coincidingFestivity->name,
                                $LITSETTINGS->YEAR
                            );
                        }
                        else if( $festivity->grade > $coincidingFestivity->grade ){
                            $festivity->hasVigilMass = true;
                            $festivity->hasVesperI = true;
                            $coincidingFestivity->hasVesperII = false;
                            $Messages[] = '<span style="padding:3px 6px; font-weight: bold; background-color: #FFC;color:Red;border-radius:6px;">IMPORTANT</span> ' . sprintf(
                                __("The Vigil Mass for the %s '%s' coincides with the %s '%s' in the year %d. Since the first Solemnity has precedence, it will have Vespers I and a vigil Mass, whereas the last Solemnity will not have either Vespers II or an evening Mass.", $LITSETTINGS->LOCALE),
                                $festivityGrade,
                                $festivity->name,
                                $coincidingFestivity_grade,
                                $coincidingFestivity->name,
                                $LITSETTINGS->YEAR
                            );
                        }
                        else if(in_array($key,$SOLEMNITIES_LORD_BVM) && !in_array($coincidingFestivityKey,$SOLEMNITIES_LORD_BVM) ){
                            $festivity->hasVigilMass = true;
                            $festivity->hasVesperI = true;
                            $coincidingFestivity->hasVesperII = false;
                            $Messages[] = '<span style="padding:3px 6px; font-weight: bold; background-color: #FFC;color:Red;border-radius:6px;">IMPORTANT</span> ' . sprintf(
                                __("The Vigil Mass for the %s '%s' coincides with the %s '%s' in the year %d. Since the first Solemnity has precedence, it will have Vespers I and a vigil Mass, whereas the last Solemnity will not have either Vespers II or an evening Mass.", $LITSETTINGS->LOCALE),
                                $festivityGrade,
                                $festivity->name,
                                $coincidingFestivity_grade,
                                $coincidingFestivity->name,
                                $LITSETTINGS->YEAR
                            );
                        }
                        else if(in_array($coincidingFestivityKey,$SOLEMNITIES_LORD_BVM) && !in_array($key,$SOLEMNITIES_LORD_BVM) ){
                            $coincidingFestivity->hasVesperII = true;
                            $festivity->hasVesperI = false;
                            $festivity->hasVigilMass = false;
                            unset($LitCal[$key . "_vigil"]);
                            $Messages[] = '<span style="padding:3px 6px; font-weight: bold; background-color: #FFC;color:Red;border-radius:6px;">IMPORTANT</span> ' . sprintf(
                                __("The Vigil Mass for the %s '%s' coincides with the %s '%s' in the year %d. This last Solemnity takes precedence, therefore it will maintain Vespers II and an evening Mass, while  the first Solemnity will not have a Vigil Mass or Vespers I.", $LITSETTINGS->LOCALE),
                                $festivityGrade,
                                $festivity->name,
                                $coincidingFestivity_grade,
                                $coincidingFestivity->name,
                                $LITSETTINGS->YEAR
                            );
                        } else {
                            if($LITSETTINGS->YEAR === 2022){
                                if($key === 'SacredHeart' || $key === 'Lent3' || $key === 'Assumption'){
                                    $coincidingFestivity->hasVesperII = false;
                                    $festivity->hasVesperI = true;
                                    $festivity->hasVigilMass = true;
                                    $Messages[] = '<span style="padding:3px 6px; font-weight: bold; background-color: #FFC;color:Red;border-radius:6px;">IMPORTANT</span> ' . sprintf(
                                        __("The Vigil Mass for the %s '%s' coincides with the %s '%s' in the year %d. As per %s, the first has precedence, therefore the Vigil Mass is confirmed as are I Vespers.", $LITSETTINGS->LOCALE),
                                        $festivityGrade,
                                        $festivity->name,
                                        $coincidingFestivity_grade,
                                        $coincidingFestivity->name,
                                        $LITSETTINGS->YEAR,
                                        '<a href="http://www.cultodivino.va/content/cultodivino/it/documenti/responsa-ad-dubia/2020/de-calendario-liturgico-2022.html">' . __("Decree of the Congregation for Divine Worship",$LITSETTINGS->LOCALE) . '</a>'
                                    );
                                }
                            }
                            else {
                                $Messages[] = '<span style="padding:3px 6px; font-weight: bold; background-color: #FFC;color:Red;border-radius:6px;">IMPORTANT</span> ' . sprintf(
                                    __("The Vigil Mass for the %s '%s' coincides with the %s '%s' in the year %d. We should ask the Congregation for Divine Worship what to do about this!", $LITSETTINGS->LOCALE),
                                    $festivityGrade,
                                    $festivity->name,
                                    $coincidingFestivity_grade,
                                    $coincidingFestivity->name,
                                    $LITSETTINGS->YEAR
                                );
                            }

                        }
                    } else {
                        if(
                            //false === ($key === 'AllSouls')
                            //&& false === ($key === 'AshWednesday')
                            false === ($coincidingFestivity->date > $LitCal["PalmSun"]->date && $coincidingFestivity->date < $LitCal["Easter"]->date)
                            && false === ($coincidingFestivity->date > $LitCal["Easter"]->date && $coincidingFestivity->date < $LitCal["Easter2"]->date)
                        ){

                            $Messages[] = '<span style="padding:3px 6px; font-weight: bold; background-color: #FFC;color:Red;border-radius:6px;">IMPORTANT</span> ' . sprintf(
                                __("The Vigil Mass for the %s '%s' coincides with the %s '%s' in the year %d. Since the first Solemnity has precedence, it will have Vespers I and a vigil Mass, whereas the last Solemnity will not have either Vespers II or an evening Mass.", $LITSETTINGS->LOCALE),
                                $festivityGrade,
                                $festivity->name,
                                $coincidingFestivity_grade,
                                $coincidingFestivity->name,
                                $LITSETTINGS->YEAR
                            );
                        }
                    }

                }
            } else {
                $LitCal[$key]->hasVigilMass = false;
                $LitCal[$key]->hasVesperI = false;
            }
        }


    }
}
//$LitCal variable is an associative array, who's keys are a string that identifies the event created (ex. ImmaculateConception)
//So in order to sort by date we have to be sure to maintain the association with the proper key, uasort allows us to do this
uasort($LitCal, array("Festivity", "comp_date"));

GenerateResponseToRequest($LitCal,$LITSETTINGS,$Messages,$SOLEMNITIES,$FEASTS_MEMORIALS);

function GenerateResponseToRequest($LitCal,$LITSETTINGS,$Messages,$SOLEMNITIES,$FEASTS_MEMORIALS){
    global $cacheFile;
    $SerializeableLitCal = new StdClass();
    $SerializeableLitCal->LitCal = $LitCal;
    $SerializeableLitCal->Settings = new stdClass();
    $SerializeableLitCal->Settings->YEAR = $LITSETTINGS->YEAR;
    $SerializeableLitCal->Settings->EPIPHANY = EPIPHANY;
    $SerializeableLitCal->Settings->ASCENSION = ASCENSION;
    $SerializeableLitCal->Settings->CORPUSCHRISTI = CORPUSCHRISTI;
    $SerializeableLitCal->Settings->LOCALE = $LITSETTINGS->LOCALE;
    $SerializeableLitCal->Settings->RETURNTYPE = $LITSETTINGS->RETURNTYPE;
    if($LITSETTINGS->NATIONAL !== false){
        $SerializeableLitCal->Settings->NATIONALCALENDAR = $LITSETTINGS->NATIONAL;
    }
    if($LITSETTINGS->DIOCESAN !== false){
        $SerializeableLitCal->Settings->DIOCESANCALENDAR = $LITSETTINGS->DIOCESAN;
    }
    $SerializeableLitCal->Metadata = new stdClass();
    $SerializeableLitCal->Metadata->SOLEMNITIES = $SOLEMNITIES;
    $SerializeableLitCal->Metadata->FEASTS_MEMORIALS = $FEASTS_MEMORIALS;
    $SerializeableLitCal->Metadata->VERSION = VERSION;

    $SerializeableLitCal->Messages = $Messages;

    //make sure we have an engineCache folder for the current Version
    if(realpath("engineCache/v" . str_replace(".","_",VERSION)) === false){
        mkdir("engineCache/v" . str_replace(".","_",VERSION),0755,true);
    }

    switch ($LITSETTINGS->RETURNTYPE) {
        case "JSON":
            file_put_contents("engineCache/v" . str_replace(".","_",VERSION) . "/" . $cacheFile,json_encode($SerializeableLitCal));
            header('Content-Type: application/json');
            echo json_encode($SerializeableLitCal);
            break;
        case "XML":
            //header("Content-type: text/html");
            $jsonStr = json_encode($SerializeableLitCal);
            $jsonObj = json_decode($jsonStr, true);
            $root = "<?xml version=\"1.0\" encoding=\"UTF-8\"?" . "><LiturgicalCalendar xmlns=\"https://www.bibleget.io/catholicliturgy\"/>";
            $xml = new SimpleXMLElement($root);
            convertArray2XML($xml, $jsonObj);
            file_put_contents("engineCache/v" . str_replace(".","_",VERSION) . "/" . $cacheFile,$xml->asXML());
            header('Content-Type: application/xml; charset=utf-8');
            print $xml->asXML();
            break;
            /*
            case "HTML":
                header("Content-type: text/html");
                break;
            */
        case "ICS":
            $GithubReleasesAPI = "https://api.github.com/repos/JohnRDOrazio/LiturgicalCalendar/releases/latest";
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $GithubReleasesAPI);
            curl_setopt($ch, CURLOPT_USERAGENT, 'LiturgicalCalendar');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $currentVersionForDownload = curl_exec($ch);
            if (curl_errno($ch)) {
              $error_msg = curl_error($ch);
              curl_close($ch);
              echo 'Could not get info about latest release from github: '.$error_msg;
              exit(0);
            }
            else{
              curl_close($ch);
            }
            $GitHubReleasesObj = json_decode($currentVersionForDownload);
            if(json_last_error() === JSON_ERROR_NONE){

                $publishDate = $GitHubReleasesObj->published_at;
                $ical = "BEGIN:VCALENDAR\r\n";
                $ical .= "PRODID:-//John Romano D'Orazio//Liturgical Calendar V1.0//EN\r\n";
                $ical .= "VERSION:2.0\r\n";
                $ical .= "CALSCALE:GREGORIAN\r\n";
                $ical .= "METHOD:PUBLISH\r\n";
                $ical .= "X-MS-OLK-FORCEINSPECTOROPEN:FALSE\r\n";
                $ical .= "X-WR-CALNAME:Roman Catholic Universal Liturgical Calendar " . strtoupper($LITSETTINGS->LOCALE) . "\r\n";
                $ical .= "X-WR-TIMEZONE:Europe/Vatican\r\n"; //perhaps allow this to be set through a GET or POST?
                $ical .= "X-PUBLISHED-TTL:PT1D\r\n";
                foreach($SerializeableLitCal->LitCal as $FestivityKey => $CalEvent){
                    $displayGrade = "";
                    $displayGradeHTML = "";
                    if($FestivityKey === 'AllSouls'){
                        $displayGrade = strip_tags(__("COMMEMORATION",$LITSETTINGS->LOCALE));
                        $displayGradeHTML = __("COMMEMORATION",$LITSETTINGS->LOCALE);
                    }
                    else if((int)$CalEvent->date->format('N') !==7 ){
                        if(property_exists($CalEvent,'displayGrade') && $CalEvent->displayGrade !== ""){
                            $displayGrade = $CalEvent->displayGrade;
                            $displayGradeHTML = '<B>' . $CalEvent->displayGrade . '</B>';
                        } else {
                            $displayGrade = _G($CalEvent->grade,$LITSETTINGS->LOCALE,false);
                            $displayGradeHTML = _G($CalEvent->grade,$LITSETTINGS->LOCALE,true);
                        }
                    }
                    else if((int)$CalEvent->grade > MEMORIAL ){
                        if(property_exists($CalEvent,'displayGrade') && $CalEvent->displayGrade !== ""){
                            $displayGrade = $CalEvent->displayGrade;
                            $displayGradeHTML = '<B>' . $CalEvent->displayGrade . '</B>';
                        } else {
                            $displayGrade = _G($CalEvent->grade,$LITSETTINGS->LOCALE,false);
                            $displayGradeHTML = _G($CalEvent->grade,$LITSETTINGS->LOCALE,true);
                        }
                    }

                    $description = _C($CalEvent->common,$LITSETTINGS->LOCALE);
                    $description .=  '\n' . $displayGrade;
                    $description .= $CalEvent->color != "" ? '\n' . ParseColorString($CalEvent->color,$LITSETTINGS->LOCALE,false) : "";
                    $description .= property_exists($CalEvent,'liturgicalyear') && $CalEvent->liturgicalyear !== null && $CalEvent->liturgicalyear != "" ? '\n' . $CalEvent->liturgicalyear : "";
                    $htmlDescription = "<P DIR=LTR>" . _C($CalEvent->common,$LITSETTINGS->LOCALE);
                    $htmlDescription .=  '<BR>' . $displayGradeHTML;
                    $htmlDescription .= $CalEvent->color != "" ? "<BR>" . ParseColorString($CalEvent->color,$LITSETTINGS->LOCALE,true) : "";
                    $htmlDescription .= property_exists($CalEvent,'liturgicalyear') && $CalEvent->liturgicalyear !== null && $CalEvent->liturgicalyear != "" ? '<BR>' . $CalEvent->liturgicalyear . "</P>" : "</P>";
                    $ical .= "BEGIN:VEVENT\r\n";
                    $ical .= "CLASS:PUBLIC\r\n";
                    $ical .= "DTSTART;VALUE=DATE:" . $CalEvent->date->format('Ymd') . "\r\n";// . "T" . $CalEvent->date->format('His') . "Z\r\n";
                    //$CalEvent->date->add(new DateInterval('P1D'));
                    //$ical .= "DTEND:" . $CalEvent->date->format('Ymd') . "T" . $CalEvent->date->format('His') . "Z\r\n";
                    $ical .= "DTSTAMP:" . date('Ymd') . "T" . date('His') . "Z\r\n";
                    /** The event created in the calendar is specific to this year, next year it may be different.
                     *  So UID must take into account the year
                     *  Next year's event should not cancel this year's event, they are different events
                     **/
                    $ical .= "UID:" . md5("LITCAL-" . $FestivityKey . '-' . $CalEvent->date->format('Y')) . "\r\n";
                    $ical .= "CREATED:" . str_replace(':' , '', str_replace('-', '', $publishDate)) . "\r\n";
                    $desc = "DESCRIPTION:" . str_replace(',','\,',$description);
                    $ical .= strlen($desc) > 75 ? rtrim(utf8_encode(chunk_split(utf8_decode($desc),71,"\r\n\t"))) . "\r\n" : "$desc\r\n";
                    $ical .= "LAST-MODIFIED:" . str_replace(':' , '', str_replace('-', '', $publishDate)) . "\r\n";
                    $summaryLang = ";LANGUAGE=" . strtolower($LITSETTINGS->LOCALE); //strtolower($LITSETTINGS->LOCALE) === "la" ? "" :
                    $summary = "SUMMARY".$summaryLang.":" . str_replace(',','\,',str_replace("\r\n"," ",$CalEvent->name));
                    $ical .= strlen($summary) > 75 ? rtrim(utf8_encode(chunk_split(utf8_decode($summary),75,"\r\n\t"))) . "\r\n" : $summary . "\r\n";
                    $ical .= "TRANSP:TRANSPARENT\r\n";
                    $ical .= "X-MICROSOFT-CDO-ALLDAYEVENT:TRUE\r\n";
                    $ical .= "X-MICROSOFT-DISALLOW-COUNTER:TRUE\r\n";
                    $xAltDesc = 'X-ALT-DESC;FMTTYPE=text/html:<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 3.2//EN">\n<HTML>\n<BODY>\n\n';
                    $xAltDesc .= str_replace(',','\,',$htmlDescription);
                    $xAltDesc .= '\n\n</BODY>\n</HTML>';
                    $ical .= strlen($xAltDesc) > 75 ? rtrim(utf8_encode(chunk_split(utf8_decode($xAltDesc),71,"\r\n\t"))) . "\r\n" : "$xAltDesc\r\n";
                    $ical .= "END:VEVENT\r\n";
                }
                $ical .= "END:VCALENDAR";
                file_put_contents("engineCache/v" . str_replace(".","_",VERSION) . "/" . $cacheFile,$ical);
    
                header('Content-Type: text/calendar; charset=UTF-8');
                header('Content-Disposition: attachment; filename="LiturgicalCalendar.ics"');
                echo $ical;
            }
            else{
                echo 'Could not parse info received from github about latest release: '.json_last_error_msg();
                exit(0);
            }
            break;
        default:
            header('Content-Type: application/json');
            echo json_encode($SerializeableLitCal);
            break;
    }
    die();
}

