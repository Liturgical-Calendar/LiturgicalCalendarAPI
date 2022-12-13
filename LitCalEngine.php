<?php

/**
 * Liturgical Calendar PHP engine script
 * Author: John Romano D'Orazio
 * Email: priest@johnromanodorazio.com
 * Licensed under the Apache 2.0 License
 * Version 3.6
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
 * Roman Missal [ USA ], 2011                                                       *
 * -----------------------------------                                            *
 * Messale Romano [ ITALIA ], 1983                                                  *
 * Messale Romano [ ITALIA ], 2020                                                  *
 *                                                                                *
 *********************************************************************************/

error_reporting( E_ALL );
ini_set( 'display_errors', 1 );
ini_set( 'date.timezone', 'Europe/Vatican' );

include_once( 'includes/enums/AcceptHeader.php' );
include_once( 'includes/enums/CacheDuration.php' );
include_once( 'includes/enums/RequestMethod.php' );
include_once( 'includes/enums/RequestContentType.php' );
include_once( 'includes/enums/ReturnType.php' );

include_once( "includes/LitCalAPI.php" );

if( file_exists("allowedOrigins.php") ) {
    include_once( 'allowedOrigins.php' );
}

$allowedOrigins = [
    "https://johnromanodorazio.com",
    "https://www.johnromanodorazio.com",
    "https://litcal.johnromanodorazio.com",
    "https://litcal-staging.johnromanodorazio.com"
];

if( defined('ALLOWED_ORIGINS') && is_array( ALLOWED_ORIGINS ) ) {
    $allowedOrigins = array_merge( $allowedOrigins, ALLOWED_ORIGINS );
}

$LitCalEngine = new LitCalAPI();
$LitCalEngine->APICore->setAllowedOrigins( $allowedOrigins );
$LitCalEngine->APICore->setAllowedRequestMethods( [ RequestMethod::GET, RequestMethod::POST, RequestMethod::OPTIONS ] );
$LitCalEngine->APICore->setAllowedRequestContentTypes( [ RequestContentType::JSON, RequestContentType::FORMDATA ] );
$LitCalEngine->APICore->setAllowedAcceptHeaders( [ AcceptHeader::JSON, AcceptHeader::XML, AcceptHeader::ICS ] );
$LitCalEngine->setAllowedReturnTypes( [ ReturnType::JSON, ReturnType::XML, ReturnType::ICS ] );
$LitCalEngine->setCacheDuration( CacheDuration::MONTH );
$LitCalEngine->Init();
