<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Http\Logs;

use LiturgicalCalendar\Api\Http\Logs\RequestResponseProcessor;
use Monolog\Level;
use Monolog\LogRecord;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RequestResponseProcessor::class)]
final class RequestResponseProcessorTest extends TestCase
{
    private function record(array $context = []): LogRecord
    {
        return new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'test',
            level: Level::Info,
            message: 'msg',
            context: $context,
        );
    }

    public function testProcessesRequestContext(): void
    {
        $request = ( new Psr17Factory() )
            ->createServerRequest('GET', 'https://example.test/foo')
            ->withHeader('Authorization', 'Bearer secret')
            ->withHeader('X-Trace', 'abc');

        $proc      = new RequestResponseProcessor();
        $processed = $proc(
            $this->record(['type' => 'request', 'request' => $request, 'request_id' => 'r123'])
        );

        self::assertSame('HTTP/1.1', $processed->extra['protocol']);
        self::assertSame('r123', $processed->extra['request_id']);
        self::assertIsInt($processed->extra['pid']);
        // Sensitive headers redacted.
        self::assertSame(['[redacted]'], $processed->extra['headers']['Authorization']);
        // Other headers preserved.
        self::assertSame(['abc'], $processed->extra['headers']['X-Trace']);
    }

    public function testProcessesResponseContext(): void
    {
        $response  = new Response(200, ['Set-Cookie' => 'foo=bar', 'X-Powered-By' => 'litcal']);
        $proc      = new RequestResponseProcessor();
        $processed = $proc(
            $this->record(['type' => 'response', 'response' => $response, 'request_id' => 'r123'])
        );

        self::assertSame(200, $processed->extra['status_code']);
        self::assertSame('r123', $processed->extra['response_id']);
        self::assertSame(['[redacted]'], $processed->extra['headers']['Set-Cookie']);
        self::assertSame(['litcal'], $processed->extra['headers']['X-Powered-By']);
    }

    public function testRequestContextWithoutRequestObjectFallsBackGracefully(): void
    {
        $proc      = new RequestResponseProcessor();
        $processed = $proc($this->record(['type' => 'request', 'request_id' => 'fallback']));
        // Returns record without throwing — graceful degradation path.
        self::assertSame('fallback', $processed->extra['request_id']);
        self::assertIsInt($processed->extra['pid']);
    }

    public function testRequestContextWithoutRequestIdUsesUnknown(): void
    {
        $proc      = new RequestResponseProcessor();
        $processed = $proc($this->record(['type' => 'request']));
        self::assertSame('unknown', $processed->extra['request_id']);
    }

    public function testResponseContextWithoutResponseThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $proc = new RequestResponseProcessor();
        $proc($this->record(['type' => 'response', 'request_id' => 'r1']));
    }

    public function testUnknownTypeThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $proc = new RequestResponseProcessor();
        $proc($this->record(['type' => 'wat']));
    }

    public function testMissingTypeThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $proc = new RequestResponseProcessor();
        $proc($this->record([]));
    }
}
