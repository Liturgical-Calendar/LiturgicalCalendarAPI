<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Schemas;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What `openapi.json` says about the supported-locale curation routes.
 *
 * `GET /admin/locales` was documented as read-only, with a `curation` object whose whole
 * content was a boolean and a sentence. The route can now be written to, and a generated
 * client that cannot see the write route is as good as not having one — so the two POST paths
 * and the widened `curation` object are pinned here rather than left to drift (issue #926).
 */
#[CoversNothing]
final class OpenApiAdminLocalesTest extends TestCase
{
    /** @var array<string, mixed>|null */
    private static ?array $openapi = null;

    /**
     * @return array<string, mixed>
     */
    private static function openapi(): array
    {
        if (null === self::$openapi) {
            /** @var array<string, mixed> $decoded */
            $decoded       = json_decode(
                (string) file_get_contents(dirname(__DIR__, 2) . '/jsondata/schemas/openapi.json'),
                true,
                512,
                JSON_THROW_ON_ERROR
            );
            self::$openapi = $decoded;
        }

        return self::$openapi;
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function curationPaths(): array
    {
        return [
            'promote' => ['/admin/locales/{locale}/promote', 'adminPromoteSupportedLocale'],
            'demote'  => ['/admin/locales/{locale}/demote', 'adminDemoteSupportedLocale'],
        ];
    }

    #[DataProvider('curationPaths')]
    public function testTheCurationRouteIsDocumentedAsAPost(string $path, string $operationId): void
    {
        /** @var array<string, array<string, mixed>> $paths */
        $paths = self::openapi()['paths'];

        self::assertArrayHasKey($path, $paths);
        self::assertSame(['post'], array_keys($paths[$path]), 'curation is a POST action, matching the other admin verbs');

        /** @var array<string, mixed> $operation */
        $operation = $paths[$path]['post'];
        self::assertSame($operationId, $operation['operationId']);
        self::assertSame(['Admin - Supported Locales'], $operation['tags']);
        self::assertArrayNotHasKey('requestBody', $operation, 'the locale and the action are both in the path');

        /** @var array<string, mixed> $responses */
        $responses = $operation['responses'];
        foreach (['200', '401', '403', '404', '409', '503'] as $status) {
            self::assertArrayHasKey($status, $responses, $path . ' does not document a ' . $status);
        }

        self::assertSame(
            '#/components/schemas/SupportedLocaleCurationResponse',
            $responses['200']['content']['application/json']['schema']['$ref']
        );
    }

    /**
     * The readiness gate is the reason this route can refuse a request that is authorized,
     * well-formed and names a real locale, so it must be documented as a distinct outcome.
     * Demotion carries no such gate, by design, and must not claim one.
     */
    public function testOnlyPromotionDocumentsTheReadinessRefusal(): void
    {
        /** @var array<string, array<string, mixed>> $paths */
        $paths = self::openapi()['paths'];

        self::assertArrayHasKey('422', $paths['/admin/locales/{locale}/promote']['post']['responses']);
        self::assertArrayNotHasKey('422', $paths['/admin/locales/{locale}/demote']['post']['responses']);
    }

    /**
     * `writable` is no longer a constant, so what it depends on has to be visible: `mode` is
     * the difference between an edit recorded for review and one written to a file the next
     * deploy may overwrite.
     */
    public function testTheCurationObjectDeclaresTheWriteMode(): void
    {
        /** @var array<string, mixed> $curation */
        $curation = self::openapi()['components']['schemas']['SupportedLocalesResponse']['properties']['curation'];

        self::assertSame(['writable', 'mode', 'reason'], $curation['required']);
        self::assertSame(
            ['change_request', 'disk', 'misconfigured'],
            $curation['properties']['mode']['enum']
        );
    }
}
