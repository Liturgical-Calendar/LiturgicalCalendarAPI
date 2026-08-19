<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers;

use LiturgicalCalendar\Api\Enum\LitSchema;
use LiturgicalCalendar\Api\Handlers\EasterHandler;
use LiturgicalCalendar\Api\Handlers\MetadataHandler;
use LiturgicalCalendar\Api\Handlers\SchemasHandler;
use LiturgicalCalendar\Api\Handlers\TestsHandler;
use LiturgicalCalendar\Api\Handlers\ValidationsHandler;
use LiturgicalCalendar\Api\Router;
use Psr\Http\Server\RequestHandlerInterface;
use Swaggest\JsonSchema\Schema;

/**
 * Validates real handler output for the read-only index routes against the
 * standalone response schema files that openapi.json refs (issue #709).
 * Health checks perform the same validation, but only via the external
 * WebSocket test interface; these tests put it in CI.
 */
final class ReadonlyPathsResponseSchemaTest extends AbstractHandlerTestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        // The base class pins Router::$apiPath to '' for stable self-links;
        // LitCalMetadata.json and LitCalSchemasPath.json constrain URL
        // properties with strict absolute-URL patterns, so emit a
        // production-like base URL instead. The parent's tearDownAfterClass()
        // restores the saved value.
        Router::$apiPath = 'http://localhost:8000';
    }

    private function validateHandlerResponse(RequestHandlerInterface $handler, string $route, LitSchema $schema): void
    {
        $resp = $handler->handle($this->requestFor('GET', $route, ['Accept-Language' => 'en']));
        self::assertSame(200, $resp->getStatusCode());
        $body = json_decode((string) $resp->getBody(), flags: JSON_THROW_ON_ERROR);
        self::assertInstanceOf(\stdClass::class, $body);
        Schema::import($schema->path())->in($body);
        $this->addToAssertionCount(1);
    }

    public function testCalendarsResponseValidatesAgainstMetadataSchema(): void
    {
        $this->validateHandlerResponse(new MetadataHandler(), '/calendars', LitSchema::METADATA);
    }

    public function testEasterResponseValidatesAgainstEasterPathSchema(): void
    {
        $this->validateHandlerResponse(new EasterHandler(), '/easter', LitSchema::EASTER);
    }

    public function testTestsResponseValidatesAgainstTestsPathSchema(): void
    {
        $this->validateHandlerResponse(new TestsHandler(), '/tests', LitSchema::TESTS);
    }

    public function testSchemasResponseValidatesAgainstSchemasPathSchema(): void
    {
        $this->validateHandlerResponse(new SchemasHandler(), '/schemas', LitSchema::SCHEMAS);
    }

    public function testValidationsResponseValidatesAgainstValidationsPathSchema(): void
    {
        $this->validateHandlerResponse(new ValidationsHandler(), '/validations', LitSchema::VALIDATIONS);
    }
}
