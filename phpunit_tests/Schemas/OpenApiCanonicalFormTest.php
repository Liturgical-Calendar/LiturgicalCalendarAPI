<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Schemas;

use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Tests\Support\OpenApiPathItemTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * How openapi.json describes the relationship between the two equivalent spellings of a
 * rite-qualified route: the bare one (`/events/nation/IT`) and the canonical explicit one
 * (`/events/roman/nation/IT`).
 *
 * Two claims, both previously undocumented (#848):
 *
 * 1. **The canonical `Link` header.** `Router::canonicalRiteUrl()` answers a bare read request
 *    with an RFC 6596 `Link: <...>; rel="canonical"` naming the explicit form. Not one response
 *    in the document declared that header, so a generated client had no idea it existed. It is
 *    declared on the responses that actually carry it: the `200` (and the `304` that stands in
 *    for it) of the bare read operations. A request that already named the rite is itself
 *    canonical and carries no header — asserted wherever the explicit form has a response object
 *    of its own. `/calendar` and the `/data` i18n sub-resource share one response component
 *    between both spellings, so there the header rides on the shared component and its
 *    description carries the condition; those are the paths this test cannot assert negatively.
 *
 * 2. **Deprecation of the bare spellings.** The established rule is: a roman variant exists ⇒ the
 *    bare form is deprecated (`/calendar` has marked its bare operations so since the rite segment
 *    landed). #848 decided to apply that rule uniformly rather than let it drift, which reaches
 *    `/events`'s tiers and all of `/data`, write methods included: `deprecated` describes the
 *    *spelling* of the path, not the safety of the operation, and the write methods have an
 *    explicit spelling to move to just as the reads do.
 */
final class OpenApiCanonicalFormTest extends TestCase
{
    use OpenApiPathItemTrait;

    private const HEADER_REF = '#/components/headers/CanonicalRiteLink';

    /** Route families whose first path segment takes an optional rite segment. */
    private const RITE_ROUTES = ['calendar', 'events', 'data'];

    /**
     * Loaded lazily rather than in `setUpBeforeClass()`: the data providers below enumerate the
     * document itself, and PHPUnit runs providers before any fixture method.
     *
     * @var array<string,mixed>|null
     */
    private static ?array $openapi = null;

    /**
     * @return array<string,mixed>
     */
    private static function openapi(): array
    {
        if (null === self::$openapi) {
            $raw = file_get_contents(dirname(__DIR__, 2) . '/jsondata/schemas/openapi.json');

            if (false === $raw) {
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
     * The route family a path belongs to, or null when it is not one of the three that take a
     * rite segment. Split on segments rather than matched as a prefix: `/calendars` is a different
     * endpoint from `/calendar`, and a prefix match would sweep it in.
     */
    private static function riteRouteOf(string $path): ?string
    {
        $segments = explode('/', ltrim($path, '/'));
        $route    = $segments[0] ?? '';

        return in_array($route, self::RITE_ROUTES, true) ? $route : null;
    }

    /**
     * Whether the path states its rite explicitly (`/events/roman/...`, `/calendar/ambrosian/...`).
     */
    private static function isRiteQualified(string $path): bool
    {
        $segments = explode('/', ltrim($path, '/'));

        return isset($segments[1]) && null !== Rite::tryFrom($segments[1]);
    }

    /**
     * The canonical spelling of a bare path: the same path with the default rite named explicitly.
     */
    private static function canonicalTwinOf(string $path): string
    {
        $segments = explode('/', ltrim($path, '/'));
        array_splice($segments, 1, 0, Rite::default()->value);

        return '/' . implode('/', $segments);
    }

    /**
     * A response object with any `$ref` to a shared response component resolved, so a response
     * declared once and reused by several operations is inspected the same way as an inline one.
     *
     * @param array<string,mixed> $response
     *
     * @return array{0:array<string,mixed>,1:bool} the resolved response, and whether it was shared
     */
    private static function resolveResponse(array $response): array
    {
        if (false === isset($response['$ref'])) {
            return [$response, false];
        }

        $ref = $response['$ref'];
        self::assertIsString($ref);
        self::assertStringStartsWith('#/components/responses/', $ref);

        /** @var array<string,array<string,array<string,mixed>>> $components */
        $components = self::openapi()['components'];
        $name       = substr($ref, strlen('#/components/responses/'));
        self::assertArrayHasKey($name, $components['responses'], "unresolvable response {$ref}");

        return [$components['responses'][$name], true];
    }

    /**
     * @param array<string,mixed> $response
     */
    private static function declaresCanonicalLink(array $response): bool
    {
        $headers = $response['headers'] ?? [];

        return is_array($headers)
            && isset($headers['Link'])
            && is_array($headers['Link'])
            && ( $headers['Link']['$ref'] ?? null ) === self::HEADER_REF;
    }

    /**
     * The read operations of every bare path in the three rite-taking families: exactly the
     * operations `Router::canonicalRiteUrl()` emits the header on.
     *
     * @return array<string,array{0:string,1:string}>
     */
    public static function bareReadOperations(): array
    {
        $operations = [];

        foreach (self::paths() as $path => $pathItem) {
            $path = (string) $path;
            if (null === self::riteRouteOf($path) || self::isRiteQualified($path)) {
                continue;
            }

            /** @var array<string,mixed> $pathItem */
            foreach (array_intersect(self::operationMethods($pathItem), ['get', 'post']) as $method) {
                $operations[strtoupper($method) . ' ' . $path] = [$path, $method];
            }
        }

        return $operations;
    }

    /**
     * The header is declared on the `200`, and on the `304` wherever one is documented: a resolved
     * conditional request stands in for the `200` it would otherwise have been and carries the same
     * canonical URL, so a client driving its own conditional requests does not lose it.
     */
    #[DataProvider('bareReadOperations')]
    public function testABareReadOperationDeclaresTheCanonicalLinkHeader(string $path, string $method): void
    {
        /** @var array<string,array<string,array<string,mixed>>> $pathItem */
        $pathItem = self::paths()[$path];
        /** @var array<string,array<string,mixed>> $responses */
        $responses = $pathItem[$method]['responses'];

        foreach (['200', '304'] as $status) {
            if (false === isset($responses[$status])) {
                continue;
            }

            [$response] = self::resolveResponse($responses[$status]);
            self::assertTrue(
                self::declaresCanonicalLink($response),
                strtoupper($method) . " {$path} carries a Link: rel=\"canonical\" on its {$status}, which it does not declare"
            );
        }
    }

    /**
     * The counterpart: a request that already names its rite is canonical, so it sends no header.
     * Only assertable where the explicit form has a response object of its own — `/calendar` and
     * the `/data` i18n sub-resource share one component between both spellings.
     *
     * @return array<string,array{0:string,1:string}>
     */
    public static function riteQualifiedReadOperations(): array
    {
        $operations = [];

        foreach (self::paths() as $path => $pathItem) {
            $path = (string) $path;
            if (null === self::riteRouteOf($path) || false === self::isRiteQualified($path)) {
                continue;
            }

            /** @var array<string,mixed> $pathItem */
            foreach (array_intersect(self::operationMethods($pathItem), ['get', 'post']) as $method) {
                $operations[strtoupper($method) . ' ' . $path] = [$path, $method];
            }
        }

        return $operations;
    }

    #[DataProvider('riteQualifiedReadOperations')]
    public function testARiteQualifiedReadOperationDeclaresNoCanonicalLinkHeader(string $path, string $method): void
    {
        /** @var array<string,array<string,array<string,mixed>>> $pathItem */
        $pathItem = self::paths()[$path];
        /** @var array<string,array<string,mixed>> $responses */
        $responses = $pathItem[$method]['responses'];

        foreach (['200', '304'] as $status) {
            if (false === isset($responses[$status])) {
                continue;
            }

            [$response, $shared] = self::resolveResponse($responses[$status]);
            if ($shared) {
                // Shared with the bare spelling, which does carry the header; the condition lives
                // in the header's own description rather than in the operation.
                continue;
            }

            self::assertFalse(
                self::declaresCanonicalLink($response),
                strtoupper($method) . " {$path} is already canonical, so its {$status} must not declare a canonical Link header"
            );
        }
    }

    /**
     * `/data`'s write methods are the reason the header is scoped by method at all: a
     * `rel="canonical"` on a `PUT` would describe nothing the request is doing.
     *
     * @return array<string,array{0:string,1:string}>
     */
    public static function writeOperations(): array
    {
        $operations = [];

        foreach (self::paths() as $path => $pathItem) {
            $path = (string) $path;
            if (null === self::riteRouteOf($path)) {
                continue;
            }

            /** @var array<string,mixed> $pathItem */
            foreach (array_intersect(self::operationMethods($pathItem), ['put', 'patch', 'delete']) as $method) {
                $operations[strtoupper($method) . ' ' . $path] = [$path, $method];
            }
        }

        return $operations;
    }

    #[DataProvider('writeOperations')]
    public function testAWriteOperationDeclaresNoCanonicalLinkHeader(string $path, string $method): void
    {
        /** @var array<string,array<string,array<string,mixed>>> $pathItem */
        $pathItem = self::paths()[$path];
        /** @var array<string,array<string,mixed>> $responses */
        $responses = $pathItem[$method]['responses'];

        foreach ($responses as $status => $response) {
            [$resolved] = self::resolveResponse($response);
            self::assertFalse(
                self::declaresCanonicalLink($resolved),
                strtoupper($method) . " {$path} emits no canonical Link header, so its {$status} must not declare one"
            );
        }
    }

    /**
     * The header is declared once and referenced, so its description — the only place the
     * "bare spelling, read methods, not a redirect" contract is written down — cannot drift
     * between the responses that carry it.
     */
    public function testTheCanonicalLinkHeaderIsDefinedOnce(): void
    {
        /** @var array<string,array<string,array<string,mixed>>> $components */
        $components = self::openapi()['components'];

        self::assertArrayHasKey('headers', $components, 'openapi.json defines no reusable headers at all');
        self::assertArrayHasKey('CanonicalRiteLink', $components['headers']);

        $header = $components['headers']['CanonicalRiteLink'];
        self::assertArrayHasKey('description', $header);
        self::assertIsString($header['description']);
        self::assertStringContainsString('rel="canonical"', $header['description']);
        self::assertSame(['type' => 'string'], $header['schema'] ?? null);
    }

    /**
     * Of the two equivalent spellings the explicit one is canonical, and the bare one is retained
     * only for backwards compatibility — so wherever the canonical spelling is documented, the bare
     * one is deprecated. Applied uniformly (#848): the rule was already in force on `/calendar`,
     * and leaving `/events`'s tiers and `/data` outside it would have made the document state the
     * rule in one family and contradict it in the next.
     *
     * @return array<string,array{0:string}>
     */
    public static function barePathsWithACanonicalTwin(): array
    {
        $paths = [];

        foreach (array_keys(self::paths()) as $path) {
            $path = (string) $path;
            if (null === self::riteRouteOf($path) || self::isRiteQualified($path)) {
                continue;
            }

            if (isset(self::paths()[self::canonicalTwinOf($path)])) {
                $paths[$path] = [$path];
            }
        }

        return $paths;
    }

    #[DataProvider('barePathsWithACanonicalTwin')]
    public function testABarePathWithACanonicalTwinIsDeprecated(string $path): void
    {
        /** @var array<string,array<string,mixed>> $pathItem */
        $pathItem = self::paths()[$path];

        foreach (self::operationMethods($pathItem) as $method) {
            /** @var array<string,mixed> $operation */
            $operation = $pathItem[$method];
            self::assertTrue(
                $operation['deprecated'] ?? false,
                strtoupper($method) . " {$path} has a canonical twin at " . self::canonicalTwinOf($path) . ', so it must be marked deprecated'
            );
        }
    }

    /**
     * The other half of the rule: the canonical spelling is the one clients are being moved to, so
     * nothing rite-qualified may carry the deprecation marker.
     *
     * @return array<string,array{0:string}>
     */
    public static function riteQualifiedPaths(): array
    {
        $paths = [];

        foreach (array_keys(self::paths()) as $path) {
            $path = (string) $path;
            if (null !== self::riteRouteOf($path) && self::isRiteQualified($path)) {
                $paths[$path] = [$path];
            }
        }

        return $paths;
    }

    #[DataProvider('riteQualifiedPaths')]
    public function testARiteQualifiedPathIsNotDeprecated(string $path): void
    {
        /** @var array<string,array<string,mixed>> $pathItem */
        $pathItem = self::paths()[$path];

        foreach (self::operationMethods($pathItem) as $method) {
            /** @var array<string,mixed> $operation */
            $operation = $pathItem[$method];
            self::assertFalse(
                $operation['deprecated'] ?? false,
                strtoupper($method) . " {$path} is the canonical spelling, so it must not be marked deprecated"
            );
        }
    }
}
