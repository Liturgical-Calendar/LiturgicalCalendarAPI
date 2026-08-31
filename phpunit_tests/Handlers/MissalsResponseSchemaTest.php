<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers;

use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Handlers\MissalsHandler;
use LiturgicalCalendar\Api\Router;
use PHPUnit\Framework\Attributes\CoversClass;
use Swaggest\JsonSchema\Schema;

/**
 * The `/missals` catalogue response, validated whole against
 * `LitCalMissalsPath.json#/definitions/Missal` — not against fragments of it.
 *
 * Three sibling properties of `Missal` drifted independently while every existing test asserted
 * only part of the shape: `region.enum` did not admit `AMBROSIAN`, `missal_id.pattern` did not
 * admit the (then) Ambrosian id either, and `api_path.pattern` predated the rite segment
 * (`/missals/{rite}/{missal_id}`) that every entry's `api_path` now carries for both rites. All
 * three would have made a genuine catalogue entry fail its own published schema — a wrong-green
 * no fragment-level assertion would have caught, because nothing exercised the whole `Missal`
 * object against the real schema at once. This class drives a real `MissalsHandler` response,
 * for both rites, through the actual `Swaggest\JsonSchema\Schema` validator this repo already
 * uses for response-schema regression tests (see `RegionalDataWriteResponseSchemaTest`), so
 * these properties can no longer drift apart unnoticed.
 */
#[CoversClass(MissalsHandler::class)]
final class MissalsResponseSchemaTest extends AbstractHandlerTestCase
{
    /**
     * `AbstractHandlerTestCase::setUpBeforeClass()` deliberately pins `Router::$apiPath` to `''`
     * for predictable handler tests, but that leaves `api_path` with no scheme/host at all —
     * unable to satisfy its own `format: uri` regardless of the `pattern` fix this class exists
     * to guard. A real base path is needed here specifically, the same way
     * `MissalMetadataMapRiteTest` and `AmbrosianMissalTest` already call `Router::getApiPaths()`
     * to get one.
     */
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        Router::getApiPaths();
    }

    protected function setUp(): void
    {
        parent::setUp();
        MissalsHandler::$missalsIndex   = null;
        MissalsHandler::$missalsIndexes = [];
    }

    /**
     * A `$ref`-able URI naming `Missal` inside `LitCalMissalsPath.json`, resolved directly:
     * unlike the `/data/*` write-response schemas checked elsewhere, this schema is
     * self-contained (its only `$ref` is the internal `#/definitions/Missal`), so there is no
     * external document for the validator to fail resolving from this location.
     */
    private static function missalSchema(): Schema
    {
        $uri = dirname(__DIR__, 2) . '/jsondata/schemas/LitCalMissalsPath.json#/definitions/Missal';

        /** @var Schema $schema */
        $schema = Schema::import($uri);

        return $schema;
    }

    public function testTheAmbrosianCatalogueEntryValidatesAgainstTheMissalSchema(): void
    {
        $response = ( new MissalsHandler([], Rite::AMBROSIAN) )->handle($this->requestFor('GET', '/missals/ambrosian'));
        self::assertSame(200, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), flags: JSON_THROW_ON_ERROR);
        self::assertInstanceOf(\stdClass::class, $body);
        self::assertIsArray($body->litcal_missals ?? null);
        self::assertNotEmpty($body->litcal_missals, 'the Ambrosian catalogue must not be empty for this to be a real test');

        $schema = self::missalSchema();
        foreach ($body->litcal_missals as $missal) {
            $schema->in($missal);
        }
        $this->addToAssertionCount(count($body->litcal_missals));
    }

    public function testARomanCatalogueEntryValidatesAgainstTheMissalSchema(): void
    {
        $response = ( new MissalsHandler([], Rite::ROMAN) )->handle($this->requestFor('GET', '/missals'));
        self::assertSame(200, $response->getStatusCode());

        $body = json_decode((string) $response->getBody(), flags: JSON_THROW_ON_ERROR);
        self::assertInstanceOf(\stdClass::class, $body);
        self::assertIsArray($body->litcal_missals ?? null);
        self::assertNotEmpty($body->litcal_missals, 'the Roman catalogue must not be empty for this to be a real test');

        $schema = self::missalSchema();
        foreach ($body->litcal_missals as $missal) {
            $schema->in($missal);
        }
        $this->addToAssertionCount(count($body->litcal_missals));
    }
}
