<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers;

use LiturgicalCalendar\Api\Enum\LitColor;
use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Handlers\CalendarHandler;
use LiturgicalCalendar\Api\Models\Calendar\LiturgicalEvent;
use LiturgicalCalendar\Api\Http\Enum\ReturnTypeParam;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Issue #781: the Ambrosian Commemoration of All the Faithful Departed takes `morello`,
 * with `black` admitted *ad libitum* on any day but Sunday (*Ordinamento Generale del
 * Messale Ambrosiano* n. 320).
 *
 * `color` is per *celebration*, not per day, so the condition is evaluated against the
 * celebration's own date and the Sunday-vigil case falls out for free: a vigil is its own
 * event inheriting the colours of the celebration it belongs to. When All Souls is on a
 * Sunday, `AllSouls_vigil` (the Saturday evening that opens it) inherits `morello` only;
 * when All Souls is on a Saturday, the Mass opening the *following* Sunday is that
 * Sunday's own vigil and carries that Sunday's colours instead.
 *
 * The resolved alternative is appended to the existing `color` array rather than carried
 * in a parallel field — the array already expresses alternatives within one celebration,
 * whether from a choice of common (`["white", "red"]` for a martyr-bishop) or from an
 * *ad libitum* option (`["rose", "purple"]` on Gaudete/Laetare).
 */
#[CoversClass(CalendarHandler::class)]
final class AmbrosianAdLibitumColorTest extends AbstractHandlerTestCase
{
    protected function tearDown(): void
    {
        setlocale(LC_ALL, 'C');
        parent::tearDown();
    }

    /**
     * Builds an Ambrosian calendar for the given year.
     *
     * `LiturgicalEvent::$internal_index` (the source of `event_idx`) is a process-lifetime
     * static, and `LitCal.json` caps `event_idx` at 2000 on the assumption of a fresh
     * process per request — true in production, false inside one PHPUnit process. This
     * class builds nine calendars, so without the reset the counter climbs past the cap.
     *
     * That matters beyond this file: `engineCache/` keys on `md5(serialize(CalendarParams))`
     * and stores the *serialized response*, `event_idx` values included. A calendar computed
     * here with an inflated counter is therefore handed to any later test requesting the
     * same year — defeating that test's own reset and failing its schema assertion. Resetting
     * before every build keeps what lands in the shared cache within the documented bounds.
     */
    private function ambrosianCalendarFor(int $year): \Psr\Http\Message\ResponseInterface
    {
        $prop = new \ReflectionProperty(LiturgicalEvent::class, 'internal_index');
        $prop->setValue(null, 0);

        $handler = new CalendarHandler([(string) $year], Rite::AMBROSIAN);
        $handler->setAllowedReturnTypes([ReturnTypeParam::JSON]);

        return $handler->handle(
            $this->requestFor('GET', "/calendar/ambrosian/{$year}", ['Accept' => 'application/json'])
        );
    }

    /**
     * 2 November by weekday. The faculty applies on every day but Sunday.
     *
     * @return array<string,array{0:int,1:string[]}>
     */
    public static function allSoulsByYear(): array
    {
        return [
            // Ordinary ferias — black admitted ad libitum.
            '2026 (Monday)'   => [2026, ['morello', 'black']],
            '2020 (Monday)'   => [2020, ['morello', 'black']],
            '2029 (Friday)'   => [2029, ['morello', 'black']],
            // Saturday is a feria like any other for this celebration: the Mass that opens
            // the following Sunday is that Sunday's own vigil, a separate event.
            '2019 (Saturday)' => [2019, ['morello', 'black']],
            '2024 (Saturday)' => [2024, ['morello', 'black']],
            // Sunday — n. 320 excludes black.
            '2025 (Sunday)'   => [2025, ['morello']],
            '2031 (Sunday)'   => [2031, ['morello']],
        ];
    }

    /**
     * @param string[] $expectedColors
     */
    #[DataProvider('allSoulsByYear')]
    public function testAllSoulsColorResolvesAdLibitumBlackByWeekday(int $year, array $expectedColors): void
    {
        $response = $this->ambrosianCalendarFor($year);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $payload = json_decode((string) $response->getBody());
        self::assertInstanceOf(\stdClass::class, $payload);

        $allSouls = array_find(
            $payload->litcal,
            static fn (\stdClass $event): bool => $event->event_key === 'AllSouls'
        );
        self::assertNotNull($allSouls, "AllSouls not found in the {$year} Ambrosian calendar");

        self::assertSame(
            $expectedColors,
            $allSouls->color,
            sprintf(
                'AllSouls on %s %d: expected %s',
                date('l', (int) mktime(0, 0, 0, 11, 2, $year)),
                $year,
                implode(' + ', $expectedColors)
            )
        );
    }

    /**
     * The localized colours must track the resolved set, not the authored one — otherwise
     * a client rendering `color_lcl` would show only "morello" on a day black is admitted.
     */
    public function testResolvedColorIsAlsoLocalized(): void
    {
        $response = $this->ambrosianCalendarFor(2026);

        $payload = json_decode((string) $response->getBody());
        self::assertInstanceOf(\stdClass::class, $payload);
        $allSouls = array_find(
            $payload->litcal,
            static fn (\stdClass $event): bool => $event->event_key === 'AllSouls'
        );
        self::assertNotNull($allSouls);

        self::assertCount(2, $allSouls->color_lcl, 'color_lcl must have one entry per resolved colour');
        self::assertSame(count($allSouls->color), count($allSouls->color_lcl));
    }

    /**
     * The proper colour leads; the ad libitum one is appended. Existing multi-colour rows in
     * the source data are inconsistent about ordering, so this is pinned deliberately.
     */
    public function testProperColorLeadsTheResolvedArray(): void
    {
        $response = $this->ambrosianCalendarFor(2026);

        $payload = json_decode((string) $response->getBody());
        self::assertInstanceOf(\stdClass::class, $payload);
        $allSouls = array_find(
            $payload->litcal,
            static fn (\stdClass $event): bool => $event->event_key === 'AllSouls'
        );
        self::assertNotNull($allSouls);

        self::assertSame(LitColor::MORELLO->value, $allSouls->color[0]);
    }

    /**
     * A vigil is its own event and inherits the colours of the celebration it opens, so the
     * Sunday exclusion propagates to `AllSouls_vigil` with no special-casing.
     *
     * @return array<string,array{0:int,1:string[]}>
     */
    public static function allSoulsVigilByYear(): array
    {
        return [
            // All Souls on a Sunday: the Saturday-evening vigil opening it is morello only.
            '2025 (vigil opens Sunday)'   => [2025, ['morello']],
            // All Souls on a Saturday: its vigil is the Friday evening, and carries black too.
            '2024 (vigil opens Saturday)' => [2024, ['morello', 'black']],
        ];
    }

    /**
     * @param string[] $expectedColors
     */
    #[DataProvider('allSoulsVigilByYear')]
    public function testAllSoulsVigilInheritsResolvedColors(int $year, array $expectedColors): void
    {
        $payload = json_decode((string) $this->ambrosianCalendarFor($year)->getBody());
        self::assertInstanceOf(\stdClass::class, $payload);

        $vigil = array_find(
            $payload->litcal,
            static fn (\stdClass $event): bool => $event->event_key === 'AllSouls_vigil'
        );
        self::assertNotNull($vigil, "AllSouls_vigil not found in the {$year} Ambrosian calendar");
        self::assertSame('AllSouls', $vigil->is_vigil_for);
        self::assertSame($expectedColors, $vigil->color);
    }
}
