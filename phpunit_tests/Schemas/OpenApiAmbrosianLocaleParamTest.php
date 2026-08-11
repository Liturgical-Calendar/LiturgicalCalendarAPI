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
    private const string PARAM_NAME = 'AmbrosianLocaleParam';

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

    /** @return string[] */
    private static function advertisedLocales(): array
    {
        /** @var array<string,array<string,mixed>> $params */
        $params = self::$openapi['components']['parameters'];
        self::assertArrayHasKey(self::PARAM_NAME, $params);

        /** @var array{schema:array{enum:string[]}} $param */
        $param = $params[self::PARAM_NAME];

        return $param['schema']['enum'];
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
     * excluded: those handlers read parameters from the request body and ignore the query
     * string entirely, so an `in: query` declaration there would document behavior that
     * does not exist.
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
