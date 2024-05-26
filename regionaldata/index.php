<?php

/**
 * LitCalRegionalData
 * PHP version 8.3
 *
 * @package  LitCal
 * @author   John Romano D'Orazio <priest@johnromanodorazio.com>
 * @license  https://www.apache.org/licenses/LICENSE-2.0.txt Apache License 2.0
 * @version  GIT: 3.9
 * @link     https://litcal.johnromanodorazio.com
 */

require_once '../vendor/autoload.php';
require_once '../includes/enums/AcceptHeader.php';
require_once '../includes/enums/LitSchema.php';
require_once '../includes/enums/RequestMethod.php';
require_once '../includes/enums/RequestContentType.php';
require_once '../includes/enums/ReturnType.php';
require_once '../includes/APICore.php';
require_once '../RegionalData.php';

use LitCal\RegionalData;

if (file_exists("../allowedOrigins.php")) {
    include_once '../allowedOrigins.php';
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

$LitCalRegionalData = new RegionalData();

$LitCalRegionalData->APICore->setAllowedOrigins($allowedOrigins);
$LitCalRegionalData->APICore->setAllowedReferers(
    array_map(
        function ($el) {
            return $el . "/";
        },
        $allowedOrigins
    )
);

$LitCalRegionalData->APICore->setAllowedAcceptHeaders([AcceptHeader::JSON]);
$LitCalRegionalData->APICore->setAllowedRequestContentTypes([RequestContentType::JSON, RequestContentType::FORMDATA]);
$LitCalRegionalData->init();
