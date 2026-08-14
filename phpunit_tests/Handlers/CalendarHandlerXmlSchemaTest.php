<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers;

use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Handlers\CalendarHandler;
use LiturgicalCalendar\Api\Http\Enum\ReturnTypeParam;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Issue #772: the Ambrosian XML serialization had never been validated against
 * `jsondata/schemas/LiturgicalCalendar.xsd`.
 *
 * `Routes/Readonly/CalendarTest::testGetCalendarReturnsXML()` covers the Roman
 * `/calendar` only, and it does so over HTTP against a running server — which in
 * this project is a Docker container bound to a *different* checkout, so it can
 * never validate the working tree's XSD against the working tree's code.
 *
 * These cases run the handler in-process and validate the serialized body with
 * `DOMDocument::schemaValidate()` against the repository's own XSD file, so both
 * rites are covered by the same mechanism and the assertion is about *this*
 * revision's schema.
 */
#[CoversClass(CalendarHandler::class)]
final class CalendarHandlerXmlSchemaTest extends AbstractHandlerTestCase
{
    /**
     * The Ambrosian calendars pin the process-global locale to `it_IT` (see
     * {@see SettingsRiteEchoTest::tearDown()}); reset to `C` so later tests
     * still see untranslated msgids.
     */
    protected function tearDown(): void
    {
        setlocale(LC_ALL, 'C');
        parent::tearDown();
    }

    /**
     * @return array<string,array{0:string[],1:Rite,2:string}>
     */
    public static function xmlCalendarRoutes(): array
    {
        return [
            'general roman calendar'  => [[], Rite::ROMAN, '/calendar'],
            'roman national calendar' => [['nation', 'IT'], Rite::ROMAN, '/calendar/nation/IT'],
            'ambrosian rite'          => [[], Rite::AMBROSIAN, '/calendar/ambrosian'],
            'ambrosian diocese'       => [['diocese', 'milano_it'], Rite::AMBROSIAN, '/calendar/ambrosian/diocese/milano_it'],
        ];
    }

    /**
     * @param string[] $pathParts
     */
    #[DataProvider('xmlCalendarRoutes')]
    public function testXmlResponseValidatesAgainstSchema(array $pathParts, Rite $rite, string $uri): void
    {
        $xsd = dirname(__DIR__, 2) . '/jsondata/schemas/LiturgicalCalendar.xsd';
        self::assertFileExists($xsd, 'XSD not found: ' . $xsd);

        $handler = new CalendarHandler($pathParts, $rite);
        $handler->setAllowedReturnTypes([
            ReturnTypeParam::JSON,
            ReturnTypeParam::YAML,
            ReturnTypeParam::XML,
            ReturnTypeParam::ICS,
        ]);
        $response = $handler->handle($this->requestFor('GET', $uri, ['Accept' => 'application/xml']));

        self::assertSame(200, $response->getStatusCode(), $uri . ' should return HTTP 200, got: ' . $response->getBody());
        self::assertStringStartsWith(
            'application/xml',
            $response->getHeaderLine('Content-Type'),
            $uri . ' should be served as application/xml'
        );

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            $dom    = new \DOMDocument();
            $loaded = $dom->loadXML((string) $response->getBody());
            self::assertTrue($loaded, $uri . ' did not produce well-formed XML: ' . self::formatLibxmlErrors());

            libxml_clear_errors();
            $valid = $dom->schemaValidate($xsd);
            self::assertTrue(
                $valid,
                $uri . ' XML does not validate against LiturgicalCalendar.xsd:' . PHP_EOL . self::formatLibxmlErrors()
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /**
     * Report *every* schema violation, not just the first: this test exists to
     * surface accumulated drift, and a one-error message would hide all but one
     * defect per run.
     */
    private static function formatLibxmlErrors(): string
    {
        $messages = array_map(
            static fn (\LibXMLError $error): string => sprintf('  line %d: %s', $error->line, trim($error->message)),
            libxml_get_errors()
        );

        // Distinct messages only: a single drifted element can repeat hundreds of
        // times across a full year of events.
        $messages = array_values(array_unique($messages));

        return $messages === [] ? '  (no libxml errors reported)' : implode(PHP_EOL, $messages);
    }
}
