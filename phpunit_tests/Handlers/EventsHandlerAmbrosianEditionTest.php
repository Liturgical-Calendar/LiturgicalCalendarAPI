<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers;

use LiturgicalCalendar\Api\Enum\AmbrosianMissal;
use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Handlers\EventsHandler;
use LiturgicalCalendar\Api\Params\EventsParams;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * `processAmbrosianSanctoraleEvents()` used to resolve an edition and then read a hard-coded path, so the
 * resolver's answer was computed and thrown away (#957). Harmless while one edition was declared; silently
 * wrong from the second onward.
 *
 * The defect is not observable through this endpoint: `/events` is year-agnostic (`EventsParams` has no
 * `year`, and `$Year` is always the current civil year), so the lookup always lands on the edition in
 * force today, whose sanctorale the old hard-coded constant happened to name too. The lookup is
 * therefore pinned at its own seam instead: it must yield an edition that SHIPS a sanctorale, which
 * `resolve()[0]` would not for a year governed by the data-less `EDITIO_TYPICA_1976`.
 */
#[CoversClass(EventsHandler::class)]
final class EventsHandlerAmbrosianEditionTest extends AbstractHandlerTestCase
{
    /**
     * `EventsHandler::setLocale()` calls the process-global `setlocale()`, which persists across tests in
     * the same PHPUnit process — the same reset `EventsHandlerRiteRoutingTest` performs, for the same reason.
     */
    protected function tearDown(): void
    {
        setlocale(LC_ALL, 'C');
        parent::tearDown();
    }

    /**
     * `EventsParams::ALLOWED_PARAMS` has no `year` entry — `setParams()` silently drops an unknown
     * key — so `new EventsParams(['year' => $year])` does NOT set `$Year`; it is left at the
     * constructor's `(int) date('Y')` default. `$Year` is a plain `public int` (no setter), so the
     * only way to pin it for a test is direct property assignment after construction.
     */
    private function editionFor(int $year): string
    {
        $handler = new EventsHandler([], Rite::AMBROSIAN);

        $ref  = new \ReflectionClass($handler);
        $prop = $ref->getProperty('EventsParams');
        $prop->setAccessible(true);

        $params       = new EventsParams([]);
        $params->Year = $year;
        $prop->setValue($handler, $params);

        $method = $ref->getMethod('ambrosianSanctoraleEdition');
        $method->setAccessible(true);

        return (string) $method->invoke($handler);
    }

    /**
     * 1990 is governed by the data-less 1976 edition, so the handler must fall through to the edition whose
     * proper is actually held. `resolve()[0]` here would hand `getSanctoraleFileName()` an edition that
     * returns `false`, and the sanctorale read would blow up.
     */
    public function testThePre2024EditionLookupYieldsAnEditionThatShipsASanctorale(): void
    {
        $edition = $this->editionFor(1990);

        self::assertIsString(
            AmbrosianMissal::getSanctoraleFileName($edition),
            'The edition /events reads for 1990 must ship a sanctorale file.'
        );
        self::assertSame(AmbrosianMissal::EDITIO_TYPICA_2024, $edition);
    }

    public function testAPost2024YearUsesTheEditionInForce(): void
    {
        self::assertSame(AmbrosianMissal::EDITIO_TYPICA_2024, $this->editionFor(2025));
    }

    /**
     * End-to-end guard: reading through the resolved edition's own accessors (`getSanctoraleFileName()`,
     * `getSanctoraleI18nFilePath()`) still yields the comune sanctorale, so the substitution did not
     * merely stop the handler throwing by serving nothing.
     *
     * `/events` is year-agnostic (`EventsParams` has no `year` param — see {@see self::editionFor()}),
     * so there is no such thing as a "pre-2024 Ambrosian `/events` request" to send; a `year` query
     * param on this route is silently ignored, not honoured. What this test proves instead is that
     * `/events`, which always resolves its edition for the current civil year, carries the comune
     * sanctorale end-to-end, exercised through a real request/response round trip rather than through
     * the reflection seam {@see self::editionFor()} uses.
     */
    public function testTheAmbrosianCatalogStillCarriesTheComuneSanctorale(): void
    {
        $handler = new EventsHandler([], Rite::AMBROSIAN);
        $request = $this->requestFor('GET', '/events/ambrosian', ['Accept-Language' => 'it']);

        $response = $handler->handle($request);
        self::assertSame(200, $response->getStatusCode());

        $body = $this->decodeJsonBody($response);
        self::assertArrayHasKey('litcal_events', $body);

        /** @var array<int,array<string,mixed>> $events */
        $events = $body['litcal_events'];
        self::assertContains(
            'StAmbrose',
            array_column($events, 'event_key'),
            'Expected the comune sanctorale to be present in the Ambrosian catalog.'
        );
    }
}
