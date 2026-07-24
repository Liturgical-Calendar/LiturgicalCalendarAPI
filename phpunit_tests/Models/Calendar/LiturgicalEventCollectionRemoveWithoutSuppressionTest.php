<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Models\Calendar;

use LiturgicalCalendar\Api\DateTime;
use LiturgicalCalendar\Api\Enum\LitColor;
use LiturgicalCalendar\Api\Enum\LitGrade;
use LiturgicalCalendar\Api\Models\Calendar\LiturgicalEvent;
use LiturgicalCalendar\Api\Models\Calendar\LiturgicalEventCollection;
use LiturgicalCalendar\Api\Params\CalendarParams;
use LiturgicalCalendar\Tests\Handlers\AbstractHandlerTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Regression coverage for `LiturgicalEventCollection::removeLiturgicalEventWithoutSuppression()`.
 *
 * `addLiturgicalEvent()` files a key into more than the solemnities/feasts/memorials maps: a
 * Lord/BVM solemnity also lands in `solemnitiesLordBVM`, and a Feast of the Lord lands in
 * `feastsLord` (never in the plain `feasts` map). The suppression-free removal used by diocesan
 * overrides must clear every one of those indexes, or a stale reference lingers after an override
 * replaces a comune Lord/BVM solemnity or Feast of the Lord. It must also never record the removed
 * event as suppressed.
 *
 * Extends `AbstractHandlerTestCase` (not plain `TestCase`) only because the
 * `LiturgicalEventCollection` constructor needs a real `CalendarParams`, which needs
 * `Router::$apiFilePath` pinned to read local source data.
 */
#[CoversClass(LiturgicalEventCollection::class)]
final class LiturgicalEventCollectionRemoveWithoutSuppressionTest extends AbstractHandlerTestCase
{
    private function makeCollection(int $year = 2025): LiturgicalEventCollection
    {
        $params = new CalendarParams();
        $params->setParams(['year' => $year]);

        return new LiturgicalEventCollection($params);
    }

    public function testRemovesLordBvmSolemnityFromEveryIndexWithoutSuppressing(): void
    {
        $cal = $this->makeCollection();

        // `Assumption` is in SOLEMNITIES_LORD_BVM, so a SOLEMNITY-grade Assumption is filed into
        // BOTH the solemnitiesLordBVM map and the solemnities map by addLiturgicalEvent().
        $assumption = new LiturgicalEvent('Assumption', DateTime::fromFormat('15-8-2025'), LitColor::WHITE, grade: LitGrade::SOLEMNITY);
        $cal->addLiturgicalEvent('Assumption', $assumption);

        self::assertTrue($cal->isSolemnityLordBVM('Assumption'), 'Precondition: Assumption should be in solemnitiesLordBVM.');
        self::assertNotNull($cal->getLiturgicalEvent('Assumption'), 'Precondition: Assumption should be in the collection.');

        $cal->removeLiturgicalEventWithoutSuppression('Assumption');

        self::assertFalse($cal->isSolemnityLordBVM('Assumption'), 'Assumption must be cleared from solemnitiesLordBVM.');
        self::assertNull($cal->getLiturgicalEvent('Assumption'), 'Assumption must be cleared from the main collection.');
        self::assertFalse($cal->isSuppressed('Assumption'), 'A suppression-free removal must not record the event as suppressed.');
    }

    public function testRemovesFeastOfTheLordFromFeastsLordWithoutSuppressing(): void
    {
        $cal = $this->makeCollection();

        // A FEAST_LORD-grade event is filed into feastsLord (never into the plain feasts map).
        $date            = DateTime::fromFormat('6-8-2025');
        $transfiguration = new LiturgicalEvent('Transfiguration', $date, LitColor::WHITE, grade: LitGrade::FEAST_LORD);
        $cal->addLiturgicalEvent('Transfiguration', $transfiguration);

        self::assertTrue($cal->inFeastsLord($date), 'Precondition: the Feast of the Lord should be in feastsLord.');

        $cal->removeLiturgicalEventWithoutSuppression('Transfiguration');

        self::assertFalse($cal->inFeastsLord($date), 'The Feast of the Lord must be cleared from feastsLord.');
        self::assertNull($cal->getLiturgicalEvent('Transfiguration'), 'The event must be cleared from the main collection.');
        self::assertFalse($cal->isSuppressed('Transfiguration'), 'A suppression-free removal must not record the event as suppressed.');
    }
}
