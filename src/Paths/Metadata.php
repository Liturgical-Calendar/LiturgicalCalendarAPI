<?php

namespace Johnrdorazio\LitCal\Paths;

use Johnrdorazio\LitCal\Enum\RomanMissal;

class Metadata
{
    private static array $baseNationalCalendars     = [];
    private static array $nationalCalendars         = [];
    private static array $diocesanCalendars         = [];
    private static array $diocesanGroups            = [];
    private static array $nationalCalendarsMetadata = [];
    private static array $widerRegions              = [];
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
                            "wider_regions" => [],
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
                        Metadata::$nationalCalendarsMetadata[$nation]["missals"] = $nationData->metadata->missals;
                        Metadata::$nationalCalendarsMetadata[$nation]["wider_regions"][] = $nationData->metadata->wider_region->name;
                        Metadata::$nationalCalendarsMetadata[$nation]["settings"] = $nationData->settings;
                    }
                }
                $filterDirResults = ['..', '.', 'index.json'];
                $dirResults = array_diff(scandir('nations'), $filterDirResults);
                $widerRegionsFiles = array_values(array_filter($dirResults, function ($el) {
                    return !is_dir('nations/' . $el) && pathinfo('nations/' . $el, PATHINFO_EXTENSION) === 'json';
                }));
                Metadata::$widerRegions = array_map(function ($el) {
                    $dirName = strtoupper(pathinfo('nations/' . $el, PATHINFO_FILENAME));
                    $langsInFolder = array_diff(scandir("nations/$dirName"), ['..','.']);
                    $widerRegionLanguages = array_values(array_filter($langsInFolder, function ($elem) use ($dirName) {
                        return pathinfo("nations/$dirName/$elem", PATHINFO_EXTENSION) === 'json';
                    }));
                    $widerRegionLanguages = array_map(fn ($el) => pathinfo("nations/$dirName/$el", PATHINFO_FILENAME), $widerRegionLanguages);
                    $widerRegionName = pathinfo('nations/' . $el, PATHINFO_FILENAME);
                    Metadata::$widerRegionsNames[] = $widerRegionName;
                    return [
                        "name" => $widerRegionName,
                        "languages" => $widerRegionLanguages,
                        "data_path" => "nations/$el",
                        "i18n_path" => "nations/$dirName",
                        "api_path" => API_BASE_PATH . '/data/widerregion/' . pathinfo('nations/' . $el, PATHINFO_FILENAME) . '?locale={language}'
                    ];
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
        $diocesanGroups = [];
        foreach (Metadata::$diocesanGroups as $key => $group) {
            $diocesanGroups[] = [
                "group_name" => $key,
                "dioceses" => $group
            ];
        }
        $diocesanCalendars = [];
        foreach (Metadata::$diocesanCalendars as $key => $calendar) {
            $diocesanCalendars[] = [
                "calendar_id" => $key,
                ...$calendar
            ];
        }
        $nationalCalendars = [];
        $nationalCalendars[] = [
            "calendar_id" => "VATICAN"
        ];
        foreach (Metadata::$nationalCalendars as $key => $calendar) {
            $nationalCalendars[] = [
                "calendar_id" => $key,
                ...Metadata::$nationalCalendarsMetadata[$key]
            ];
        }
        $response = json_encode([
            "litcal_metadata" => [
                "national_calendars"          => $nationalCalendars,
                "national_calendars_keys"     => array_push(array_keys(Metadata::$nationalCalendars), "VATICAN"),
                "diocesan_calendars"          => $diocesanCalendars,
                "diocesan_calendars_keys"     => array_keys(Metadata::$diocesanCalendars),
                "diocesan_groups"             => $diocesanGroups,
                "wider_regions"               => Metadata::$widerRegions,
                "wider_regions_keys"          => Metadata::$widerRegionsNames,
                "roman_missals"               => RomanMissal::produceMetadata()
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
