<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Schemas;

use LiturgicalCalendar\Api\Enum\PathCategory;
use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Handlers\RegionalDataHandler;
use LiturgicalCalendar\Api\Http\Enum\RequestMethod;
use LiturgicalCalendar\Api\Http\Exception\ValidationException;
use LiturgicalCalendar\Api\Params\RegionalDataParams;
use LiturgicalCalendar\Api\Router;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `RegionalDataParams` canonicalises and validates a `locale` parameter across the whole `/data`
 * family, and openapi.json documented it on none of the paths (#839): a client could not discover
 * the parameter, could not learn its accepted values, and could not learn that a bad one is a
 * refusal rather than a fallback.
 *
 * Two things have to stay true and are asserted here against the running code rather than against
 * a restatement of it:
 *
 * - *where* the parameter is read from, which is not uniform. `RegionalDataHandler` merges
 *   `getScalarQueryParams()` into the parameter array for GET and `parseBodyParams()` for POST,
 *   never both, and neither for the write methods. The contract therefore has to declare `locale`
 *   as a query parameter on GET, as a request body field on POST, and not at all elsewhere. Each
 *   of those claims is probed against the handler in process below.
 * - *which values* it accepts, which is the full locale set the server ships and not the narrowed
 *   Ambrosian set that `/calendar/ambrosian` and `/events/ambrosian` document. `/data` narrows per
 *   calendar instead, and does it with a `422` rather than the `400` that an out-of-set value
 *   earns, so reusing `AmbrosianLocaleParam` on `/data/ambrosian/...` would misdescribe both the
 *   accepted set and the status code.
 *
 * The `locale` parameter is not the `{i18n_locale}` path segment of #838; that is a different
 * resource with a different binding, asserted in {@see OpenApiDataI18nSubResourceTest}.
 */
final class OpenApiDataLocaleParamTest extends TestCase
{
    private const string PARAM_NAME = 'RegionalDataLocaleQueryParam';
    private const string BODY_NAME  = 'RegionalDataRequestBody';

    /** @var array<string,mixed> */
    private static array $openapi;

    public static function setUpBeforeClass(): void
    {
        // The in-process probes read source data through JsonData paths.
        Router::getApiPaths();
    }

    /**
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
     * The `/data` paths that address a calendar definition, which are the ones that take a
     * `locale`. Derived from the document rather than listed, so a tier added later (as #843
     * added the rite-qualified forms) is covered without editing this file. The `{i18n_locale}`
     * sub-resource paths are excluded: they address the stored translation map, where a `locale`
     * is validated but inert.
     *
     * @return array<string,array{0:string}>
     */
    public static function calendarDataPaths(): array
    {
        $paths = [];

        foreach (array_keys(self::paths()) as $path) {
            if (false === str_starts_with($path, '/data/') || str_ends_with($path, '/{i18n_locale}')) {
                continue;
            }

            $paths[$path] = [$path];
        }

        return $paths;
    }

    /**
     * GET reads `locale` from the query string, so every calendar-data GET has to declare it, and
     * has to declare it by reference so that all seven tiers cannot drift apart.
     */
    #[DataProvider('calendarDataPaths')]
    public function testEveryCalendarDataGetDeclaresTheSharedLocaleQueryParameter(string $path): void
    {
        /** @var array<string,array<string,mixed>> $pathItem */
        $pathItem = self::paths()[$path];
        self::assertArrayHasKey('get', $pathItem);

        /** @var list<array<string,string>> $parameters */
        $parameters = $pathItem['get']['parameters'];

        self::assertContains(
            '#/components/parameters/' . self::PARAM_NAME,
            array_column($parameters, '$ref'),
            "GET {$path} accepts a `locale` query parameter but does not declare it"
        );
    }

    /**
     * POST is a read alias of GET but reads its parameters from the body, so the same `locale` has
     * to appear there as a body field and must *not* appear as a query parameter: a query `locale`
     * on POST is silently ignored, and declaring it would document behaviour that does not exist.
     */
    #[DataProvider('calendarDataPaths')]
    public function testEveryCalendarDataPostDocumentsTheLocaleBodyFieldAndNoQueryParameter(string $path): void
    {
        /** @var array<string,array<string,mixed>> $pathItem */
        $pathItem = self::paths()[$path];
        self::assertArrayHasKey('post', $pathItem);

        /** @var array<string,mixed> $operation */
        $operation = $pathItem['post'];

        /** @var list<array<string,string>> $parameters */
        $parameters = $operation['parameters'];
        self::assertNotContains(
            '#/components/parameters/' . self::PARAM_NAME,
            array_column($parameters, '$ref'),
            "POST {$path} ignores a query string `locale`; declaring it would document behaviour that does not exist"
        );

        self::assertArrayHasKey('requestBody', $operation, "POST {$path} must document its body parameters");

        /** @var array<string,array{schema:array<string,string>}> $content */
        $content = $operation['requestBody']['content'];
        self::assertNotEmpty($content);

        foreach ($content as $mediaType => $definition) {
            self::assertSame(
                '#/components/schemas/' . self::BODY_NAME,
                $definition['schema']['$ref'] ?? null,
                "POST {$path} ({$mediaType}) must reference the shared calendar source data body"
            );
        }
    }

    /**
     * The write methods read no client-supplied `locale` at all, so none of them may declare one.
     */
    #[DataProvider('calendarDataPaths')]
    public function testTheWriteMethodsDeclareNoLocaleParameter(string $path): void
    {
        /** @var array<string,array<string,mixed>> $pathItem */
        $pathItem = self::paths()[$path];

        foreach (['put', 'patch', 'delete'] as $method) {
            self::assertArrayHasKey($method, $pathItem);

            /** @var list<array<string,mixed>> $parameters */
            $parameters = $pathItem[$method]['parameters'];

            foreach ($parameters as $parameter) {
                $name = $parameter['name'] ?? self::componentParameterName($parameter);

                self::assertNotSame(
                    'locale',
                    $name,
                    strtoupper($method) . " {$path} does not read a client-supplied `locale`; only Accept-Language reaches it"
                );
            }
        }
    }

    /**
     * The parameter must point at the same shared locale definition the rest of the document uses
     * for an unrestricted `locale`, rather than carrying its own copy of a 900-entry enum. The
     * reference is compared against `/temporale`'s `locale` query parameter rather than to a
     * literal, so the two cannot drift apart: they describe the same accepted set.
     */
    public function testTheLocaleParameterReusesTheSharedLocaleDefinition(): void
    {
        /** @var array<string,array<string,mixed>> $params */
        $params = self::openapi()['components']['parameters'];
        self::assertArrayHasKey(self::PARAM_NAME, $params);

        /** @var array{schema:array<string,string>} $param */
        $param = $params[self::PARAM_NAME];

        self::assertSame(
            self::temporaleLocaleSchemaRef(),
            $param['schema']['$ref'] ?? null,
            'the /data locale parameter must reuse the shared unrestricted locale definition'
        );

        /** @var array<string,array<string,mixed>> $schemas */
        $schemas = self::openapi()['components']['schemas'];
        self::assertArrayHasKey(self::BODY_NAME, $schemas);

        /** @var array{properties:array<string,array<string,string>>} $body */
        $body = $schemas[self::BODY_NAME];
        self::assertSame(
            $param['schema']['$ref'],
            $body['properties']['locale']['$ref'] ?? null,
            'the POST body locale field must reuse the same set as the GET query parameter, not restate it'
        );
    }

    /**
     * `/data` is not narrowed to the Ambrosian liturgical languages the way `/calendar/ambrosian`
     * and `/events/ambrosian` are. `RegionalDataParams` accepts any locale the server ships, on
     * every tier and rite alike; the per-calendar narrowing happens later in the handler and is a
     * `422`, not the `400` an out-of-set value earns. Reusing `AmbrosianLocaleParam` here would
     * therefore advertise both the wrong set and the wrong status.
     */
    public function testTheAmbrosianTierIsNotNarrowedToTheAmbrosianLocaleSet(): void
    {
        $params = new RegionalDataParams([
            'category' => PathCategory::DIOCESE,
            'key'      => 'milano_it',
            'rite'     => Rite::AMBROSIAN,
            'locale'   => 'fr_FR',
        ]);

        self::assertSame(
            'fr_FR',
            $params->locale,
            'RegionalDataParams accepts any locale the server ships, including for an Ambrosian diocese'
        );

        /** @var array<string,array<string,mixed>> $schemas */
        $schemas = self::openapi()['components']['schemas'];

        /** @var array{enum:list<string>} $ambrosian */
        $ambrosian = $schemas['AmbrosianLocale'];
        self::assertNotContains(
            'fr_FR',
            $ambrosian['enum'],
            'the fixture this assertion rests on has changed: fr_FR is no longer outside the Ambrosian set'
        );

        foreach (array_keys(self::calendarDataPaths()) as $path) {
            /** @var array<string,array<string,mixed>> $pathItem */
            $pathItem = self::paths()[$path];

            foreach ($pathItem as $method => $operation) {
                if (false === is_array($operation) || false === isset($operation['parameters'])) {
                    continue;
                }

                /** @var list<array<string,string>> $parameters */
                $parameters = $operation['parameters'];
                self::assertNotContains(
                    '#/components/parameters/AmbrosianLocaleParam',
                    array_column($parameters, '$ref'),
                    "{$method} {$path} must not restrict its locales to the Ambrosian set; /data narrows per calendar with a 422"
                );
            }
        }
    }

    /**
     * Every place a client could put a `locale`, for each method whose binding can be settled
     * without writing anything, paired with whether the handler is expected to read it from there.
     * Both halves of the grid matter: the positive cases prove the parameter is declared where it
     * is read, and the negative ones prove it is not declared where it would be silently dropped.
     *
     * PUT and PATCH are absent on purpose. Their branch parses and schema-validates the calendar
     * payload *before* `RegionalDataParams` ever looks at a locale, so a probe carrying a bad
     * locale always fails on the payload first and can prove nothing; reaching the locale check
     * would require a payload valid enough to be written to disk. DELETE shares the same
     * "merges neither" branch and is probed below, which settles the family.
     *
     * @return array<string,array{0:string,1:string,2:bool}>
     */
    public static function bindingProbes(): array
    {
        return [
            'GET reads the query string'      => ['GET', 'query', true],
            'GET ignores the request body'    => ['GET', 'body', false],
            'POST reads the request body'     => ['POST', 'body', true],
            'POST ignores the query string'   => ['POST', 'query', false],
            'DELETE ignores the query string' => ['DELETE', 'query', false],
            'DELETE ignores the request body' => ['DELETE', 'body', false],
        ];
    }

    /**
     * Send a deliberately invalid locale in one specific place and require the handler to notice
     * it exactly when the contract says it will. A locale the server does not ship is refused by
     * `RegionalDataParams::setParams()` with a message naming the parameter, which is an
     * unmistakable signal that the value was bound; anything else means it was not.
     */
    #[DataProvider('bindingProbes')]
    public function testTheHandlerBindsLocaleWhereTheContractSaysItDoes(string $method, string $sentIn, bool $isRead): void
    {
        // A non-existent nation for DELETE, so that the probe can never reach a real deletion:
        // the key is refused first, and a locale refusal would still precede it if one were bound.
        $key   = $method === 'DELETE' ? 'ZZ' : 'IT';
        $query = $sentIn === 'query' ? ['locale' => 'zz_ZZ'] : [];
        $body  = $sentIn === 'body' ? '{"locale":"zz_ZZ"}' : '';

        $refusal = self::probe($method, ['nation', $key], $query, $body);

        self::assertSame(
            $isRead,
            self::isLocaleRefusal($refusal),
            $isRead
                ? "{$method} /data/nation/{$key} did not refuse an unsupported locale sent in the {$sentIn}, so the contract declares it in the wrong place"
                : "{$method} /data/nation/{$key} refused a locale sent in the {$sentIn}, so it does bind one there and the contract must say so"
        );
    }

    /**
     * A well-formed locale the calendar does not declare is a different refusal from a locale the
     * server does not ship: `422 Unprocessable Content`, not `400 Bad Request`. Both are reachable
     * on every calendar-data read, so both have to be declared, or a generated client has no
     * branch for the one status it will actually meet. This is the omission #843 fixed for the
     * rite-mismatch `422`; the locale narrowing produces the same status on the national and
     * wider-region tiers, where it was undeclared.
     */
    #[DataProvider('calendarDataPaths')]
    public function testBothLocaleRefusalsAreDeclaredOnTheReadOperations(string $path): void
    {
        /** @var array<string,array<string,mixed>> $pathItem */
        $pathItem = self::paths()[$path];

        foreach (['get', 'post'] as $method) {
            /** @var array<string,mixed> $responses */
            $responses = $pathItem[$method]['responses'];

            self::assertArrayHasKey(
                '400',
                $responses,
                strtoupper($method) . " {$path} refuses a locale the server does not ship with 400, which it does not declare"
            );
            self::assertArrayHasKey(
                '422',
                $responses,
                strtoupper($method) . " {$path} refuses a locale the calendar does not declare with 422, which it does not declare"
            );
        }
    }

    /**
     * The `422` above is not hypothetical: assert the handler really produces it for a locale that
     * is perfectly valid but foreign to the addressed calendar, so the response declared on every
     * read operation is anchored to behaviour and not to this test's opinion.
     */
    public function testTheCalendarScopedLocaleRefusalIsReachable(): void
    {
        $refusal = self::probe('GET', ['nation', 'IT'], ['locale' => 'en_US'], '');

        self::assertNotNull($refusal, 'GET /data/nation/IT?locale=en_US must be refused: IT declares only it_IT');
        self::assertStringContainsString('for param `locale`', $refusal->getMessage());
        self::assertSame(
            422,
            $refusal->getCode(),
            'a valid locale the calendar does not declare is a 422, not the 400 an unshipped locale earns'
        );
    }

    /**
     * Drive the handler in process for one request and hand back whatever it refused with.
     *
     * @param list<string>         $pathParams
     * @param array<string,string> $query
     */
    private static function probe(string $method, array $pathParams, array $query, string $body): ?\Throwable
    {
        $handler = new RegionalDataHandler($pathParams);
        $handler->setAllowedRequestMethods([
            RequestMethod::GET,
            RequestMethod::POST,
            RequestMethod::PUT,
            RequestMethod::PATCH,
            RequestMethod::DELETE,
        ]);

        $request = ( new ServerRequest($method, '/data/' . implode('/', $pathParams), [
            'Accept'          => 'application/json',
            'Accept-Language' => 'it-IT',
            'Content-Type'    => 'application/json',
        ]) )->withQueryParams($query);

        if ($body !== '') {
            $request = $request->withBody(Stream::create($body));
        }

        try {
            $handler->handle($request);
        } catch (\Throwable $refusal) {
            return $refusal;
        }

        return null;
    }

    /**
     * Whether a refusal is the parameter-level locale refusal, as opposed to no refusal at all or
     * a refusal about something else entirely (an unknown key, a malformed payload).
     */
    private static function isLocaleRefusal(?\Throwable $refusal): bool
    {
        return $refusal instanceof ValidationException
            && str_contains($refusal->getMessage(), 'for param `locale`');
    }

    /**
     * The `$ref` of `/temporale`'s `locale` query parameter, which is the document's existing
     * unrestricted locale surface.
     */
    private static function temporaleLocaleSchemaRef(): string
    {
        /** @var array<string,array<string,mixed>> $paths */
        $paths = self::paths();
        self::assertArrayHasKey('/temporale', $paths, 'the precedent this assertion compares against has moved');

        /** @var list<array<string,mixed>> $parameters */
        $parameters = $paths['/temporale']['get']['parameters'];

        foreach ($parameters as $parameter) {
            if (( $parameter['name'] ?? null ) !== 'locale') {
                continue;
            }

            /** @var array{schema:array<string,string>} $parameter */
            $ref = $parameter['schema']['$ref'] ?? null;
            self::assertIsString($ref);

            return $ref;
        }

        self::fail('/temporale no longer documents a locale query parameter');
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
