<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Support;

/**
 * Shared reader for OpenAPI path items, used by the `phpunit_tests/Schemas/OpenApi*Test`
 * classes that assert the routing grammar against `jsondata/schemas/openapi.json`.
 *
 * Three of those classes had grown their own identical copy of {@see operationMethods()},
 * which meant the list of HTTP method keys — the one thing that decides what counts as an
 * operation rather than a sibling property — had to be maintained in three places.
 */
trait OpenApiPathItemTrait
{
    /**
     * The HTTP methods a path item documents, sorted, with any non-method key (`parameters`,
     * `summary`, `description`, `servers`) filtered out — those are legal siblings of the
     * operations in an OpenAPI path item and are not operations themselves.
     *
     * Sorted rather than in document order: ordering inside a path item carries no meaning in
     * OpenAPI, so an ordered comparison would fail on a cosmetic reshuffle.
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
