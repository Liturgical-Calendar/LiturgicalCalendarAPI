<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Schemas;

use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Router;
use LiturgicalCalendar\Api\Services\CalendarMetadataProvider;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The Ambrosian operations describe a `locale` parameter that returns 400 for a language
 * the rite has no books for (issue #761), so the contract has to actually declare that
 * parameter — prose alone leaves a generated client with no idea the restriction exists.
 *
 * Every value the schema advertises must be one the endpoint really accepts, and it must
 * be advertised on the operations that really read it. Both halves are asserted against
 * the same runtime rule the handlers enforce
 * ({@see CalendarMetadataProvider::riteSupportsLocale()}) rather than against a second
 * hardcoded list, so the schema cannot quietly drift away from the behavior.
 */
final class OpenApiAmbrosianLocaleParamTest extends TestCase
{
    private const string PARAM_NAME  = 'AmbrosianLocaleParam';
    private const string SCHEMA_NAME = 'AmbrosianLocale';
    private const string BODY_NAME   = 'AmbrosianEventsRequestBody';

    /** @var array<string,mixed> */
    private static array $openapi;

    public static function setUpBeforeClass(): void
    {
        // riteSupportsLocale() derives the rite's languages from the Ambrosian i18n folder
        // on disk, and JsonData paths are built from Router::$apiFilePath.
        Router::$apiFilePath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR;

        $raw = file_get_contents(dirname(__DIR__, 2) . '/jsondata/schemas/openapi.json');
        self::assertIsString($raw, 'Could not read openapi.json');

        /** @var array<string,mixed> $decoded */
        $decoded       = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        self::$openapi = $decoded;
    }

    /**
     * The locale set the contract advertises, read from the shared schema that both the
     * query parameter and the events request body reference — so a drift in either usage
     * is a drift in the value asserted here.
     *
     * @return string[]
     */
    private static function advertisedLocales(): array
    {
        /** @var array<string,array<string,mixed>> $schemas */
        $schemas = self::$openapi['components']['schemas'];
        self::assertArrayHasKey(self::SCHEMA_NAME, $schemas);

        /** @var array{enum:string[]} $schema */
        $schema = $schemas[self::SCHEMA_NAME];

        return $schema['enum'];
    }

    /**
     * The query parameter must not carry its own copy of the locale set; it has to point at
     * the shared schema, otherwise the parameter and the request body can disagree.
     */
    public function testTheQueryParameterReferencesTheSharedLocaleSchema(): void
    {
        /** @var array<string,array<string,mixed>> $params */
        $params = self::$openapi['components']['parameters'];
        self::assertArrayHasKey(self::PARAM_NAME, $params);

        /** @var array{schema:array<string,string>} $param */
        $param = $params[self::PARAM_NAME];
        self::assertSame('#/components/schemas/' . self::SCHEMA_NAME, $param['schema']['$ref'] ?? null);
    }

    /**
     * The advertised set must be non-empty and must not have been widened to locales the
     * rite rejects — the parameter exists precisely to express that narrowing.
     */
    public function testEveryAdvertisedLocaleIsOneTheRiteAccepts(): void
    {
        $advertised = self::advertisedLocales();
        self::assertNotEmpty($advertised);

        foreach ($advertised as $locale) {
            self::assertTrue(
                CalendarMetadataProvider::riteSupportsLocale(Rite::AMBROSIAN, $locale),
                "openapi.json advertises `{$locale}` for the Ambrosian rite, which rejects it at runtime"
            );
        }
    }

    /**
     * Both liturgical languages must be reachable through the documented parameter. Latin is
     * the editio typica and Italian the vernacular edition; advertising only one of them
     * would document a narrower API than the one that ships.
     */
    public function testBothAmbrosianLiturgicalLanguagesAreAdvertised(): void
    {
        $languages = array_map(fn (string $locale): string => explode('_', $locale)[0], self::advertisedLocales());

        self::assertContains('it', $languages);
        self::assertContains('la', $languages);
    }

    /**
     * The GET operations that read `locale` from the query string. POST is deliberately
     * excluded here: those handlers read parameters from the request body and ignore the
     * query string entirely (verified: `POST …?locale=nl` returns 200 while the same value
     * in the body returns 400), so an `in: query` declaration there would document behavior
     * that does not exist. The events POST operations document it as a body field instead —
     * see {@see self::testEventsPostOperationsDocumentTheLocaleRequestBody()}.
     *
     * @return array<string,array{0:string}>
     */
    public static function ambrosianGetOperations(): array
    {
        return [
            'comune calendar'          => ['/calendar/ambrosian'],
            'comune calendar for year' => ['/calendar/ambrosian/{year}'],
            'diocesan calendar'        => ['/calendar/ambrosian/diocese/{calendar_id}'],
            'diocesan calendar/year'   => ['/calendar/ambrosian/diocese/{calendar_id}/{year}'],
            'comune events'            => ['/events/ambrosian'],
            'diocesan events'          => ['/events/ambrosian/diocese/{calendar_id}'],
        ];
    }

    #[DataProvider('ambrosianGetOperations')]
    public function testLocaleParameterIsDeclaredOnEveryAmbrosianGetOperation(string $path): void
    {
        /** @var array<string,array<string,mixed>> $paths */
        $paths = self::$openapi['paths'];
        self::assertArrayHasKey($path, $paths);

        /** @var array{parameters:array<int,array<string,string>>} $operation */
        $operation  = $paths[$path]['get'];
        $referenced = array_column($operation['parameters'], '$ref');

        self::assertContains(
            '#/components/parameters/' . self::PARAM_NAME,
            $referenced,
            "GET {$path} documents the locale restriction in prose but does not declare the parameter"
        );
    }

    /**
     * The Ambrosian events POST operations accept the same restricted `locale`, but as a
     * request body field rather than a query parameter, and they declared no request body at
     * all. Assert every content type they document resolves to the shared body schema, so the
     * 400 the description promises is expressed in the contract for POST too — and so a
     * client generated from this document can find the field.
     *
     * @return array<string,array{0:string}>
     */
    public static function ambrosianEventsPostOperations(): array
    {
        return [
            'comune events'   => ['/events/ambrosian'],
            'diocesan events' => ['/events/ambrosian/diocese/{calendar_id}'],
        ];
    }

    #[DataProvider('ambrosianEventsPostOperations')]
    public function testEventsPostOperationsDocumentTheLocaleRequestBody(string $path): void
    {
        /** @var array<string,array<string,mixed>> $paths */
        $paths = self::$openapi['paths'];
        self::assertArrayHasKey($path, $paths);

        /** @var array{requestBody?:array{content:array<string,array{schema:array<string,string>}>}} $operation */
        $operation = $paths[$path]['post'];
        self::assertArrayHasKey('requestBody', $operation, "POST {$path} must document its body parameters");

        $content = $operation['requestBody']['content'];
        self::assertNotEmpty($content);

        foreach ($content as $mediaType => $definition) {
            self::assertSame(
                '#/components/schemas/' . self::BODY_NAME,
                $definition['schema']['$ref'] ?? null,
                "POST {$path} ({$mediaType}) must reference the documented Ambrosian events body"
            );
        }

        /** @var array<string,array<string,mixed>> $schemas */
        $schemas = self::$openapi['components']['schemas'];
        self::assertArrayHasKey(self::BODY_NAME, $schemas);

        /** @var array{properties:array<string,array<string,string>>} $body */
        $body = $schemas[self::BODY_NAME];
        self::assertSame(
            '#/components/schemas/' . self::SCHEMA_NAME,
            $body['properties']['locale']['$ref'] ?? null,
            'the body locale field must reuse the shared locale set, not restate it'
        );
    }

    /**
     * The restriction is rite-scoped, so the Roman calendar operations must not pick up the
     * Ambrosian parameter — they accept every locale the API ships.
     */
    public function testRomanOperationsDoNotUseTheAmbrosianLocaleParameter(): void
    {
        /** @var array<string,array<string,mixed>> $paths */
        $paths = self::$openapi['paths'];

        foreach ($paths as $path => $operations) {
            if (str_contains($path, '/ambrosian')) {
                continue;
            }

            foreach ($operations as $method => $operation) {
                if (false === is_array($operation) || false === isset($operation['parameters'])) {
                    continue;
                }

                /** @var array<int,array<string,string>> $parameters */
                $parameters = $operation['parameters'];
                self::assertNotContains(
                    '#/components/parameters/' . self::PARAM_NAME,
                    array_column($parameters, '$ref'),
                    "{$method} {$path} is not an Ambrosian route and must not restrict its locales"
                );
            }
        }
    }
}
