<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests;

final class EasterTest extends ApiTestCase
{
    public function testGetEasterReturnsJson(): void
    {
        $response = $this->http->get('/easter');
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringStartsWith('application/json', $response->getHeaderLine('Content-Type'));

        $data = json_decode((string) $response->getBody());
        $this->assertSame(JSON_ERROR_NONE, json_last_error(), 'Invalid JSON: ' . json_last_error_msg());
        $this->assertIsObject($data);
        $this->assertObjectHasProperty('litcal_easter', $data);
        $this->assertIsArray($data->litcal_easter);

        foreach ($data->litcal_easter as $easter) {
            $this->assertIsObject($easter);
            $this->assertObjectHasProperty('gregorianEaster', $easter);
            $this->assertIsInt($easter->gregorianEaster);
            $this->assertObjectHasProperty('julianEaster', $easter);
            $this->assertIsInt($easter->julianEaster);
            $this->assertObjectHasProperty('westernJulianEaster', $easter);
            $this->assertIsInt($easter->westernJulianEaster);
            $this->assertObjectHasProperty('coinciding', $easter);
            $this->assertIsBool($easter->coinciding);
            $this->assertObjectHasProperty('gregorianDateString', $easter);
            $this->assertIsString($easter->gregorianDateString);
            $this->assertObjectHasProperty('julianDateString', $easter);
            $this->assertIsString($easter->julianDateString);
            $this->assertObjectHasProperty('westernJulianDateString', $easter);
            $this->assertIsString($easter->westernJulianDateString);
        }

        $this->assertObjectHasProperty('lastCoincidenceString', $data);
        $this->assertIsString($data->lastCoincidenceString);

        $this->assertObjectHasProperty('lastCoincidence', $data);
        // We don't worry about being limited to 32-bit integers,
        // because both locally and on GitHub Actions, we have 64-bit PHP
        $this->assertIsInt($data->lastCoincidence);
        $this->assertEquals(22983264000, $data->lastCoincidence);
    }
}
