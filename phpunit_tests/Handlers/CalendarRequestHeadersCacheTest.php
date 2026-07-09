<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers;

use LiturgicalCalendar\Api\Handlers\CalendarHandler;
use LiturgicalCalendar\Api\Http\Enum\ReturnTypeParam;
use LiturgicalCalendar\Api\Router;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Issue #684: metadata.request_headers is request-specific data, but the calendar cache is
 * shared (keyed by calendar params, not headers). On a cache hit the handler re-injects the
 * current caller's headers into the serialized body so it never echoes whichever client
 * first populated the entry. This is the handle()-driven end-to-end check on that behaviour;
 * the per-format splice helpers are covered as pure logic in CalendarRequestHeadersSpliceTest.
 */
#[CoversClass(CalendarHandler::class)]
final class CalendarRequestHeadersCacheTest extends AbstractHandlerTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $fixturePath = realpath(__DIR__ . '/../fixtures/api');
        self::assertNotFalse($fixturePath, 'M1 calendars fixture must be present');
        Router::$apiPath = 'file://' . $fixturePath;
    }

    private function purgeEngineCache(string $extension): void
    {
        foreach (glob(dirname(__DIR__, 2) . '/engineCache/v*/[0-9a-f]*.' . $extension) ?: [] as $staleCache) {
            unlink($staleCache);
        }
    }

    /**
     * End-to-end: two requests with identical calendar params (hence the SAME shared cache
     * key) but a different request-specific header must each see their OWN header echoed —
     * even though the second is served from the shared server cache the first populated.
     *
     * X-Requested-With is used (rather than Accept) because it is captured into
     * request_headers without participating in return-type negotiation, so both requests
     * deterministically resolve to JSON and hit the same cache entry. The per-format Accept
     * replacement itself is covered by the splice unit tests.
     */
    public function testSharedCacheReflectsEachCallersOwnHeader(): void
    {
        $this->purgeEngineCache('json');

        $make = function (): CalendarHandler {
            $handler = new CalendarHandler(['2025']);
            $handler->setAllowedReturnTypes([ReturnTypeParam::JSON, ReturnTypeParam::YAML, ReturnTypeParam::XML, ReturnTypeParam::ICS]);
            return $handler;
        };

        $r1 = $make()->handle($this->requestFor('GET', '/calendar/2025', ['Accept-Language' => 'la', 'X-Requested-With' => 'FirstClient']));
        self::assertSame(200, $r1->getStatusCode());
        self::assertSame('FirstClient', $this->decodeJsonBody($r1)['metadata']['request_headers']['X-Requested-With']);

        $r2 = $make()->handle($this->requestFor('GET', '/calendar/2025', ['Accept-Language' => 'la', 'X-Requested-With' => 'SecondClient']));
        self::assertSame(200, $r2->getStatusCode());
        self::assertSame('ServerCache', $r2->getHeaderLine('X-LitCal-Generated'), 'Second request must be served from the shared server cache');
        self::assertSame(
            'SecondClient',
            $this->decodeJsonBody($r2)['metadata']['request_headers']['X-Requested-With'],
            'The cached response must reflect the second caller\'s own header, not the first caller\'s'
        );
    }
}
