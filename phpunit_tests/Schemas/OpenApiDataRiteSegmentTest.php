<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Schemas;

use LiturgicalCalendar\Api\Enum\PathCategory;
use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Http\Exception\ValidationException;
use LiturgicalCalendar\Api\Params\RegionalDataParams;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `Router::extractRiteSegment()` accepts an optional leading rite segment on `data`, exactly as
 * it does on `calendar` and `events`, but openapi.json documented only the bare forms — so a
 * client reading the contract could not discover `/data/ambrosian/diocese/{key}` at all (#818).
 *
 * The rite × tier matrix is deliberately not full: only the diocesan tier exists under more than
 * one rite, so `/data/ambrosian/nation/{key}` and `/data/ambrosian/widerregion/{key}` are refused
 * by {@see RegionalDataParams::validateRiteCompatibility()}. A contract that advertised them would
 * be worse than one that omits the rite segment entirely.
 *
 * Both halves are asserted against that runtime rule rather than against a second hardcoded list,
 * so the document cannot quietly drift away from the routing grammar: adding a rite, or giving a
 * rite a national tier, fails here until the contract says so too.
 */
final class OpenApiDataRiteSegmentTest extends TestCase
{
    /** @var array<string,mixed> */
    private static array $openapi;

    public static function setUpBeforeClass(): void
    {
        $raw = file_get_contents(dirname(__DIR__, 2) . '/jsondata/schemas/openapi.json');
        self::assertIsString($raw, 'Could not read openapi.json');

        /** @var array<string,mixed> $decoded */
        $decoded       = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        self::$openapi = $decoded;
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private static function paths(): array
    {
        /** @var array<string,array<string,mixed>> $paths */
        $paths = self::$openapi['paths'];

        return $paths;
    }

    /**
     * Whether the handler's parameter object accepts this rite for this tier. This is the same
     * check the running API performs before any file path is built.
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
     * A non-default rite is addressed by naming it in the path, so the contract must list exactly
     * the rite-qualified paths the API accepts — no more (a documented 400 is a lie a generated
     * client will act on) and no fewer (the omission is what #818 reports).
     *
     * The Roman rite is deliberately excluded: it is the rite an absent segment resolves to, and
     * the contract expresses it through the bare path rather than through a `/data/roman/...`
     * duplicate of every operation. `/data/roman/{category}/{key}` is still accepted at runtime as
     * an explicit synonym, and the bare paths' descriptions say so.
     */
    #[DataProvider('riteAndCategoryCombinations')]
    public function testRiteQualifiedPathIsDocumentedExactlyWhenItIsAccepted(Rite $rite, PathCategory $category): void
    {
        if ($rite === Rite::default()) {
            self::assertArrayHasKey(
                "/data/{$category->value}/{key}",
                self::paths(),
                'the bare form of every tier must stay documented'
            );

            // The exclusion described in this method's docblock, asserted rather than merely stated.
            // Without this the decision lived only in prose, and a `/data/roman/...` duplicate could be
            // added without anything noticing that the contract had grown a second spelling of a path
            // it already documents. Should that duplication ever become the intent — mirroring how
            // `/calendar` enumerates both rites at every tier — this is the assertion to revisit, which
            // is the point: the change would be a deliberate one rather than a drift.
            self::assertArrayNotHasKey(
                "/data/{$rite->value}/{$category->value}/{key}",
                self::paths(),
                'the Roman rite is expressed through the bare path, not through a /data/roman/... duplicate'
            );

            return;
        }

        $path     = "/data/{$rite->value}/{$category->value}/{key}";
        $accepted = self::combinationIsAccepted($rite, $category);

        if ($accepted) {
            self::assertArrayHasKey(
                $path,
                self::paths(),
                "the API accepts {$path} but openapi.json does not document it"
            );
        } else {
            self::assertArrayNotHasKey(
                $path,
                self::paths(),
                "openapi.json documents {$path}, which the API refuses with 400 Bad Request"
            );
        }
    }

    /**
     * The rite segment selects a partition of the source tree; it does not change what may be done
     * to it. `/data` is wired for GET, POST, PUT, PATCH and DELETE alike on both forms (Router's
     * `data` case keys the allowed methods off the path-part count, which the rite segment does not
     * alter), and the write methods are JWT-protected on both. A rite-qualified path that quietly
     * documented a narrower — or wider — surface would misdescribe the authorization boundary.
     */
    public function testTheRiteQualifiedPathMirrorsTheBareFormsOperationSurface(): void
    {
        $paths = self::paths();
        self::assertArrayHasKey('/data/ambrosian/diocese/{key}', $paths);

        /** @var array<string,array<string,mixed>> $bare */
        $bare = $paths['/data/diocese/{key}'];
        /** @var array<string,array<string,mixed>> $riteQualified */
        $riteQualified = $paths['/data/ambrosian/diocese/{key}'];

        // Compare the *sets* of documented methods. Ordering inside a path item carries no meaning in
        // OpenAPI, and a path item may legally hold non-method keys (`parameters`, `summary`, `servers`),
        // so an ordered comparison of raw keys would fail on a cosmetic reshuffle and would drag a
        // hoisted `parameters` array into the security loop below, where it would compare null to null
        // and pass for the wrong reason.
        $bareMethods          = self::operationMethods($bare);
        $riteQualifiedMethods = self::operationMethods($riteQualified);

        self::assertSame(
            $bareMethods,
            $riteQualifiedMethods,
            'the rite-qualified path must document the same methods as the bare form'
        );

        foreach ($bareMethods as $method) {
            self::assertSame(
                $bare[$method]['security'] ?? null,
                $riteQualified[$method]['security'] ?? null,
                "{$method} /data/ambrosian/diocese/{key} must be protected exactly like the bare form"
            );
        }
    }

    /**
     * The refusal of an Ambrosian diocese on the bare path is a `422`, not a `404` or a `400`
     * (`RegionalDataHandler::checkDiocesanCalendarConditions()`), which means the rite segment is
     * *required* for those dioceses rather than an optional decoration. Every operation of both
     * diocesan paths that can produce that refusal has to declare it, or a client generated from
     * this document has no branch to handle the one status it will actually meet.
     *
     * @return array<string,array{0:string,1:string}>
     */
    public static function diocesanOperationsThatCanRefuseOnRite(): array
    {
        $operations = [];

        // PUT is excluded: it creates a calendar that does not exist yet, so there is no stored
        // rite to disagree with — the conditions check skips the comparison for PUT.
        foreach (['get', 'post', 'patch', 'delete'] as $method) {
            foreach (['/data/diocese/{key}', '/data/ambrosian/diocese/{key}'] as $path) {
                $operations[strtoupper($method) . ' ' . $path] = [$path, $method];
            }
        }

        return $operations;
    }

    #[DataProvider('diocesanOperationsThatCanRefuseOnRite')]
    public function testTheRiteMismatchRefusalIsDeclared(string $path, string $method): void
    {
        $paths = self::paths();
        self::assertArrayHasKey($path, $paths);

        /** @var array<string,array<string,array<string,mixed>>> $pathItem */
        $pathItem = $paths[$path];
        self::assertArrayHasKey($method, $pathItem);

        self::assertArrayHasKey(
            '422',
            $pathItem[$method]['responses'],
            strtoupper($method) . " {$path} can refuse a diocese of the wrong rite with 422, which it does not declare"
        );
    }

    /**
     * The HTTP methods a path item documents, sorted, with any non-method key (`parameters`,
     * `summary`, `description`, `servers`) filtered out — those are legal siblings of the operations
     * in an OpenAPI path item and are not operations themselves.
     *
     * @param array<string,mixed> $pathItem
     *
     * @return list<string>
     */
    private static function operationMethods(array $pathItem): array
    {
        $methods = array_values(array_intersect(
            array_keys($pathItem),
            ['get', 'put', 'post', 'delete', 'options', 'head', 'patch', 'trace']
        ));
        sort($methods);

        return $methods;
    }
}
