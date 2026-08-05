<?php

namespace LiturgicalCalendar\Tests\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use PHPUnit\Framework\TestCase;

/**
 * Verifies invariants of the LiturgicalCalendar OpenFGA authorization model
 * that OpenFgaAuthorizationMiddleware relies on for its checks.
 *
 * The authorization model is owned by cdcf-infra
 * (`auth/models/LiturgicalCalendar.json`) and is no longer vendored into this
 * repo as a file. These tests instead fetch the model from the deployed
 * OpenFGA store — the very store and model ID the API reads via
 * OPENFGA_STORE_ID / OPENFGA_MODEL_ID — so the invariants are verified
 * against the model the API will actually check against, not a static copy
 * that could drift from it.
 *
 * Requires a reachable store seeded via `docker compose up authz-seed` and
 * `./scripts/setup-openfga.sh --update-env` to populate OPENFGA_API_URL /
 * OPENFGA_STORE_ID / OPENFGA_MODEL_ID (and OPENFGA_API_TOKEN if the store
 * requires bearer auth) in the environment PHPUnit runs with. When that
 * isn't set up — e.g. in CI, or a checkout with no local OpenFGA stack —
 * every test here skips cleanly rather than failing or silently passing.
 */
class OpenFgaModelTest extends TestCase
{
    /** @var array<string, mixed>|null */
    private static ?array $modelCache = null;

    /**
     * Reads OPENFGA_* the same way `OpenFgaClient::fromEnv()` does ($_ENV
     * first, then getenv()), then fetches the pinned OPENFGA_MODEL_ID when
     * one is set, or the store's latest model otherwise. Skips the calling
     * test — via `markTestSkipped`, which is reported distinctly from a pass
     * or failure — when the store isn't configured or isn't reachable.
     *
     * @return array<string, mixed>
     */
    private function loadModel(): array
    {
        if (self::$modelCache !== null) {
            return self::$modelCache;
        }

        $apiUrl  = self::getEnvString('OPENFGA_API_URL');
        $storeId = self::getEnvString('OPENFGA_STORE_ID');
        $modelId = self::getEnvString('OPENFGA_MODEL_ID');
        $token   = self::getEnvString('OPENFGA_API_TOKEN');

        $howToFix = "Run 'docker compose up authz-seed' to seed the store from cdcf-infra, "
            . "then './scripts/setup-openfga.sh --update-env' to populate the OPENFGA_* env vars.";

        if ($apiUrl === '' || $storeId === '') {
            $this->markTestSkipped("OpenFGA store not configured (OPENFGA_API_URL / OPENFGA_STORE_ID unset). {$howToFix}");
        }

        $path    = $modelId !== ''
            ? "/stores/{$storeId}/authorization-models/{$modelId}"
            : "/stores/{$storeId}/authorization-models?page_size=1";
        $headers = ['Accept' => 'application/json'];
        if ($token !== '') {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        $client = new Client(['base_uri' => rtrim($apiUrl, '/'), 'timeout' => 5, 'connect_timeout' => 2]);

        try {
            $response = $client->get($path, ['headers' => $headers]);
            $decoded  = json_decode((string) $response->getBody(), true);
        } catch (GuzzleException $e) {
            $this->markTestSkipped("OpenFGA store at {$apiUrl} not reachable ({$e->getMessage()}). {$howToFix}");
        }

        self::assertIsArray($decoded);

        $model = $modelId !== ''
            ? ( $decoded['authorization_model'] ?? null )
            : ( $decoded['authorization_models'][0] ?? null );

        if (!is_array($model)) {
            $this->markTestSkipped("Store {$storeId} has no authorization model. {$howToFix}");
        }

        self::$modelCache = self::stripApiDefaults($model);
        return self::$modelCache;
    }

    /**
     * Reads an environment variable the same way `OpenFgaClient::getEnvString()`
     * does: `$_ENV` takes precedence over `getenv()`, matching how the app
     * itself resolves OpenFGA configuration.
     */
    private static function getEnvString(string $name): string
    {
        $value = $_ENV[$name] ?? null;
        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }
        $envValue = getenv($name);
        if (is_string($envValue) && trim($envValue) !== '') {
            return trim($envValue);
        }
        return '';
    }

    /**
     * Keys the live API decorates every authorization-model response with
     * that the source model file never carried: `module` / `source_info` on
     * every relation and type are always API-added, so they are stripped
     * unconditionally. `object` and `condition`, by contrast, are meaningful
     * when non-empty — `condition` names a condition on a directly-related
     * user type, and a non-empty `object` is data the model intends — so
     * those two are stripped only when the API leaves them at its empty
     * default (`''`, `[]`, or `null`, matching what the API actually emits).
     * Unlike those, `this: {}` (an empty object/array) IS meaningful — it is
     * how the model itself expresses "the direct relation" — so
     * normalization must remove specific extraneous keys rather than
     * collapse empty structures generically. Mirrors the normalization
     * `scripts/setup-openfga.sh` used to perform before this repo stopped
     * owning the model file.
     */
    private const ALWAYS_STRIPPED_KEYS = ['module', 'source_info'];

    /**
     * Stripped only when the value is empty (`''`, `[]`, or `null`); a
     * non-empty value is meaningful model data and must survive
     * normalization intact. See ALWAYS_STRIPPED_KEYS docblock above.
     */
    private const STRIPPED_WHEN_EMPTY_KEYS = ['object', 'condition'];

    /**
     * @param mixed $value
     * @return mixed
     */
    private static function stripApiDefaults(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        $result = [];
        foreach ($value as $key => $v) {
            if (in_array($key, self::ALWAYS_STRIPPED_KEYS, true)) {
                continue;
            }
            if (in_array($key, self::STRIPPED_WHEN_EMPTY_KEYS, true) && ( $v === '' || $v === [] || $v === null )) {
                continue;
            }
            $result[$key] = self::stripApiDefaults($v);
        }
        return $result;
    }

    public function testNoTypeDefinesDeleter(): void
    {
        $model = $this->loadModel();
        foreach ($model['type_definitions'] as $def) {
            if (!isset($def['relations'])) {
                continue;
            }
            self::assertArrayNotHasKey('deleter', $def['relations'], "{$def['type']} still defines deleter");
            self::assertArrayNotHasKey(
                'deleter',
                $def['metadata']['relations'] ?? [],
                "{$def['type']} still has deleter metadata"
            );
        }
    }

    public function testEditorAndViewerAreUnionsOfAdmin(): void
    {
        $model = $this->loadModel();
        $types = array_column($model['type_definitions'], 'relations', 'type');
        foreach (['national_calendar', 'diocesan_calendar', 'wider_region', 'general_roman_calendar'] as $t) {
            $editorChildren = $types[$t]['editor']['union']['child'];
            self::assertContains(['this' => []], $editorChildren, "$t editor missing this");
            self::assertContains(['computedUserset' => ['relation' => 'admin']], $editorChildren, "$t editor missing admin");

            $viewerChildren = $types[$t]['viewer']['union']['child'];
            self::assertContains(['computedUserset' => ['relation' => 'editor']], $viewerChildren, "$t viewer missing editor");
            self::assertContains(['computedUserset' => ['relation' => 'admin']], $viewerChildren, "$t viewer missing admin");
        }
    }

    public function testWiderRegionHasMemberNationTtu(): void
    {
        $model = $this->loadModel();
        $types = array_column($model['type_definitions'], 'relations', 'type');
        $meta  = array_column($model['type_definitions'], 'metadata', 'type');

        self::assertArrayHasKey('member_nation', $types['wider_region']);
        self::assertSame(
            [['type' => 'national_calendar']],
            $meta['wider_region']['relations']['member_nation']['directly_related_user_types']
        );

        $adminChildren = $types['wider_region']['admin']['union']['child'];
        self::assertContains([
            'tupleToUserset' => [
                'tupleset'        => ['relation' => 'member_nation'],
                'computedUserset' => ['relation' => 'admin'],
            ],
        ], $adminChildren, 'wider_region admin missing member_nation TTU');
    }
}
