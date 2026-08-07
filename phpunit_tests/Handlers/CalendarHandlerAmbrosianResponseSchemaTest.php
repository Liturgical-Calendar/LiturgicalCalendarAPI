<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers;

use LiturgicalCalendar\Api\Enum\LitSchema;
use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Handlers\CalendarHandler;
use LiturgicalCalendar\Api\Http\Enum\ReturnTypeParam;
use LiturgicalCalendar\Api\Models\Calendar\LiturgicalEvent;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * The five Pentecost-anchored celebrations this branch adds, with their Easter offsets and
     * the civil dates those offsets produce for 2025 (Easter 2025 = Apr 20, per the Missal's own
     * annual table pinned in `AmbrosianAnnualTableTest`).
     *
     * @return array<string,array{0:string,1:int,2:string}> event_key, Easter offset, expected date
     */
    public static function pentecostAnchoredCelebrations2025(): array
    {
        return [
            'MaryMotherChurch (Easter+50, Monday after Pentecost)'  => ['MaryMotherChurch', 50, '2025-06-09'],
            'Trinity (Easter+56, I domenica dopo Pentecoste)'       => ['Trinity', 56, '2025-06-15'],
            'CorpusChristi (Easter+60, the Thursday after)'         => ['CorpusChristi', 60, '2025-06-19'],
            'SacredHeart (Easter+68, Friday after the II domenica)' => ['SacredHeart', 68, '2025-06-27'],
            'ImmaculateHeart (Easter+69, the Saturday after)'       => ['ImmaculateHeart', 69, '2025-06-28'],
        ];
    }

    /**
     * Closes the loop through the REAL handler. Every other test for these five celebrations
     * runs through test-only glue (`AmbrosianTemporaleHarnessTrait`,
     * `AmbrosianRealYearHarnessTrait`) that `CalendarHandler` never calls, so until now nothing
     * proved the five keys actually reach a `/calendar/ambrosian/2025` response body on the
     * right dates after the full production pipeline — sanctorale, diocesan overlay, season
     * stamping, precedence resolution, vigils and serialization included.
     */
    #[DataProvider('pentecostAnchoredCelebrations2025')]
    public function testPentecostAnchoredCelebrationsAppearInTheAmbrosian2025Response(string $eventKey, int $easterOffset, string $expectedDate): void
    {
        $response = $this->handle(['2025'], '/calendar/ambrosian/2025');
        self::assertSame(200, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), flags: JSON_THROW_ON_ERROR);
        self::assertInstanceOf(\stdClass::class, $body);
        self::assertIsArray($body->litcal);

        $matches = array_values(array_filter(
            $body->litcal,
            static fn (object $event): bool => $event->event_key === $eventKey
        ));

        self::assertCount(1, $matches, "$eventKey must appear exactly once in the /calendar/ambrosian/2025 response");
        self::assertSame(
            $expectedDate,
            ( new \DateTimeImmutable($matches[0]->date) )->format('Y-m-d'),
            "$eventKey must fall on $expectedDate (Easter 2025 + $easterOffset days)"
        );

        // Independent arithmetic check on the anchor itself, so a wrong expectation in the data
        // provider cannot make this test pass against an equally wrong engine.
        self::assertSame(
            $expectedDate,
            ( new \DateTimeImmutable('2025-04-20') )->add(new \DateInterval('P' . $easterOffset . 'D'))->format('Y-m-d'),
            "Data-provider self-check: Easter 2025 + $easterOffset days must be $expectedDate"
        );
    }
}
