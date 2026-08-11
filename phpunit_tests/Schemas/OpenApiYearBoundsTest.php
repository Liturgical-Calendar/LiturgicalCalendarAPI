<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Schemas;

use LiturgicalCalendar\Api\Params\CalendarParams;
use PHPUnit\Framework\TestCase;

/**
 * The year bounds `openapi.json` advertises must be the bounds `CalendarParams` actually
 * enforces — per rite, since the two rites do not share a floor.
 *
 * This exists because they had already drifted. The Ambrosian rite starts in 1976, the year
 * of the first reformed *Messale Ambrosiano*, and `CalendarParams::validateRiteCompatibility()`
 * returns 400 for anything earlier; the Ambrosian operations documented that in prose while
 * their `year` path parameter still advertised the API-wide `minimum: 1970`, so a generated
 * client or a schema-driven form would happily offer years the API rejects. Prose and schema
 * disagreeing is precisely the failure this pins down, so assert against the constants rather
 * than against literals — a future change to either floor has to update the schema or fail here.
 *
 * The Roman assertions are the other half of the guard: the fix introduced a rite-specific
 * `AmbrosianYearPathParam`, and nothing must have re-pointed the Roman paths at it.
 */
final class OpenApiYearBoundsTest extends TestCase
{
    /** @var array<string,mixed> */
    private static array $openapi;

    public static function setUpBeforeClass(): void
    {
        $path = dirname(__DIR__, 2) . '/jsondata/schemas/openapi.json';
        $raw  = file_get_contents($path);
        self::assertIsString($raw, 'Could not read openapi.json');

        /** @var array<string,mixed> $decoded */
        $decoded       = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        self::$openapi = $decoded;
    }

    /**
     * Every `year` path parameter in the document, resolved through `$ref`, keyed by
     * "{path} {method}". Resolution matters: the bug being guarded against was invisible at
     * the operation level, because the operations referenced a shared component that carried
     * the wrong floor.
     *
     * @return array<string,array{minimum:int,maximum:int}>
     */
    private static function yearParameters(): array
    {
        /** @var array<string,array<string,mixed>> $paths */
        $paths = self::$openapi['paths'];
        /** @var array<string,array<string,mixed>> $components */
        $components = self::$openapi['components']['parameters'];

        $found = [];
        foreach ($paths as $path => $operations) {
            foreach ($operations as $method => $operation) {
                if (false === in_array($method, ['get', 'post'], true) || false === is_array($operation)) {
                    continue;
                }

                /** @var array<int,array<string,mixed>> $parameters */
                $parameters = $operation['parameters'] ?? [];
                foreach ($parameters as $parameter) {
                    if (isset($parameter['$ref']) && is_string($parameter['$ref'])) {
                        $componentName = substr($parameter['$ref'], strrpos($parameter['$ref'], '/') + 1);
                        if (false === isset($components[$componentName])) {
                            continue;
                        }
                        $parameter = $components[$componentName];
                    }

                    if (( $parameter['name'] ?? null ) !== 'year' || ( $parameter['in'] ?? null ) !== 'path') {
                        continue;
                    }

                    /** @var array{minimum:int,maximum:int} $schema */
                    $schema                       = $parameter['schema'];
                    $found[$path . ' ' . $method] = ['minimum' => $schema['minimum'], 'maximum' => $schema['maximum']];
                }
            }
        }

        return $found;
    }

    public function testEveryYearPathParameterIsAccountedFor(): void
    {
        $found = self::yearParameters();

        // Sanity floor for the two assertions below: if a refactor ever stopped resolving
        // these, both would pass vacuously against an empty set.
        self::assertNotEmpty($found);
        self::assertNotEmpty(array_filter(array_keys($found), fn (string $k): bool => str_contains($k, '/ambrosian/')));
        self::assertNotEmpty(array_filter(array_keys($found), fn (string $k): bool => false === str_contains($k, '/ambrosian/')));
    }

    public function testAmbrosianYearParametersAdvertiseTheAmbrosianFloor(): void
    {
        foreach (self::yearParameters() as $operation => $bounds) {
            if (false === str_contains($operation, '/ambrosian/')) {
                continue;
            }

            self::assertSame(
                CalendarParams::AMBROSIAN_YEAR_LOWER_LIMIT,
                $bounds['minimum'],
                "{$operation} must advertise the Ambrosian year floor the API enforces"
            );
            self::assertSame(CalendarParams::YEAR_UPPER_LIMIT, $bounds['maximum'], $operation);
        }
    }

    public function testRomanYearParametersKeepTheApiWideFloor(): void
    {
        foreach (self::yearParameters() as $operation => $bounds) {
            if (str_contains($operation, '/ambrosian/')) {
                continue;
            }

            self::assertSame(
                CalendarParams::YEAR_LOWER_LIMIT,
                $bounds['minimum'],
                "{$operation} is a Roman-rite path and must keep the API-wide year floor"
            );
            self::assertSame(CalendarParams::YEAR_UPPER_LIMIT, $bounds['maximum'], $operation);
        }
    }
}
