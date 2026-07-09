<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers;

use LiturgicalCalendar\Api\Handlers\CalendarHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Pure-logic coverage for CalendarHandler's per-format request_headers splice helpers
 * (issue #684). Each helper operates only on $this->requestHeaders and a serialized body,
 * so it is exercised via reflection with the constructor skipped — no Router/JWT/DB setup
 * is needed, hence a plain PHPUnit\Framework\TestCase rather than AbstractHandlerTestCase.
 * The handle()-driven end-to-end cache behaviour lives in CalendarRequestHeadersCacheTest.
 */
#[CoversClass(CalendarHandler::class)]
final class CalendarRequestHeadersSpliceTest extends TestCase
{
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

    public function testYamlSpliceReplacesRequestHeaders(): void
    {
        $body = "metadata:\n  request_headers:\n    Accept: '*/*'\n    Accept-Language: en\n  version: '5.0'\n";
        $out  = $this->splice('spliceRequestHeadersYaml', ['Accept' => 'application/yaml', 'Accept-Language' => 'it'], $body);

        $parsed = Yaml::parse($out);
        self::assertSame(['Accept' => 'application/yaml', 'Accept-Language' => 'it'], $parsed['metadata']['request_headers']);
        self::assertSame('5.0', $parsed['metadata']['version'], 'Everything except request_headers must be untouched');
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

    public function testXmlSpliceHandlesEmptyCachedHeaders(): void
    {
        // A header-less caller serializes request_headers as a self-closing element.
        $body = "<LiturgicalCalendar><Metadata>\n    <RequestHeaders/>\n    <Version>5.0</Version></Metadata></LiturgicalCalendar>";
        $out  = $this->splice('spliceRequestHeadersXml', ['Accept' => 'application/xml'], $body);

        self::assertStringContainsString('<Accept>application/xml</Accept>', $out);
        self::assertStringContainsString('<Version>5.0</Version>', $out, 'Everything else must be untouched');
        self::assertNotFalse(simplexml_load_string($out), 'Result must remain well-formed XML');
    }
}
