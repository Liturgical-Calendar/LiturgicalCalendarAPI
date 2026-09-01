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
 * Both editions resolve to the same file today, so the defect is invisible in the response. The edition
 * lookup is therefore pinned at its own seam: it must yield an edition that SHIPS a sanctorale.
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

    private function editionFor(int $year): string
    {
        $handler = new EventsHandler([], Rite::AMBROSIAN);

        $ref  = new \ReflectionClass($handler);
        $prop = $ref->getProperty('EventsParams');
        $prop->setAccessible(true);
        $prop->setValue($handler, new EventsParams(['year' => $year]));

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
     * End-to-end guard: a pre-2024 Ambrosian catalog still carries the comune sanctorale, so the
     * substitution did not merely stop the handler throwing by serving nothing.
     */
    public function testThePre2024AmbrosianCatalogStillCarriesTheComuneSanctorale(): void
    {
        $handler = new EventsHandler([], Rite::AMBROSIAN);
        $request = $this->requestFor('GET', '/events/ambrosian', ['Accept-Language' => 'it'])
            ->withQueryParams(['year' => '1990']);

        $response = $handler->handle($request);
        self::assertSame(200, $response->getStatusCode());

        $body = $this->decodeJsonBody($response);
        self::assertArrayHasKey('litcal_events', $body);

        /** @var array<int,array<string,mixed>> $events */
        $events = $body['litcal_events'];
        self::assertContains(
            'StAmbrose',
            array_column($events, 'event_key'),
            'Expected the comune sanctorale to still be present for a pre-2024 year.'
        );
    }
}
