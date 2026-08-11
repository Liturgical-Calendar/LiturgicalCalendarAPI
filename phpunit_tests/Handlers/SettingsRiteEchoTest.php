<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers;

use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Handlers\CalendarHandler;
use LiturgicalCalendar\Api\Handlers\EventsHandler;
use LiturgicalCalendar\Api\Http\Enum\ReturnTypeParam;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Issue #760: `/calendar` and `/events` echo the rite they were computed under in
 * their `settings` block.
 *
 * The rite-level Ambrosian calendar is the case that makes this necessary: it sets
 * neither `national_calendar` nor `diocesan_calendar` (correctly — it is neither), so
 * before this field a `/calendar/ambrosian` response was indistinguishable from a
 * `/calendar` response by payload alone. `settings.rite` is emitted for every rite,
 * `roman` included, so consumers can read it without a presence check.
 */
#[CoversClass(CalendarHandler::class)]
#[CoversClass(EventsHandler::class)]
final class SettingsRiteEchoTest extends AbstractHandlerTestCase
{
    /**
     * `EventsHandler::setLocale()` calls the process-global `setlocale(LC_ALL, ...)`
     * unconditionally, and the Milan diocesan catalog forces `it_IT`. Left pinned, it
     * breaks later tests that rely on gettext falling through to the untranslated
     * (Latin) msgid. Reset to `C` — no catalog binds to it, so lookups pass through
     * unchanged. Same rationale as {@see EventsHandlerRiteRoutingTest::tearDown()}.
     */
    protected function tearDown(): void
    {
        setlocale(LC_ALL, 'C');
        parent::tearDown();
    }

    /**
     * @param string[] $pathParts
     * @return array<string,mixed>
     */
    private function calendarSettings(array $pathParts, Rite $rite, string $uri): array
    {
        $handler = new CalendarHandler($pathParts, $rite);
        $handler->setAllowedReturnTypes([
            ReturnTypeParam::JSON,
            ReturnTypeParam::YAML,
            ReturnTypeParam::XML,
            ReturnTypeParam::ICS,
        ]);
        $response = $handler->handle($this->requestFor('GET', $uri, ['Accept' => 'application/json']));
        $body     = $this->decodeJsonBody($response);

        self::assertArrayHasKey('settings', $body, 'Calendar response should carry a settings block');
        self::assertIsArray($body['settings']);
        return $body['settings'];
    }

    /**
     * @param string[] $pathParts
     * @return array<string,mixed>
     */
    private function eventsSettings(array $pathParts, Rite $rite, string $uri): array
    {
        $handler  = new EventsHandler($pathParts, $rite);
        $response = $handler->handle($this->requestFor('GET', $uri, ['Accept-Language' => 'en']));
        $body     = $this->decodeJsonBody($response);

        self::assertArrayHasKey('settings', $body, 'Events response should carry a settings block');
        self::assertIsArray($body['settings']);
        return $body['settings'];
    }

    /**
     * @return array<string,array{0:string[],1:Rite,2:string,3:string}>
     */
    public static function calendarRoutes(): array
    {
        return [
            'general roman calendar'  => [['2025'], Rite::ROMAN, '/calendar/2025', 'roman'],
            'explicit roman segment'  => [['2025'], Rite::ROMAN, '/calendar/roman/2025', 'roman'],
            'national calendar'       => [['nation', 'IT'], Rite::ROMAN, '/calendar/nation/IT', 'roman'],
            'diocesan calendar'       => [['diocese', 'romamo_it'], Rite::ROMAN, '/calendar/diocese/romamo_it', 'roman'],
            'ambrosian comune'        => [[], Rite::AMBROSIAN, '/calendar/ambrosian', 'ambrosian'],
            'ambrosian comune w/year' => [['2025'], Rite::AMBROSIAN, '/calendar/ambrosian/2025', 'ambrosian'],
            'ambrosian diocese'       => [['diocese', 'milano_it'], Rite::AMBROSIAN, '/calendar/ambrosian/diocese/milano_it', 'ambrosian'],
        ];
    }

    /**
     * @param string[] $pathParts
     */
    #[DataProvider('calendarRoutes')]
    public function testCalendarSettingsEchoTheRite(array $pathParts, Rite $rite, string $uri, string $expected): void
    {
        $settings = $this->calendarSettings($pathParts, $rite, $uri);

        self::assertArrayHasKey('rite', $settings, "$uri should echo settings.rite");
        self::assertSame($expected, $settings['rite']);
    }

    /**
     * The whole point of the field: a rite-level Ambrosian response has no
     * national_calendar and no diocesan_calendar to identify it by, so without
     * settings.rite it is indistinguishable from the General Roman Calendar.
     */
    public function testAmbrosianComuneIsIdentifiableWithoutCalendarKeys(): void
    {
        $settings = $this->calendarSettings([], Rite::AMBROSIAN, '/calendar/ambrosian');

        self::assertArrayNotHasKey('national_calendar', $settings);
        self::assertArrayNotHasKey('diocesan_calendar', $settings);
        self::assertSame('ambrosian', $settings['rite']);
    }

    /**
     * `Utilities::convertArray2XML()` maps `rite` to a `<Rite>` element, which the
     * Settings sequence in LiturgicalCalendar.xsd declares between HolydaysOfObligation
     * and the optional NationalCalendar/DiocesanCalendar elements.
     */
    public function testXmlResponseCarriesRiteElement(): void
    {
        $handler = new CalendarHandler([], Rite::AMBROSIAN);
        $handler->setAllowedReturnTypes([ReturnTypeParam::JSON, ReturnTypeParam::XML]);
        $response = $handler->handle(
            $this->requestFor('GET', '/calendar/ambrosian', ['Accept' => 'application/xml'])
        );

        self::assertStringContainsString('<Rite>ambrosian</Rite>', (string) $response->getBody());
    }

    /**
     * @return array<string,array{0:string[],1:Rite,2:string,3:string}>
     */
    public static function eventsRoutes(): array
    {
        return [
            'roman catalog'     => [[], Rite::ROMAN, '/events', 'roman'],
            'national catalog'  => [['nation', 'IT'], Rite::ROMAN, '/events/nation/IT', 'roman'],
            'ambrosian comune'  => [[], Rite::AMBROSIAN, '/events/ambrosian', 'ambrosian'],
            'ambrosian diocese' => [['diocese', 'milano_it'], Rite::AMBROSIAN, '/events/ambrosian/diocese/milano_it', 'ambrosian'],
        ];
    }

    /**
     * @param string[] $pathParts
     */
    #[DataProvider('eventsRoutes')]
    public function testEventsSettingsEchoTheRite(array $pathParts, Rite $rite, string $uri, string $expected): void
    {
        $settings = $this->eventsSettings($pathParts, $rite, $uri);

        self::assertArrayHasKey('rite', $settings, "$uri should echo settings.rite");
        self::assertSame($expected, $settings['rite']);
    }

    public function testAmbrosianEventsComuneIsIdentifiableWithoutCalendarKeys(): void
    {
        $settings = $this->eventsSettings([], Rite::AMBROSIAN, '/events/ambrosian');

        self::assertNull($settings['national_calendar']);
        self::assertNull($settings['diocesan_calendar']);
        self::assertSame('ambrosian', $settings['rite']);
    }
}
