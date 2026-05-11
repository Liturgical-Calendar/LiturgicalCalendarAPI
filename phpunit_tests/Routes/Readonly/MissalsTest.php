<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Routes\Readonly;

use LiturgicalCalendar\Tests\ApiTestCase;

/**
 * End-to-end tests for the /missals endpoint and its single-missal variant.
 *
 * Exercises MissalsHandler + the propriumdesanctis loading path that the
 * unit tests don't reach (the unit tests cover MissalMetadata / MissalsMap
 * shape, not the disk-IO + JSON Schema validation that runs on a real
 * request).
 */
final class MissalsTest extends ApiTestCase
{
    public function testListReturnsJsonCollection(): void
    {
        $response = self::$http->get('/missals', []);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringStartsWith('application/json', $response->getHeaderLine('Content-Type'));

        $data = json_decode((string) $response->getBody());
        $this->assertSame(JSON_ERROR_NONE, json_last_error(), json_last_error_msg());
        $this->assertIsObject($data);
        $this->assertObjectHasProperty('litcal_missals', $data);
        $this->assertIsArray($data->litcal_missals);
        $this->assertNotEmpty($data->litcal_missals);

        $missalIds = [];
        foreach ($data->litcal_missals as $missal) {
            $this->assertIsObject($missal);
            $this->assertObjectHasProperty('missal_id', $missal);
            $this->assertObjectHasProperty('name', $missal);
            $this->assertObjectHasProperty('region', $missal);
            $this->assertObjectHasProperty('locales', $missal);
            $this->assertObjectHasProperty('year_published', $missal);
            $this->assertObjectHasProperty('year_limits', $missal);
            $this->assertIsString($missal->missal_id);
            $this->assertIsArray($missal->locales);
            $this->assertIsInt($missal->year_published);
            $missalIds[] = $missal->missal_id;
        }

        $this->assertContains('EDITIO_TYPICA_1970', $missalIds, 'Editio Typica 1970 should always be listed');
    }

    public function testListSupportsRegionFilter(): void
    {
        $response = self::$http->get('/missals', ['query' => ['region' => 'VA']]);
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode((string) $response->getBody());
        $this->assertIsObject($data);
        $this->assertIsArray($data->litcal_missals);
        foreach ($data->litcal_missals as $m) {
            $this->assertSame('VA', $m->region, 'Region filter should restrict to VA');
        }
    }

    public function testSingleMissalReturnsPropriumDeSanctis(): void
    {
        // Editio Typica 1970 is the canonical missal; its propriumdesanctis is
        // committed under jsondata/sourcedata/missals/propriumdesanctis_1970/.
        $response = self::$http->get('/missals/EDITIO_TYPICA_1970', []);
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode((string) $response->getBody());
        $this->assertIsArray($data, 'Single-missal response should be the proprium array');
        $this->assertNotEmpty($data);

        $first = $data[0];
        $this->assertIsObject($first);
        $this->assertObjectHasProperty('event_key', $first);
        $this->assertObjectHasProperty('grade', $first);
        $this->assertObjectHasProperty('color', $first);
        $this->assertIsInt($first->grade);
        $this->assertIsArray($first->color);
    }

    public function testUnknownMissalIdReturnsClientError(): void
    {
        $response = self::$http->get('/missals/NOT_A_REAL_MISSAL_ID', []);
        $status   = $response->getStatusCode();
        $this->assertGreaterThanOrEqual(400, $status, "Expected 4xx for unknown missal_id, got $status");
        $this->assertLessThan(500, $status, "Expected 4xx (not 5xx) for unknown missal_id, got $status");
    }
}
