<?php

/**
 * Liturgical Calendar display script using AJAX and Javascript
 * Author: John Romano D'Orazio 
 * Email: priest@johnromanodorazio.com
 * Licensed under the Apache 2.0 License
 * Version 2.3
 * Date Created: 27 December 2017
 */

/**************************
 * BEGIN DISPLAY LOGIC
 * 
 *************************/
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

$Settings = json_encode(array("year" => $YEAR, "epiphany" => $EPIPHANY, "ascension" => $ASCENSION, "corpuschristi" => $CORPUSCHRISTI, "locale" => $LOCALE));

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
    "HTML presentation elaborated by JAVASCRIPT using an AJAX request to a %s" => [
        "en" => "HTML presentation elaborated by JAVASCRIPT using an AJAX request to a %s",
        "it" => "Presentazione HTML elaborata con JAVASCRIPT usando una richiesta AJAX al motore PHP %s",
        "la" => "Repraesentatio HTML elaborata cum JAVASCRIPT utendo petitionem AJAX ad machinam PHP %s"
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
    ]
];

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
    <link rel="icon" type="image/x-icon" href="../favicon.ico">
    <meta name="msapplication-TileColor" content="#ffffff" />
    <meta name="msapplication-TileImage" content="easter-egg-5-144-279148.png">
    <link rel="apple-touch-icon-precomposed" sizes="152x152" href="../easter-egg-5-152-279148.png">
    <link rel="apple-touch-icon-precomposed" sizes="144x144" href="../easter-egg-5-144-279148.png">
    <link rel="apple-touch-icon-precomposed" sizes="120x120" href="../easter-egg-5-120-279148.png">
    <link rel="apple-touch-icon-precomposed" sizes="114x114" href="../easter-egg-5-114-279148.png">
    <link rel="apple-touch-icon-precomposed" sizes="72x72" href="../easter-egg-5-72-279148.png">
    <link rel="apple-touch-icon-precomposed" href="../easter-egg-5-57-279148.png">
    <link rel="icon" href="../easter-egg-5-32-279148.png" sizes="32x32">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <style>
        .backNav {
            background-color: yellow;
            font-size: 1.1em;
            text-align: center;
            cursor: pointer;
            text-decoration: none;
            width: 95%;
            margin: 0px auto;
            border-bottom: 2px outset LightBlue;
            display: block;
            padding: 12px 24px;
            font-weight: bold;
        }

        .backNav:hover {
            background-color: gold;
            color: DarkBlue;
        }

        #LitCalTable {
            width: 75%;
            margin: 30px auto;
            border: 1px solid Blue;
            border-radius: 6px;
            padding: 10px;
            background: LightBlue;
        }

        #LitCalTable td {
            padding: 8px 6px;
        }

        td.rotate {
            width: 1.5em;
            white-space: nowrap;
            text-align: center;
            vertical-align: middle;
        }

        td.rotate div {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            font-size: 1.8em;
            font-weight: bold;
            writing-mode: vertical-rl;
            transform: rotate(180.0deg);
        }

        .dateEntry {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            font-size: .7em;
            font-weight: bold;
        }

        .lds-roller {
            display: inline-block;
            position: absolute;
            width: 80px;
            height: 80px;
            top: 50%;
            left: 50%;
            margin-left: -40px;
            margin-right: -40px;
        }

        .lds-roller div {
            animation: lds-roller 1.2s cubic-bezier(0.5, 0, 0.5, 1) infinite;
            transform-origin: 40px 40px;
        }

        .lds-roller div:after {
            content: " ";
            display: block;
            position: absolute;
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: #fff;
            margin: -4px 0 0 -4px;
        }

        .lds-roller div:nth-child(1) {
            animation-delay: -0.036s;
        }

        .lds-roller div:nth-child(1):after {
            top: 63px;
            left: 63px;
        }

        .lds-roller div:nth-child(2) {
            animation-delay: -0.072s;
        }

        .lds-roller div:nth-child(2):after {
            top: 68px;
            left: 56px;
        }

        .lds-roller div:nth-child(3) {
            animation-delay: -0.108s;
        }

        .lds-roller div:nth-child(3):after {
            top: 71px;
            left: 48px;
        }

        .lds-roller div:nth-child(4) {
            animation-delay: -0.144s;
        }

        .lds-roller div:nth-child(4):after {
            top: 72px;
            left: 40px;
        }

        .lds-roller div:nth-child(5) {
            animation-delay: -0.18s;
        }

        .lds-roller div:nth-child(5):after {
            top: 71px;
            left: 32px;
        }

        .lds-roller div:nth-child(6) {
            animation-delay: -0.216s;
        }

        .lds-roller div:nth-child(6):after {
            top: 68px;
            left: 24px;
        }

        .lds-roller div:nth-child(7) {
            animation-delay: -0.252s;
        }

        .lds-roller div:nth-child(7):after {
            top: 63px;
            left: 17px;
        }

        .lds-roller div:nth-child(8) {
            animation-delay: -0.288s;
        }

        .lds-roller div:nth-child(8):after {
            top: 56px;
            left: 12px;
        }

        @keyframes lds-roller {
            0% {
                transform: rotate(0deg);
            }

            100% {
                transform: rotate(360deg);
            }
        }

        #spinnerWrapper {
            background-color: Black;
            opacity: 80%;
            position: absolute;
            left: 0px;
            top: 0px;
            width: 100%;
            height: 100%;
        }

        #calSettings {
            width: 70%;
            margin: 0px auto;
            border-collapse: collapse;
        }

        #calSettings tr td {
            padding: 12px;
        }

        #calSettings tr:first-child {
            border-bottom: 1px groove White;
        }

        #calSettings tr td:first-child {
            text-align: right;
            padding-right: 18px;
        }
        #calSettings tr td:nth-child(3) {
            border-left: 1px groove white;
            padding-left: 18px;
            text-align: right;
        }

        #openSettings {
            position: absolute;
            display: block;
            top: 70px;
            right: 24px;
            color: steelblue;
            cursor: pointer;
            background-color: palegoldenrod;
            padding: 6px;
            border: 1px groove white;
        }

        #openSettings:hover {
            color: darkslategray;
            background-color: lightyellow;
        }
    </style>
    <link rel="stylesheet" href="https://ajax.googleapis.com/ajax/libs/jqueryui/1.12.1/themes/smoothness/jquery-ui.css">
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.2.1/jquery.min.js"></script>
    <script src="https://ajax.googleapis.com/ajax/libs/jqueryui/1.12.1/jquery-ui.min.js"></script>
    <script type="text/javascript">
        let $Settings = JSON.parse('<?php echo $Settings; ?>');
        $Settings.returntype = "JSON";
        let $LOCALE = $Settings.locale;
        let IntlDTOptions = {
            weekday: 'short',
            year: 'numeric',
            month: 'long',
            day: 'numeric'
        };

        let IntlMonthFmt = {
            month: 'long'
        };

        let countSameDayEvents = function($currentKeyIndex, $EventsArray, $cc) {
            let $Keys = Object.keys($EventsArray);
            let $currentFestivity = $EventsArray[$Keys[$currentKeyIndex]];
            //console.log("currentFestivity: " + $currentFestivity.name + " | " + $currentFestivity.date);
            if ($currentKeyIndex < $Keys.length - 1) {
                let $nextFestivity = $EventsArray[$Keys[$currentKeyIndex + 1]];
                //console.log("nextFestivity: " + $nextFestivity.name + " | " + $nextFestivity.date);
                if ($nextFestivity.date.getTime() === $currentFestivity.date.getTime()) {
                    //console.log("We have an occurrence!");
                    $cc.count++;
                    countSameDayEvents($currentKeyIndex + 1, $EventsArray, $cc);
                }
            }
        }


        let countSameMonthEvents = function($currentKeyIndex, $EventsArray, $cm) {
            let $Keys = Object.keys($EventsArray);
            let $currentFestivity = $EventsArray[$Keys[$currentKeyIndex]];
            if ($currentKeyIndex < $Keys.length - 1) {
                let $nextFestivity = $EventsArray[$Keys[$currentKeyIndex + 1]];
                if ($nextFestivity.date.getMonth() == $currentFestivity.date.getMonth()) {
                    $cm.count++;
                    countSameMonthEvents($currentKeyIndex + 1, $EventsArray, $cm);
                }
            }
        }


        let ordSuffix = function(ord) {
            var ord_suffix = ''; //st, nd, rd, th
            if (ord === 1 || (ord % 10 === 1 && ord != 11)) {
                ord_suffix = 'st';
            } else if (ord === 2 || (ord % 10 === 2 && ord != 12)) {
                ord_suffix = 'nd';
            } else if (ord === 3 || (ord % 10 === 3 && ord != 13)) {
                ord_suffix = 'rd';
            } else {
                ord_suffix = 'th';
            }
            return ord_suffix;
        }

        const $GRADE = ["", "COMMEMORATION", "OPTIONAL MEMORIAL", "MEMORIAL", "FEAST", "FEAST OF THE LORD", "SOLEMNITY", "HIGHER RANKING SOLEMNITY"];

        let genLitCal = function($Settings) {

            $.ajax({
                method: 'POST',
                data: $Settings,
                url: '../LitCalEngine.php',
                success: function(LitCalData) {
                    console.log(LitCalData);

                    let strHTML = '';
                    let $YEAR = 0;
                    if (LitCalData.hasOwnProperty("Settings")) {
                        $YEAR = LitCalData.Settings.YEAR;
                    }
                    if (LitCalData.hasOwnProperty("LitCal")) {
                        let $LitCal = LitCalData.LitCal;

                        for (const key in $LitCal) {
                            if ($LitCal.hasOwnProperty(key)) {
                                $LitCal[key].date = new Date($LitCal[key].date * 1000); //transform PHP timestamp to javascript date object
                            }
                        }

                        let $dayCnt = 0;
                        const $highContrast = ['purple', 'red', 'green'];
                        let $LitCalKeys = Object.keys($LitCal);

                        let $currentMonth = -1;
                        let $newMonth = false;
                        let $cm = {
                            count: 0
                        };
                        let $cc = {
                            count: 0
                        };
                        for (let $keyindex = 0; $keyindex < $LitCalKeys.length; $keyindex++) {
                            $dayCnt++;
                            let $keyname = $LitCalKeys[$keyindex];
                            let $festivity = $LitCal[$keyname];
                            let dy = ($festivity.date.getDay() === 0 ? 7 : $festivity.date.getDay()); // get the day of the week
                            /*
                            //LET'S CALCULATE THE LITURGICAL YEAR CYCLE
                            let $currentCycle = '';

                            //if we're dealing with a Sunday or a Solemnity or a Feast of the Lord, then we calculate the Sunday/Festive Cycle
                            if (dy === 7 || $festivity.grade > 4) {
                                if ($festivity.date.getTime() < $LitCal["Advent1"].date.getTime()) { //$festivity.date.getTime() >= Date.parse('1-1-'+$YEAR) && 
                                    $currentCycle = "YEAR " + ($SUNDAY_CYCLE[($YEAR - 1) % 3]);
                                } else if ($festivity.date.getTime() >= $LitCal["Advent1"].date.getTime()) { // && $festivity.date.getTime() <= Date.parse('31-12-'+$YEAR)
                                    $currentCycle = "YEAR " + ($SUNDAY_CYCLE[$YEAR % 3]);
                                }
                            }
                            //otherwise we calculate the weekday cycle
                            else {
                                if ($festivity.date.getTime() < $LitCal["Advent1"].date.getTime()) { //$festivity.date.getTime() >= Date.parse('1-1-'+$YEAR) && 
                                    $currentCycle = "YEAR " + ($WEEKDAY_CYCLE[($YEAR - 1) % 2]);
                                } else if ($festivity.date.getTime() >= $LitCal["Advent1"].date.getTime()) { // && $festivity.date.getTime() <= Date.parse('31-12-'+$YEAR)
                                    $currentCycle = "YEAR " + ($WEEKDAY_CYCLE[$YEAR % 2]);
                                }
                            }
                            */

                            //If we are at the start of a new month, count how many events we have in that same month, so we can display the Month table cell
                            if ($festivity.date.getMonth() !== $currentMonth) {
                                $newMonth = true;
                                $currentMonth = $festivity.date.getMonth();
                                $cm.count = 0;
                                countSameMonthEvents($keyindex, $LitCal, $cm);
                            }

                            //Let's check if we have more than one event on the same day, such as optional memorials...
                            $cc.count = 0;
                            countSameDayEvents($keyindex, $LitCal, $cc);
                            //console.log($festivity.name);
                            //console.log($cc);
                            if ($cc.count > 0) {
                                console.log("we have an occurrence of multiple festivities on same day");
                                for (let $ev = 0; $ev <= $cc.count; $ev++) {
                                    $keyname = $LitCalKeys[$keyindex];
                                    $festivity = $LitCal[$keyname];
                                    // LET'S DO SOME MORE MANIPULATION ON THE FESTIVITY->COMMON STRINGS AND THE FESTIVITY->COLOR...
                                    if ($festivity.common !== "" && $festivity.common !== "Proper") {
                                        $commons = $festivity.common.split("|");
                                        $commons = $commons.map(function($txt) {
                                            let $common = $txt.split(":");
                                            let $commonGeneral = __($common[0], $LOCALE);
                                            let $commonSpecific = (typeof $common[1] !== 'undefined' && $common[1] != "") ? __($common[1], $LOCALE) : "";
                                            let $commonKey = '';
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
                                            return __("From the Common", $LOCALE) + " " + __($commonKey, $LOCALE) + " " + $commonGeneral + ($commonSpecific != "" ? ": " + $commonSpecific : "");
                                        });
                                        $festivity.common = $commons.join("; " + __("or", $LOCALE) + " ");
                                    } else if ($festivity.common == "Proper") {
                                        $festivity.common = __($festivity.common, $LOCALE);
                                    }
                                    $festivity.color = $festivity.color.split("|")[0];

                                    //check which liturgical season we are in, to use the right color for that season...
                                    let $color = "green";
                                    if (($festivity.date.getTime() > $LitCal["Advent1"].date.getTime() && $festivity.date.getTime() < $LitCal["Christmas"].date.getTime()) || ($festivity.date.getTime() > $LitCal["AshWednesday"].date.getTime() && $festivity.date.getTime() < $LitCal["Easter"].date.getTime())) {
                                        $color = "purple";
                                    } else if ($festivity.date.getTime() > $LitCal["Easter"].date.getTime() && $festivity.date.getTime() < $LitCal["Pentecost"].date.getTime()) {
                                        $color = "white";
                                    } else if ($festivity.date.getTime() > $LitCal["Christmas"].date.getTime() || $festivity.date.getTime() < $LitCal["BaptismLord"].date.getTime()) {
                                        $color = "white";
                                    }


                                    strHTML += '<tr style="background-color:' + $color + ';' + ($highContrast.indexOf($color) != -1 ? 'color:white;' : '') + '">';
                                    if ($newMonth) {
                                        let $monthRwsp = $cm.count + 1;
                                        strHTML += '<td class="rotate" rowspan = "' + $monthRwsp + '"><div>' + ($LOCALE === 'LA' ? $months[$festivity.date.getMonth()].toUpperCase() : new Intl.DateTimeFormat($LOCALE.toLowerCase(), IntlMonthFmt).format($festivity.date).toUpperCase()) + '</div></td>';
                                        $newMonth = false;
                                    }

                                    if ($ev == 0) {
                                        let $rwsp = $cc.count + 1;
                                        let $festivity_date_str = $LOCALE == 'LA' ? getLatinDateStr($festivity.date) : new Intl.DateTimeFormat($LOCALE.toLowerCase(), IntlDTOptions).format($festivity.date);

                                        strHTML += '<td rowspan="' + $rwsp + '" class="dateEntry">' + $festivity_date_str + '</td>';
                                    }
                                    $currentCycle = ($festivity.hasOwnProperty("liturgicalyear") ? ' (' + $festivity.liturgicalyear + ')' : "");
                                    strHTML += '<td>' + $festivity.name + $currentCycle + ' - <i>' + __($festivity.color, $LOCALE) + '</i><br /><i>' + $festivity.common + '</i></td>';
                                    strHTML += '<td>' + $GRADE[$festivity.grade] + '</td>';
                                    strHTML += '</tr>';
                                    $keyindex++;
                                }
                                $keyindex--;

                            } else {
                                // LET'S DO SOME MORE MANIPULATION ON THE FESTIVITY->COMMON STRINGS AND THE FESTIVITY->COLOR...
                                if ($festivity.common !== "" && $festivity.common !== "Proper") {
                                    $commons = $festivity.common.split("|");
                                    $commons = $commons.map(function($txt) {
                                        let $common = $txt.split(":");
                                        let $commonGeneral = __($common[0], $LOCALE);
                                        let $commonSpecific = (typeof $common[1] !== 'undefined' && $common[1] != "") ? __($common[1], $LOCALE) : "";
                                        let $commonKey = '';
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
                                        return __("From the Common", $LOCALE) + " " + __($commonKey, $LOCALE) + " " + $commonGeneral + ($commonSpecific != "" ? ": " + $commonSpecific : "");
                                    });
                                    $festivity.common = $commons.join("; " + __("or", $LOCALE) + " ");
                                } else if ($festivity.common == "Proper") {
                                    $festivity.common = __($festivity.common, $LOCALE);
                                }
                                $festivity.color = $festivity.color.split("|")[0];
                                strHTML += '<tr style="background-color:' + $festivity.color + ';' + ($highContrast.indexOf($festivity.color) != -1 ? 'color:white;' : '') + '">';
                                if ($newMonth) {
                                    let $monthRwsp = $cm.count + 1;
                                    strHTML += '<td class="rotate" rowspan = "' + $monthRwsp + '"><div>' + ($LOCALE === 'LA' ? $months[$festivity.date.getMonth()].toUpperCase() : new Intl.DateTimeFormat($LOCALE.toLowerCase(), IntlMonthFmt).format($festivity.date).toUpperCase()) + '</div></td>';
                                    $newMonth = false;
                                }

                                let $festivity_date_str = $LOCALE == 'LA' ? getLatinDateStr($festivity.date) : new Intl.DateTimeFormat($LOCALE.toLowerCase(), IntlDTOptions).format($festivity.date);
                                // $festivity_date_str += ', ';
                                // $festivity_date_str += new Intl.DateTimeFormat($LOCALE.toLowerCase(), IntlDTOptions).format($festivity.date);
                                // $festivity_date_str += ' ';
                                // $festivity_date_str += $festivity.date.getDate();
                                // $festivity_date_str += ordSuffix($festivity.date.getDate());
                                // $festivity_date_str += ', ';
                                // $festivity_date_str += $festivity.date.getFullYear();

                                strHTML += '<td class="dateEntry">' + $festivity_date_str + '</td>';
                                $currentCycle = ($festivity.hasOwnProperty("liturgicalyear") ? ' (' + $festivity.liturgicalyear + ')' : "");
                                strHTML += '<td>' + $festivity.name + $currentCycle + ' - <i>' + __($festivity.color, $LOCALE) + '</i><br /><i>' + $festivity.common + '</i></td>';
                                strHTML += '<td>' + $GRADE[$festivity.grade] + '</td>';
                                strHTML += '</tr>';
                            }

                        }
                        $('#LitCalTable tbody').append(strHTML);
                        $('#dayCnt').text($dayCnt);
                        $('#spinnerWrapper').fadeOut('slow');
                    }

                }
            });
        }

        let $messages = {
                "From the Common": {
                    "en": "From the Common",
                    "it": "Dal Comune",
                    "la": "De Communi"
                },
                "of (SING_MASC)": {
                    "en": "of",
                    "it": "del",
                    "la": ""
                },
                "of (SING_FEMM)": {
                    "en": "of the",
                    "it": "della",
                    "la": ""
                },
                "of (PLUR_MASC)": {
                    "en": "of",
                    "it": "dei",
                    "la": ""
                },
                "of (PLUR_MASC_ALT)": {
                    "en": "of",
                    "it": "degli",
                    "la": ""
                },
                "of (PLUR_FEMM)": {
                    "en": "of",
                    "it": "delle",
                    "la": ""
                },
                /*translators: in reference to the Common of the Blessed Virgin Mary */
                "Blessed Virgin Mary": {
                    "en": "Blessed Virgin Mary",
                    "it": "Beata Vergine Maria",
                    "la": "Beatæ Virginis Mariæ"
                },
                "Martyrs": {
                    "en": "Martyrs",
                    "it": "Martiri",
                    "la": "Martyrum"
                },
                "Pastors": {
                    "en": "Pastors",
                    "it": "Pastori",
                    "la": "Pastorum"
                },
                "Doctors": {
                    "en": "Doctors",
                    "it": "Dottori della Chiesa",
                    "la": "Doctorum Ecclesiae"
                },
                "Virgins": {
                    "en": "Virgins",
                    "it": "Vergini",
                    "la": "Virginum"
                },
                "Holy Men and Women": {
                    "en": "Holy Men and Women",
                    "it": "Santi e delle Sante",
                    "la": "Sanctorum et Sanctarum"
                },
                "For One Martyr": {
                    "en": "For One Martyr",
                    "it": "Per un martire",
                    "la": "Pro uno martyre"
                },
                "For Several Martyrs": {
                    "en": "For Several Martyrs",
                    "it": "Per più martiri",
                    "la": "Pro pluribus martyribus"
                },
                "For Missionary Martyrs": {
                    "en": "For Missionary Martyrs",
                    "it": "Per i martiri missionari",
                    "la": "Pro missionariis martyribus"
                },
                "For a Virgin Martyr": {
                    "en": "For a Virgin Martyr",
                    "it": "Per una vergine martire",
                    "la": "Pro virgine martyre"
                },
                "For Several Pastors": {
                    "en": "For Several Pastors",
                    "it": "Per i pastori",
                    "la": "Pro Pastoribus"
                },
                "For a Pope": {
                    "en": "For a Pope",
                    "it": "Per i papi",
                    "la": "Pro Papa"
                },
                "For a Bishop": {
                    "en": "For a Bishop",
                    "it": "Per i vescovi",
                    "la": "Pro Episcopo"
                },
                "For One Pastor": {
                    "en": "For One Pastor",
                    "it": "Per un Pastore",
                    "la": "Pro Pastoribus"
                },
                "For Missionaries": {
                    "en": "For Missionaries",
                    "it": "Per i missionari",
                    "la": "Pro missionariis"
                },
                "For One Virgin": {
                    "en": "For One Virgin",
                    "it": "Per una vergine",
                    "la": "Pro una virgine"
                },
                "For Several Virgins": {
                    "en": "For Several Virgins",
                    "it": "Per più vergini",
                    "la": "Pro pluribus virginibus"
                },
                "For Religious": {
                    "en": "For Religious",
                    "it": "Per i religiosi",
                    "la": "Pro Religiosis"
                },
                "For Those Who Practiced Works of Mercy": {
                    "en": "For Those Who Practiced Works of Mercy",
                    "it": "Per gli operatori di misericordia",
                    "la": "Pro iis qui opera Misericordiæ Exercuerunt"
                },
                "For an Abbot": {
                    "en": "For an Abbot",
                    "it": "Per un abate",
                    "la": "Pro abbate"
                },
                "For a Monk": {
                    "en": "For a Monk",
                    "it": "Per un monaco",
                    "la": "Pro monacho"
                },
                "For a Nun": {
                    "en": "For a Nun",
                    "it": "Per i religiosi",
                    "la": "Pro moniali"
                },
                "For Educators": {
                    "en": "For Educators",
                    "it": "Per gli educatori",
                    "la": "Pro Educatoribus"
                },
                "For Holy Women": {
                    "en": "For Holy Women",
                    "it": "Per le sante",
                    "la": "Pro Sanctis Mulieribus"
                },
                "For One Saint": {
                    "en": "For One Saint",
                    "it": "Per un Santo",
                    "la": "Pro uno Sancto"
                },
                "or": {
                    "en": "or",
                    "it": "oppure",
                    "la": "vel"
                },
                "Proper": {
                    "en": "Proper",
                    "it": "Proprio",
                    "la": "Proprium"
                },
                "green": {
                    "en": "green",
                    "it": "verde",
                    "la": "viridis"
                },
                "purple": {
                    "en": "purple",
                    "it": "viola",
                    "la": "purpura"
                },
                "white": {
                    "en": "white",
                    "it": "bianco",
                    "la": "albus"
                },
                "red": {
                    "en": "red",
                    "it": "rosso",
                    "la": "ruber"
                },
                "pink": {
                    "en": "pink",
                    "it": "rosa",
                    "la": "rosea"
                }
            },
            $daysOfTheWeek = [
                "dies Solis",
                "dies Lunae",
                "dies Martis",
                "dies Mercurii",
                "dies Iovis",
                "dies Veneris",
                "dies Saturni"
            ],
            $months = [
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
            ],
            __ = function($key, $locale) {
                $lcl = $locale.toLowerCase();
                if ($messages !== null && typeof $messages == 'object') {
                    if ($messages.hasOwnProperty($key) && typeof $messages[$key] == 'object') {
                        if ($messages[$key].hasOwnProperty($lcl)) {
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
            },
            getLatinDateStr = function($date) {
                $festivity_date_str = $daysOfTheWeek[$date.getDay()];
                $festivity_date_str += ', ';
                $festivity_date_str += $date.getDate();
                $festivity_date_str += ' ';
                $festivity_date_str += $months[$date.getMonth()];
                $festivity_date_str += ' ';
                $festivity_date_str += $date.getFullYear();
                // $festivity_date_str += new Intl.DateTimeFormat($LOCALE.toLowerCase(), IntlDTOptions).format($festivity.date);
                // $festivity_date_str += ordSuffix($festivity.date.getDate());
                // $festivity_date_str += ', ';
                return $festivity_date_str;
            };

        $(document).ready(function() {
            $('#settingsWrapper').dialog({
                title: '<?php echo __('Customize options for generating the Roman Calendar', $LOCALE) ?>',
                modal: true,
                width: '80%',
                show: {
                    effect: 'fade',
                    duration: 500
                },
                hide: {
                    effect: 'fade',
                    duration: 500
                },
                //autoOpen: false
            });
            $(document).on('click', '#openSettings', function() {
                $('#settingsWrapper').dialog("open");
            });
            $('#generateLitCal').button();
            $('#calSettingsForm').on("submit", function( event ) {
                event.preventDefault();
                let $Settings = $(this).serialize();
                $Settings.returntype = 'JSON';
                $('#settingsWrapper').dialog("close");
                genLitCal($Settings);
            });

            if($('#nationalpreset').find('select').val() !== "ITALY"){
                $('#diocesanpreset').find('select').prop('disabled',true);
            }

            $(document).on('change','#nationalpreset select',function(){
                if($(this).val() === "ITALY" ){
                    $('#diocesanpreset').find('select').prop('disabled',false).val("DIOCESIROMA");
                }
                else{
                    $('#diocesanpreset').find('select').prop('disabled',true).val("");
                }
            });

        });
    </script>
</head>

<body>
    <div id="spinnerWrapper">
        <div class="lds-roller">
            <div></div>
            <div></div>
            <div></div>
            <div></div>
            <div></div>
            <div></div>
            <div></div>
            <div></div>
        </div>
    </div>
    <div><a class="backNav" href="/LiturgicalCalendar">↩ Go back ↩</a></div>
    <span class="material-icons" id="openSettings">settings</span>
    <?php

    echo '<h1 style="text-align:center;">' . __('Liturgical Calendar Calculation for a Given Year', $LOCALE) . ' (' . $YEAR . ')</h1>';
    echo '<h2 style="text-align:center;">' . sprintf(__('HTML presentation elaborated by JAVASCRIPT using an AJAX request to a %s', $LOCALE), '<a href="../LitCalEngine.php">PHP engine</a>') . '</h2>';

    if ($YEAR > 9999) {
        $YEAR = 9999;
    }
    if ($YEAR < 1969) {
        echo '<div style="text-align:center;border:3px ridge Green;background-color:LightBlue;width:75%;margin:10px auto;padding:10px;">';
        echo 'You are viewing a year prior to 1969: the calendar produced will not reflect the calendar used that year, but rather how the current Roman calendar would have been applied in that year.';
        echo '</div>';
    }

    echo '<div id="settingsWrapper">';
    echo '<form id="calSettingsForm">';
    echo '<table id="calSettings">';
    echo '<tr><td colspan="2"><label>' . __('YEAR', $LOCALE) . ': </td><td colspan="2"><input type="number" name="year" id="year" min="1969" max="9999" value="' . $YEAR . '" /></label></td></tr>';
    echo '<tr><td><label>LOCALE: </td><td><select name="locale" id="locale"><option value="EN" ' . ($LOCALE === "EN" ? " SELECTED" : "") . '>ENGLISH</option><option value="IT" ' . ($LOCALE === "IT" ? " SELECTED" : "") . '>ITALIANO</option><option value="LA" ' . ($LOCALE === "LA" ? " SELECTED" : "") . '>LATINO</option></select></label></td><td>NATIONAL PRESET: </td><td id="nationalpreset"><select><option value="VATICAN">Vatican</option><option value="ITALY">Italy</option><option value="USA">USA</option></select></td></tr>';
    echo '<tr><td><label>' . __('EPIPHANY', $LOCALE) . ': </td><td><select name="epiphany" id="epiphany"><option value="JAN6" ' . (EPIPHANY === "JAN6" ? " SELECTED" : "") . '>January 6</option><option value="SUNDAY_JAN2_JAN8" ' . (EPIPHANY === "SUNDAY_JAN2_JAN8" ? " SELECTED" : "") . '>Sunday Jan 2↔Jan 8</option></select></label></td><td>DIOCESAN PRESET: </td><td id="diocesanpreset"><select><option value=""></option><option value="DIOCESIROMA">Diocesi di Roma</option><option value="DIOCESILAZIO">Le diocesi del Lazio</option></select></td></tr>';
    echo '<tr><td><label>' . __('ASCENSION', $LOCALE) . ': </td><td><select name="ascension" id="ascension"><option value="THURSDAY" ' . (ASCENSION === "THURSDAY" ? " SELECTED" : "") . '>Thursday</option><option value="SUNDAY" ' . (ASCENSION === "SUNDAY" ? " SELECTED" : "") . '>Sunday</option></select></label></td><td></td><td></td></tr>';
    echo '<tr><td><label>CORPUS CHRISTI: </td><td><select name="corpuschristi" id="corpuschristi"><option value="THURSDAY" ' . (CORPUSCHRISTI === "THURSDAY" ? " SELECTED" : "") . '>Thursday</option><option value="SUNDAY" ' . (CORPUSCHRISTI === "SUNDAY" ? " SELECTED" : "") . '>Sunday</option></select></label></td><td></td><td></td></tr>';
    echo '<tr>';
    echo '<td colspan="4" style="text-align:center;"><input type="submit" id="generateLitCal" value="Generate Calendar" /></td>';
    echo '</tr></table>';
    echo '</form>';
    echo '</div>';

    echo '<div style="text-align:center;border:2px groove White;border-radius:6px;width:60%;margin:0px auto;padding-bottom:6px;">';

    echo '<h3>' . __('Configurations being used to generate this calendar:', $LOCALE) . '</h3>';
    echo '<span>' . __('YEAR', $LOCALE) . ' = ' . $YEAR . ', ' . __('EPIPHANY', $LOCALE) . ' = ' . EPIPHANY . ', ' . __('ASCENSION', $LOCALE) . ' = ' . ASCENSION . ', CORPUS CHRISTI = ' . CORPUSCHRISTI . ', LOCALE = ' . $LOCALE . '</span>';

    echo '</div>';

    echo '<table id="LitCalTable">';
    echo '<thead><tr><th>' . __("Month", $LOCALE) . '</th><th>' . __("Date in Gregorian Calendar", $LOCALE) . '</th><th>' . __("General Roman Calendar Festivity", $LOCALE) . '</th><th>' . __("Grade of the Festivity", $LOCALE) . '</th></tr></thead>';
    echo '<tbody>';

    echo '</tbody></table>';

    //echo '<div style="text-align:center;border:3px ridge Green;background-color:LightBlue;width:75%;margin:10px auto;padding:10px;">'.$dayCnt.' event days created</div>';
    echo '<div style="text-align:center;border:3px ridge Green;background-color:LightBlue;width:75%;margin:10px auto;padding:10px;"><span id="dayCnt"></span> event days created</div>';


    ?>
</body>