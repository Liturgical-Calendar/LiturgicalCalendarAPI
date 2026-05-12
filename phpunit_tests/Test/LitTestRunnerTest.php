<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Test;

use LiturgicalCalendar\Api\Router;
use LiturgicalCalendar\Api\Test\LitTestRunner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * In-process unit tests for LitTestRunner.
 *
 * Companion to phpunit_tests/WebSocket/ExecuteUnitTestTest.php — the
 * WS-driven tests cover the happy paths and the setup-time errors that
 * committed test JSONs can reach. The assertion-failure branches (event
 * present when it should not be, date mismatch, event missing when it
 * should be present) are awkward to trigger over WS because the calendar
 * always reflects the current rules; here we synthesise mismatched
 * data directly so the failure paths — and the `jsonData` attachment
 * that goes with them — are exercised deterministically.
 *
 * Reads test instructions from the committed jsondata/tests/ folder via
 * LitTestRunner's constructor (no fixtures needed), then feeds in
 * hand-built calendar payloads.
 */
#[CoversClass(LitTestRunner::class)]
final class LitTestRunnerTest extends TestCase
{
    private static string $savedApiFilePath = '';

    public static function setUpBeforeClass(): void
    {
        // LitTestRunner's constructor reads JsonData::TESTS_FOLDER->path(),
        // which composes Router::$apiFilePath as a prefix. The static is
        // typed and uninitialised when the production bootstrap hasn't
        // run, so we pin it to the project root here for the duration
        // of the class and restore it afterwards. Mirrors the pattern
        // in phpunit_tests/Handlers/AbstractHandlerTestCase.
        self::$savedApiFilePath = isset(Router::$apiFilePath) ? Router::$apiFilePath : '';
        Router::$apiFilePath    = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR;
    }

    public static function tearDownAfterClass(): void
    {
        Router::$apiFilePath = self::$savedApiFilePath;
    }

    /**
     * Build a minimal calendar response with the given litcal events and year.
     *
     * The LitTestRunner only reads `settings->year`, optional
     * `settings->national_calendar` / `settings->diocesan_calendar`, and
     * the `litcal` array. Anything else gets passed through into the
     * `jsonData` attachment but isn't inspected.
     *
     * @param array<int,\stdClass> $litcal
     */
    private function calendarPayload(int $year, array $litcal): \stdClass
    {
        return (object) [
            'settings' => (object) ['year' => $year],
            'litcal'   => $litcal,
        ];
    }

    public function testEventExistsAndHasExpectedDateProducesSuccess(): void
    {
        // 2019: MaryMotherChurchTest expects MaryMotherChurch on 2019-06-10.
        $data   = $this->calendarPayload(2019, [
            (object) ['event_key' => 'MaryMotherChurch', 'date' => '2019-06-10T00:00:00+00:00'],
        ]);
        $runner = new LitTestRunner('MaryMotherChurchTest', $data);
        $this->assertTrue($runner->isReady());
        $runner->runTest();

        $msg = $runner->getMessage();
        $this->assertSame('success', $msg->type);
        $this->assertSame('MaryMotherChurchTest', $msg->test);
        $this->assertStringContainsString('expected_value = 2019-06-10', $msg->text);
        $this->assertObjectNotHasProperty('jsonData', $msg);
    }

    public function testEventExistsButHasWrongDateAttachesJsonData(): void
    {
        // Same year (2019) but date deliberately off-by-one. Expected
        // is 2019-06-10; provide 2019-06-11. LitTestRunner emits the
        // "expected to have a date value of X, instead it had Y" branch
        // and attaches jsonData.
        $data   = $this->calendarPayload(2019, [
            (object) ['event_key' => 'MaryMotherChurch', 'date' => '2019-06-11T00:00:00+00:00'],
        ]);
        $runner = new LitTestRunner('MaryMotherChurchTest', $data);
        $runner->runTest();

        $msg = $runner->getMessage();
        $this->assertSame('error', $msg->type);
        $this->assertStringContainsString('2019-06-10', $msg->text);
        $this->assertStringContainsString('2019-06-11', $msg->text);
        $this->assertObjectHasProperty(
            'jsonData',
            $msg,
            'Assertion-failure errors should carry the full calendar payload'
        );
        $this->assertSame($data, $msg->jsonData);
    }

    public function testEventExpectedButMissingAttachesJsonData(): void
    {
        // No MaryMotherChurch event at all in 2019's litcal — the runner
        // hits the "should exist, instead it was not found" branch.
        $data   = $this->calendarPayload(2019, [
            (object) ['event_key' => 'SomeOtherEvent', 'date' => '2019-06-10T00:00:00+00:00'],
        ]);
        $runner = new LitTestRunner('MaryMotherChurchTest', $data);
        $runner->runTest();

        $msg = $runner->getMessage();
        $this->assertSame('error', $msg->type);
        $this->assertStringContainsString('should exist, instead it was not found', $msg->text);
        $this->assertObjectHasProperty('jsonData', $msg);
    }

    public function testEventShouldNotExistButFoundAttachesJsonData(): void
    {
        // 2017 is in MaryMotherChurchTest's "eventNotExists" zone (the
        // memorial wasn't yet instituted). Putting MaryMotherChurch in
        // anyway should fail the assertion and attach jsonData.
        $data   = $this->calendarPayload(2017, [
            (object) ['event_key' => 'MaryMotherChurch', 'date' => '2017-06-05T00:00:00+00:00'],
        ]);
        $runner = new LitTestRunner('MaryMotherChurchTest', $data);
        $runner->runTest();

        $msg = $runner->getMessage();
        $this->assertSame('error', $msg->type);
        $this->assertStringContainsString('should not exist', $msg->text);
        $this->assertObjectHasProperty('jsonData', $msg);
    }

    public function testOutOfBoundsYearProducesErrorWithoutJsonData(): void
    {
        // MaryMotherChurchTest has assertions for 2015–2019 only.
        // Year 2099 is out of bounds → setup-time error, no jsonData.
        $data   = $this->calendarPayload(2099, [
            (object) ['event_key' => 'MaryMotherChurch', 'date' => '2099-05-25T00:00:00+00:00'],
        ]);
        $runner = new LitTestRunner('MaryMotherChurchTest', $data);
        $runner->runTest();

        $msg = $runner->getMessage();
        $this->assertSame('error', $msg->type);
        $this->assertStringContainsString('Out of bounds', $msg->text);
        $this->assertObjectNotHasProperty('jsonData', $msg, 'Setup errors should not carry the full calendar payload');
    }

    public function testUnknownTestNameProducesErrorWithoutJsonData(): void
    {
        $data   = $this->calendarPayload(2019, []);
        $runner = new LitTestRunner('Definitely_No_Such_Test_Definition_Xyz', $data);
        $this->assertFalse($runner->isReady());

        $msg = $runner->getMessage();
        $this->assertSame('error', $msg->type);
        $this->assertStringContainsString('could not read Test instructions', $msg->text);
        $this->assertObjectNotHasProperty('jsonData', $msg);
    }

    public function testGetMessageWithoutRunningProducesFallbackError(): void
    {
        // Constructing a ready runner without calling runTest() leaves
        // ::$Message null; getMessage() falls through to the fallback
        // "unknown error" path. Setup-style, no jsonData.
        $data   = $this->calendarPayload(2019, [
            (object) ['event_key' => 'MaryMotherChurch', 'date' => '2019-06-10T00:00:00+00:00'],
        ]);
        $runner = new LitTestRunner('MaryMotherChurchTest', $data);
        $this->assertTrue($runner->isReady());

        $msg = $runner->getMessage();
        $this->assertSame('error', $msg->type);
        $this->assertStringContainsString('unknown error', $msg->text);
        $this->assertObjectNotHasProperty('jsonData', $msg);
    }

    public function testNationalCalendarSettingsAppearInMessageText(): void
    {
        // When settings->national_calendar is present, the success
        // message text mentions "the national calendar of <X>". Exercises
        // LitTestRunner::getCalendarType() + ::getCalendarName().
        $data                              = $this->calendarPayload(2019, [
            (object) ['event_key' => 'MaryMotherChurch', 'date' => '2019-06-10T00:00:00+00:00'],
        ]);
        $data->settings->national_calendar = 'US';

        $runner = new LitTestRunner('MaryMotherChurchTest', $data);
        $runner->runTest();

        $msg = $runner->getMessage();
        $this->assertSame('success', $msg->type);
        $this->assertStringContainsString('Calendar US', $msg->text);
    }

    public function testDiocesanCalendarSettingsAppearInMessageText(): void
    {
        $data                              = $this->calendarPayload(2019, [
            (object) ['event_key' => 'MaryMotherChurch', 'date' => '2019-06-10T00:00:00+00:00'],
        ]);
        $data->settings->diocesan_calendar = 'rotter_nl';

        $runner = new LitTestRunner('MaryMotherChurchTest', $data);
        $runner->runTest();

        $msg = $runner->getMessage();
        $this->assertSame('success', $msg->type);
        $this->assertStringContainsString('Calendar rotter_nl', $msg->text);
    }
}
