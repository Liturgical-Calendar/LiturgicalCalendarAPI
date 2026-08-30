<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services\SourceData;

use LiturgicalCalendar\Api\Enum\LitSchema;
use Swaggest\JsonSchema\Schema;

/**
 * Re-checks a change request's proposed content against the schema in force *now*.
 *
 * Content is validated once, at submission, by the handler that accepted it. Approval can
 * come days later, and the schemas move in between — so a batch can be perfectly valid when
 * it is submitted and no longer valid when a reviewer says yes to it. Without this, the
 * mismatch surfaces on the pull request the publisher opens, as a red CI run on work an
 * administrator has already blessed: the check lands on the wrong side of the gate. Issue #918.
 *
 * This deliberately reuses the same machinery the write path already uses — `swaggest/json-schema`
 * over the files in `jsondata/schemas` — rather than introducing a second notion of validity.
 * (Issue #826 tracks replacing that library; when it lands, it lands here too, in one place.)
 *
 * **What counts as a row to check.** Every row that carries content, whatever its `operation`.
 * Neither `operation` nor `metadata.deletes_resource` is the right predicate here:
 *
 * - `operation = 'delete'` does NOT mean a resource is being removed. `RegionalDataHandler`
 *   stages a DELETE for every locale file dropped from `metadata.locales` on a calendar that
 *   very much still exists — and that same batch restages the calendar file itself, which must
 *   be validated.
 * - `metadata.deletes_resource` marks a batch that removes a whole resource. Those batches stage
 *   nothing but DELETEs, so keying off it would change no outcome while adding a second, weaker
 *   reason to skip a row.
 *
 * "Does this row carry bytes that will be written to a file" is the only question that matters,
 * and `content !== null` answers it exactly. A DELETE writes no bytes, so there is nothing a
 * schema could have an opinion about.
 */
final class ChangeRequestSchemaValidator
{
    /**
     * Imported schemas, memoised per instance: one batch commonly stages several files
     * governed by the same schema (a calendar plus a locale file per language).
     *
     * @var array<string, Schema>
     */
    private array $imported = [];

    /**
     * Every way the batch's rows fail the schemas currently in force, in row order.
     *
     * An empty list means the batch may be approved. Each entry names the offending file,
     * the schema it was checked against by bare filename (never a server path — see
     * {@see LitSchema::name()}), and what the violation was.
     *
     * **A path no schema claims is reported as valid, not as a violation.** The queue's rows
     * are repo-relative paths, and {@see SourceDataSchemaResolver} covers every family a write
     * handler can stage; a `null` there therefore means either a family that genuinely has no
     * schema, or a handler that has grown a new one. Refusing on `null` would jam the reviewer's
     * queue on a batch that is fine, with an error no administrator could act on and no
     * workaround short of a redeploy — strictly worse than the bounded exposure this gate exists
     * to narrow, which is a *failing* schema check, not a missing one. It also keeps this gate
     * aligned with the CI it is anticipating: `SchemaValidationTest` likewise checks the files it
     * has schemas for.
     *
     * @param array<int, array<string, mixed>> $rows Rows of one batch, as hydrated by
     *                                               {@see \LiturgicalCalendar\Api\Repositories\SourceDataChangeRequestRepository::getBatch()}.
     *
     * @return list<array{path: string, schema: string, detail: string}>
     */
    public function violations(array $rows): array
    {
        $violations = [];

        foreach ($rows as $row) {
            $content = $row['content'] ?? null;
            if (!is_string($content)) {
                // No bytes proposed for this path — see the class docblock.
                continue;
            }

            $path = is_string($row['path'] ?? null) ? $row['path'] : '';
            if ($path === '') {
                continue;
            }

            $schema = SourceDataSchemaResolver::forPath($path);
            if ($schema === null) {
                continue;
            }

            $detail = $this->check($schema, $content);
            if ($detail !== null) {
                $violations[] = [
                    'path'   => $path,
                    'schema' => $schema->name(),
                    'detail' => $detail,
                ];
            }
        }

        return $violations;
    }

    /**
     * Null when `$content` satisfies `$schema`; otherwise why it does not.
     *
     * A schema that cannot be imported at all is reported as a violation of that file rather
     * than swallowed: a deployment whose `jsondata/schemas` is broken cannot honestly say a
     * batch has been re-validated, and answering "valid" there would defeat the entire gate.
     */
    private function check(LitSchema $schema, string $content): ?string
    {
        try {
            $decoded = json_decode($content, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return 'the proposed content is not valid JSON: ' . $e->getMessage();
        }

        try {
            $this->import($schema)->in($decoded);
        } catch (\Throwable $e) {
            return self::truncate($e->getMessage());
        }

        return null;
    }

    private function import(LitSchema $schema): Schema
    {
        if (!isset($this->imported[$schema->value])) {
            // Imported from the file path, not from decoded JSON, so relative $refs such as
            // `./CommonDef.json#/definitions/EventKey` resolve — the same call the write
            // handlers make.
            $imported = Schema::import($schema->path());
            if (!$imported instanceof Schema) {
                throw new \RuntimeException('Schema ' . $schema->name() . ' did not import as a schema');
            }
            $this->imported[$schema->value] = $imported;
        }

        return $this->imported[$schema->value];
    }

    /**
     * Schema libraries can emit very long messages for a failed `oneOf`, and this text ends up
     * in an HTTP problem-details body. Keep the head of it — which is where the pointer to the
     * offending property lives — and say plainly that the rest was cut.
     */
    private static function truncate(string $message): string
    {
        $limit = 500;

        return mb_strlen($message) <= $limit
            ? $message
            : mb_substr($message, 0, $limit) . '… (truncated)';
    }
}
