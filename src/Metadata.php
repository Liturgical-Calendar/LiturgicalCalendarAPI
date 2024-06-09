<?php

namespace Johnrdorazio\LitCal;

use Johnrdorazio\LitCal\Enum\RomanMissal;

class Metadata
{
    //private static array $widerRegionCalendars      = [];
    private static array $baseNationalCalendars     = [ "VATICAN" ];
    private static array $nationalCalendars         = [];
    private static array $diocesanCalendars         = [];
    private static array $diocesanGroups            = [];
    private static array $nationalCalendarsMetadata = [];
    private static array $widerRegionsNames         = [];

    private static function retrieveCalendars()
    {
        /*foreach (glob("nations/*.json") as $filename) {
            Metadata::$widerRegionCalendars[] = strtoupper(basename($filename, ".json"));
        } */
        $directories = array_map('basename', glob('nations/*', GLOB_ONLYDIR));
        foreach ($directories as $directory) {
            if (file_exists("nations/$directory/$directory.json")) {
                Metadata::$baseNationalCalendars[] = $directory;
            }
        }
    }

    private static function buildIndex(): int
    {
        if (file_exists('nations/index.json')) {
            $index = file_get_contents('nations/index.json');
            if ($index !== false) {
                Metadata::$diocesanCalendars  = json_decode($index, true);
                foreach (Metadata::$diocesanCalendars as $key => $value) {
                    unset(Metadata::$diocesanCalendars[$key]["path"]);

                    // Build diocesan groups
                    if (array_key_exists("group", $value) && $value !== "") {
                        if (!array_key_exists($value["group"], Metadata::$diocesanGroups)) {
                            Metadata::$diocesanGroups[$value["group"]] = [];
                        }
                        Metadata::$diocesanGroups[$value["group"]][] = $key;
                    }

                    // Build national calendars and national calendars metadata
                    if (!array_key_exists(Metadata::$diocesanCalendars[$key]["nation"], Metadata::$nationalCalendars)) {
                        Metadata::$nationalCalendars[Metadata::$diocesanCalendars[$key]["nation"]] = [];
                        Metadata::$nationalCalendarsMetadata[Metadata::$diocesanCalendars[$key]["nation"]] = [
                            "missals"       => [],
                            "widerRegions"  => [],
                            "dioceses"      => [],
                            "settings"      => []
                        ];
                    }
                    Metadata::$nationalCalendars[Metadata::$diocesanCalendars[$key]["nation"]][] = $key;
                    Metadata::$nationalCalendarsMetadata[Metadata::$diocesanCalendars[$key]["nation"]]["dioceses"][] = $key;
                }

                foreach (Metadata::$baseNationalCalendars as $nation) {
                    if (!array_key_exists($nation, Metadata::$nationalCalendars)) {
                        Metadata::$nationalCalendars[$nation] = [];
                    }
                    if (file_exists("nations/$nation/$nation.json")) {
                        $nationData = json_decode(file_get_contents("nations/$nation/$nation.json"));
                        Metadata::$nationalCalendarsMetadata[$nation]["missals"] = $nationData->Metadata->Missals;
                        Metadata::$nationalCalendarsMetadata[$nation]["widerRegions"][] = $nationData->Metadata->WiderRegion->name;
                        Metadata::$nationalCalendarsMetadata[$nation]["settings"] = $nationData->Settings;
                    }
                }
                $filterDirResults = ['..', '.', 'index.json'];
                $dirResults = array_diff(scandir('nations'), $filterDirResults);
                $widerRegionsFiles = array_values(array_filter($dirResults, function ($el) {
                    return !is_dir('nations/' . $el) && pathinfo('nations/' . $el, PATHINFO_EXTENSION) === 'json';
                }));
                Metadata::$widerRegionsNames = array_map(function ($el) {
                    return pathinfo('nations/' . $el, PATHINFO_FILENAME);
                }, $widerRegionsFiles);
                return 200;
            } else {
                return 503;
            }
        } else {
            return 404;
        }
    }

    public static function response()
    {
        $response = json_encode([
            "LitCalMetadata" => [
                "NationalCalendars"         => Metadata::$nationalCalendars,
                "NationalCalendarsMetadata" => Metadata::$nationalCalendarsMetadata,
                "DiocesanCalendars"         => Metadata::$diocesanCalendars,
                "DiocesanGroups"            => Metadata::$diocesanGroups,
                "WiderRegions"              => Metadata::$widerRegionsNames,
                "RomanMissals"              => RomanMissal::produceMetadata()
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
    }

    public static function init()
    {
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

        Metadata::retrieveCalendars();
        $indexResult = Metadata::buildIndex();

        if (200 === $indexResult) {
            Metadata::response();
        } else {
            http_response_code($indexResult);
        }
    }
}
