<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Routes\Readonly;

use LiturgicalCalendar\Tests\ApiTestCase;

final class CalendarsTest extends ApiTestCase
{
    private static array $diocesesLatinRiteByNation;
    private static array $dioceseIDs;
    private const REGION_PATTERN       = '/^[A-Z]{2}$/';
    private const LOCALE_PATTERN       = '/^[a-z]{2,3}(?:_[A-Z][a-z]{3})?(?:_[A-Z]{2}|\d{3})?(?:_[A-Za-z0-9]+)*$/';
    private const MISSAL_ID_PATTERN    = '/^[A-Z0-9_]+$/';
    private const TIMEZONE_PATTERN     = '/^[A-Z][a-z]+\/[A-Za-z_]+$/';
    private const WIDER_REGION_PATTERN = '/^(Europe|Africa|Asia|Oceania|Americas)$/';

    private static string $WIDER_REGION_API_PATH_PATTERN;

    public function testGetCalendarsReturnsJson(): void
    {
        $response = $this->http->get('/calendars');
        $this->assertSame(200, $response->getStatusCode(), 'Expected HTTP 200 OK');
        $this->assertStringStartsWith('application/json', $response->getHeaderLine('Content-Type'), 'Content-Type should be application/json');

        // Decode JSON and check
        $data = json_decode((string) $response->getBody());
        $this->assertSame(JSON_ERROR_NONE, json_last_error(), 'Invalid JSON: ' . json_last_error_msg());
        $this->assertionsForCalendarObject($data);
    }

    public function testGetCalendarsReturnsYaml(): void
    {
        if (!extension_loaded('yaml')) {
            $this->markTestSkipped('YAML extension is not installed');
        }

        $response = $this->http->get('/calendars', [
            'headers' => ['Accept' => 'application/yaml'],
        ]);

        // Assert status code
        $this->assertSame(200, $response->getStatusCode(), 'Expected HTTP 200 OK');
        $this->assertStringStartsWith('application/yaml', $response->getHeaderLine('Content-Type'), 'Content-Type should be application/yaml');

        // Decode YAML and check
        $yaml = yaml_parse((string) $response->getBody());
        $this->assertIsArray($yaml, 'YAML Response should be an array');
        $encoded = json_encode($yaml);
        $this->assertSame(JSON_ERROR_NONE, json_last_error(), 'YAML Response should have been encoded to JSON ' . json_last_error_msg());
        $data = json_decode($encoded);
        $this->assertSame(JSON_ERROR_NONE, json_last_error(), 'JSON encoded YAML Response should have been decoded as an object: ' . json_last_error_msg());
        $this->assertIsObject($data, 'Response should have been transformed to a JSON object');
        $this->assertionsForCalendarObject($data);
    }

    public function testPostCalendarsReturnsJson(): void
    {
        $response = $this->http->post('/calendars');

        // Assert status code
        $this->assertSame(200, $response->getStatusCode(), 'Expected HTTP 200 OK');
        $this->assertStringStartsWith('application/json', $response->getHeaderLine('Content-Type'), 'Content-Type should be application/json');

        // Decode JSON and check
        $data = json_decode((string) $response->getBody());
        $this->assertSame(JSON_ERROR_NONE, json_last_error(), 'Invalid JSON: ' . json_last_error_msg());
        $this->assertionsForCalendarObject($data);
    }

    public function testPutCalendarsReturnsError(): void
    {
        $response = $this->http->put('/calendars');
        $this->assertSame(405, $response->getStatusCode(), 'Expected HTTP 405 Method Not Allowed');
    }

    public function testPatchCalendarsReturnsError(): void
    {
        $response = $this->http->patch('/calendars');
        $this->assertSame(405, $response->getStatusCode(), 'Expected HTTP 405 Method Not Allowed');
    }

    public function testDeleteCalendarsReturnsError(): void
    {
        $response = $this->http->delete('/calendars');
        $this->assertSame(405, $response->getStatusCode(), 'Expected HTTP 405 Method Not Allowed');
    }

    private function assertionsForCalendarObject(object $data): void
    {
        $this->assertIsObject($data, 'Response should be a JSON object');
        $this->assertObjectHasProperty('litcal_metadata', $data, 'JSON should have an "litcal_metadata" property');
        $this->assertIsObject($data->litcal_metadata, 'litcal_metadata should be an object');
        $metadata = $data->litcal_metadata;
        $this->assertMetadataStructure($metadata);
    }

    private function assertNationalCalendarStructure(object $national_calendar): void
    {
        $this->assertObjectHasProperty('calendar_id', $national_calendar, 'Each item in national_calendars should have a "calendar_id" property');
        $this->assertIsString($national_calendar->calendar_id, 'calendar_id should be a string');
        $this->assertMatchesRegularExpression(self::REGION_PATTERN, $national_calendar->calendar_id, 'calendar_id should be a valid region identifier');

        $this->assertObjectHasProperty('missals', $national_calendar, 'Each item in national_calendars should have a "missals" property');
        $this->assertIsArray($national_calendar->missals, 'missals should be an array');
        foreach ($national_calendar->missals as $missal) {
            $this->assertIsString($missal, 'Each element of missals should be a string');
            $this->assertMatchesRegularExpression(self::MISSAL_ID_PATTERN, $missal, 'Each element of missals should be a valid missal identifier');
        }

        $this->assertObjectHasProperty('locales', $national_calendar, 'Each item in national_calendars should have a "locales" property');
        $this->assertIsArray($national_calendar->locales, 'locales should be an array');
        foreach ($national_calendar->locales as $locale) {
            $this->assertIsString($locale, 'Each element of locales should be a string');
            $this->assertMatchesRegularExpression(self::LOCALE_PATTERN, $locale, 'Each element of locales should be a valid locale identifier');
        }

        $this->assertObjectHasProperty('settings', $national_calendar, 'Each item in national_calendars should have a "settings" property');
        $this->assertIsObject($national_calendar->settings, 'settings should be an object');

        $this->assertObjectHasProperty('epiphany', $national_calendar->settings, 'Each item in national_calendars should have an "epiphany" property under "settings"');
        $this->assertIsString($national_calendar->settings->epiphany, 'epiphany should be a string');
        $this->assertThat($national_calendar->settings->epiphany, $this->logicalOr(
            $this->equalTo('SUNDAY_JAN2_JAN8'),
            $this->equalTo('JAN6')
        ), 'epiphany should be either "SUNDAY_JAN2_JAN8" or "JAN6"');

        $this->assertObjectHasProperty('ascension', $national_calendar->settings, 'Each item in national_calendars should have an "ascension" property under "settings"');
        $this->assertIsString($national_calendar->settings->ascension, 'ascension should be a string');
        $this->assertThat($national_calendar->settings->ascension, $this->logicalOr(
            $this->equalTo('SUNDAY'),
            $this->equalTo('THURSDAY')
        ), 'ascension should be either "SUNDAY" or "THURSDAY"');


        $this->assertObjectHasProperty('corpus_christi', $national_calendar->settings, 'Each item in national_calendars should have a "corpus_christi" property under "settings"');
        $this->assertIsString($national_calendar->settings->corpus_christi, 'corpus_christi should be a string');
        $this->assertThat($national_calendar->settings->corpus_christi, $this->logicalOr(
            $this->equalTo('SUNDAY'),
            $this->equalTo('THURSDAY')
        ), 'corpus_christi should be either "SUNDAY" or "THURSDAY"');

        $this->assertObjectHasProperty('eternal_high_priest', $national_calendar->settings, 'Each item in national_calendars should have an "eternal_high_priest" property under "settings"');
        $this->assertIsBool($national_calendar->settings->eternal_high_priest, 'eternal_high_priest should be a boolean');

        if (isset($national_calendar->wider_region)) {
            $this->assertIsString($national_calendar->wider_region, 'wider_region should be a string');
        }

        if (isset($national_calendar->dioceses)) {
            $this->assertIsArray($national_calendar->dioceses, 'dioceses should be an array');
            foreach ($national_calendar->dioceses as $diocese) {
                $this->assertIsString($diocese, 'Each element of dioceses should be a string');
                $this->assertContains(
                    $diocese,
                    self::$diocesesLatinRiteByNation[$national_calendar->calendar_id],
                    sprintf('The diocese id "%s" should be found in the diocesesLatinRiteByNation array for national calendar_id "%s"', $diocese, $national_calendar->calendar_id)
                );
            }
        }
    }

    private function assertDiocesanCalendarStructure(object $diocesan_calendar): void
    {
        $this->assertObjectHasProperty('calendar_id', $diocesan_calendar, 'Each item in diocesan_calendars should have a "calendar_id" property');
        $this->assertIsString($diocesan_calendar->calendar_id, 'calendar_id should be a string');

        $this->assertObjectHasProperty('nation', $diocesan_calendar, 'Each item in diocesan_calendars should have a "nation" property');
        $this->assertIsString($diocesan_calendar->nation, 'nation should be a string');
        $this->assertMatchesRegularExpression(self::REGION_PATTERN, $diocesan_calendar->nation, 'nation should be a valid region identifier');

        $this->assertContains(
            $diocesan_calendar->calendar_id,
            self::$diocesesLatinRiteByNation[$diocesan_calendar->nation],
            sprintf('The diocese id "%s" should be found in the diocesesLatinRiteByNation array for under nation "%s"', $diocesan_calendar->calendar_id, $diocesan_calendar->nation)
        );

        $this->assertObjectHasProperty('locales', $diocesan_calendar, 'Each item in diocesan_calendars should have a "locales" property');
        $this->assertIsArray($diocesan_calendar->locales, 'locales should be an array');
        foreach ($diocesan_calendar->locales as $locale) {
            $this->assertIsString($locale, 'Each element of locales should be a string');
            $this->assertMatchesRegularExpression(self::LOCALE_PATTERN, $locale, 'Each element of locales should be a valid locale identifier');
        }

        $this->assertObjectHasProperty('timezone', $diocesan_calendar, 'Each item in diocesan_calendars should have a "timezone" property');
        $this->assertIsString($diocesan_calendar->timezone, 'timezone should be a string');
        $this->assertMatchesRegularExpression(self::TIMEZONE_PATTERN, $diocesan_calendar->timezone, 'timezone should be a valid timezone identifier');

        if (isset($diocesan_calendar->group)) {
            $this->assertIsString($diocesan_calendar->group, 'group should be a string');
        }

        if (isset($diocesan_calendar->settings)) {
            $this->assertIsObject($diocesan_calendar->settings, 'settings should be an object');
            if (isset($diocesan_calendar->settings->corpus_christi)) {
                $this->assertThat($diocesan_calendar->settings->corpus_christi, $this->logicalOr(
                    $this->equalTo('SUNDAY'),
                    $this->equalTo('THURSDAY')
                ), 'corpus_christi should be either "SUNDAY" or "THURSDAY"');
            }
            if (isset($diocesan_calendar->settings->ascension)) {
                $this->assertThat($diocesan_calendar->settings->ascension, $this->logicalOr(
                    $this->equalTo('SUNDAY'),
                    $this->equalTo('THURSDAY')
                ), 'ascension should be either "SUNDAY" or "THURSDAY"');
            }
            if (isset($diocesan_calendar->settings->epiphany)) {
                $this->assertThat($diocesan_calendar->settings->epiphany, $this->logicalOr(
                    $this->equalTo('JAN6'),
                    $this->equalTo('SUNDAY_JAN2_JAN8')
                ), 'epiphany should be either "JAN6" or "SUNDAY_JAN2_JAN8"');
            }
        }
    }

    private function assertDiocesanGroupStructure(object $diocesan_group): void
    {
        $this->assertObjectHasProperty('group_name', $diocesan_group, 'Each item in diocesan_groups should have a "group_name" property');
        $this->assertIsString($diocesan_group->group_name, 'group_name should be a string');
        $this->assertObjectHasProperty('dioceses', $diocesan_group, 'Each item in diocesan_groups should have a "dioceses" property');
        $this->assertIsArray($diocesan_group->dioceses, 'dioceses should be an array');
        foreach ($diocesan_group->dioceses as $diocese) {
            $this->assertIsString($diocese, 'Each element of dioceses should be a string');
            $this->assertContains(
                $diocese,
                self::$dioceseIDs,
                sprintf('The diocese id "%s" should be a valid diocese identifier as found in the world_dioceses.json file', $diocese)
            );
        }
    }

    private function assertWiderRegionStructure(object $wider_region): void
    {
        $this->assertObjectHasProperty('name', $wider_region, 'Each item in wider_regions should have a "name" property');
        $this->assertIsString($wider_region->name, 'name should be a string');
        $this->assertMatchesRegularExpression(self::WIDER_REGION_PATTERN, $wider_region->name, 'name should be a valid wider region name');
        $this->assertObjectHasProperty('locales', $wider_region, 'Each item in wider_regions should have a "locales" property');
        $this->assertIsArray($wider_region->locales, 'locales should be an array');
        foreach ($wider_region->locales as $locale) {
            $this->assertIsString($locale, 'Each element of locales should be a string');
            $this->assertMatchesRegularExpression(self::LOCALE_PATTERN, $locale, 'Each element of locales should be a valid locale code');
        }
        $this->assertObjectHasProperty('api_path', $wider_region, 'Each item in wider_regions should have a "api_path" property');
        $this->assertIsString($wider_region->api_path, 'api_path should be a string');
        $this->assertMatchesRegularExpression(self::$WIDER_REGION_API_PATH_PATTERN, $wider_region->api_path, 'api_path should be a valid wider region api_path URL');
    }

    private function assertMetadataStructure(object $metadata): void
    {
        $this->assertObjectHasProperty('national_calendars', $metadata, 'JSON should have a "national_calendars" property under "litcal_metadata"');
        $this->assertObjectHasProperty('national_calendars_keys', $metadata, 'JSON should have a "national_calendars_keys" property under "litcal_metadata"');
        $this->assertObjectHasProperty('diocesan_calendars', $metadata, 'JSON should have a "diocesan_calendars" property under "litcal_metadata"');
        $this->assertObjectHasProperty('diocesan_calendars_keys', $metadata, 'JSON should have a "diocesan_calendars_keys" property under "litcal_metadata"');
        $this->assertObjectHasProperty('diocesan_groups', $metadata, 'JSON should have a "diocesan_groups" property under "litcal_metadata"');
        $this->assertObjectHasProperty('wider_regions', $metadata, 'JSON should have a "wider_regions" property under "litcal_metadata"');
        $this->assertObjectHasProperty('wider_regions_keys', $metadata, 'JSON should have a "wider_regions_keys" property under "litcal_metadata"');
        $this->assertObjectHasProperty('locales', $metadata, 'JSON should have a "locales" property under "litcal_metadata"');
        $this->assertIsArray($metadata->national_calendars, 'national_calendars should be an array');
        $this->assertIsArray($metadata->national_calendars_keys, 'national_calendars_keys should be an array');
        $this->assertIsArray($metadata->diocesan_calendars, 'diocesan_calendars should be an array');
        $this->assertIsArray($metadata->diocesan_calendars_keys, 'diocesan_calendars_keys should be an array');
        $this->assertIsArray($metadata->diocesan_groups, 'diocesan_groups should be an array');
        $this->assertIsArray($metadata->wider_regions, 'wider_regions should be an array');
        $this->assertIsArray($metadata->wider_regions_keys, 'wider_regions_keys should be an array');
        $this->assertIsArray($metadata->locales, 'locales should be an array');

        // Run assertions on each national_calendars item
        foreach ($metadata->national_calendars as $national_calendar) {
            $this->assertIsObject($national_calendar, 'Each item in national_calendars should be an object');
            $this->assertNationalCalendarStructure($national_calendar);
        }

        // Run Assertion on each diocesan_calendars item
        foreach ($metadata->diocesan_calendars as $diocesan_calendar) {
            $this->assertIsObject($diocesan_calendar, 'Each item in diocesan_calendars should be an object');
            $this->assertDiocesanCalendarStructure($diocesan_calendar);
        }

        // Run assertions on each diocesan_groups item
        foreach ($metadata->diocesan_groups as $diocesan_group) {
            $this->assertIsObject($diocesan_group, 'Each element of diocesan_groups should be an object');
            $this->assertDiocesanGroupStructure($diocesan_group);
        }

        // Run assertion on each wider_regions item
        foreach ($metadata->wider_regions as $wider_region) {
            $this->assertIsObject($wider_region, 'Each element of wider_regions should be an object');
            $this->assertWiderRegionStructure($wider_region);
        }

        // Run assertions on each national_calendar_keys item
        foreach ($metadata->national_calendars_keys as $key) {
            $this->assertIsString($key, 'Each element of national_calendar_keys should be a string');
            $this->assertMatchesRegularExpression(self::REGION_PATTERN, $key, 'Each element of national_calendar_keys should be a valid region identifier');
        }

        // Run assertions on each diocesan_calendars_keys item
        foreach ($metadata->diocesan_calendars_keys as $key) {
            $this->assertIsString($key, 'Each element of diocesan_calendar_keys should be a string');
            $this->assertContains(
                $key,
                self::$dioceseIDs,
                sprintf('The diocese id "%s" should be a valid diocese identifier as found in the world_dioceses.json file', $key)
            );
        }

        // Run assertions on each wider_regions_keys item
        foreach ($metadata->wider_regions_keys as $key) {
            $this->assertIsString($key, 'Each element of wider_regions_keys should be a string');
            $this->assertMatchesRegularExpression(self::WIDER_REGION_PATTERN, $key, 'Each element of wider_regions_keys should be a valid region identifier');
        }

        // Run assertions on each locales item
        foreach ($metadata->locales as $locale) {
            $this->assertIsString($locale, 'Each element of locales should be a string');
            $this->assertMatchesRegularExpression(self::LOCALE_PATTERN, $locale, 'Each element of locales should be a valid locale code');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Only load the data once
        if (false === isset(self::$diocesesLatinRiteByNation)) {
            $root     = ApiTestCase::findProjectRoot();
            $filePath = $root . '/jsondata/world_dioceses.json';

            // File existence and readability
            if (!file_exists($filePath)) {
                throw new \RuntimeException("File not found: {$filePath}");
            }
            if (!is_readable($filePath)) {
                throw new \RuntimeException("File not readable: {$filePath}");
            }

            // Load file
            $catholicDiocesesRaw = file_get_contents($filePath);
            if ($catholicDiocesesRaw === false) {
                throw new \RuntimeException("Failed to read file: {$filePath}");
            }

            // Decode JSON with strict error handling
            try {
                $catholicDioceses = json_decode($catholicDiocesesRaw, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw new \RuntimeException("Invalid JSON in {$filePath}: " . $e->getMessage(), 0, $e);
            }

            $dioceseIDArray = [];
            foreach ($catholicDioceses['catholic_dioceses_latin_rite'] as $nation) {
                $nationID   = strtoupper($nation['country_iso']);
                $dioceseIDs = array_column($nation['dioceses'], 'diocese_id');
                array_push($dioceseIDArray, ...$dioceseIDs);
                self::$diocesesLatinRiteByNation[$nationID] = $dioceseIDs;
            }
            self::$dioceseIDs = $dioceseIDArray;

            self::$WIDER_REGION_API_PATH_PATTERN = sprintf(
                '/^%s:\/\/%s:%d\/data\/widerregion\/(Europe|Africa|Asia|Oceania|Americas)\?locale=\{locale\}$/',
                $_ENV['API_PROTOCOL'],
                $_ENV['API_HOST'],
                $_ENV['API_PORT']
            );
        }
    }
}
