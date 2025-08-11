<?php

namespace LiturgicalCalendar\Tests;

use LiturgicalCalendar\Tests\ApiTestCase;

final class EasterTest extends ApiTestCase
{
    public function testGetEasterReturnsJson(): void
    {
        $response = $this->http->get('/easter');
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode((string) $response->getBody());
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
        $this->assertIsInt($data->lastCoincidence);
        $this->assertEquals(22983264000, $data->lastCoincidence);
    }
}
