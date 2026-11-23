<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers;

use LiturgicalCalendar\Api\Enum\LitSchema;
use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Handlers\CalendarHandler;
use LiturgicalCalendar\Api\Http\Enum\ReturnTypeParam;
use LiturgicalCalendar\Api\Models\Calendar\LiturgicalEvent;
use Swaggest\JsonSchema\Schema;

/**
 * `readings_source_url` on `/calendar` events (#1074): a link to the event's readings on the
 * USCCB site for US calendars and on the CEI site for Italian ones, and nothing elsewhere.
 */
final class CalendarReadingsSourceUrlTest extends AbstractHandlerTestCase
{
    /**
     * @param list<string> $pathParts
     */
    private function body(array $pathParts, string $uri, Rite $rite = Rite::ROMAN): \stdClass
    {
        // event_idx is a process-lifetime counter that LitCal.json caps; see CalendarHandlerAmbrosianResponseSchemaTest.
        ( new \ReflectionProperty(LiturgicalEvent::class, 'internal_index') )->setValue(null, 0);

        $handler = new CalendarHandler($pathParts, $rite);
        $handler->setAllowedReturnTypes([ReturnTypeParam::JSON]);
        $response = $handler->handle($this->requestFor('GET', $uri, ['Accept' => 'application/json']));
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $body = json_decode((string) $response->getBody(), flags: JSON_THROW_ON_ERROR);
        self::assertInstanceOf(\stdClass::class, $body);
        return $body;
    }

    /**
     * @return array<string, \stdClass>
     */
    private static function byKey(\stdClass $body): array
    {
        self::assertIsArray($body->litcal);
        $events = [];
        foreach ($body->litcal as $event) {
            self::assertInstanceOf(\stdClass::class, $event);
            $events[(string) $event->event_key] = $event;
        }
        return $events;
    }

    private static function linkCount(\stdClass $body): int
    {
        return count(array_filter(self::byKey($body), static fn (\stdClass $e): bool => property_exists($e, 'readings_source_url')));
    }

    public function testUsCalendarLinksMemorialPage(): void
    {
        $events = self::byKey($this->body(['nation', 'US', '2025'], '/calendar/nation/US/2025'));
        self::assertSame('https://bible.usccb.org/bible/readings/0903-memorial-gregory.cfm', $events['StGregoryGreat']->readings_source_url ?? null);
        self::assertSame('https://bible.usccb.org/bible/readings/100625.cfm', $events['OrdWeekday27Monday']->readings_source_url ?? null);
    }

    public function testLentenCommemorationLinksTheDayPage(): void
    {
        $events = self::byKey($this->body(['nation', 'US', '2023'], '/calendar/nation/US/2023'));
        self::assertSame(1, $events['StJohnGod']->grade);
        self::assertSame('https://bible.usccb.org/bible/readings/030823.cfm', $events['StJohnGod']->readings_source_url ?? null);
    }

    public function testItalianCalendarLinksCei(): void
    {
        $events = self::byKey($this->body(['nation', 'IT', '2025'], '/calendar/nation/IT/2025'));
        self::assertSame(
            'https://www.chiesacattolica.it/liturgia-del-giorno/?data-liturgia=20250203#607',
            $events['StBlase']->readings_source_url ?? null
        );
    }

    public function testUsDioceseInheritsUsccb(): void
    {
        $events = self::byKey($this->body(['diocese', 'boston_us', '2025'], '/calendar/diocese/boston_us/2025'));
        self::assertSame('https://bible.usccb.org/bible/readings/100625.cfm', $events['OrdWeekday27Monday']->readings_source_url ?? null);
    }

    public function testGeneralCalendarHasNoLinks(): void
    {
        self::assertSame(0, self::linkCount($this->body(['2025'], '/calendar/2025')));
    }

    public function testAmbrosianDioceseHasNoLinks(): void
    {
        self::assertSame(0, self::linkCount($this->body(['diocese', 'milano_it', '2025'], '/calendar/ambrosian/diocese/milano_it/2025', Rite::AMBROSIAN)));
    }

    public function testOutOfRangeYearHasNoLinks(): void
    {
        self::assertSame(0, self::linkCount($this->body(['nation', 'US', '2010'], '/calendar/nation/US/2010')));
    }

    public function testResponseValidatesAgainstLitCalSchema(): void
    {
        $body = $this->body(['nation', 'US', '2025'], '/calendar/nation/US/2025');
        self::assertGreaterThan(0, self::linkCount($body));
        Schema::import(LitSchema::LITCAL->path())->in($body);
        $this->addToAssertionCount(1);
    }
}
