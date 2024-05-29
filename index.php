<?php

/**
 * Liturgical Calendar API main script
 * PHP version 8.3
 * @author  John Romano D'Orazio <priest@johnromanodorazio.com>
 * @link    https://litcal.johnromanodorazio.com
 * @license Apache 2.0 License
 * @version 3.9
 * Date Created: 27 December 2017
 */

/**********************************************************************************
 *                          ABBREVIATIONS                                         *
 * CB     Cerimonial of Bishops                                                   *
 * CCL    Code of Canon Law                                                       *
 * IM     General Instruction of the Roman Missal                                 *
 * IH     General Instruction of the Liturgy of the Hours                         *
 * LH     Liturgy of the Hours                                                    *
 * LY     Universal Norms for the Liturgical Year and the Calendar ( Roman Missal ) *
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
 * -----------------------------------                                            *
 * Roman Missal [ USA ], 2011                                                     *
 * -----------------------------------                                            *
 * Messale Romano [ ITALIA ], 1983                                                *
 * Messale Romano [ ITALIA ], 2020                                                *
 * -----------------------------------                                            *
 * Romeins Missaal [ NETHERLANDS ], 1978
 *********************************************************************************/

// error_reporting(E_ALL);
// ini_set('display_errors', 1);
ini_set('date.timezone', 'Europe/Vatican');

include_once('includes/enums/AcceptHeader.php');
include_once('includes/enums/CacheDuration.php');
include_once('includes/enums/LitColor.php');
include_once('includes/enums/LitCommon.php');
include_once('includes/enums/LitFeastType.php');
include_once('includes/enums/LitGrade.php');
include_once('includes/enums/LitLocale.php');
include_once('includes/enums/LitSeason.php');
include_once('includes/enums/RequestMethod.php');
include_once('includes/enums/RequestContentType.php');
include_once('includes/enums/ReturnType.php');
include_once('includes/enums/RomanMissal.php');
include_once('includes/enums/Ascension.php');
include_once('includes/enums/Epiphany.php');
include_once('includes/enums/CorpusChristi.php');
include_once('includes/enums/CalendarType.php');

include_once('includes/APICore.php');
include_once("includes/API.php");
include_once('includes/DateTime.php');

include_once("includes/Festivity.php");
include_once("includes/FestivityCollection.php");
include_once("includes/LitSettings.php");
include_once("includes/LitFunc.php");
include_once("includes/LitMessages.php");
include_once("includes/DateTime.php");
include_once("includes/pgettext.php");

use LitCal\API;
use LitCal\enum\RequestMethod;
use LitCal\enum\RequestContentType;
use LitCal\enum\AcceptHeader;
use LitCal\enum\ReturnType;
use LitCal\enum\CacheDuration;

if (file_exists("allowedOrigins.php")) {
    include_once('allowedOrigins.php');
}

$allowedOrigins = [
    "https://johnromanodorazio.com",
    "https://www.johnromanodorazio.com",
    "https://litcal.johnromanodorazio.com",
    "https://litcal-staging.johnromanodorazio.com"
];

if (defined('ALLOWED_ORIGINS') && is_array(ALLOWED_ORIGINS)) {
    $allowedOrigins = array_merge($allowedOrigins, ALLOWED_ORIGINS);
}

$LitCalEngine = new API();
$LitCalEngine->APICore->setAllowedOrigins($allowedOrigins);
$LitCalEngine->APICore->setAllowedRequestMethods([ RequestMethod::GET, RequestMethod::POST, RequestMethod::OPTIONS ]);
$LitCalEngine->APICore->setAllowedRequestContentTypes([ RequestContentType::JSON, RequestContentType::FORMDATA ]);
$LitCalEngine->APICore->setAllowedAcceptHeaders([ AcceptHeader::JSON, AcceptHeader::XML, AcceptHeader::ICS, AcceptHeader::YML ]);
$LitCalEngine->setAllowedReturnTypes([ ReturnType::JSON, ReturnType::XML, ReturnType::ICS, ReturnType::YML ]);
$LitCalEngine->setCacheDuration(CacheDuration::MONTH);
$LitCalEngine->init();
