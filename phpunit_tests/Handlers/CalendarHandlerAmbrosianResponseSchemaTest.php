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
 * Plan 7 Task 10 acceptance gate: the real, live `/calendar/ambrosian/{year}` response —
 * generated end-to-end through `CalendarHandler::handle()` via `calculateAmbrosianCalendar()`
 * (Task 9), now that the 501 gate is lifted — must validate against the full `LitCal.json`
 * response schema, not just the `LiturgicalEvent` sub-schema exercised in isolation by
 * `AmbrosianLitCalSchemaTest` (Task 1).
 *
 * This is what proves the Task 1 additions (the `AFTER_EPIPHANY`/`AFTER_PENTECOST` seasons and
 * the optional `is_dominical`/`is_aliturgical` booleans) actually cover every event a real
 * Ambrosian calculation produces — including the Task 9 empty-readings placeholder
 * (`AmbrosianReadings::empty()`) on every temporale- and sanctorale-origin event, and the
 * `psalter_week` value `LiturgicalEventCollection::calculatePsalterWeek()` back-fills.
 */
final class CalendarHandlerAmbrosianResponseSchemaTest extends AbstractHandlerTestCase
{
    private function handle(array $pathParts, string $uri): \Psr\Http\Message\ResponseInterface
    {
        // LiturgicalEvent::$internal_index (the source of event_idx) is a process-lifetime static
        // counter — see GoldenMaster::normalize()'s docblock, which strips event_idx from golden
        // fixture comparisons for the same reason. LitCal.json caps event_idx at 2000 (documented
        // there as "starting from 0 for the first event that is calculated"), an assumption that
        // only holds for a fresh process/request. Reset it here so this schema-validity assertion
        // doesn't depend on how many other tests ran earlier in the same PHPUnit process.
        $prop = new \ReflectionProperty(LiturgicalEvent::class, 'internal_index');
        $prop->setValue(null, 0);

        $handler = new CalendarHandler($pathParts, Rite::AMBROSIAN);
        $handler->setAllowedReturnTypes([ReturnTypeParam::JSON]);
        return $handler->handle($this->requestFor('GET', $uri, ['Accept' => 'application/json']));
    }

    public function testAmbrosianComune2025ResponseValidatesAgainstLitCalSchema(): void
    {
        $response = $this->handle(['2025'], '/calendar/ambrosian/2025');
        self::assertSame(200, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), flags: JSON_THROW_ON_ERROR);
        self::assertInstanceOf(\stdClass::class, $body);
        self::assertIsArray($body->litcal);
        self::assertNotEmpty($body->litcal);

        Schema::import(LitSchema::LITCAL->path())->in($body);
        $this->addToAssertionCount(1);
    }
}
