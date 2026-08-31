<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Routes\Readonly;

use LiturgicalCalendar\Tests\ApiTestCase;

/**
 * Integration tests for the /missals endpoint and its single-missal variant.
 *
 * Exercises MissalsHandler + the propriumdesanctis loading path that the
 * unit tests don't reach (the unit tests cover MissalMetadata / MissalsMap
 * shape, not the disk-IO + JSON Schema validation that runs on a real
 * request).
 */
final class MissalsTest extends ApiTestCase
{
    /**
     * The local dev server on this port may be a stale build that predates rite-aware
     * `/missals` (issue #953): before that feature, `/missals/ambrosian` did not exist as a
     * route, so the bare `/missals/{missal_id}` handler read `ambrosian` as an unknown
     * missal_id and answered 404. A permanently red suite is worse than one that skips
     * knowingly — the next genuine regression on this branch would be invisible in three
     * pre-existing failures — so this probes the rite-aware surface once and skips the three
     * tests below together when it is absent, rather than leaving them red against a server
     * that was never going to pass them. In CI the server runs this branch, so a 404 there is
     * a real regression and must fail loudly (see {@see \LiturgicalCalendar\Tests\ApiTestCase::runningInCi()},
     * inherited from the shared base rather than duplicated per test class).
     *
     * Deliberately a fresh probe request to `/missals/ambrosian`, not a reuse of whatever
     * response the calling test already received: the three callers hit three different
     * routes (`/missals/ambrosian`, `/missals`, `/missals/ambrosian/{missal_id}`), and only
     * the first is unambiguously "existed before #953 or not" — the bare `/missals` route
     * predates this feature too and still answers 200 either way, and a stale server's
     * multi-segment `/missals/ambrosian/{missal_id}` request fails with 405, not 404, which
     * would otherwise need its own case to recognise.
     */
    private function skipIfServerPredatesRiteAwareMissals(): void
    {
        if (self::runningInCi()) {
            return;
        }

        $response = self::$http->get('/missals/ambrosian', []);
        if ($response->getStatusCode() === 404) {
            $this->markTestSkipped(
                'Server on this port predates rite-aware /missals (issue #953): GET /missals/ambrosian '
                . 'returned 404, meaning the live server does not have this route yet. Restart the '
                . 'server from this branch (./stop-server.sh && composer start) to exercise these '
                . 'tests locally; CI always verifies them against the branch\'s own server.'
            );
        }
    }

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
        // committed under jsondata/sourcedata/rite/roman/missals/propriumdesanctis_1970/.
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

    /**
     * End-to-end coverage of the rite-scoped catalogue over a live HTTP request (issue #953).
     * The unit- and handler-level tests (MissalCatalogTest, MissalsRiteRoutingTest) already
     * exercise the routing and index logic in-process; this proves the same behaviour survives
     * the full Router + middleware pipeline + real disk IO.
     */
    public function testTheAmbrosianCatalogueIsReachableOverHttp(): void
    {
        $this->skipIfServerPredatesRiteAwareMissals();

        $response = self::$http->get('/missals/ambrosian', []);
        $this->assertSame(200, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), true);
        $this->assertIsArray($body);
        $ids = array_column($body['litcal_missals'], 'missal_id');
        $this->assertSame(['EDITIO_TYPICA_2024'], $ids);
    }

    public function testTheBareCatalogueAdvertisesTheCanonicalForm(): void
    {
        $this->skipIfServerPredatesRiteAwareMissals();

        $response = self::$http->get('/missals', []);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('/missals/roman', $response->getHeaderLine('Link'));
        $this->assertStringContainsString('rel="canonical"', $response->getHeaderLine('Link'));
    }

    public function testTheAmbrosianSanctoraleRowsAreReachable(): void
    {
        $this->skipIfServerPredatesRiteAwareMissals();

        $response = self::$http->get('/missals/ambrosian/EDITIO_TYPICA_2024', []);
        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertIsArray($body);
        $this->assertNotEmpty($body, 'the Ambrosian sanctorale rows must be served');
    }
}
