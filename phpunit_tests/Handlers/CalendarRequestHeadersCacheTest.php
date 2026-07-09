<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers;

use LiturgicalCalendar\Api\Handlers\CalendarHandler;
use LiturgicalCalendar\Api\Http\Enum\ReturnTypeParam;
use LiturgicalCalendar\Api\Router;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Yaml\Yaml;

/**
 * Issue #684: metadata.request_headers is request-specific data, but the calendar
 * cache is shared (keyed by calendar params, not headers). On a cache hit the handler
 * re-injects the current caller's headers into the serialized body so it never echoes
 * whichever client first populated the entry. These tests cover the per-format textual
 * splice helpers directly, plus one end-to-end check on the shared JSON cache entry.
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

    /**
     * Invoke a private splice helper with a given set of request headers. The helpers
     * use only $this->requestHeaders, so the constructor (and its metadata fetch) is skipped.
     *
     * @param array<string,string> $headers
     */
    private function splice(string $method, array $headers, string $body): string
    {
        $handler = ( new \ReflectionClass(CalendarHandler::class) )->newInstanceWithoutConstructor();
        ( new \ReflectionProperty(CalendarHandler::class, 'requestHeaders') )->setValue($handler, $headers);
        return (string) ( new \ReflectionMethod(CalendarHandler::class, $method) )->invoke($handler, $body);
    }

    public function testJsonSpliceReplacesOnlyRequestHeaders(): void
    {
        $body = '{"metadata":{"request_headers":{"Accept":"*\/*","Accept-Language":"en"},"version":"5.0"}}';
        $out  = $this->splice('spliceRequestHeadersJson', ['Accept' => 'application/json', 'Accept-Language' => 'it'], $body);

        $decoded = json_decode($out, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['Accept' => 'application/json', 'Accept-Language' => 'it'], $decoded['metadata']['request_headers']);
        self::assertSame('5.0', $decoded['metadata']['version'], 'Everything except request_headers must be untouched');
    }

    public function testJsonSpliceHandlesEmptyCachedHeaders(): void
    {
        // A caller that sent none of the useful headers serializes request_headers as [].
        $body = '{"metadata":{"request_headers":[],"version":"5.0"}}';
        $out  = $this->splice('spliceRequestHeadersJson', ['Accept' => 'application/json'], $body);

        $decoded = json_decode($out, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['Accept' => 'application/json'], $decoded['metadata']['request_headers']);
    }

    public function testYamlSpliceHandlesEmptyCachedHeaders(): void
    {
        // A header-less caller serializes request_headers as Symfony's inline empty map.
        $body = "metadata:\n  request_headers: {  }\n  version: '5.0'\n";
        $out  = $this->splice('spliceRequestHeadersYaml', ['Accept' => 'application/yaml', 'Accept-Language' => 'it'], $body);

        $parsed = Yaml::parse($out);
        self::assertSame(['Accept' => 'application/yaml', 'Accept-Language' => 'it'], $parsed['metadata']['request_headers']);
        self::assertSame('5.0', $parsed['metadata']['version'], 'Everything except request_headers must be untouched');
    }

    public function testXmlSpliceHandlesEmptyCachedHeaders(): void
    {
        // A header-less caller serializes request_headers as a self-closing element.
        $body = "<LiturgicalCalendar><Metadata>\n    <RequestHeaders/>\n    <Version>5.0</Version></Metadata></LiturgicalCalendar>";
        $out  = $this->splice('spliceRequestHeadersXml', ['Accept' => 'application/xml'], $body);

        self::assertStringContainsString('<Accept>application/xml</Accept>', $out);
        self::assertStringContainsString('<Version>5.0</Version>', $out, 'Everything else must be untouched');
        self::assertNotFalse(simplexml_load_string($out), 'Result must remain well-formed XML');
    }

    public function testXmlSpliceReplacesRequestHeaders(): void
    {
        $body = "<LiturgicalCalendar><Metadata>\n    <RequestHeaders>\n      <Accept>*/*</Accept>\n"
            . "    </RequestHeaders>\n    <Version>5.0</Version></Metadata></LiturgicalCalendar>";
        $out  = $this->splice('spliceRequestHeadersXml', ['Accept' => 'application/xml', 'Accept-Language' => 'it'], $body);

        self::assertStringContainsString('<Accept>application/xml</Accept>', $out);
        self::assertStringContainsString('<Accept-Language>it</Accept-Language>', $out);
        self::assertStringNotContainsString('*/*', $out, 'The first caller\'s Accept must be gone');
        self::assertStringContainsString('<Version>5.0</Version>', $out, 'Everything else must be untouched');
        self::assertNotFalse(simplexml_load_string($out), 'Result must remain well-formed XML');
    }

    public function testYamlSpliceReplacesRequestHeaders(): void
    {
        $body = "metadata:\n  request_headers:\n    Accept: '*/*'\n    Accept-Language: en\n  version: '5.0'\n";
        $out  = $this->splice('spliceRequestHeadersYaml', ['Accept' => 'application/yaml', 'Accept-Language' => 'it'], $body);

        $parsed = Yaml::parse($out);
        self::assertSame(['Accept' => 'application/yaml', 'Accept-Language' => 'it'], $parsed['metadata']['request_headers']);
        self::assertSame('5.0', $parsed['metadata']['version'], 'Everything except request_headers must be untouched');
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
     * replacement itself is covered by the splice unit tests above.
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
