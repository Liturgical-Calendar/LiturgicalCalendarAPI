<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Routes\Readonly;

use LiturgicalCalendar\Tests\ApiTestCase;

final class EasterTest extends ApiTestCase
{
    public function testGetEasterReturnsJson(): void
    {
        $response = self::$http->get('/easter');
        $this->assertSame(200, $response->getStatusCode(), 'Expected HTTP 200 OK');
        $this->assertStringStartsWith('application/json', $response->getHeaderLine('Content-Type'), 'Content-Type should be application/json');

        $data = json_decode((string) $response->getBody());
        $this->assertSame(JSON_ERROR_NONE, json_last_error(), 'Invalid JSON: ' . json_last_error_msg());
        $this->assertIsObject($data, 'Response should be a JSON object');
        $this->assertObjectHasProperty('litcal_easter', $data, 'Response should have a litcal_easter property');
        $this->assertIsArray($data->litcal_easter, 'Response litcal_easter should be an array');

        foreach ($data->litcal_easter as $easter) {
            $this->assertIsObject($easter, 'Response litcal_easter should be an array of objects');
            $this->assertObjectHasProperty('gregorianEaster', $easter, 'Response litcal_easter should have a gregorianEaster property');
            $this->assertIsInt($easter->gregorianEaster, 'Response litcal_easter gregorianEaster should be an integer');
            $this->assertObjectHasProperty('julianEaster', $easter, 'Response litcal_easter should have a julianEaster property');
            $this->assertIsInt($easter->julianEaster, 'Response litcal_easter julianEaster should be an integer');
            $this->assertObjectHasProperty('westernJulianEaster', $easter, 'Response litcal_easter should have a westernJulianEaster property');
            $this->assertIsInt($easter->westernJulianEaster, 'Response litcal_easter westernJulianEaster should be an integer');
            $this->assertObjectHasProperty('coinciding', $easter, 'Response litcal_easter should have a coinciding property');
            $this->assertIsBool($easter->coinciding, 'Response litcal_easter coinciding should be a boolean');
            $this->assertObjectHasProperty('gregorianDateString', $easter, 'Response litcal_easter should have a gregorianDateString property');
            $this->assertIsString($easter->gregorianDateString, 'Response litcal_easter gregorianDateString should be a string');
            $this->assertObjectHasProperty('julianDateString', $easter, 'Response litcal_easter should have a julianDateString property');
            $this->assertIsString($easter->julianDateString, 'Response litcal_easter julianDateString should be a string');
            $this->assertObjectHasProperty('westernJulianDateString', $easter, 'Response litcal_easter should have a westernJulianDateString property');
            $this->assertIsString($easter->westernJulianDateString, 'Response litcal_easter westernJulianDateString should be a string');
        }

        $this->assertObjectHasProperty('lastCoincidenceString', $data, 'Response should have a lastCoincidenceString property');
        $this->assertIsString($data->lastCoincidenceString, 'Response lastCoincidenceString should be a string');

        $this->assertObjectHasProperty('lastCoincidence', $data, 'Response should have a lastCoincidence property');
        // We don't worry about being limited to 32-bit integers,
        // because both locally and on GitHub Actions, we have 64-bit PHP
        $this->assertIsInt($data->lastCoincidence, 'Response lastCoincidence should be an integer');
        $this->assertEquals(22983264000, $data->lastCoincidence, 'Response lastCoincidence should be 22983264000');
    }
}
