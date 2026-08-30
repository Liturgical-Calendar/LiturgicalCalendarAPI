<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Handlers\Concerns;

use LiturgicalCalendar\Api\Http\Exception\ValidationException;
use LiturgicalCalendar\Api\Router;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Shapes the per-batch change request detail body shared by the reviewer's
 * `GET /admin/change-requests/{batchId}` and the submitter's
 * `GET /auth/change-requests/{batchId}`.
 *
 * **The two routes differ in who may read a batch, never in what a batch looks like.**
 * The reviewer is authorized through OpenFGA on the specific batch id; the submitter is
 * scoped in SQL to their own `sub`. Once either has been let through, the body is
 * identical — the files are the submitter's own proposal either way, and there is nothing
 * in them a reviewer may see that their author may not. Keeping one shape means one
 * `ChangeRequestBatchDetail` schema, and no chance of the two drifting into subtly
 * different renderings of the same rows.
 *
 * The **current** bytes are resolved alongside the proposed ones so a client can render a
 * diff rather than a blob. Reviewing a change means reading what it changes, and asking a
 * client to fetch each file separately (from a route that may not even expose it) would
 * make the diff the client's problem to assemble and get subtly wrong.
 */
trait RendersChangeRequestDetail
{
    /**
     * The response body for one batch: the batch itself, exactly as a list route renders
     * it, plus one entry per proposed file.
     *
     * `batch` is nested rather than spread at the top level so that
     * `ChangeRequestBatchDetail` can `$ref` `ChangeRequestBatch` instead of duplicating its
     * fifteen properties — that schema is `additionalProperties: false`, so an `allOf` that
     * bolted `files` alongside its properties would be invalid against its own first branch.
     *
     * @param array<string, mixed>             $batch The collapsed batch.
     * @param array<int, array<string, mixed>> $rows  Every row of that batch, ordered by path.
     *
     * @return array<string, mixed>
     */
    protected function changeRequestDetailBody(array $batch, array $rows, bool $includeContent): array
    {
        return [
            'batch'            => $batch,
            'files'            => array_values(array_map(
                fn (array $row): array => $this->changeRequestFile($row, $includeContent),
                $rows
            )),
            'content_included' => $includeContent,
        ];
    }

    /**
     * One proposed file.
     *
     * `content` is null for a `delete` row by table constraint
     * (`chk_scr_delete_has_no_content`), so null here is meaningful data rather than missing
     * data. `current_content` is null when nothing exists at that path in the deployed source
     * tree — the ordinary case for a `create`.
     *
     * The two `*_bytes` keys are always populated from the REAL content, even when
     * `$includeContent` is false and the bodies themselves are suppressed. That is the whole
     * point of the switch: a client can size a batch (the decrees corpus batches 22 files)
     * and decide whether to pull the bodies, without the ambiguity of a null it cannot tell
     * apart from "this row genuinely has no content".
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function changeRequestFile(array $row, bool $includeContent): array
    {
        $path    = is_string($row['path'] ?? null) ? $row['path'] : '';
        $content = is_string($row['content'] ?? null) ? $row['content'] : null;
        $current = $this->currentContentFor($path);

        return [
            'path'                  => $path,
            'operation'             => is_string($row['operation'] ?? null) ? $row['operation'] : '',
            'base_sha'              => is_string($row['base_sha'] ?? null) ? $row['base_sha'] : null,
            'content'               => $includeContent ? $content : null,
            'content_bytes'         => $content === null ? null : strlen($content),
            'current_content'       => $includeContent ? $current : null,
            'current_content_bytes' => $current === null ? null : strlen($current),
        ];
    }

    /**
     * The bytes currently on disk at a stored repository-relative path, or null.
     *
     * Every path in the table was produced by stripping the deployment root off an absolute
     * path the API itself built, so none of them can escape that root. This re-checks anyway
     * — containment is asserted against `realpath()`, not against the string — because "the
     * database only ever holds paths we wrote" is exactly the kind of invariant that a later
     * import path, migration or fixture quietly stops honouring, and the failure mode is
     * reading an arbitrary file off the server and handing it to a reviewer.
     *
     * Every unreadable case answers null rather than throwing: a missing or unreadable file
     * is a legitimate answer to "what is there now" (it is the answer for every `create`),
     * and a detail view of a change request must not be made unavailable by the state of the
     * tree it proposes to change.
     */
    private function currentContentFor(string $repoRelativePath): ?string
    {
        if ($repoRelativePath === '' || str_starts_with($repoRelativePath, '/')) {
            return null;
        }

        $root = isset(Router::$apiFilePath) ? Router::$apiFilePath : '';
        if ($root === '') {
            return null;
        }

        $realRoot = realpath(rtrim($root, DIRECTORY_SEPARATOR));
        $realPath = realpath($root . $repoRelativePath);

        if ($realRoot === false || $realPath === false) {
            return null;
        }

        if (!str_starts_with($realPath, $realRoot . DIRECTORY_SEPARATOR)) {
            return null;
        }

        if (!is_file($realPath) || !is_readable($realPath)) {
            return null;
        }

        $bytes = file_get_contents($realPath);

        return is_string($bytes) ? $bytes : null;
    }

    /**
     * Whether this request wants the file bodies.
     *
     * Defaults to true: the reason the route exists is to show a reviewer what a batch
     * proposes, so the useful answer must not be the one you have to ask for. An
     * unrecognised value is a 400 rather than a silent fallback — a caller who believes
     * they suppressed a megabyte of content must never be handed it anyway.
     */
    private function wantsChangeRequestContent(ServerRequestInterface $request): bool
    {
        $raw = $request->getQueryParams()['include_content'] ?? null;

        if ($raw === null) {
            return true;
        }

        if (is_bool($raw)) {
            return $raw;
        }

        if (is_string($raw)) {
            $normalized = strtolower(trim($raw));

            if (in_array($normalized, ['true', '1', 'yes'], true)) {
                return true;
            }

            if (in_array($normalized, ['false', '0', 'no'], true)) {
                return false;
            }
        }

        throw new ValidationException('Invalid include_content value. Expected one of: true, false, 1, 0, yes, no.');
    }

    /**
     * A batch id that is not a UUID is decidable from the input alone, so a 400 here leaks
     * nothing an attacker could not already tell by inspection — unlike "no such batch",
     * which is deliberately indistinguishable from "not yours".
     */
    private function assertBatchIdShape(string $batchId): void
    {
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $batchId)) {
            throw new ValidationException('Invalid batch ID format');
        }
    }
}
