<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Models\Calendar\Temporale;

use LiturgicalCalendar\Api\LocaleDateFormatter;
use LiturgicalCalendar\Api\Models\Calendar\LiturgicalEventCollection;
use LiturgicalCalendar\Api\Models\Calendar\Temporale\TemporaleContext;
use LiturgicalCalendar\Api\Models\PropriumDeTemporeMap;
use LiturgicalCalendar\Api\Params\CalendarParams;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The message sink is passed to the constructor by reference, so any message a
 * temporale engine appends must land in the caller's own array in emission order.
 * The Roman engine never emits a message, so this contract is otherwise untested;
 * these tests pin it (and guard it for the future Ambrosian engine).
 */
#[CoversClass(TemporaleContext::class)]
final class TemporaleContextTest extends TestCase
{
    /**
     * The four non-message collaborators are irrelevant to the message-sink
     * behaviour under test and are never touched by addMessage(); instantiate
     * them without their constructors to keep this a pure, no-I/O unit test
     * (CalendarParams' real constructor loads calendars metadata from source data).
     *
     * @return array{LiturgicalEventCollection, CalendarParams, PropriumDeTemporeMap, LocaleDateFormatter}
     */
    private function collaborators(): array
    {
        return [
            ( new \ReflectionClass(LiturgicalEventCollection::class) )->newInstanceWithoutConstructor(),
            ( new \ReflectionClass(CalendarParams::class) )->newInstanceWithoutConstructor(),
            ( new \ReflectionClass(PropriumDeTemporeMap::class) )->newInstanceWithoutConstructor(),
            ( new \ReflectionClass(LocaleDateFormatter::class) )->newInstanceWithoutConstructor(),
        ];
    }

    public function testAddMessageAppendsToTheCallersArrayByReference(): void
    {
        [$cal, $params, $propriumDeTempore, $formatter] = $this->collaborators();
        $sink                                           = ['pre-existing'];
        $context                                        = new TemporaleContext($cal, $params, $propriumDeTempore, $formatter, $sink);

        $context->addMessage('from-engine');

        self::assertSame(['pre-existing', 'from-engine'], $sink);
    }

    public function testAddMessagePreservesEmissionOrderAcrossAppends(): void
    {
        [$cal, $params, $propriumDeTempore, $formatter] = $this->collaborators();
        $sink                                           = [];
        $context                                        = new TemporaleContext($cal, $params, $propriumDeTempore, $formatter, $sink);

        $context->addMessage('first');
        $context->addMessage('second');

        self::assertSame(['first', 'second'], $sink);
    }
}
