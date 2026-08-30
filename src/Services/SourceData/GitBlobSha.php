<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services\SourceData;

/**
 * The git object id of a file's contents — the same value GitHub's Git Data API
 * returns as a tree entry's `sha` and as {@see \LiturgicalCalendar\Api\Services\GitHub\GitHubGitDataClient::createBlob()}'s
 * return value.
 *
 * A blob's object id is `sha1("blob " . byteLength . "\0" . contents)`. That is a git
 * format constant, not an implementation detail of any particular git version, which is
 * what makes it safe to compute locally and compare against a sha GitHub produced: a
 * change request's `base_sha` is written here, from the file on disk at submission time,
 * and a rebase check compares it against the blob sha the same path carries on the branch
 * the publish is about to land on.
 *
 * SHA-1 is used because that is what a git object id IS. This is a content address for
 * comparison against git's own, never a security primitive, so the collision weaknesses
 * that rule SHA-1 out elsewhere are not in play here — an attacker who could produce a
 * blob-sha collision would already have had to get the colliding content past the
 * approval gate.
 *
 * Note the length is the byte length, so `strlen()` is correct and `mb_strlen()` would be
 * wrong: git counts octets, and every source-data file this touches may contain multi-byte
 * UTF-8.
 */
final class GitBlobSha
{
    /**
     * The blob sha of `$content` exactly as git would compute it.
     */
    public static function ofContent(string $content): string
    {
        return sha1('blob ' . strlen($content) . "\0" . $content);
    }

    /**
     * The blob sha of the file at `$absolutePath`, or null when there is no readable file
     * there.
     *
     * Null is a value, not an error: a change request that CREATES a file was authored
     * against no upstream blob at all, and that is exactly what a null `base_sha` records.
     * See the `base_sha` notes in `docs/ops/change-request-runbook.md` for how a null is
     * to be read back — the row's `operation` disambiguates "there was no file" from
     * "this row predates the column being written".
     */
    public static function ofFile(string $absolutePath): ?string
    {
        if (!is_file($absolutePath) || !is_readable($absolutePath)) {
            return null;
        }

        $content = file_get_contents($absolutePath);

        return is_string($content) ? self::ofContent($content) : null;
    }
}
