<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\WebSocket;

use PHPUnit\Framework\TestCase;

/**
 * Integration tests for the validateCalendar WebSocket action.
 *
 * `validateCalendar` is one of the three async actions on the Ratchet
 * WebSocket handler in src/Health.php. It fetches a calendar from the
 * HTTP API (`Route::CALENDAR->path() . /<year>?year_type=CIVIL` or the
 * `/nation/<X>/<year>` / `/diocese/<X>/<year>` variants), then runs
 * format-specific validation against the appropriate schema:
 *
 *   - JSON → validateDataAgainstSchema against `LitSchema::LITCAL`
 *   - XML  → DOMDocument::schemaValidate against `LiturgicalCalendar.xsd`
 *   - ICS  → Sabre\VObject\Reader + Document::validate()
 *   - YML  → Symfony YAML parser + JSON-shaped schema validation
 *
 * Each happy path emits three messages over the WS connection — a
 * `file-exists` success, a `json-valid` (format-decoded) success, and a
 * `schema-valid` success. Errors short-circuit at the failing step.
 *
 * Together with phpunit_tests/WebSocket/ExecuteUnitTestTest.php +
 * ExecuteValidationTest.php this covers the three executeXxx /
 * validateXxx async branches in Health.php that were at 0% before
 * issue #589's follow-ups.
 *
 * Skipped automatically when either ws://127.0.0.1:8082 or
 * http://127.0.0.1:8000 is unreachable — the action needs both.
 */
final class ValidateCalendarTest extends TestCase
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

        $wsProbe = @stream_socket_client(sprintf('tcp://%s:%d', $this->wsHost, $this->wsPort), $_e, $_m, 1.0);
        if ($wsProbe === false) {
            $this->markTestSkipped(
                sprintf('WS server not reachable on %s:%d — start it with `composer ws:start`.', $this->wsHost, $this->wsPort)
            );
        }
        fclose($wsProbe);

        $apiProbe = @stream_socket_client(sprintf('tcp://%s:%d', $this->apiHost, $this->apiPort), $_e, $_m, 1.0);
        if ($apiProbe === false) {
            $this->markTestSkipped(
                sprintf('HTTP API not reachable on %s:%d — start it with `composer start`. validateCalendar needs both.', $this->apiHost, $this->apiPort)
            );
        }
        fclose($apiProbe);
    }

    /**
     * Send a payload, then keep reading frames until either `$expectedFrames`
     * arrive or the socket times out — whichever comes first. Unlike the
     * unary executeUnitTest action, validateCalendar emits a sequence of
     * messages (file-exists → json-valid → schema-valid), and the WS
     * handler never closes after the last one — we have to time out.
     *
     * @param array<string,mixed> $payload
     * @return array<int,\stdClass>
     */
    private function validateCalendar(array $payload, int $expectedFrames): array
    {
        $client = WsTestClient::connect($this->wsHost, $this->wsPort, 30.0);
        try {
            $client->sendText(json_encode($payload, JSON_THROW_ON_ERROR));

            $frames = [];
            for ($i = 0; $i < $expectedFrames; $i++) {
                $reply   = $client->receiveText();
                $decoded = json_decode($reply);
                $this->assertInstanceOf(\stdClass::class, $decoded, "Frame {$i} did not decode as JSON: " . substr($reply, 0, 200));
                $this->assertObjectHasProperty('type', $decoded, "Frame {$i} missing `type`");
                $frames[] = $decoded;
            }
            return $frames;
        } finally {
            $client->close();
        }
    }

    /**
     * Assert that the given frame is a successful `<phase>` message for
     * the Vatican universal calendar in the given year.
     */
    private function assertPhase(\stdClass $frame, string $phase, int $year, string $calendar = 'VA'): void
    {
        $this->assertSame('success', $frame->type, "Expected success on {$phase}, got error: " . ( $frame->text ?? '?' ));
        $this->assertObjectHasProperty('classes', $frame);
        $this->assertSame(".calendar-{$calendar}.{$phase}.year-{$year}", $frame->classes);
    }

    public function testJsonHappyPathEmitsThreeSuccessMessages(): void
    {
        $frames = $this->validateCalendar([
            'action'       => 'validateCalendar',
            'calendar'     => 'VA',
            'year'         => 2020,
            'category'     => 'nationalcalendar',
            'responsetype' => 'JSON',
        ], 3);

        $this->assertPhase($frames[0], 'file-exists', 2020);
        $this->assertPhase($frames[1], 'json-valid', 2020);
        $this->assertStringContainsString('decoded as JSON', $frames[1]->text);
        $this->assertPhase($frames[2], 'schema-valid', 2020);
        $this->assertStringContainsString('validated against the Schema', $frames[2]->text);
    }

    public function testXmlHappyPathEmitsThreeSuccessMessages(): void
    {
        $frames = $this->validateCalendar([
            'action'       => 'validateCalendar',
            'calendar'     => 'VA',
            'year'         => 2020,
            'category'     => 'nationalcalendar',
            'responsetype' => 'XML',
        ], 3);

        $this->assertPhase($frames[0], 'file-exists', 2020);
        $this->assertPhase($frames[1], 'json-valid', 2020);
        $this->assertStringContainsString('decoded as XML', $frames[1]->text);
        $this->assertPhase($frames[2], 'schema-valid', 2020);
        // The XML schema validator embeds the .xsd path in the success text.
        $this->assertStringContainsString('LiturgicalCalendar.xsd', $frames[2]->text);
    }

    public function testIcsHappyPathEmitsThreeSuccessMessages(): void
    {
        $frames = $this->validateCalendar([
            'action'       => 'validateCalendar',
            'calendar'     => 'VA',
            'year'         => 2020,
            'category'     => 'nationalcalendar',
            'responsetype' => 'ICS',
        ], 3);

        $this->assertPhase($frames[0], 'file-exists', 2020);
        $this->assertPhase($frames[1], 'json-valid', 2020);
        $this->assertStringContainsString('decoded as ICS', $frames[1]->text);
        $this->assertPhase($frames[2], 'schema-valid', 2020);
        // The iCalendar validator success text mentions RFC 5545.
        $this->assertStringContainsString('rfc5545', $frames[2]->text);
    }

    public function testYmlHappyPathEmitsThreeSuccessMessages(): void
    {
        $frames = $this->validateCalendar([
            'action'       => 'validateCalendar',
            'calendar'     => 'VA',
            'year'         => 2020,
            'category'     => 'nationalcalendar',
            'responsetype' => 'YML',
        ], 3);

        $this->assertPhase($frames[0], 'file-exists', 2020);
        $this->assertPhase($frames[1], 'json-valid', 2020);
        $this->assertStringContainsString('decoded as YAML', $frames[1]->text);
        $this->assertPhase($frames[2], 'schema-valid', 2020);
    }

    public function testUnknownCalendarFailsAtJsonValidPhase(): void
    {
        // The first message is always the "file-exists" success because
        // the WS handler reports any non-error HTTP response (the 404
        // page) as a "file" that exists. The body, however, isn't a
        // valid LitCal JSON document, so the json-valid phase fails.
        $frames = $this->validateCalendar([
            'action'       => 'validateCalendar',
            'calendar'     => 'NONEXISTENT_NATION_589',
            'year'         => 2020,
            'category'     => 'nationalcalendar',
            'responsetype' => 'JSON',
        ], 2);

        $this->assertSame('success', $frames[0]->type);
        $this->assertSame('.calendar-NONEXISTENT_NATION_589.file-exists.year-2020', $frames[0]->classes);
        $this->assertSame('error', $frames[1]->type);
        $this->assertSame('.calendar-NONEXISTENT_NATION_589.json-valid.year-2020', $frames[1]->classes);
        $this->assertObjectHasProperty('responsetype', $frames[1]);
        $this->assertSame('JSON', $frames[1]->responsetype);
    }
}
