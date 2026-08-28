<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers;

use LiturgicalCalendar\Api\Enum\LitSchema;
use LiturgicalCalendar\Api\Handlers\DecreesHandler;
use LiturgicalCalendar\Api\Router;
use Swaggest\JsonSchema\Schema;

/**
 * Validates the actual GET /decrees index response against the per-action
 * response shapes in LitCalDecreesPath.json (issue #314).
 */
final class DecreesHandlerResponseSchemaTest extends AbstractHandlerTestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        // The base class pins Router::$apiPath to '' for stable self-links;
        // this test validates api_path against the response schema's strict
        // absolute-URL pattern, so emit a production-like base URL instead.
        Router::$apiPath = 'http://localhost:8000';
    }

    private function decreesIndexBody(): \stdClass
    {
        $resp = ( new DecreesHandler() )->handle(
            $this->requestFor('GET', '/decrees', ['Accept-Language' => 'en'])
        );
        self::assertSame(200, $resp->getStatusCode());
        $body = json_decode((string) $resp->getBody(), flags: JSON_THROW_ON_ERROR);
        self::assertInstanceOf(\stdClass::class, $body);
        return $body;
    }

    public function testDecreesIndexValidatesAgainstPathSchema(): void
    {
        $body = $this->decreesIndexBody();
        Schema::import(LitSchema::DECREES->path())->in($body);
        $this->addToAssertionCount(1);
    }

    /**
     * urls_langs is an explicit per-language override, not a derived commodity, so unlike
     * before it IS exposed: a client building per-language links cannot compute it.
     */
    public function testUrlsLangsInResponseMetadataIsAccepted(): void
    {
        $body                                          = $this->decreesIndexBody();
        $body->litcal_decrees[0]->metadata->urls_langs = (object) ['en' => 'https://www.vatican.va/roman_curia/congregations/ccdds/documents/test.html'];
        Schema::import(LitSchema::DECREES->path())->in($body);
        $this->addToAssertionCount(1);
    }

    public function testUrlsLangsWithANonUrlValueIsRejected(): void
    {
        $body                                          = $this->decreesIndexBody();
        $body->litcal_decrees[0]->metadata->urls_langs = (object) ['en' => 'not-a-vatican-url'];
        $this->expectException(\Swaggest\JsonSchema\Exception::class);
        Schema::import(LitSchema::DECREES->path())->in($body);
    }

    /**
     * Guards the anchoring of DecreeURLS.patternProperties: unanchored, a key such as
     * `xxde` matched the language alternation and slipped past additionalProperties:false.
     */
    public function testUrlsLangsWithAnUnknownLanguageKeyIsRejected(): void
    {
        $body                                          = $this->decreesIndexBody();
        $body->litcal_decrees[0]->metadata->urls_langs = (object) ['xxde' => 'https://www.vatican.va/roman_curia/congregations/ccdds/documents/test.html'];
        $this->expectException(\Swaggest\JsonSchema\Exception::class);
        Schema::import(LitSchema::DECREES->path())->in($body);
    }

    public function testMissingNameOnNameBearingDecreeIsRejected(): void
    {
        $body     = $this->decreesIndexBody();
        $tampered = false;
        foreach ($body->litcal_decrees as $decree) {
            if ($decree->metadata->action === 'makeDoctor') {
                unset($decree->liturgical_event->name);
                $tampered = true;
                break;
            }
        }
        self::assertTrue($tampered, 'Expected at least one makeDoctor decree in the index');
        $this->expectException(\Swaggest\JsonSchema\Exception::class);
        Schema::import(LitSchema::DECREES->path())->in($body);
    }

    private function singleDecreeBody(string $decreeId): \stdClass
    {
        $resp = ( new DecreesHandler([$decreeId]) )->handle(
            $this->requestFor('GET', '/decrees/' . $decreeId, ['Accept-Language' => 'en'])
        );
        self::assertSame(200, $resp->getStatusCode());
        $body = json_decode((string) $resp->getBody(), flags: JSON_THROW_ON_ERROR);
        self::assertInstanceOf(\stdClass::class, $body);
        return $body;
    }

    private static function singleDecreeSchema(): Schema
    {
        return Schema::import(LitSchema::DECREES->path() . '#/definitions/SingleDecreeResponse');
    }

    public function testSingleDecreeResponseValidatesAgainstSingleDecreeSchema(): void
    {
        // Rich case: name-bearing createNew decree with liturgical_event
        // readings and non-empty cross-locale i18n/readings maps.
        self::singleDecreeSchema()->in($this->singleDecreeBody('MaryMotherChurch_Create'));
        $this->addToAssertionCount(1);
    }

    public function testSingleDecreeResponseAllowsEmptyAggregateMaps(): void
    {
        // StMaryMagdalene_Upgrade has no i18n entries (grade decrees bear no
        // name) and no lectionary entries, so both aggregate maps are {}.
        $body = $this->singleDecreeBody('StMaryMagdalene_Upgrade');
        self::assertSame([], get_object_vars($body->i18n));
        self::assertSame([], get_object_vars($body->readings));
        self::singleDecreeSchema()->in($body);
        $this->addToAssertionCount(1);
    }
}
