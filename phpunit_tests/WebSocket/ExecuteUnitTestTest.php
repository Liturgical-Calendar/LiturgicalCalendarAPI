<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\WebSocket;

use PHPUnit\Framework\TestCase;

/**
 * Integration tests for the executeUnitTest WebSocket action.
 *
 * This is the only entry point that reaches src/Test/ — LitTestRunner is
 * instantiated only inside Health::executeUnitTest(), which is invoked via
 * the Ratchet WebSocket handler at public/LitCalTestServer.php. Driving it
 * here closes the 0% coverage gap on src/Test/ that was called out in
 * issue #589 (follow-up to #588).
 *
 * Flow: the action triggers an internal HTTP call back into the API server
 * (`/calendar/<year>?year_type=CIVIL` for the Vatican universal calendar),
 * then constructs a LitTestRunner around the decoded response and runs the
 * assertion for the requested year. The single reply frame carries
 * `type: success` or `type: error`.
 *
 * Skipped automatically when either ws://127.0.0.1:8082 or
 * http://127.0.0.1:8000 is unreachable, because the action needs both to
 * complete a roundtrip.
 */
final class ExecuteUnitTestTest extends TestCase
{
    private string $wsHost;
    private int $wsPort;
    private string $apiHost;
    private int $apiPort;

    protected function setUp(): void
    {
        $this->wsHost  = (string) ( $_ENV['WS_HOST'] ?? '127.0.0.1' );
        $this->wsPort  = (int) ( $_ENV['WS_PORT'] ?? 8082 );
        $this->apiHost = (string) ( $_ENV['API_HOST'] ?? '127.0.0.1' );
        $this->apiPort = (int) ( $_ENV['API_PORT'] ?? 8000 );

        $wsProbe = @stream_socket_client(
            sprintf('tcp://%s:%d', $this->wsHost, $this->wsPort),
            $_e,
            $_m,
            1.0
        );
        if ($wsProbe === false) {
            $this->markTestSkipped(
                sprintf('WS server not reachable on %s:%d — start it with `composer ws:start`.', $this->wsHost, $this->wsPort)
            );
        }
        fclose($wsProbe);

        $apiProbe = @stream_socket_client(
            sprintf('tcp://%s:%d', $this->apiHost, $this->apiPort),
            $_e,
            $_m,
            1.0
        );
        if ($apiProbe === false) {
            $this->markTestSkipped(
                sprintf(
                    'HTTP API not reachable on %s:%d — start it with `composer start`. '
                    . 'executeUnitTest needs both the WS server AND the HTTP API.',
                    $this->apiHost,
                    $this->apiPort
                )
            );
        }
        fclose($apiProbe);
    }

    /**
     * Connect, send an executeUnitTest payload, and read until a frame
     * arrives that looks like the response (carries a `type` key). 30s
     * read timeout because the WS handler dispatches an internal HTTP
     * call before replying, and pcov instrumentation slows that path on
     * the CI runner.
     *
     * @param array<string,mixed> $payload
     */
    private function executeUnitTest(array $payload): \stdClass
    {
        $client = WsTestClient::connect($this->wsHost, $this->wsPort, 30.0);
        try {
            $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
            $client->sendText($encoded);
            $reply = $client->receiveText();
        } finally {
            $client->close();
        }

        $decoded = json_decode($reply);
        $this->assertInstanceOf(\stdClass::class, $decoded, 'WS reply should decode as a JSON object. Got: ' . substr($reply, 0, 200));
        $this->assertObjectHasProperty('type', $decoded, 'WS reply missing `type` key. Body starts: ' . substr($reply, 0, 200));

        return $decoded;
    }

    public function testHappyPathRunsLitTestRunnerAndReportsSuccess(): void
    {
        // MaryMotherChurchTest has explicit assertions for years 2015–2019.
        // Year 2019 with the Vatican universal calendar exercises the
        // LitTestRunner happy path (eventExists AND hasExpectedDate).
        $reply = $this->executeUnitTest([
            'action'   => 'executeUnitTest',
            'category' => 'nationalcalendar',
            'calendar' => 'VA',
            'year'     => 2019,
            'test'     => 'MaryMotherChurchTest',
        ]);

        $this->assertSame('success', $reply->type, 'Expected success for year=2019. Got: ' . ( $reply->text ?? '(no text)' ));
        $this->assertObjectHasProperty('test', $reply);
        $this->assertSame('MaryMotherChurchTest', $reply->test);
        $this->assertObjectHasProperty('classes', $reply);
        $this->assertSame('.MaryMotherChurchTest.year-2019.test-valid', $reply->classes);
        $this->assertObjectHasProperty('text', $reply);
        $this->assertStringContainsString('MaryMotherChurchTest passed', $reply->text);
        // The success path does NOT attach jsonData — see Test/LitTestRunner::setMessage().
        $this->assertObjectNotHasProperty('jsonData', $reply);
    }

    public function testOutOfBoundsYearReportsError(): void
    {
        // 2020 has no explicit assertion in MaryMotherChurchTest.json
        // (assertions stop at 2019). TestsMap::retrieveAssertionForYear()
        // returns null and LitTestRunner::runTest() emits the
        // "Out of bounds error" branch. This is a config-time error, not
        // an assertion failure, so the reply does NOT embed the calendar
        // jsonData — that attachment is reserved for actual assertion
        // mismatches (see Test\LitTestRunnerTest::test...AssertionFailure
        // for the path that does carry jsonData).
        $reply = $this->executeUnitTest([
            'action'   => 'executeUnitTest',
            'category' => 'nationalcalendar',
            'calendar' => 'VA',
            'year'     => 2020,
            'test'     => 'MaryMotherChurchTest',
        ]);

        $this->assertSame('error', $reply->type);
        $this->assertObjectHasProperty('text', $reply);
        $this->assertStringContainsString('Out of bounds', $reply->text);
        $this->assertStringContainsString('MaryMotherChurchTest', $reply->text);
        $this->assertObjectHasProperty('classes', $reply);
        $this->assertSame('.MaryMotherChurchTest.year-2020.test-valid', $reply->classes);
        $this->assertObjectNotHasProperty('jsonData', $reply, 'Out-of-bounds (setup-time) errors should not carry the full calendar payload');
    }

    public function testUnknownTestNameReportsError(): void
    {
        // A test name that has no JSON definition in jsondata/tests/
        // makes the LitTestRunner constructor fall through to the
        // "could not read Test instructions" branch (file_exists() false).
        // Setup-time error: no jsonData attached.
        $reply = $this->executeUnitTest([
            'action'   => 'executeUnitTest',
            'category' => 'nationalcalendar',
            'calendar' => 'VA',
            'year'     => 2019,
            'test'     => 'NoSuchTest_Xyz_589',
        ]);

        $this->assertSame('error', $reply->type);
        $this->assertObjectHasProperty('text', $reply);
        $this->assertStringContainsString('could not read Test instructions', $reply->text);
        $this->assertStringContainsString('NoSuchTest_Xyz_589', $reply->text);
        $this->assertObjectHasProperty('test', $reply);
        $this->assertSame('NoSuchTest_Xyz_589', $reply->test);
        $this->assertObjectNotHasProperty('jsonData', $reply);
    }

    public function testDiocesanCalendarTestPassesAndExercisesDiocesanAppliesTo(): void
    {
        // rotter_nl_HLaurentiusdiakenenmartelaarpatroonvanhetbisdomTest.json
        // carries `applies_to: {diocesan_calendar: "rotter_nl"}`, which is the
        // mirror branch of PrayerUnbornTest's national_calendar arm in
        // TestItem::checkAppliesToExcludesConditions(). Together they cover
        // both single-calendar specifiers; the array-form properties
        // (national_calendars / diocesan_calendars) are still better suited
        // to direct unit tests since no committed test JSON uses them.
        //
        // Drives the diocesancalendar arm of buildCalendarRequestPath()
        // in Health.php for free.
        $reply = $this->executeUnitTest([
            'action'   => 'executeUnitTest',
            'category' => 'diocesancalendar',
            'calendar' => 'rotter_nl',
            'year'     => 2020,
            'test'     => 'rotter_nl_HLaurentiusdiakenenmartelaarpatroonvanhetbisdomTest',
        ]);

        $this->assertSame('success', $reply->type, 'Expected success. Got: ' . ( $reply->text ?? '(no text)' ));
        $this->assertObjectHasProperty('test', $reply);
        $this->assertSame('rotter_nl_HLaurentiusdiakenenmartelaarpatroonvanhetbisdomTest', $reply->test);
        $this->assertObjectHasProperty('text', $reply);
        $this->assertStringContainsString('passed for the Calendar rotter_nl', $reply->text);
    }

    public function testNationSpecificTestPassesAndExercisesAppliesTo(): void
    {
        // PrayerUnbornTest.json carries `year_since: 2011` and
        // `applies_to: {national_calendar: "US"}`, so loading it through
        // the WS handler hits TestItem's optional-property paths
        // (year_since branch + applies_to validation +
        // checkAppliesToExcludesConditions's `national_calendar` arm)
        // that MaryMotherChurchTest doesn't touch.
        //
        // 2020 falls in the test's explicit assertions and Jan 22 is a
        // Wednesday in 2020 (no Sunday/solemnity collision), so the
        // assertion compares against 2020-01-22.
        $reply = $this->executeUnitTest([
            'action'   => 'executeUnitTest',
            'category' => 'nationalcalendar',
            'calendar' => 'US',
            'year'     => 2020,
            'test'     => 'PrayerUnbornTest',
        ]);

        $this->assertSame('success', $reply->type, 'Expected success. Got: ' . ( $reply->text ?? '(no text)' ));
        $this->assertObjectHasProperty('test', $reply);
        $this->assertSame('PrayerUnbornTest', $reply->test);
        $this->assertObjectHasProperty('classes', $reply);
        $this->assertSame('.PrayerUnbornTest.year-2020.test-valid', $reply->classes);
        $this->assertObjectHasProperty('text', $reply);
        $this->assertStringContainsString('PrayerUnbornTest passed', $reply->text);
    }
}
