<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('date.timezone', 'Europe/Vatican');

include_once('../includes/enums/RomanMissal.php');

use LitCal\enum\RomanMissal;

$requestHeaders = getallheaders();
if (isset($requestHeaders[ "Origin" ])) {
    header("Access-Control-Allow-Origin: {$requestHeaders[ "Origin" ]}");
    header('Access-Control-Allow-Credentials: true');
} else {
    header('Access-Control-Allow-Origin: *');
}
header('Access-Control-Max-Age: 86400');    // cache for 1 day
header('Cache-Control: must-revalidate, max-age=259200');
header('Content-Type: application/json');

$widerRegionCalendars = [];
foreach (glob("../nations/*.json") as $filename) {
    $widerRegionCalendars[] = strtoupper(basename($filename, ".json"));
}

$baseNationalCalendars = [ "VATICAN" ];
$directories = array_map('basename', glob('../nations/*', GLOB_ONLYDIR));
foreach ($directories as $directory) {
    if (file_exists("../nations/$directory/$directory.json")) {
        $baseNationalCalendars[] = $directory;
    }
}

if (file_exists('../nations/index.json')) {
    $index = file_get_contents('../nations/index.json');
    if ($index !== false) {
        $diocesanCalendars  = json_decode($index, true);
        $nationalCalendars  = [];
        $diocesanGroups     = [];
        $nationalCalendarsMetadata = [];
        foreach ($diocesanCalendars as $key => $value) {
            unset($diocesanCalendars[$key]["path"]);
            if (array_key_exists("group", $value) && $value !== "") {
                if (!array_key_exists($value["group"], $diocesanGroups)) {
                    $diocesanGroups[$value["group"]] = [];
                }
                $diocesanGroups[$value["group"]][] = $key;
            }
            if (!array_key_exists($diocesanCalendars[$key]["nation"], $nationalCalendars)) {
                $nationalCalendars[$diocesanCalendars[$key]["nation"]] = [];
                $nationalCalendarsMetadata[$diocesanCalendars[$key]["nation"]] = [
                    "missals" => [],
                    "widerRegions" => [],
                    "dioceses" => [],
                    "settings" => []
                ];
            }
            $nationalCalendars[$diocesanCalendars[$key]["nation"]][] = $key;
            $nationalCalendarsMetadata[$diocesanCalendars[$key]["nation"]]["dioceses"][] = $key;
        }

        foreach ($baseNationalCalendars as $nation) {
            if (!array_key_exists($nation, $nationalCalendars)) {
                $nationalCalendars[$nation] = [];
            }
            if (file_exists("../nations/$nation/$nation.json")) {
                $nationData = json_decode(file_get_contents("../nations/$nation/$nation.json"));
                $nationalCalendarsMetadata[$nation]["missals"] = $nationData->Metadata->Missals;
                $nationalCalendarsMetadata[$nation]["widerRegions"][] = $nationData->Metadata->WiderRegion->name;
                $nationalCalendarsMetadata[$nation]["settings"] = $nationData->Settings;
            }
        }
        $filterDirResults = ['..', '.', 'index.json'];
        $dirResults = array_diff(scandir('../nations'), $filterDirResults);
        $widerRegionsFiles = array_values(array_filter($dirResults, function ($el) {
            return !is_dir('../nations/' . $el) && pathinfo('../nations/' . $el, PATHINFO_EXTENSION) === 'json';
        }));
        $widerRegionsNames = array_map(function ($el) {
            return pathinfo('../nations/' . $el, PATHINFO_FILENAME);
        }, $widerRegionsFiles);

        $response = json_encode([
            "LitCalMetadata" => [
                "NationalCalendars" => $nationalCalendars,
                "NationalCalendarsMetadata" => $nationalCalendarsMetadata,
                "DiocesanCalendars" => $diocesanCalendars,
                "DiocesanGroups"    => $diocesanGroups,
                "WiderRegions"      => $widerRegionsNames,
                "RomanMissals"      => RomanMissal::produceMetadata()
            ]
        ], JSON_PRETTY_PRINT);
        $responseHash = md5($response);
        header("Etag: \"{$responseHash}\"");
        if (!empty($_SERVER['HTTP_IF_NONE_MATCH']) && $_SERVER['HTTP_IF_NONE_MATCH'] === $responseHash) {
            header($_SERVER[ "SERVER_PROTOCOL" ] . " 304 Not Modified");
            header('Content-Length: 0');
        } else {
            echo $response;
        }
    } else {
        http_response_code(503);
    }
} else {
    http_response_code(404);
}
die();
