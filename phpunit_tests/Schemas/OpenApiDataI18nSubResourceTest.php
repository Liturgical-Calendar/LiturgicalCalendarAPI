<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Schemas;

use LiturgicalCalendar\Api\Enum\JsonData;
use LiturgicalCalendar\Api\Enum\LitSchema;
use LiturgicalCalendar\Api\Enum\PathCategory;
use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Handlers\RegionalDataHandler;
use LiturgicalCalendar\Api\Http\Exception\ValidationException;
use LiturgicalCalendar\Api\Params\RegionalDataParams;
use LiturgicalCalendar\Api\Router;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Swaggest\JsonSchema\Schema;

/**
 * `/data/{category}/{key}/{i18n_locale}` is a working sub-resource that returns a *different*
 * payload shape from the two-segment path it hangs off: `RegionalDataHandler` binds the third
 * path segment to `i18nRequest` and answers with the stored translation map rather than with a
 * calendar definition. openapi.json documented none of it (#838), so a client reading the
 * contract could not discover the resource, and could not learn what it returns.
 *
 * Everything here is asserted against the handler and its parameter object rather than against a
 * second hardcoded list, so the document cannot quietly drift away from the routing grammar:
 *
 * - which tiers the sub-resource exists on comes from {@see RegionalDataParams::validateRiteCompatibility()},
 *   exactly as {@see OpenApiDataRiteSegmentTest} derives the two-segment matrix;
 * - which methods it exists for comes from `RegionalDataHandler::validateRequestPath()`, the same
 *   guard the running API applies before any file path is built;
 * - what it returns comes from {@see LitSchema::I18N}, the schema the API itself validates stored
 *   translation files against.
 *
 * The `{i18n_locale}` path segment is deliberately *not* spelled `{locale}`: the `locale` parameter
 * of the two-segment paths (#839) is a different thing, and a contract that gave them one name
 * would be worse than one that documented neither.
 */
final class OpenApiDataI18nSubResourceTest extends TestCase
{
    /** @var array<string,mixed> */
    private static array $openapi;

    public static function setUpBeforeClass(): void
    {
        // Required for JsonData::path() and LitSchema::path() to resolve.
        Router::getApiPaths();
    }

    /**
     * The parsed document. Loaded lazily rather than in `setUpBeforeClass()` because the data
     * providers below derive their cases from it, and PHPUnit resolves providers first.
     *
     * @return array<string,mixed>
     */
    private static function openapi(): array
    {
        if (false === isset(self::$openapi)) {
            $raw = file_get_contents(dirname(__DIR__, 2) . '/jsondata/schemas/openapi.json');

            if (false === is_string($raw)) {
                throw new \RuntimeException('Could not read openapi.json');
            }

            /** @var array<string,mixed> $decoded */
            $decoded       = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            self::$openapi = $decoded;
        }

        return self::$openapi;
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private static function paths(): array
    {
        /** @var array<string,array<string,mixed>> $paths */
        $paths = self::openapi()['paths'];

        return $paths;
    }

    /**
     * Whether the handler's parameter object accepts this rite for this tier. Same check the
     * running API performs, and the same one the two-segment matrix is asserted against.
     */
    private static function combinationIsAccepted(Rite $rite, PathCategory $category): bool
    {
        try {
            new RegionalDataParams([
                'category' => $category,
                'key'      => 'milano_it',
                'rite'     => $rite,
            ]);
        } catch (ValidationException) {
            return false;
        }

        return true;
    }

    /**
     * @return array<string,array{0:Rite,1:PathCategory}>
     */
    public static function riteAndCategoryCombinations(): array
    {
        $combinations = [];

        foreach (Rite::cases() as $rite) {
            foreach (PathCategory::cases() as $category) {
                $combinations[$rite->value . ' / ' . $category->value] = [$rite, $category];
            }
        }

        return $combinations;
    }

    /**
     * The sub-resource hangs off every `/data` tier the API accepts, and off no other: the rite
     * segment narrows which tiers exist, not which sub-resources they carry. A documented path
     * the API refuses is a lie a generated client will act on; an undocumented one is #838.
     */
    #[DataProvider('riteAndCategoryCombinations')]
    public function testTheI18nSubResourceIsDocumentedExactlyWhereItsTierExists(Rite $rite, PathCategory $category): void
    {
        $paths = self::paths();

        if ($rite === Rite::default()) {
            self::assertArrayHasKey(
                "/data/{$category->value}/{key}/{i18n_locale}",
                $paths,
                "the bare {$category->value} tier accepts a third path segment but openapi.json does not document it"
            );

            self::assertArrayHasKey(
                "/data/{$rite->value}/{$category->value}/{key}/{i18n_locale}",
                $paths,
                'the canonical explicit-rite form must be documented alongside the bare form, as the two-segment paths are'
            );

            return;
        }

        $path = "/data/{$rite->value}/{$category->value}/{key}/{i18n_locale}";

        if (self::combinationIsAccepted($rite, $category)) {
            self::assertArrayHasKey(
                $path,
                $paths,
                "the API accepts {$path} but openapi.json does not document it"
            );
        } else {
            self::assertArrayNotHasKey(
                $path,
                $paths,
                "openapi.json documents {$path}, which the API refuses with 400 Bad Request"
            );
        }
    }

    /**
     * Whether `RegionalDataHandler` tolerates a three-segment `/data` path for this method. This
     * is `validateRequestPath()`, the first thing the handler does with a request and the guard
     * that decides whether the third segment is a sub-resource address or a malformed request.
     */
    private static function thirdSegmentIsAcceptedFor(string $method): bool
    {
        $pathParams = ['diocese', 'romamo_it', 'it_IT'];
        $handler    = new RegionalDataHandler($pathParams);
        $request    = new ServerRequest($method, '/data/' . implode('/', $pathParams));

        $validate = new \ReflectionMethod(RegionalDataHandler::class, 'validateRequestPath');

        try {
            $validate->invoke($handler, $request);
        } catch (ValidationException) {
            return false;
        }

        return true;
    }

    /**
     * @return array<string,array{0:string}>
     */
    public static function requestMethods(): array
    {
        $methods = [];

        foreach (['get', 'post', 'put', 'patch', 'delete'] as $method) {
            $methods[strtoupper($method)] = [$method];
        }

        return $methods;
    }

    /**
     * The sub-resource exists for `GET` and `POST` only. `validateRequestPath()` allows two or
     * three path segments for those and exactly two for the write methods, so a `PUT`, `PATCH` or
     * `DELETE` carrying a third segment never reaches the resource at all: it is refused with
     * `400 Bad Request` before authorization is even considered on the path count alone.
     *
     * #838 asked for this to be established rather than assumed, so it is asserted against the
     * guard instead of being restated: wiring a write method for three segments fails here until
     * the contract documents it.
     */
    #[DataProvider('requestMethods')]
    public function testOnlyTheMethodsThatAcceptAThirdSegmentAreDocumented(string $method): void
    {
        $accepted = self::thirdSegmentIsAcceptedFor(strtoupper($method));

        foreach (self::i18nPathKeys() as $path) {
            /** @var array<string,mixed> $pathItem */
            $pathItem = self::paths()[$path];

            if ($accepted) {
                self::assertArrayHasKey(
                    $method,
                    $pathItem,
                    strtoupper($method) . " {$path} is accepted by the handler but is not documented"
                );
            } else {
                self::assertArrayNotHasKey(
                    $method,
                    $pathItem,
                    'openapi.json documents ' . strtoupper($method) . " {$path}, which the handler refuses with 400 Bad Request on the path segment count"
                );
            }
        }
    }

    /**
     * The whole point of #838 is that this sub-resource does not return a calendar. Its `200` must
     * therefore name the schema the API itself validates stored translation files against, and not
     * one of the calendar schemas the two-segment paths return.
     *
     * @return array<string,array{0:string,1:string}>
     */
    public static function i18nOperations(): array
    {
        $operations = [];

        foreach (self::i18nPathKeys() as $path) {
            foreach (['get', 'post'] as $method) {
                $operations[strtoupper($method) . ' ' . $path] = [$path, $method];
            }
        }

        return $operations;
    }

    #[DataProvider('i18nOperations')]
    public function testTheTranslationMapIsDocumentedWithTheStoredTranslationSchema(string $path, string $method): void
    {
        /** @var array<string,array<string,mixed>> $pathItem */
        $pathItem = self::paths()[$path];
        self::assertArrayHasKey($method, $pathItem);

        /** @var array<string,mixed> $responses */
        $responses = $pathItem[$method]['responses'];
        self::assertArrayHasKey('200', $responses, strtoupper($method) . " {$path} must document a success response");

        $ref = self::resolveResponseSchemaRef($responses['200']);

        self::assertNotNull(
            $ref,
            strtoupper($method) . " {$path} must name a response schema for the translation map"
        );
        self::assertStringEndsWith(
            LitSchema::I18N->value,
            $ref,
            strtoupper($method) . " {$path} must return the stored translation schema (" . LitSchema::I18N->value . '), not a calendar schema'
        );
    }

    /**
     * The documented response schema has to actually describe what the handler serves.
     * `getI18nData()` streams the stored `i18n/{locale}.json` file back verbatim, so a real one of
     * those files is the response body, and validating it against the schema the contract names
     * closes the loop between the two.
     */
    public function testAStoredTranslationFileValidatesAgainstTheDocumentedResponseSchema(): void
    {
        $file = strtr(JsonData::NATIONAL_CALENDAR_I18N_FILE->path(), [
            '{nation}' => 'IT',
            '{locale}' => 'it_IT',
        ]);

        self::assertFileExists($file, 'the fixture this assertion is built on has moved');

        $contents = file_get_contents($file);
        self::assertIsString($contents);

        $payload = json_decode($contents, false, 512, JSON_THROW_ON_ERROR);

        $schema = Schema::import(LitSchema::I18N->path());
        $schema->in($payload);

        self::assertIsObject($payload, 'the translation map is a JSON object of event_key to translated name');
    }

    /**
     * `i18n` and `locale` must stay two names for two things. The third path segment binds to
     * `i18nRequest` and selects a stored file; the `locale` parameter of the two-segment paths
     * (#839) selects the language of a calendar definition and has no effect here. Spelling the
     * segment `{locale}` would collapse the distinction that both issues insist on, and would put
     * two differently-behaving parameters called `locale` on one operation.
     */
    #[DataProvider('i18nOperations')]
    public function testTheI18nSegmentIsNotSpelledLikeTheLocaleParameter(string $path, string $method): void
    {
        self::assertStringContainsString('{i18n_locale}', $path);

        /** @var array<string,array<string,mixed>> $pathItem */
        $pathItem = self::paths()[$path];

        /** @var list<array<string,mixed>> $parameters */
        $parameters = $pathItem[$method]['parameters'];

        foreach ($parameters as $parameter) {
            $name = $parameter['name'] ?? self::componentParameterName($parameter);

            self::assertNotSame(
                'locale',
                $name,
                strtoupper($method) . " {$path} declares a parameter named `locale`; the sub-resource segment binds to i18nRequest and the two must not share a name"
            );
        }
    }

    /**
     * The i18n sub-resource paths documented in openapi.json.
     *
     * @return list<string>
     */
    private static function i18nPathKeys(): array
    {
        return array_values(array_filter(
            array_keys(self::paths()),
            static fn (string $path): bool => str_starts_with($path, '/data/') && str_ends_with($path, '/{i18n_locale}')
        ));
    }

    /**
     * The `$ref` of the `application/json` schema of a response, following one level of
     * `#/components/responses` indirection.
     *
     * @param array<string,mixed> $response
     */
    private static function resolveResponseSchemaRef(array $response): ?string
    {
        if (isset($response['$ref']) && is_string($response['$ref'])) {
            $name = substr($response['$ref'], strrpos($response['$ref'], '/') + 1);
            /** @var array<string,array<string,mixed>> $components */
            $components = self::openapi()['components']['responses'];
            self::assertArrayHasKey($name, $components, "unknown response component {$name}");
            $response = $components[$name];
        }

        $ref = $response['content']['application/json']['schema']['$ref'] ?? null;

        return is_string($ref) ? $ref : null;
    }

    /**
     * The `name` of a parameter given as a `#/components/parameters` reference.
     *
     * @param array<string,mixed> $parameter
     */
    private static function componentParameterName(array $parameter): ?string
    {
        if (false === isset($parameter['$ref']) || false === is_string($parameter['$ref'])) {
            return null;
        }

        $key = substr($parameter['$ref'], strrpos($parameter['$ref'], '/') + 1);
        /** @var array<string,array<string,mixed>> $components */
        $components = self::openapi()['components']['parameters'];
        self::assertArrayHasKey($key, $components, "unknown parameter component {$key}");

        $name = $components[$key]['name'] ?? null;

        return is_string($name) ? $name : null;
    }
}
