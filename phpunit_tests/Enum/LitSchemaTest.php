<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Enum;

use LiturgicalCalendar\Api\Enum\LitSchema;
use LiturgicalCalendar\Api\Http\Exception\ValidationException;
use LiturgicalCalendar\Api\Router;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(LitSchema::class)]
final class LitSchemaTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        // Ensure Router static paths are populated so LitSchema::path() works.
        Router::getApiPaths();
    }

    public function testEachCaseHasNonEmptyPath(): void
    {
        foreach (LitSchema::cases() as $case) {
            $path = $case->path();
            self::assertIsString($path);
            self::assertStringEndsWith('.json', $path);
            self::assertStringContainsString('schemas', $path);
        }
    }

    public function testErrorMessagesAreDistinctAndStartWithSchemaPrefix(): void
    {
        $messages = [];
        foreach (LitSchema::cases() as $case) {
            $err = $case->error();
            self::assertStringStartsWith('Schema validation error:', $err);
            $messages[$case->name] = $err;
        }
        self::assertSame(count($messages), count(array_unique($messages)));
    }

    public function testFromUrlRoundTripsForEveryCase(): void
    {
        foreach (LitSchema::cases() as $case) {
            $resolved = LitSchema::fromURL($case->path());
            self::assertSame($case, $resolved);
        }
    }

    public function testFromUrlRejectsUnknownUrl(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid schema URL: /not-real.json');
        LitSchema::fromURL('/not-real.json');
    }
}
