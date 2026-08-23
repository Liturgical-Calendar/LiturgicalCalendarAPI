<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Models\Calendar\Temporale;

use LiturgicalCalendar\Api\DateTime;
use LiturgicalCalendar\Api\Http\Exception\ServiceUnavailableException;
use LiturgicalCalendar\Api\Models\Calendar\Temporale\PropriumDeTemporeEventFactory;
use LiturgicalCalendar\Api\Models\Calendar\Temporale\TemporaleContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins the contract of the shared "date it, build it, add it" helper that
 * `RomanTemporale`, `AmbrosianTemporale` and `CalendarHandler` all now route
 * through (issue #724).
 *
 * The guard tests are the point of the refactor. Before the date was folded
 * into the create call, callers wrote:
 *
 * ```php
 * $ctx->propriumDeTempore['BadKey']->setDate($date);   // <- null-dereferences here
 * $this->createPropriumDeTemporeLiturgicalEventByKey('BadKey', $ctx);
 * ```
 *
 * so an unknown key blew up on the un-guarded `setDate()` line with a PHP
 * error, never reaching the helper's `offsetExists()` check. Folding moved the
 * mutation behind the guard, so an unknown key now raises the intended
 * ServiceUnavailableException instead.
 *
 * Uses the Ambrosian harness purely because it wires a real
 * PropriumDeTemporeMap + LiturgicalEventCollection; nothing here is
 * rite-specific.
 */
#[CoversClass(PropriumDeTemporeEventFactory::class)]
final class PropriumDeTemporeEventFactoryTest extends TestCase
{
    use AmbrosianTemporaleHarnessTrait;

    private const YEAR = 2025;

    private function context(): TemporaleContext
    {
        $messages = [];
        return $this->buildContext(self::YEAR, $messages);
    }

    public function testAppliesTheGivenDateAndAddsTheEventToTheCalendar(): void
    {
        $ctx  = $this->context();
        $date = DateTime::fromFormat('25-12-' . self::YEAR);

        $event = PropriumDeTemporeEventFactory::create($ctx->propriumDeTempore, $ctx->cal, 'Christmas', $date);

        self::assertSame('2025-12-25', $event->date->format('Y-m-d'));
        self::assertSame($event, $ctx->cal->getLiturgicalEvent('Christmas'));
        self::assertSame(
            '2025-12-25',
            $ctx->propriumDeTempore['Christmas']->date->format('Y-m-d'),
            'The date must be written through to the Proprium de Tempore entry, as the old setDate() call did.'
        );
    }

    public function testANullDateLeavesTheEntryDatedAsItAlreadyWas(): void
    {
        $ctx = $this->context();
        $ctx->propriumDeTempore['Christmas']->setDate(DateTime::fromFormat('25-12-' . self::YEAR));

        $event = PropriumDeTemporeEventFactory::create($ctx->propriumDeTempore, $ctx->cal, 'Christmas', null);

        self::assertSame('2025-12-25', $event->date->format('Y-m-d'));
    }

    public function testAnUnknownKeyThrowsInsteadOfDereferencingNull(): void
    {
        $ctx = $this->context();

        $this->expectException(ServiceUnavailableException::class);
        $this->expectExceptionMessage('requires a key from the Proprium de Tempore, instead got NoSuchEvent');

        PropriumDeTemporeEventFactory::create(
            $ctx->propriumDeTempore,
            $ctx->cal,
            'NoSuchEvent',
            DateTime::fromFormat('25-12-' . self::YEAR)
        );
    }

    public function testANullKeyThrows(): void
    {
        $ctx = $this->context();

        $this->expectException(ServiceUnavailableException::class);

        PropriumDeTemporeEventFactory::create($ctx->propriumDeTempore, $ctx->cal, null, DateTime::fromFormat('25-12-' . self::YEAR));
    }

    public function testARejectedKeyAddsNothingToTheCalendar(): void
    {
        $ctx    = $this->context();
        $before = $ctx->cal->getLiturgicalEvents()->getKeys();

        try {
            PropriumDeTemporeEventFactory::create(
                $ctx->propriumDeTempore,
                $ctx->cal,
                'NoSuchEvent',
                DateTime::fromFormat('25-12-' . self::YEAR)
            );
            self::fail('Expected a ServiceUnavailableException for an unknown key.');
        } catch (ServiceUnavailableException) {
            // expected
        }

        self::assertSame($before, $ctx->cal->getLiturgicalEvents()->getKeys());
    }

    public function testTheContextConvenienceMethodDelegatesToTheFactory(): void
    {
        $ctx = $this->context();

        $event = $ctx->createPropriumDeTemporeEvent('Christmas', DateTime::fromFormat('25-12-' . self::YEAR));

        self::assertSame('2025-12-25', $event->date->format('Y-m-d'));
        self::assertSame($event, $ctx->cal->getLiturgicalEvent('Christmas'));
    }
}
