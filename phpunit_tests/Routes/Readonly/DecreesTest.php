<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Routes\Readonly;

use LiturgicalCalendar\Tests\ApiTestCase;

/**
 * Integration tests for the /decrees endpoint and its single-decree variant.
 *
 * The Decrees endpoint serves Dicastery for Divine Worship rulings that
 * modify the liturgical calendar (e.g. adding Mary Mother of the Church
 * 2018, upgrading St Mary Magdalene 2016). DecreesHandler loads these from
 * jsondata/sourcedata/decrees/decrees.json + applies the requested locale's
 * i18n strings.
 */
final class DecreesTest extends ApiTestCase
{
    public function testListReturnsJsonCollection(): void
    {
        $response = self::$http->get('/decrees', []);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringStartsWith('application/json', $response->getHeaderLine('Content-Type'));

        $data = json_decode((string) $response->getBody());
        $this->assertSame(JSON_ERROR_NONE, json_last_error(), json_last_error_msg());
        $this->assertIsObject($data);
        $this->assertObjectHasProperty('litcal_decrees', $data);
        $this->assertIsArray($data->litcal_decrees);
        $this->assertNotEmpty($data->litcal_decrees);

        $decreeIds = [];
        foreach ($data->litcal_decrees as $d) {
            $this->assertIsObject($d);
            $this->assertObjectHasProperty('decree_id', $d);
            $this->assertObjectHasProperty('decree_date', $d);
            $this->assertObjectHasProperty('decree_protocol', $d);
            $this->assertObjectHasProperty('description', $d);
            $this->assertIsString($d->decree_id);
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $d->decree_date);
            $decreeIds[] = $d->decree_id;
        }

        // StMaryMagdalene_Upgrade is committed and stable; safe to anchor on.
        $this->assertContains('StMaryMagdalene_Upgrade', $decreeIds);
    }

    public function testSingleDecreeReturnsDefinition(): void
    {
        $response = self::$http->get('/decrees/StMaryMagdalene_Upgrade', []);
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode((string) $response->getBody());
        $this->assertIsObject($data);
        $this->assertSame('StMaryMagdalene_Upgrade', $data->decree_id);
        $this->assertObjectHasProperty('decree_date', $data);
        $this->assertObjectHasProperty('decree_protocol', $data);
        $this->assertObjectHasProperty('description', $data);
    }

    public function testUnknownDecreeIdReturnsClientError(): void
    {
        $response = self::$http->get('/decrees/NOT_A_REAL_DECREE', []);
        $status   = $response->getStatusCode();
        $this->assertGreaterThanOrEqual(400, $status);
        $this->assertLessThan(500, $status);
    }

    public function testListHonoursLocaleHeader(): void
    {
        // Latin is always available since decrees are issued in Latin. Asking
        // for la-VA should succeed and return Latin-localised description text.
        $response = self::$http->get('/decrees', ['headers' => ['Accept-Language' => 'la-VA']]);
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode((string) $response->getBody());
        $this->assertIsObject($data);
        $this->assertIsArray($data->litcal_decrees);
        $this->assertNotEmpty($data->litcal_decrees);
    }
}
