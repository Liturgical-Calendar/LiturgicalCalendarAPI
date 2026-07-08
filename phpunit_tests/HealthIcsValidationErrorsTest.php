<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests;

use LiturgicalCalendar\Api\Health;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Property;

/**
 * Coverage for Health::formatIcsValidationErrors(), which renders the
 * schema-validation errors returned by Sabre\VObject's Document::validate()
 * into the human-readable strings surfaced over the health-check WebSocket.
 *
 * Each error's `node` is a Sabre\VObject\Property read for its lineIndex /
 * lineString. sabre/vobject 5.0.0 retyped those properties and declared
 * Node/Property as a (non-generic) IteratorAggregate; this test pins the
 * rendering so the accompanying PHPStan shape handling stays honest. The
 * helper is instance-state-free, so it is exercised directly via reflection
 * without standing up the WebSocket server.
 */
#[CoversClass(Health::class)]
final class HealthIcsValidationErrorsTest extends TestCase
{
    /**
     * @param array<array-key, mixed> $result
     * @return list<string>
     */
    private function format(array $result): array
    {
        // The method uses no instance state; skip the constructor (and its
        // environment/service coupling) and invoke it directly.
        $health = ( new \ReflectionClass(Health::class) )->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(Health::class, 'formatIcsValidationErrors');
        /** @var list<string> $strings */
        $strings = $method->invoke($health, $result);
        return $strings;
    }

    private function propertyAtLine(int $lineIndex, string $lineString): Property
    {
        $vcal = new VCalendar([], false);
        $prop = $vcal->add('X-BROKEN', 'oops');
        self::assertInstanceOf(Property::class, $prop);
        $prop->lineIndex  = $lineIndex;
        $prop->lineString = $lineString;
        return $prop;
    }

    /**
     * The three ICSErrorLevel values (2 => Warning, 3 => Fatal Error) render as
     * "<level>: <message> at line <index> (<source line>)", one entry per error,
     * preserving order.
     */
    public function testRendersLevelMessageAndLineForEachError(): void
    {
        $result = [
            ['level' => 3, 'message' => 'Every VCALENDAR must have a PRODID', 'node' => $this->propertyAtLine(2, 'BEGIN:VCALENDAR')],
            ['level' => 2, 'message' => 'DTSTART is expected', 'node' => $this->propertyAtLine(9, 'BEGIN:VEVENT')],
        ];

        self::assertSame(
            [
                'Fatal Error: Every VCALENDAR must have a PRODID at line 2 (BEGIN:VCALENDAR)',
                'Warning: DTSTART is expected at line 9 (BEGIN:VEVENT)',
            ],
            $this->format($result)
        );
    }

    public function testEmptyResultYieldsNoStrings(): void
    {
        self::assertSame([], $this->format([]));
    }
}
