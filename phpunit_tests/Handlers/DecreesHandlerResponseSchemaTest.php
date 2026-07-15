<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers;

use LiturgicalCalendar\Api\Enum\LitSchema;
use LiturgicalCalendar\Api\Handlers\DecreesHandler;
use Swaggest\JsonSchema\Schema;

/**
 * Validates the actual GET /decrees index response against the per-action
 * response shapes in LitCalDecreesPath.json (issue #314).
 */
final class DecreesHandlerResponseSchemaTest extends AbstractHandlerTestCase
{
    private function decreesIndexBody(): \stdClass
    {
        $resp = ( new DecreesHandler() )->handle(
            $this->requestFor('GET', '/decrees', ['Accept-Language' => 'en'])
        );
        self::assertSame(200, $resp->getStatusCode());
        $body = json_decode((string) $resp->getBody());
        assert($body instanceof \stdClass);
        return $body;
    }

    public function testDecreesIndexValidatesAgainstPathSchema(): void
    {
        $body = $this->decreesIndexBody();
        Schema::import(LitSchema::DECREES->path())->in($body);
        $this->addToAssertionCount(1);
    }

    public function testUrlsLangsInResponseMetadataIsRejected(): void
    {
        // The models drop urls_langs; a response carrying it must not validate.
        $body                                          = $this->decreesIndexBody();
        $body->litcal_decrees[0]->metadata->urls_langs = (object) ['en' => 'https://www.vatican.va/roman_curia/congregations/ccdds/documents/test.html'];
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
}
