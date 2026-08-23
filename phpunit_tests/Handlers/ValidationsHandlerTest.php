<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers;

use LiturgicalCalendar\Api\Handlers\ValidationsHandler;
use LiturgicalCalendar\Api\Http\Exception\MethodNotAllowedException;
use LiturgicalCalendar\Api\Http\Exception\NotAcceptableException;
use LiturgicalCalendar\Api\Http\Exception\ValidationException;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * `GET /validations` — what this API can be asked to check (#806 step A).
 *
 * The endpoint exists so clients stop hardcoding this repo's on-disk layout, so the assertion that
 * matters most is the negative one: no response may contain a filesystem path.
 */
#[CoversClass(ValidationsHandler::class)]
final class ValidationsHandlerTest extends AbstractHandlerTestCase
{
    public function testOptionsPreflightSucceeds(): void
    {
        $response = ( new ValidationsHandler() )->handle(
            $this->requestFor('OPTIONS', '/validations', [
                'Origin'                        => 'https://app.example.test',
                'Access-Control-Request-Method' => 'GET',
            ])
        );

        self::assertSame(204, $response->getStatusCode());
    }

    public function testGetReturnsTheInventoryEnvelope(): void
    {
        $response = ( new ValidationsHandler() )->handle($this->requestFor('GET', '/validations'));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));

        $body = $this->decodeJsonBody($response);
        self::assertArrayHasKey('litcal_validations', $body);

        // The static half alone is 18; enumeration only adds, so a fixed total would fail whenever a
        // calendar is added — a false alarm rather than drift. The drift test is what pins coverage.
        self::assertGreaterThan(18, count($body['litcal_validations']));

        $ids = array_column($body['litcal_validations'], 'id');
        self::assertSame(array_unique($ids), $ids, 'advertised ids must be unique');
    }

    public function testEveryItemCarriesTheAdvertisedFields(): void
    {
        $body = $this->decodeJsonBody(
            ( new ValidationsHandler() )->handle($this->requestFor('GET', '/validations'))
        );

        foreach ($body['litcal_validations'] as $item) {
            foreach (['id', 'kind', 'rite', 'region', 'label', 'schema', 'steps', 'expected_locales'] as $key) {
                self::assertArrayHasKey($key, $item);
            }
            self::assertContains($item['kind'], ['file', 'folder']);
            self::assertContains($item['rite'], ['roman', 'ambrosian']);
            self::assertTrue($item['region'] === null || preg_match('/^[A-Z]{2}$/', $item['region']) === 1);
            self::assertStringEndsWith('.json', $item['schema']);
            self::assertContains(
                $item['steps'],
                [['exists', 'parses', 'validates'], ['exists', 'parses', 'validates', 'covers']],
                "unexpected step list on {$item['id']}"
            );
            // The fourth step and the expectation it reports on are advertised together or not at all: a
            // client sizes its rendering from `steps`, so an item promising a `covers` card with nothing
            // to compare against would leave a card no frame ever paints.
            self::assertSame(
                in_array('covers', $item['steps'], true),
                null !== $item['expected_locales'],
                "{$item['id']}: the covers step and expected_locales disagree"
            );
        }
    }

    /** The reason the endpoint exists: no client should ever see a path again. */
    public function testTheResponseLeaksNoFilesystemPath(): void
    {
        $raw = (string) ( new ValidationsHandler() )->handle($this->requestFor('GET', '/validations'))->getBody();

        self::assertStringNotContainsString('jsondata', $raw);
        self::assertStringNotContainsString('sourcedata', $raw);
        self::assertStringNotContainsString('"path"', $raw);
    }

    public function testAnUnacceptableAcceptHeaderIsRejected(): void
    {
        $this->expectException(NotAcceptableException::class);
        ( new ValidationsHandler() )->handle(
            $this->requestFor('GET', '/validations', ['Accept' => 'image/png'])
        );
    }

    public function testANonGetVerbIsRejected(): void
    {
        $this->expectException(MethodNotAllowedException::class);
        ( new ValidationsHandler() )->handle($this->requestFor('DELETE', '/validations'));
    }

    public function testAPathParameterIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        ( new ValidationsHandler(['roman']) )->handle($this->requestFor('GET', '/validations/roman'));
    }
}
