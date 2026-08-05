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
 * The invariants themselves are not hardcoded here: they are derived from
 * `authz/openfga-expectations.json`, the same file cdcf-infra's
 * `auth/validate-expectations.sh` reads to check this repo's declared
 * expectations against the model it provisions (see that script's header
 * for the full schema and semantics of each rule key). Deriving from one
 * file, rather than asserting the same invariants twice in two repos in
 * two languages, is the point: a change to the expectations file changes
 * both what this test checks and what cdcf-infra's CI allows it to ship,
 * so the two checks cannot silently drift apart.
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

    /** @var array<string, mixed>|null */
    private static ?array $expectationsCache = null;

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
     * Reads `authz/openfga-expectations.json` — the same file
     * cdcf-infra's `auth/validate-expectations.sh` fetches for this
     * consumer — and returns it decoded. Deliberately not cached across
     * PHPUnit *processes* (there is only one here), only across test
     * methods within a single run, so editing the file and re-running
     * picks up the change immediately.
     *
     * @return array<string, mixed>
     */
    private function loadExpectations(): array
    {
        if (self::$expectationsCache !== null) {
            return self::$expectationsCache;
        }

        $path = dirname(__DIR__, 2) . '/authz/openfga-expectations.json';
        self::assertFileExists($path, 'authz/openfga-expectations.json is missing');

        $decoded = json_decode((string) file_get_contents($path), true);
        self::assertIsArray($decoded, 'authz/openfga-expectations.json does not decode to an object');

        self::$expectationsCache = $decoded;
        return self::$expectationsCache;
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

    /**
     * @param array<string, mixed> $model
     * @return list<string>
     */
    private static function modelTypes(array $model): array
    {
        return array_column($model['type_definitions'] ?? [], 'type');
    }

    /**
     * @param array<string, mixed> $model
     * @return array<string, mixed> the `relations` block of $type, or [] when
     *   the type is absent or has none — mirroring the provider-side
     *   validator's `relsOf`, where a relation-less type in scope fails
     *   every relation required of it rather than being silently skipped.
     */
    private static function relsOf(array $model, string $type): array
    {
        foreach ($model['type_definitions'] ?? [] as $def) {
            if (( $def['type'] ?? null ) === $type) {
                return $def['relations'] ?? [];
            }
        }
        return [];
    }

    /**
     * "*" resolves DIFFERENTLY for a requirement than for a prohibition —
     * see `auth/validate-expectations.sh`'s header in cdcf-infra for the
     * full rationale, which this mirrors exactly so the two checks cannot
     * silently diverge. A requirement ("the types I use must have these
     * relations") scopes "*" to `required_types` when declared, and to the
     * whole model otherwise. A prohibition ("nothing anywhere defines
     * this relation") always scopes "*" to the whole model, regardless of
     * `required_types` — narrowing it would let an undeclared type carry
     * the forbidden relation and still pass.
     *
     * @param array<string, mixed> $expectations
     * @param array<string, mixed> $model
     * @return list<string>
     */
    private static function requirementScope(array $expectations, array $model): array
    {
        $requiredTypes = $expectations['required_types'] ?? [];
        return count($requiredTypes) > 0 ? $requiredTypes : self::modelTypes($model);
    }

    /**
     * @param string $key
     * @param array<string, mixed> $expectations
     * @param array<string, mixed> $model
     * @return list<string>
     */
    private static function typesForRequirement(string $key, array $expectations, array $model): array
    {
        return $key === '*' ? self::requirementScope($expectations, $model) : [$key];
    }

    /**
     * @param string $key
     * @param array<string, mixed> $model
     * @return list<string>
     */
    private static function typesForProhibition(string $key, array $model): array
    {
        return $key === '*' ? self::modelTypes($model) : [$key];
    }

    /**
     * Mirrors `includesRelation` in `auth/validate-expectations.sh`
     * exactly: a SUFFICIENT-path check, not a necessity one. Holding
     * $target must, on its own, be enough to hold whatever $rewrite
     * describes.
     *
     *   - computedUserset: sufficient by definition when it names $target.
     *   - union: sufficient if ANY child is sufficient.
     *   - intersection: sufficient only if EVERY child is sufficient — a
     *     merely-necessary branch is not the same as a sufficient one.
     *   - difference (base, but not subtract): sufficient only through
     *     `base` and NOT through `subtract`, since `subtract` excludes.
     *   - tupleToUserset: never descended into — it grants a relation on a
     *     *different* object, so holding $target there says nothing about
     *     holding it here.
     *
     * @param mixed $rewrite
     */
    private static function includesRelation(mixed $rewrite, string $target): bool
    {
        if (!is_array($rewrite)) {
            return false;
        }
        if (isset($rewrite['computedUserset'])) {
            return ( $rewrite['computedUserset']['relation'] ?? null ) === $target;
        }
        if (isset($rewrite['union'])) {
            foreach ($rewrite['union']['child'] ?? [] as $child) {
                if (self::includesRelation($child, $target)) {
                    return true;
                }
            }
            return false;
        }
        if (isset($rewrite['intersection'])) {
            foreach ($rewrite['intersection']['child'] ?? [] as $child) {
                if (!self::includesRelation($child, $target)) {
                    return false;
                }
            }
            return true;
        }
        if (isset($rewrite['difference'])) {
            $base     = self::includesRelation($rewrite['difference']['base'] ?? null, $target);
            $subtract = self::includesRelation($rewrite['difference']['subtract'] ?? null, $target);
            return $base && !$subtract;
        }
        return false;
    }

    public function testRequiredTypesArePresentInModel(): void
    {
        $model        = $this->loadModel();
        $expectations = $this->loadExpectations();
        $modelTypes   = self::modelTypes($model);

        foreach (( $expectations['required_types'] ?? [] ) as $type) {
            self::assertContains($type, $modelTypes, "required type \"{$type}\" not found in model");
        }
    }

    public function testForbiddenTypesAreAbsentFromModel(): void
    {
        $model        = $this->loadModel();
        $expectations = $this->loadExpectations();
        $modelTypes   = self::modelTypes($model);

        foreach (( $expectations['forbidden_types'] ?? [] ) as $type) {
            self::assertNotContains($type, $modelTypes, "forbidden type \"{$type}\" is present in model");
        }
    }

    public function testForbiddenRelationsAreAbsent(): void
    {
        $model        = $this->loadModel();
        $expectations = $this->loadExpectations();

        foreach (( $expectations['forbidden_relations'] ?? [] ) as $key => $relations) {
            foreach (self::typesForProhibition($key, $model) as $type) {
                foreach ($relations as $relation) {
                    self::assertArrayNotHasKey(
                        $relation,
                        self::relsOf($model, $type),
                        "type \"{$type}\" has forbidden relation \"{$relation}\""
                    );
                }
            }
        }
    }

    public function testRequiredRelationsArePresent(): void
    {
        $model        = $this->loadModel();
        $expectations = $this->loadExpectations();

        foreach (( $expectations['required_relations'] ?? [] ) as $key => $relations) {
            foreach (self::typesForRequirement($key, $expectations, $model) as $type) {
                foreach ($relations as $relation) {
                    self::assertArrayHasKey(
                        $relation,
                        self::relsOf($model, $type),
                        "type \"{$type}\" is missing required relation \"{$relation}\""
                    );
                }
            }
        }
    }

    public function testRelationIncludes(): void
    {
        $model        = $this->loadModel();
        $expectations = $this->loadExpectations();

        foreach (( $expectations['relation_includes'] ?? [] ) as $key => $rules) {
            foreach ($rules as $namedRelation => $targets) {
                $scope    = self::typesForRequirement($key, $expectations, $model);
                $defining = array_values(array_filter(
                    $scope,
                    fn (string $type): bool => array_key_exists($namedRelation, self::relsOf($model, $type))
                ));

                self::assertNotEmpty(
                    $defining,
                    "no type in scope for \"{$key}\" defines relation \"{$namedRelation}\" — "
                        . 'nothing in the model can satisfy a claim about it'
                );

                foreach ($defining as $type) {
                    $rewrite = self::relsOf($model, $type)[$namedRelation];
                    foreach ($targets as $target) {
                        self::assertTrue(
                            self::includesRelation($rewrite, $target),
                            "type \"{$type}\" relation \"{$namedRelation}\" does not include \"{$target}\" via computedUserset"
                        );
                    }
                }
            }
        }
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
}
