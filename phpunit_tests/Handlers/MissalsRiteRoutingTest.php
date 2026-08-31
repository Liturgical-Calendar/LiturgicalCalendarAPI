<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers;

use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Handlers\MissalsHandler;
use LiturgicalCalendar\Api\Router;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(MissalsHandler::class)]
#[CoversClass(Router::class)]
final class MissalsRiteRoutingTest extends AbstractHandlerTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        MissalsHandler::$missalsIndex   = null;
        MissalsHandler::$missalsIndexes = [];
    }

    public function testTheRiteSegmentIsStrippedFromTheMissalsRoute(): void
    {
        $parts = ['ambrosian', 'EDITIO_TYPICA_2024'];
        $rite  = Router::extractRiteSegment('missals', $parts);

        self::assertSame(Rite::AMBROSIAN, $rite);
        self::assertSame(['EDITIO_TYPICA_2024'], $parts, 'the rite segment must be consumed so shape parsing is unchanged');
    }

    public function testABareMissalsPathMeansRoman(): void
    {
        $parts = [];
        self::assertSame(Rite::ROMAN, Router::extractRiteSegment('missals', $parts));
        self::assertSame([], $parts);
    }

    /**
     * A missal id can never be mistaken for a rite: rite values are lowercase, missal ids upper.
     */
    public function testAMissalIdIsNotMistakenForARite(): void
    {
        $parts = ['EDITIO_TYPICA_1970'];
        self::assertSame(Rite::ROMAN, Router::extractRiteSegment('missals', $parts));
        self::assertSame(['EDITIO_TYPICA_1970'], $parts, 'a missal id must not be consumed as a rite');
    }

    public function testTheBareSpellingAdvertisesTheCanonicalForm(): void
    {
        $url = Router::canonicalRiteUrl('missals', 'GET', false, Rite::ROMAN, ['EDITIO_TYPICA_1970']);
        self::assertIsString($url);
        self::assertStringEndsWith('/missals/roman/EDITIO_TYPICA_1970', $url);
    }

    public function testAnExplicitRiteHasNoCanonicalForm(): void
    {
        self::assertNull(Router::canonicalRiteUrl('missals', 'GET', true, Rite::ROMAN, ['EDITIO_TYPICA_1970']));
    }

    /**
     * canonicalRiteUrl() is restricted to read methods, so a sanctorale write never carries the
     * header — and neither does the CORS preflight that precedes it.
     */
    public function testAWriteHasNoCanonicalForm(): void
    {
        self::assertNull(Router::canonicalRiteUrl('missals', 'PUT', false, Rite::ROMAN, ['US_2011', 'StZzTest']));
    }

    public function testTheAmbrosianCatalogueIsServed(): void
    {
        $response = ( new MissalsHandler([], Rite::AMBROSIAN) )->handle($this->requestFor('GET', '/missals/ambrosian'));

        self::assertSame(200, $response->getStatusCode());
        $body = $this->decodeJsonBody($response);
        self::assertIsArray($body['litcal_missals']);
        $ids = array_column($body['litcal_missals'], 'missal_id');
        self::assertSame(['EDITIO_TYPICA_2024'], $ids);
    }

    /**
     * The index is a process-lifetime static. Both orders, in one process: a guard that only asks
     * whether *an* index exists would let whichever rite built first answer for the other.
     */
    public function testEachRiteKeepsItsOwnIndexWithinOneProcess(): void
    {
        $ambrosianFirst = ( new MissalsHandler([], Rite::AMBROSIAN) )->handle($this->requestFor('GET', '/missals/ambrosian'));
        $romanSecond    = ( new MissalsHandler([], Rite::ROMAN) )->handle($this->requestFor('GET', '/missals'));

        $ambrosianIds = array_column($this->decodeJsonBody($ambrosianFirst)['litcal_missals'], 'missal_id');
        $romanIds     = array_column($this->decodeJsonBody($romanSecond)['litcal_missals'], 'missal_id');

        self::assertSame(['EDITIO_TYPICA_2024'], $ambrosianIds);
        self::assertContains('EDITIO_TYPICA_1970', $romanIds, 'the Roman index must not be the Ambrosian one');
        self::assertNotContains('EDITIO_TYPICA_2024', $romanIds);
    }

    public function testTheRomanCatalogueStillAnswersOnTheBarePath(): void
    {
        $response = ( new MissalsHandler([], Rite::ROMAN) )->handle($this->requestFor('GET', '/missals'));

        self::assertSame(200, $response->getStatusCode());
        $ids = array_column($this->decodeJsonBody($response)['litcal_missals'], 'missal_id');
        self::assertContains('EDITIO_TYPICA_1970', $ids);
        self::assertNotContains('EDITIO_TYPICA_2024', $ids);
    }
}
