<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Models\Calendar\Precedence;

use LiturgicalCalendar\Api\LocaleDateFormatter;
use LiturgicalCalendar\Api\Models\Calendar\LiturgicalEventCollection;
use LiturgicalCalendar\Api\Models\Calendar\Precedence\PrecedenceContext;
use LiturgicalCalendar\Api\Params\CalendarParams;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The message sink is passed to the constructor by reference, so any message a
 * precedence resolver appends must land in the caller's own array in emission order.
 * This mirrors TemporaleContextTest's coverage of the same by-ref message-sink contract.
 */
#[CoversClass(PrecedenceContext::class)]
final class PrecedenceContextTest extends TestCase
{
    /**
     * The three non-message collaborators are irrelevant to the message-sink
     * behaviour under test and are never touched by addMessage(); instantiate
     * them without their constructors to keep this a pure, no-I/O unit test
     * (CalendarParams' real constructor loads calendars metadata from source data).
     *
     * @return array{LiturgicalEventCollection, CalendarParams, LocaleDateFormatter}
     */
    private function collaborators(): array
    {
        return [
            ( new \ReflectionClass(LiturgicalEventCollection::class) )->newInstanceWithoutConstructor(),
            ( new \ReflectionClass(CalendarParams::class) )->newInstanceWithoutConstructor(),
            ( new \ReflectionClass(LocaleDateFormatter::class) )->newInstanceWithoutConstructor(),
        ];
    }

    public function testConstructorInjectsCalAndParamsInstances(): void
    {
        [$cal, $params, $formatter] = $this->collaborators();
        $sink                       = [];
        $context                    = new PrecedenceContext($cal, $params, $formatter, $sink);

        self::assertSame($cal, $context->cal);
        self::assertSame($params, $context->params);
    }

    public function testAddMessageAppendsToTheCallersArrayByReference(): void
    {
        [$cal, $params, $formatter] = $this->collaborators();
        $sink                       = ['pre-existing'];
        $context                    = new PrecedenceContext($cal, $params, $formatter, $sink);

        $context->addMessage('from-resolver');

        self::assertSame(['pre-existing', 'from-resolver'], $sink);
    }

    public function testAddMessagePreservesEmissionOrderAcrossAppends(): void
    {
        [$cal, $params, $formatter] = $this->collaborators();
        $sink                       = [];
        $context                    = new PrecedenceContext($cal, $params, $formatter, $sink);

        $context->addMessage('first');
        $context->addMessage('second');

        self::assertSame(['first', 'second'], $sink);
    }
}
