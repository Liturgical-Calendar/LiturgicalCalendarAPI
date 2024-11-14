<?php

namespace LiturgicalCalendar\Api\Paths;

use LiturgicalCalendar\Api\Enum\StatusCode;

class Metadata
{
    private static array $baseNationalCalendars     = [];
    private static array $nationalCalendars         = [];
    private static array $diocesanCalendars         = [];
    private static array $diocesanGroups            = [];
    private static array $nationalCalendarsMetadata = [];
    private static array $widerRegions              = [];
    private static array $widerRegionsNames         = [];
    private static array $locales                   = [];

    private static function retrieveNationalCalendarNamesFromFolders()
    {
        $directories = array_map('basename', glob('data/nations/*', GLOB_ONLYDIR));
        foreach ($directories as $directory) {
            if (file_exists("data/nations/$directory/$directory.json")) {
                $nationalCalendarDefinition = file_get_contents("data/nations/$directory/$directory.json");
                $nationalCalendarData = json_decode($nationalCalendarDefinition);
                if (JSON_ERROR_NONE === json_last_error()) {
                    Metadata::$baseNationalCalendars[$directory] = $nationalCalendarData;
                } else {
                    Metadata::$baseNationalCalendars[$directory] = null;
                }
            }
        }
    }

    /**
     * @return void
     * @description
     * This static function processes the contents of the data/wider_regions directory.
     * It takes the files in the directory, checks if they are JSON files and if they are wider region definitions.
     * If they are, it reads the languages and builds the data for the wider region metadata.
     * The data is then stored in the Metadata::$widerRegions array.
     * The function also builds the data for the API path and the i18n path, which are stored in the same array.
     * The function also stores the names of the wider regions in the Metadata::$widerRegionsNames array.
     * @see Metadata::$widerRegions
     * @see Metadata::$widerRegionsNames
     */
    private static function buildWiderRegionData()
    {
        $filterDirResults = ['..', '.'];
        $dirResults = array_diff(scandir('data/wider_regions'), $filterDirResults);
        $widerRegionsFiles = array_values(array_filter($dirResults, function ($el) {
            return !is_dir('data/wider_regions/' . $el) && pathinfo('data/wider_regions/' . $el, PATHINFO_EXTENSION) === 'json';
        }));
        Metadata::$widerRegions = array_map(function ($el) {
            $dirName = strtoupper(pathinfo('data/wider_regions/' . $el, PATHINFO_FILENAME));
            $langsInFolder = array_diff(scandir("data/wider_regions/$dirName"), ['..','.']);
            $widerRegionLanguages = array_values(array_filter($langsInFolder, function ($elem) use ($dirName) {
                return pathinfo("data/wider_regions/$dirName/$elem", PATHINFO_EXTENSION) === 'json';
            }));
            $widerRegionLanguages = array_map(fn ($el) => pathinfo("data/wider_regions/$dirName/$el", PATHINFO_FILENAME), $widerRegionLanguages);
            $widerRegionName = pathinfo('data/wider_regions/' . $el, PATHINFO_FILENAME);
            Metadata::$widerRegionsNames[] = $widerRegionName;
            return [
                "name"      => $widerRegionName,
                "languages" => $widerRegionLanguages,
                "data_path" => "data/wider_regions/$el",
                "i18n_path" => "data/wider_regions/$dirName",
                "api_path"  => API_BASE_PATH . '/data/widerregion/' . pathinfo('data/wider_regions/' . $el, PATHINFO_FILENAME) . '?locale={language}'
            ];
        }, $widerRegionsFiles);
    }

    /**
     * Retrieves and applies the settings overrides for a specific diocesan calendar based on the provided key and server path.
     * Diocesan calendars inherit their settings regarding Epiphany, Ascension, Corpus Christi etc. from National calendars,
     * however they can override some of these settings. Here we check if a Diocesan calendar has any overrides defined,
     * and if so we add this information to the response object.
     *
     * If the server path file does not exist, the function returns early.
     * It reads the diocesan calendar definition from the server path, decodes it, and checks for JSON decoding errors.
     * If there are overrides defined in the calendar data, it updates the settings for the corresponding diocesan calendar.
     */
    private static function retrieveDiocesanSettings(string $diocesan_key, string $server_path)
    {
        // This should never happen!
        // We shouldn't even have the $server_path value in index.json if the file doesn't exist.
        // But let's stay on the safe side and avoid any potential PHP breaking errors.
        if (false === file_exists($server_path)) {
            return;
        }
        $diocesanCalendarDefinition = file_get_contents($server_path);
        $diocesanCalendarData = json_decode($diocesanCalendarDefinition);
        if (JSON_ERROR_NONE !== json_last_error()) {
            return;
        }
        if (property_exists($diocesanCalendarData, 'overrides')) {
            $idx = array_search($diocesan_key, array_column(Metadata::$diocesanCalendars, 'calendar_id'), true);
            Metadata::$diocesanCalendars[$idx]['settings'] = $diocesanCalendarData->overrides;
        }
    }

    /**
     * Populates the Metadata::$locales array with the list of supported locales.
     *
     * It does this by scanning the i18n/ folder and retrieving the folder names
     * of all its subfolders. The result is an array of strings, where each string
     * is a locale code. The locale code is in the format of a single string
     * containing the language code, optionally followed by an underscore and the
     * region code. Examples of valid locale codes are: en, en_GB, it_IT, pt_PT.
     */
    private static function getLocales(): void
    {
        Metadata::$locales = array_merge(['en'], array_map('basename', glob('i18n/*', GLOB_ONLYDIR)));
    }

    /**
     * Builds an index of all National and Diocesan calendars,
     * and of locales supported for the General Roman Calendar
     *
     * @return int Returns the HTTP Status Code for the Response
     */
    private static function buildIndex(): int
    {
        // The first way of retrieving the names of currently supported National calendars
        // is by scanning the `data/nations/` folder and picking out folder names
        // for folders that contain a JSON file of the same name
        // If a folder doesn't contain a JSON file of the same name it's a Wider Region rather than a Nation
        // A National calendar can exist without any Diocesan calendars defined,
        // so this first approach will retrieve all National calendars by Nation
        // independently from the fact that Diocesan calendars have or have not been defined for that Nation
        Metadata::retrieveNationalCalendarNamesFromFolders();

        // Information about Diocesan Calendars is stored in an index.json file to make life easier
        if (false === file_exists('data/nations/index.json')) {
            return StatusCode::NOT_FOUND;
        }

        $index = file_get_contents('data/nations/index.json');
        if (false === $index) {
            return StatusCode::SERVICE_UNAVAILABLE;
        }

        Metadata::$diocesanCalendars = json_decode($index, true);
        if (JSON_ERROR_NONE !== json_last_error()) {
            return StatusCode::UNPROCESSABLE_CONTENT;
        }

        foreach (Metadata::$diocesanCalendars as $idx => $dioceseEntry) {
            // The client does not need to know where the file resides on the server.
            // This information is only useful to the API itself, when processing the data.
            $diocesanCalendarPathOnServer = Metadata::$diocesanCalendars[$idx]["path"];
            unset(Metadata::$diocesanCalendars[$idx]["path"]);

            // Build any diocesan groups that might be defined
            if (array_key_exists("group", $dioceseEntry) && $dioceseEntry["group"] !== "") {
                $diocesan_group_name = $dioceseEntry["group"];
                if (!array_key_exists($diocesan_group_name, Metadata::$diocesanGroups)) {
                    Metadata::$diocesanGroups[$diocesan_group_name] = [];
                }
                // Push the name of the diocese to the group that it belongs to
                Metadata::$diocesanGroups[$diocesan_group_name][] = $dioceseEntry["calendar_id"];
            }

            Metadata::retrieveDiocesanSettings($dioceseEntry["calendar_id"], $diocesanCalendarPathOnServer);

            // Build national calendars and national calendars metadata.
            // This is a second approach to retrieving the names of National calendars,
            // based on the Diocesan calendars that are associated with National calendars.
            // Of course a Diocesan calendar cannot be associated with a National calendar
            // if the National calendar does not exist, so we will already have all the names of National calendars
            // from the ::retrieveNationalCalendarNamesFromFolders() method,
            // but this is a way of double checking this relationship.
            // The mentioned method stores the names of National calendars to a Metadata::$baseNationalCalendars array,
            // so those National calendars will not yet be defined in the Metadata::$nationalCalendars array.
            // Basically, we are only now starting to build National calendars based on Diocesan calendar info,
            // adding the information about Dioceses associated with them,
            // then we will subsequently check against the Metadata::$baseNationalCalendars array
            // to fill in any missing National calendars that have no Dioceses associated with them yet.
            $nation = $dioceseEntry["nation"];
            if (!array_key_exists($nation, Metadata::$nationalCalendars)) {
                Metadata::$nationalCalendars[$nation] = [];
                Metadata::$nationalCalendarsMetadata[$nation] = [
                    "missals"       => [],
                    "wider_regions" => [],
                    "dioceses"      => [],
                    "settings"      => []
                ];
            }
            Metadata::$nationalCalendars[$nation][] = $dioceseEntry["calendar_id"];
            Metadata::$nationalCalendarsMetadata[$nation]["dioceses"][] = $dioceseEntry["calendar_id"];
        }

        // Now we double check against the ::$baseNationalCalendars array
        // which was populated by the ::retrieveNationalCalendarNamesFromFolders() method
        // to fill in any missing National calendars,
        // and populate the metadata associated with each National calendar
        // which we also retrieved in the ::retrieveNationalCalendarNamesFromFolders() method
        foreach (Metadata::$baseNationalCalendars as $nation => $nationData) {
            if (!array_key_exists($nation, Metadata::$nationalCalendars)) {
                Metadata::$nationalCalendars[$nation] = [];
            }
            if (null !== $nationData) {
                Metadata::$nationalCalendarsMetadata[$nation]["missals"] = $nationData->metadata->missals;
                Metadata::$nationalCalendarsMetadata[$nation]["wider_regions"][] = $nationData->metadata->wider_region->name;
                Metadata::$nationalCalendarsMetadata[$nation]["settings"] = $nationData->settings;
            }
        }

        // We will build information about Wider Regions
        // that National calendars might be associated with.
        Metadata::buildWiderRegionData();

        // Finally we retrieve all available locales for the General Roman Calendar
        Metadata::getLocales();
        return 200;
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
        $nationalCalendars = [];
        $nationalCalendars[] = [
            "calendar_id" => "VA",
            "missals"     => [
                "EDITIO_TYPICA_1970",
                "EDITIO_TYPICA_1971",
                "EDITIO_TYPICA_1975",
                "EDITIO_TYPICA_2002",
                "EDITIO_TYPICA_2008"
            ]
        ];
        foreach (Metadata::$nationalCalendars as $key => $calendar) {
            $nationalCalendars[] = [
                "calendar_id" => $key,
                ...Metadata::$nationalCalendarsMetadata[$key]
            ];
        }
        $nationalCalendarsKeys = [
            "VA",
            ...array_keys(Metadata::$nationalCalendars)
        ];
        $response = json_encode([
            "litcal_metadata" => [
                "national_calendars"          => $nationalCalendars,
                "national_calendars_keys"     => $nationalCalendarsKeys,
                "diocesan_calendars"          => array_values(Metadata::$diocesanCalendars),
                "diocesan_calendars_keys"     => array_column(Metadata::$diocesanCalendars, 'calendar_id'),
                "diocesan_groups"             => $diocesanGroups,
                "wider_regions"               => Metadata::$widerRegions,
                "wider_regions_keys"          => Metadata::$widerRegionsNames,
                "locales"                     => Metadata::$locales
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

    /**
     * Initialization function for the metadata API.
     *
     * It sets the appropriate CORS headers and calls the `buildIndex` and `response` methods.
     *
     * @return void
     */
    public static function init()
    {
        if (isset($_SERVER[ 'REQUEST_METHOD' ])) {
            if (isset($_SERVER[ 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' ])) {
                header("Access-Control-Allow-Methods: OPTIONS,GET,POST");
            }
            if (isset($_SERVER[ 'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' ])) {
                header("Access-Control-Allow-Headers: {$_SERVER[ 'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' ]}");
            }
        }

        /*
        $requestHeaders = getallheaders();
        if (isset($requestHeaders[ "Origin" ])) {
            header("Access-Control-Allow-Origin: {$requestHeaders[ "Origin" ]}");
            header('Access-Control-Allow-Credentials: true');
        } else {
            header('Access-Control-Allow-Origin: *');
        }
        */
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Max-Age: 86400');    // cache for 1 day
        header('Cache-Control: must-revalidate, max-age=259200');
        header('Content-Type: application/json');

        $indexResult = Metadata::buildIndex();

        if (200 === $indexResult) {
            Metadata::response();
        } else {
            http_response_code($indexResult);
        }
    }
}
