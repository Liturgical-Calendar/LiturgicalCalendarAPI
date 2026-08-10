<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Routes\Readonly;

use LiturgicalCalendar\Tests\ApiTestCase;

/**
 * Live HTTP integration test for the calendar route's ETag.
 *
 * A calendar representation embeds its own generation stamp — `timestamp` / `date_time` in JSON
 * and YML, `<Timestamp>` / `<DateTime>` in XML, `DTSTAMP` in ICS — which changes on every
 * generation but says nothing about the calendar itself. Hashing the raw body therefore produced a validator
 * that changed whenever the response was regenerated: on every request where the server cache
 * is bypassed (localhost), and on every server-cache regeneration in production, even though
 * the calendar content was byte-for-byte the same. A conditional request then re-transferred
 * the whole ~500 KB body for nothing.
 *
 * The validator is now computed over the representation with those generation stamps
 * neutralised, and is therefore weak (`W/`): two responses sharing an ETag are semantically
 * equivalent but not necessarily byte-identical (RFC 9110 §8.8.1).
 */
final class CalendarEtagStabilityTest extends ApiTestCase
{
    /**
     * The generation stamps have one-second granularity, so two back-to-back requests usually
     * land in the same second and would agree even with the stamp folded into the validator.
     * Crossing a second boundary is what makes this a regression guard rather than a coin flip.
     */
    private const GENERATION_STAMP_TICK_MICROSECONDS = 1_100_000;

    public function testTheCalendarEtagIsStableAcrossIdenticalRequests(): void
    {
        $first = self::$http->get('/calendar/2026', []);
        usleep(self::GENERATION_STAMP_TICK_MICROSECONDS);
        $second = self::$http->get('/calendar/2026', []);

        self::assertSame(200, $first->getStatusCode());
        self::assertSame(200, $second->getStatusCode());
        self::assertNotSame('', $first->getHeaderLine('ETag'));
        self::assertSame(
            $first->getHeaderLine('ETag'),
            $second->getHeaderLine('ETag'),
            'the generation stamp embedded in the body must not leak into the validator'
        );
    }

    public function testTheCalendarEtagIsWeak(): void
    {
        // The bodies behind two equal validators differ in their generation stamp, so a strong
        // validator would be a lie (RFC 9110 §8.8.1).
        $response = self::$http->get('/calendar/2026', []);

        self::assertMatchesRegularExpression('#^W/"[0-9a-f]+"$#', $response->getHeaderLine('ETag'));
    }

    public function testAConditionalCalendarRequestRevalidatesWithA304(): void
    {
        $first = self::$http->get('/calendar/2026', []);
        $etag  = $first->getHeaderLine('ETag');
        self::assertNotSame('', $etag);

        // A client revalidates some time after it cached, not within the same second.
        usleep(self::GENERATION_STAMP_TICK_MICROSECONDS);

        // Echoed back exactly as received, W/ prefix included, as a conforming client would.
        $second = self::$http->get('/calendar/2026', ['headers' => ['If-None-Match' => $etag]]);

        self::assertSame(304, $second->getStatusCode());
        self::assertSame('', (string) $second->getBody());
    }

    public function testTheIcsRepresentationAlsoHasAStableEtag(): void
    {
        // ICS carries its generation stamp as DTSTAMP rather than in a metadata object.
        $first = self::$http->get('/calendar/2026?return_type=ICS', []);
        usleep(self::GENERATION_STAMP_TICK_MICROSECONDS);
        $second = self::$http->get('/calendar/2026?return_type=ICS', []);

        self::assertSame(200, $first->getStatusCode());
        self::assertSame($first->getHeaderLine('ETag'), $second->getHeaderLine('ETag'));
    }

    public function testTheXmlRepresentationAlsoHasAStableEtag(): void
    {
        // XML carries the same stamp as JSON but under PascalCase element names,
        // `<Timestamp>` / `<DateTime>`.
        $first = self::$http->get('/calendar/2026?return_type=XML', []);
        usleep(self::GENERATION_STAMP_TICK_MICROSECONDS);
        $second = self::$http->get('/calendar/2026?return_type=XML', []);

        self::assertSame(200, $first->getStatusCode());
        self::assertSame($first->getHeaderLine('ETag'), $second->getHeaderLine('ETag'));
    }

    public function testAnEntityTagWhoseOpaqueValueContainsACommaIsNotSplitIntoSeparateTags(): void
    {
        // An entity-tag's opaque value may itself contain a comma (RFC 9110 §8.8.3 permits any
        // visible character except `"`), so splitting the field on commas can synthesise a
        // fragment equal to our own validator and answer 304 to a client that holds nothing of
        // the sort. This sends ONE tag that merely contains the validator, so it must not match.
        $first     = self::$http->get('/calendar/2026', []);
        $validator = trim(preg_replace('/^W\//', '', $first->getHeaderLine('ETag')) ?? '', '"');
        self::assertNotSame('', $validator);

        $response = self::$http->get('/calendar/2026', [
            'headers' => ['If-None-Match' => '"unrelated,' . $validator . '"']
        ]);

        self::assertSame(200, $response->getStatusCode());
    }

    public function testAValidatorListedAmongSeveralEntityTagsStillMatches(): void
    {
        // The converse of the test above: a genuine comma-separated list must still match.
        $first = self::$http->get('/calendar/2026', []);
        $etag  = $first->getHeaderLine('ETag');
        self::assertNotSame('', $etag);

        $response = self::$http->get('/calendar/2026', [
            'headers' => ['If-None-Match' => '"somethingelse", ' . $etag]
        ]);

        self::assertSame(304, $response->getStatusCode());
    }

    public function testDifferentCalendarsStillGetDifferentEtags(): void
    {
        // Guards the neutralisation against being too broad: it must blank the generation stamp,
        // not the content around it.
        $y2026 = self::$http->get('/calendar/2026', []);
        $y2027 = self::$http->get('/calendar/2027', []);

        self::assertNotSame($y2026->getHeaderLine('ETag'), $y2027->getHeaderLine('ETag'));
    }

    public function testDifferentRepresentationsOfTheSameCalendarGetDifferentEtags(): void
    {
        $json = self::$http->get('/calendar/2026', []);
        $yml  = self::$http->get('/calendar/2026?return_type=YML', []);

        self::assertNotSame($json->getHeaderLine('ETag'), $yml->getHeaderLine('ETag'));
    }
}
