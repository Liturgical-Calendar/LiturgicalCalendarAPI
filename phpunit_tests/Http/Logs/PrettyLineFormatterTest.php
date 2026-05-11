<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Http\Logs;

use LiturgicalCalendar\Api\Http\Logs\PrettyLineFormatter;
use Monolog\Level;
use Monolog\LogRecord;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PrettyLineFormatter::class)]
final class PrettyLineFormatterTest extends TestCase
{
    private function makeRecord(
        Level $level,
        string $message,
        array $context = [],
        array $extra = []
    ): LogRecord {
        return new LogRecord(
            datetime: new \DateTimeImmutable('2025-01-01 12:00:00'),
            channel: 'test',
            level: $level,
            message: $message,
            context: $context,
            extra: $extra
        );
    }

    public function testInfoMessageIsColouredGreen(): void
    {
        $formatter = new PrettyLineFormatter();
        $output    = $formatter->format($this->makeRecord(Level::Info, 'pong'));
        self::assertStringContainsString("\033[0;32m", $output);
        self::assertStringContainsString('pong', $output);
        self::assertStringContainsString("\033[0m", $output);
    }

    public function testWarningMessageIsColouredYellow(): void
    {
        $formatter = new PrettyLineFormatter();
        $output    = $formatter->format($this->makeRecord(Level::Warning, 'careful'));
        self::assertStringContainsString("\033[0;33m", $output);
    }

    public function testResponseContextColoursByStatus(): void
    {
        $formatter = new PrettyLineFormatter();

        $output500 = $formatter->format($this->makeRecord(
            Level::Info,
            'http',
            ['type' => 'response', 'response' => new Response(503)]
        ));
        self::assertStringContainsString("\033[0;31m", $output500); // red

        $output400 = $formatter->format($this->makeRecord(
            Level::Info,
            'http',
            ['type' => 'response', 'response' => new Response(404)]
        ));
        self::assertStringContainsString("\033[0;33m", $output400); // yellow

        $output200 = $formatter->format($this->makeRecord(
            Level::Info,
            'http',
            ['type' => 'response', 'response' => new Response(200)]
        ));
        self::assertStringContainsString("\033[0;32m", $output200); // green
    }

    public function testAlreadyColouredMessageIsLeftAlone(): void
    {
        $formatter = new PrettyLineFormatter();
        $message   = "\033[0;36mhello\033[0m";
        $output    = $formatter->format($this->makeRecord(Level::Info, $message));
        // Should not have wrapped with an additional green colour code.
        self::assertStringContainsString($message, $output);
    }

    public function testExtraFieldsAreRenderedPretty(): void
    {
        $formatter = new PrettyLineFormatter();
        $record    = $this->makeRecord(
            Level::Info,
            'hi',
            extra: [
                'flag'   => true,
                'number' => 42,
                'nullv'  => null,
                'list'   => ['a', 'b'],
                'nested' => ['child' => 'deep'],
                'obj'    => (object) ['x' => 1],
            ]
        );
        $output    = $formatter->format($record);
        self::assertStringContainsString('Extra:', $output);
        self::assertStringContainsString('flag', $output);
        self::assertStringContainsString('true', $output);
        self::assertStringContainsString('number', $output);
        self::assertStringContainsString('42', $output);
        self::assertStringContainsString('nullv', $output);
        self::assertStringContainsString('null', $output);
        self::assertStringContainsString('deep', $output);
        // Object converted to array vars and rendered (with indent spaces).
        self::assertMatchesRegularExpression('/x:\s+1/', $output);
    }

    public function testEmergencyLevelUsesRedBackground(): void
    {
        $formatter = new PrettyLineFormatter();
        $output    = $formatter->format($this->makeRecord(Level::Emergency, 'fire'));
        self::assertStringContainsString("\033[1;41m", $output);
    }
}
