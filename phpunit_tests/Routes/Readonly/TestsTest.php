<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Routes\Readonly;

use LiturgicalCalendar\Tests\ApiTestCase;

/**
 * End-to-end tests for the /tests endpoint and its single-resource variant.
 *
 * Serves the catalog of test definitions used by the UnitTestInterface
 * websocket runner. The HTTP endpoint itself doesn't invoke LitTestRunner —
 * that path lives in Health.php / the websocket harness — but the JSON
 * payload + content negotiation surface here was previously uncovered.
 */
final class TestsTest extends ApiTestCase
{
    public function testListReturnsJsonCollection(): void
    {
        $response = self::$http->get('/tests', []);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringStartsWith('application/json', $response->getHeaderLine('Content-Type'));

        $data = json_decode((string) $response->getBody());
        $this->assertSame(JSON_ERROR_NONE, json_last_error(), json_last_error_msg());
        $this->assertIsObject($data);
        $this->assertObjectHasProperty('litcal_tests', $data);
        $this->assertIsArray($data->litcal_tests);
        $this->assertNotEmpty($data->litcal_tests, 'Expected at least one test definition');

        foreach ($data->litcal_tests as $test) {
            $this->assertIsObject($test);
            $this->assertObjectHasProperty('name', $test);
            $this->assertObjectHasProperty('event_key', $test);
            $this->assertObjectHasProperty('test_type', $test);
            $this->assertObjectHasProperty('assertions', $test);
            $this->assertIsString($test->name);
            $this->assertIsString($test->event_key);
            $this->assertIsString($test->test_type);
            $this->assertIsArray($test->assertions);
        }
    }

    public function testSingleTestByNameReturnsDefinition(): void
    {
        // MaryMotherChurchTest is committed to jsondata/tests/ and is a stable
        // pick (added 2018 by decree, won't move).
        $response = self::$http->get('/tests/MaryMotherChurchTest', []);
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode((string) $response->getBody());
        $this->assertIsObject($data);
        $this->assertSame('MaryMotherChurchTest', $data->name);
        $this->assertSame('MaryMotherChurch', $data->event_key);
        $this->assertIsArray($data->assertions);
        $this->assertNotEmpty($data->assertions);

        foreach ($data->assertions as $a) {
            $this->assertObjectHasProperty('year', $a);
            $this->assertObjectHasProperty('assert', $a);
            $this->assertIsInt($a->year);
        }
    }

    public function testUnknownTestNameReturns404(): void
    {
        $response = self::$http->get('/tests/NoSuchTestName_Xyz', []);
        $this->assertSame(404, $response->getStatusCode());
    }

    public function testListRespectsYamlAcceptHeader(): void
    {
        $response = self::$http->get('/tests', ['headers' => ['Accept' => 'application/yaml']]);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringStartsWith('application/yaml', $response->getHeaderLine('Content-Type'));

        // We don't depend on ext-yaml in the test process (it's only loaded
        // server-side). The header confirms negotiation; a body shape check
        // confirms the YAML encoder actually ran.
        $body = (string) $response->getBody();
        $this->assertStringContainsString('litcal_tests:', $body, 'YAML body should contain the top-level key');
    }
}
