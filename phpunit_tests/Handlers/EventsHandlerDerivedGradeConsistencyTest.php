<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers;

use LiturgicalCalendar\Api\Enum\LitGrade;
use LiturgicalCalendar\Api\Handlers\EventsHandler;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * `/events` serializes `grade` next to `grade_lcl` and `grade_abbr`, which the catalog model
 * derives from it. `EventsHandler` applies decree, wider-region and national `setProperty:grade`
 * (and `makePatron`) rows by assigning `$event->grade` directly, which does not bring those labels
 * with it — so the catalog could publish a FEAST whose own label still read "Memorial" (#872).
 *
 * These tests assert the invariant over the whole catalog rather than over the one event that
 * happens to be affected today: every entry's labels must be the ones its OWN grade produces.
 * Latin is requested so the expected strings come from `LitGrade`'s hardcoded Latin branch rather
 * than from process-global gettext state.
 */
#[CoversClass(EventsHandler::class)]
final class EventsHandlerDerivedGradeConsistencyTest extends AbstractHandlerTestCase
{
    /**
     * Returns the catalog keyed by `event_key`, along with the locale the response says it was
     * rendered in — which is NOT always the requested one: a national calendar that does not
     * support Latin resolves to its own locale, and the labels must then match THAT.
     *
     * @param array<int, string> $pathParams
     * @return array{0: array<string, array<string, mixed>>, 1: string}
     */
    private function catalogByKey(array $pathParams, string $uri): array
    {
        $response = ( new EventsHandler($pathParams) )->handle(
            $this->requestFor('GET', $uri, ['Accept-Language' => 'la'])
        );
        self::assertSame(200, $response->getStatusCode());

        $body  = $this->decodeJsonBody($response);
        $byKey = [];
        foreach ($body['litcal_events'] as $event) {
            $byKey[$event['event_key']] = $event;
        }
        self::assertNotEmpty($byKey);

        return [$byKey, $body['settings']['locale']];
    }

    /**
     * @param array<string, array<string, mixed>> $catalog
     */
    private function assertGradeLabelsMatchGrade(array $catalog, string $locale, string $context): void
    {
        foreach ($catalog as $eventKey => $event) {
            $grade = LitGrade::from($event['grade']);
            self::assertSame(
                $grade->i18n($locale, false, false),
                $event['grade_lcl'],
                sprintf('%s: `%s` publishes grade %d with the label of a different grade.', $context, $eventKey, $event['grade'])
            );
            self::assertSame(
                $grade->i18n($locale, false, true),
                $event['grade_abbr'],
                sprintf('%s: `%s` publishes grade %d with the abbreviation of a different grade.', $context, $eventKey, $event['grade'])
            );
        }
    }

    /**
     * The live instance of the defect: the 2016 decree raising St Mary Magdalene from a memorial to
     * a feast is applied as a bare `grade` assignment, leaving her memorial labels in place.
     */
    public function testADecreeRaisedGradeCarriesItsOwnLabels(): void
    {
        [$catalog] = $this->catalogByKey([], '/events');

        self::assertArrayHasKey('StMaryMagdalene', $catalog);
        self::assertSame(LitGrade::FEAST->value, $catalog['StMaryMagdalene']['grade'], 'the decree raising St Mary Magdalene to a feast must have been applied');
        self::assertSame(LitGrade::FEAST->i18n('la', false, false), $catalog['StMaryMagdalene']['grade_lcl']);
        self::assertSame(LitGrade::FEAST->i18n('la', false, true), $catalog['StMaryMagdalene']['grade_abbr']);
    }

    public function testGeneralCatalogGradeLabelsAreConsistent(): void
    {
        [$catalog, $locale] = $this->catalogByKey([], '/events');
        $this->assertGradeLabelsMatchGrade($catalog, $locale, 'general catalog');
    }

    public function testNationalCatalogGradeLabelsAreConsistent(): void
    {
        [$catalog, $locale] = $this->catalogByKey(['nation', 'US'], '/events/nation/US');
        $this->assertGradeLabelsMatchGrade($catalog, $locale, 'US national catalog');

        [$catalog, $locale] = $this->catalogByKey(['nation', 'IT'], '/events/nation/IT');
        $this->assertGradeLabelsMatchGrade($catalog, $locale, 'IT national catalog');
    }
}
