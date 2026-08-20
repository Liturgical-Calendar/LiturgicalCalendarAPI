<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests;

use LiturgicalCalendar\Api\Enum\ProtocolErrorCode;
use LiturgicalCalendar\Api\Health;
use LiturgicalCalendar\Api\Router;
use LiturgicalCalendar\Tests\Support\HealthQueueIsolationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ratchet\ConnectionInterface;

/**
 * What `Health` does with a message it cannot act on.
 *
 * Every test here drives `onMessage()` with a real JSON string. The private validator is never
 * invoked directly: #825's lesson was that an emitter can be correct and tested while nothing routes
 * to it, and a test that calls the right function directly passes against exactly that bug.
 */
#[CoversClass(Health::class)]
final class HealthProtocolValidationTest extends TestCase
{
    use HealthQueueIsolationTrait;

    public static function setUpBeforeClass(): void
    {
        Router::getApiPaths();
    }

    private static function createStubConnection(int $resourceId = 1)
    {
        return new class ($resourceId) implements ConnectionInterface {
            /** @var list<string> */
            public array $sent = [];

            public function __construct(public int $resourceId)
            {
            }

            public function send($data)
            {
                $this->sent[] = (string) $data;

                return $this;
            }

            public function close()
            {
            }
        };
    }

    /**
     * Send one raw message and return the frames it produced.
     *
     * @return list<\stdClass>
     */
    private function frames(string $raw): array
    {
        $conn = self::createStubConnection();
        ob_start();
        $this->newHealth()->onMessage($conn, $raw);
        ob_end_clean();

        return array_map(static fn (string $f): \stdClass => json_decode($f), $conn->sent);
    }

    public function testARejectionIsATypedProtocolErrorRatherThanAnEchobot(): void
    {
        $frames = $this->frames((string) json_encode(['action' => 'validateSource', 'target' => ['id' => 'nation:roman:ZZ']]));

        self::assertCount(1, $frames);
        self::assertSame('protocolError', $frames[0]->type, 'rejections are typed now, not echoes');
        self::assertSame(ProtocolErrorCode::UNKNOWN_TARGET_ID->value, $frames[0]->errorCode);
        self::assertSame('Unknown validation target: nation:roman:ZZ', $frames[0]->text, 'the prose is unchanged; only the envelope is typed');
    }

    /**
     * The reason a message was refused must reach the client, not only the server log.
     *
     * `errorCode` classifies the failure but does not describe it: `INVALID_JSON` cannot say
     * "Control character error", which is the part that tells whoever is debugging the client what
     * to look at. An earlier revision of this branch sent the reason to stdout and echoed only the
     * raw message back, which is why this is asserted rather than assumed.
     *
     * Asserted against the literal string `'Syntax error'`, not a fresh `json_last_error_msg()` call
     * made here: `frames()` json_decodes the server's (valid JSON) *response* after `onMessage()`
     * returns, and that decode succeeds, so by the time this method runs, the global JSON error state
     * has already been reset to `JSON_ERROR_NONE` — a call here would read "No error", not the
     * decode failure being asserted against. `'Syntax error'` is what PHP 8.4 actually produces for
     * this malformed input, confirmed by running this test.
     */
    public function testTheReasonForARefusalReachesTheClientAndNotOnlyTheLog(): void
    {
        $frames = $this->frames('{ this is not json');

        self::assertSame('protocolError', $frames[0]->type);
        self::assertSame(ProtocolErrorCode::INVALID_JSON->value, $frames[0]->errorCode);
        self::assertStringContainsString(
            'Syntax error',
            (string) $frames[0]->text,
            'the specific decode failure has no errorCode equivalent, so it has to be in the text'
        );
    }
}
