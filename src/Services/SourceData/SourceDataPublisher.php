<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services\SourceData;

use InvalidArgumentException;
use LiturgicalCalendar\Api\Enum\ChangeOperation;
use LiturgicalCalendar\Api\Repositories\SourceDataChangeRequestRepository;
use LiturgicalCalendar\Api\Services\GitHub\GitHubGitDataClient;
use RuntimeException;

/**
 * Turns one approved change-request batch into a single commit on a per-resource branch,
 * plus a rolling pull request.
 *
 * # Sequence
 *
 * ```text
 * getRef(branch)            null -> createRef(branch, getRef(baseBranch))
 * getCommitTreeSha(head)    resolve the branch head commit to the tree it points at
 * createBlob(content)       once per non-delete row
 * createTree(baseTree)      one entry per row; delete rows carry sha: null
 * createCommit(...)         author = editor, committer = the App, parent = branch head
 * updateRef(branch, sha)    always force: false
 * findOpenPullRequest()     -> openPullRequest() only when null
 * ```
 *
 * # One commit per batch, never merged across batches
 *
 * A batch is exactly one call to {@see publish()} and produces exactly one commit. Batching
 * several approved batches into one commit would merge two editors' work into a single
 * author, destroying the per-editor authorship this whole design exists to preserve — see the
 * author/committer split below.
 *
 * # Branch naming
 *
 * `litcal-data/<resource_type>/<resource_id>` — e.g. `litcal-data/national_calendar/roman/US`.
 * `resource_id` already contains a `/` for rite-qualified ids
 * ({@see \LiturgicalCalendar\Api\Services\RiteScopedObjectId}); that is legal in a git ref and
 * intended. The name is stable per resource, which is what makes the pull request "rolling":
 * every later batch for the same resource lands on the same branch and is picked up by
 * {@see findOpenPullRequest()} instead of opening a second, competing pull request.
 *
 * # Author vs committer
 *
 * The commit's `author` is the editor who submitted the batch (`submitted_by_name`,
 * `submitted_by_email`); the `committer` is this publisher's own configured identity (the
 * GitHub App). This split is the entire reason the design exists: it lets the repository
 * history attribute a change to the person who actually made it, while the App remains the
 * party that actually pushed it.
 *
 * An unverified email must never become the commit author email — presenting it as an
 * authenticated identity would let anyone who can set an arbitrary address in their profile
 * forge authorship of a third party in a public repository. {@see authorFor()} uses
 * `submitted_by_email` only when `submitted_by_email_verified` is true; otherwise it falls
 * back to a GitHub `noreply`-style placeholder. There is no real per-editor noreply mapping
 * available here (editors authenticate through Zitadel, not a GitHub account), so the
 * fallback is a fixed address rather than a genuine `<id>+<login>@users.noreply.github.com`.
 */
final class SourceDataPublisher implements SourceDataPublisherInterface
{
    private const TREE_ENTRY_MODE = '100644';

    private const TREE_ENTRY_TYPE = 'blob';

    /**
     * @see class docblock, "Author vs committer" — used only when
     * `submitted_by_email_verified` is false or the email is missing.
     */
    private const UNVERIFIED_AUTHOR_EMAIL = 'noreply@users.noreply.github.com';

    private const FALLBACK_AUTHOR_NAME = 'Unknown Editor';

    public function __construct(
        private readonly SourceDataChangeRequestRepository $repository,
        private readonly GitHubGitDataClient $client,
        /** The branch a resource's first publish branches from, e.g. `development`. */
        private readonly string $baseBranch,
        /** The App's display name, used as the commit `committer.name`. */
        private readonly string $committerName,
        /** The App's email, used as the commit `committer.email`. */
        private readonly string $committerEmail
    ) {
    }

    /**
     * Publish one approved batch and record the result on every row.
     *
     * @throws InvalidArgumentException If the batch does not exist (empty row set).
     * @throws RuntimeException         If the configured base branch does not exist on GitHub.
     * @throws \LiturgicalCalendar\Api\Services\GitHub\GitHubApiException If any GitHub call fails —
     *         in particular, a non-fast-forward `updateRef()` on a branch another publish landed
     *         on concurrently, which the caller is expected to retry.
     */
    public function publish(string $batchId): PublishResult
    {
        $rows = $this->repository->getBatch($batchId);
        if ($rows === []) {
            throw new InvalidArgumentException(sprintf('No change request batch found for id "%s"', $batchId));
        }

        $payload = PublishablePayload::fromBatchRows($rows);
        $branch  = self::branchFor($payload);

        $headSha = $this->client->getRef($branch);
        if (null === $headSha) {
            $headSha = $this->client->getRef($this->baseBranch);
            if (null === $headSha) {
                throw new RuntimeException(sprintf('Base branch "%s" does not exist on GitHub', $this->baseBranch));
            }
            $this->client->createRef($branch, $headSha);
        }

        $baseTreeSha = $this->client->getCommitTreeSha($headSha);
        $treeEntries = $this->buildTreeEntries($payload);
        $treeSha     = $this->client->createTree($baseTreeSha, $treeEntries);

        $commitSha = $this->client->createCommit(
            $this->commitMessageFor($payload, $batchId),
            $treeSha,
            $headSha,
            $this->authorFor($payload),
            ['name' => $this->committerName, 'email' => $this->committerEmail]
        );

        $this->client->updateRef($branch, $commitSha);

        $prNumber = $this->client->findOpenPullRequest($branch);
        if (null === $prNumber) {
            $prNumber = $this->client->openPullRequest(
                $branch,
                $this->baseBranch,
                $this->pullRequestTitleFor($payload),
                $this->pullRequestBodyFor($payload, $batchId)
            );
        }

        $this->repository->recordPublication($batchId, $branch, $commitSha, $prNumber, $headSha);

        return new PublishResult($branch, $commitSha, $prNumber, $headSha);
    }

    private static function branchFor(PublishablePayload $payload): string
    {
        return sprintf('litcal-data/%s/%s', $payload->resourceType, $payload->resourceId);
    }

    /**
     * @return list<array{path: string, mode: string, type: string, sha: string|null}>
     */
    private function buildTreeEntries(PublishablePayload $payload): array
    {
        $entries = [];
        foreach ($payload->files as $file) {
            $entries[] = [
                'path' => $file['path'],
                'mode' => self::TREE_ENTRY_MODE,
                'type' => self::TREE_ENTRY_TYPE,
                // A delete carries sha: null, the Git Data API's mechanism for removing a
                // path from the resulting tree. PublishablePayload::fromBatchRows() already
                // guarantees every non-delete row has non-null content, so createBlob() here
                // never receives null.
                'sha'  => ChangeOperation::DELETE === $file['operation']
                    ? null
                    : $this->client->createBlob((string) $file['content']),
            ];
        }

        return $entries;
    }

    /**
     * @return array{name: string, email: string}
     */
    private function authorFor(PublishablePayload $payload): array
    {
        $email = $payload->submittedByEmailVerified && null !== $payload->submittedByEmail
            ? $payload->submittedByEmail
            : self::UNVERIFIED_AUTHOR_EMAIL;

        return [
            'name'  => $payload->submittedByName ?? self::FALLBACK_AUTHOR_NAME,
            'email' => $email,
        ];
    }

    private function commitMessageFor(PublishablePayload $payload, string $batchId): string
    {
        return sprintf('Publish %s/%s (batch %s)', $payload->resourceType, $payload->resourceId, $batchId);
    }

    private function pullRequestTitleFor(PublishablePayload $payload): string
    {
        return sprintf('Source data: %s/%s', $payload->resourceType, $payload->resourceId);
    }

    private function pullRequestBodyFor(PublishablePayload $payload, string $batchId): string
    {
        $paths = implode("\n", array_map(
            static fn (array $file): string => '- ' . $file['path'],
            $payload->files
        ));

        return sprintf(
            "Automated publish of approved change request batch `%s`, submitted by %s.\n\nFiles:\n%s",
            $batchId,
            $payload->submittedByName ?? self::FALLBACK_AUTHOR_NAME,
            $paths
        );
    }

    /**
     * Split a `GITHUB_REPOSITORY` value of the form `owner/repo` — e.g.
     * `Liturgical-Calendar/LiturgicalCalendarAPI` — into the two strings
     * {@see \LiturgicalCalendar\Api\Services\GitHub\GitHubGitDataClient}'s constructor takes
     * explicitly. `GITHUB_REPOSITORY` is a single environment value; the client deliberately
     * does not parse it itself, so whoever wires the client up must split it first.
     *
     * @return array{owner: string, repo: string}
     * @throws InvalidArgumentException If `$githubRepository` is not exactly one `/`-separated
     *                                  `owner/repo` pair with both halves non-empty.
     */
    public static function splitGithubRepository(string $githubRepository): array
    {
        $parts = explode('/', $githubRepository);
        if (2 !== count($parts) || '' === $parts[0] || '' === $parts[1]) {
            throw new InvalidArgumentException(
                sprintf('GITHUB_REPOSITORY must be in the form "owner/repo", got "%s"', $githubRepository)
            );
        }

        return ['owner' => $parts[0], 'repo' => $parts[1]];
    }
}
