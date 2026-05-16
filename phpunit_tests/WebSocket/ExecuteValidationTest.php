<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\WebSocket;

use PHPUnit\Framework\TestCase;

/**
 * Integration tests for the executeValidation WebSocket action.
 *
 * `executeValidation` is the second of the three async actions on the
 * Ratchet handler in src/Health.php (alongside `executeUnitTest` and
 * `validateCalendar`). It validates that:
 *
 *   - a JSON file on disk (`category: 'sourceDataCheck'`,
 *     `sourceFile: <identifier>`) exists, is JSON, and conforms to
 *     its JSON Schema; OR
 *   - a folder of i18n JSON files (`sourceFolder: <identifier>`) all
 *     decode and validate; OR
 *   - a resource served by the API (`category: 'resourceDataCheck'`,
 *     `sourceFile: <URL>`) decodes and matches a schema.
 *
 * Each scenario emits a sequence of frames (`file-exists`,
 * `json-valid`, `schema-valid`, plus a final `validation` summary in
 * the folder case). The handler short-circuits at the failing step on
 * error. Three of the four scenarios below exercise stable, committed
 * paths in jsondata/sourcedata/.
 *
 * Skipped automatically when either ws://127.0.0.1:8082 or
 * http://127.0.0.1:8000 is unreachable — the WS server may fan out to
 * the HTTP API for resourceDataCheck.
 */
final class ExecuteValidationTest extends TestCase
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
                sprintf('HTTP API not reachable on %s:%d — start it with `composer start`.', $this->apiHost, $this->apiPort)
            );
        }
        fclose($apiProbe);
    }

    /**
     * Read up to `$expectedFrames` frames or until the socket times out.
     *
     * @param array<string,mixed> $payload
     * @return array<int,\stdClass>
     */
    private function executeValidation(array $payload, int $expectedFrames): array
    {
        $client = WsTestClient::connect($this->wsHost, $this->wsPort, 30.0);
        try {
            $client->sendText(json_encode($payload, JSON_THROW_ON_ERROR));

            $frames = [];
            for ($i = 0; $i < $expectedFrames; $i++) {
                $reply   = $client->receiveText();
                $decoded = json_decode($reply, false, 512, JSON_THROW_ON_ERROR);
                $this->assertInstanceOf(\stdClass::class, $decoded, "Frame {$i} did not decode as JSON: " . substr($reply, 0, 200));
                $this->assertObjectHasProperty('type', $decoded, "Frame {$i} missing `type`");
                $frames[] = $decoded;
            }
            return $frames;
        } finally {
            $client->close();
        }
    }

    public function testSourceDataCheckPropriumDeSanctis1970IsValid(): void
    {
        // The 1970 Editio Typica's Sanctorale ships in the repo and is
        // schema-valid by construction. Exercises the
        // proprium-de-sanctis-<year> branch of executeValidation's
        // sourceDataCheck path (including the regex match on the
        // `validate` field and the RomanMissal::getSanctoraleFileName
        // resolution).
        $frames = $this->executeValidation([
            'action'     => 'executeValidation',
            'category'   => 'sourceDataCheck',
            'validate'   => 'proprium-de-sanctis-1970',
            'sourceFile' => 'proprium-de-sanctis-1970',
        ], 3);

        $this->assertSame('success', $frames[0]->type);
        $this->assertSame('.proprium-de-sanctis-1970.file-exists', $frames[0]->classes);
        $this->assertSame('success', $frames[1]->type);
        $this->assertSame('.proprium-de-sanctis-1970.json-valid', $frames[1]->classes);
        $this->assertSame('success', $frames[2]->type);
        $this->assertSame('.proprium-de-sanctis-1970.schema-valid', $frames[2]->classes);
    }

    public function testResourceDataCheckMetadataDecodesAsJson(): void
    {
        // resourceDataCheck against the /metadata endpoint hits the
        // schema-lookup arm that the sourceDataCheck path doesn't.
        // (The third frame ends up as a schema-detection error in this
        // setup since the validate-key→schema map doesn't expose the
        // metadata schema by URL — but the first two frames are stable
        // and exercise the resourceDataCheck branch deterministically.)
        $frames = $this->executeValidation([
            'action'     => 'executeValidation',
            'category'   => 'resourceDataCheck',
            'validate'   => 'metadata',
            'sourceFile' => sprintf('http://%s:%d/metadata', $this->apiHost, $this->apiPort),
        ], 2);

        $this->assertSame('success', $frames[0]->type);
        $this->assertSame('.metadata.file-exists', $frames[0]->classes);
        $this->assertStringContainsString('/metadata', $frames[0]->text);

        $this->assertSame('success', $frames[1]->type);
        $this->assertSame('.metadata.json-valid', $frames[1]->classes);
        $this->assertStringContainsString('decoded as JSON', $frames[1]->text);
    }

    public function testMissingSourceFileReturnsEchobotValidationError(): void
    {
        // executeValidation requires `sourceFile` (or `sourceFolder`).
        // Omitting both should be rejected by validateMessageProperties()
        // before the action dispatches, so the reply is the standard
        // "echobot"-typed validation-error envelope rather than a
        // sequence of per-phase frames.
        $frames = $this->executeValidation([
            'action'   => 'executeValidation',
            'category' => 'resourceDataCheck',
            'validate' => 'metadata',
        ], 1);

        $this->assertSame('echobot', $frames[0]->type);
        $this->assertObjectHasProperty('errorMsg', $frames[0]);
        $this->assertNotEmpty($frames[0]->errorMsg);
    }
}
