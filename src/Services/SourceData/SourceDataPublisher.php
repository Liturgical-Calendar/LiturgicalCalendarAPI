<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services\SourceData;

use InvalidArgumentException;
use LiturgicalCalendar\Api\Enum\ChangeOperation;
use LiturgicalCalendar\Api\Repositories\SourceDataChangeRequestRepository;
use LiturgicalCalendar\Api\Services\GitHub\GitHubAppAuth;
use LiturgicalCalendar\Api\Services\GitHub\GitHubGitDataClient;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
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

    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly SourceDataChangeRequestRepository $repository,
        private readonly GitHubGitDataClient $client,
        /** The branch a resource's first publish branches from, e.g. `development`. */
        private readonly string $baseBranch,
        /** The App's display name, used as the commit `committer.name`. */
        private readonly string $committerName,
        /** The App's email, used as the commit `committer.email`. */
        private readonly string $committerEmail,
        ?LoggerInterface $logger = null
    ) {
        $this->logger = $logger ?? new NullLogger();
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

        $recorded = $this->repository->recordPublication($batchId, $branch, $commitSha, $prNumber, $headSha);
        if (0 === $recorded) {
            // See recordPublication()'s own docblock: zero rows means the batch was no longer
            // `queued` when this ran — most likely another runner already recorded a publish
            // for it first (a stale claim reclaimed while this run was in flight, then both
            // runs' pushes succeeded). The GitHub side effects above (commit, ref update, pull
            // request) already happened and cannot be undone here; this is visibility only, so
            // the guard's effect is not silent.
            $this->logger->warning(
                'source_data.publish.record_blocked',
                [
                    'batch_id' => $batchId,
                    'reason'   => 'publication_status was not "queued" when recordPublication() ran '
                        . '(likely: another runner already recorded a publish for this batch)',
                ]
            );
        }

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
        $parsed = self::parseGithubRepository($githubRepository);
        if (null === $parsed) {
            throw new InvalidArgumentException(
                sprintf('GITHUB_REPOSITORY must be in the form "owner/repo", got "%s"', $githubRepository)
            );
        }

        return $parsed;
    }

    /**
     * The single definition of what a well-formed `GITHUB_REPOSITORY` is, shared by
     * {@see splitGithubRepository()} (which throws) and {@see isConfigured()} (which reports).
     *
     * Kept in ONE place on purpose. When the shape rule lived only inside the throwing
     * splitter, `isConfigured()` tested non-emptiness alone — so a value that was set but
     * malformed (a pasted repository URL, a trailing slash, a bare repo name with no owner)
     * made `GET /health` report the publisher `configured` while every run died on it. Two
     * copies of the rule would drift back into exactly that.
     *
     * @return array{owner: string, repo: string}|null Null when the value is not exactly one
     *         `/`-separated pair with both halves non-empty.
     */
    private static function parseGithubRepository(string $githubRepository): ?array
    {
        $parts = explode('/', $githubRepository);
        if (2 !== count($parts) || '' === $parts[0] || '' === $parts[1]) {
            return null;
        }

        return ['owner' => $parts[0], 'repo' => $parts[1]];
    }

    /**
     * True when the publisher has everything it needs to actually publish: the GitHub App
     * credential ({@see GitHubAppAuth::isConfigured()}) plus a WELL-FORMED `GITHUB_REPOSITORY`.
     *
     * The shape check is not fussiness. `fromEnv()` rejects anything that is not an
     * `owner/repo` pair, so a set-but-malformed value — a pasted repository URL, a trailing
     * slash, a bare repo name — means no run can ever publish. Testing only for non-emptiness
     * made this method answer `configured`, and `/health` then reported a healthy publisher
     * while approved work accumulated: the precise failure mode this block exists to catch,
     * reached through a value that is present rather than absent. The rule itself lives in
     * {@see parseGithubRepository()}, shared with the splitter, so the two can never disagree.
     *
     * `GITHUB_BASE_BRANCH`, `GITHUB_APP_COMMITTER_NAME`, and `GITHUB_APP_COMMITTER_EMAIL` are
     * deliberately not checked here: {@see fromEnv()} defaults all three when unset or empty,
     * so their absence never leaves the publisher unable to run — only the four checked here
     * do. Mirrors {@see GitHubAppAuth::isConfigured()} and is consumed by
     * {@see \LiturgicalCalendar\Api\Health::buildSourceDataPublisherStatus()}.
     */
    public static function isConfigured(): bool
    {
        return GitHubAppAuth::isConfigured()
            && null !== self::parseGithubRepository(self::getEnvString('GITHUB_REPOSITORY'));
    }

    /**
     * Build a fully wired instance from environment variables: `GITHUB_APP_ID`,
     * `GITHUB_APP_INSTALLATION_ID`, `GITHUB_APP_PRIVATE_KEY_PATH` (via
     * {@see GitHubAppAuth::fromEnv()}), `GITHUB_REPOSITORY` (required), and the optional
     * `GITHUB_BASE_BRANCH` / `GITHUB_APP_COMMITTER_NAME` / `GITHUB_APP_COMMITTER_EMAIL`.
     * `$logger` defaults to a `NullLogger` and is used only for `publish()`'s
     * `recordPublication()`-blocked warning — see that method's own docblock.
     *
     * Mirrors {@see \LiturgicalCalendar\Api\Services\OpenFgaClient::fromEnv()}: centralizes
     * every `mixed` `$_ENV`/`getenv()` read in `src/`, behind the already-narrowed
     * {@see getEnvString()} below, rather than leaving a CLI script to read (and blindly
     * cast) them directly — `phpstan.neon.dist` scans `paths: [src]` only, so a
     * script-level `(string) $_ENV[...]` is invisible to `composer analyse`.
     *
     * @throws RuntimeException         If the GitHub App credential is not configured (see
     *                                  {@see GitHubAppAuth::fromEnv()}) or `GITHUB_REPOSITORY`
     *                                  is unset or empty.
     * @throws InvalidArgumentException If `GITHUB_REPOSITORY` is not a valid "owner/repo" pair.
     */
    public static function fromEnv(
        SourceDataChangeRequestRepository $repository,
        ClientInterface $http,
        CacheItemPoolInterface $installationTokenCache,
        ?LoggerInterface $logger = null
    ): self {
        $auth = GitHubAppAuth::fromEnv($http, $installationTokenCache);

        $githubRepository = self::getEnvString('GITHUB_REPOSITORY');
        if ('' === $githubRepository) {
            throw new RuntimeException('GITHUB_REPOSITORY is not configured (expected "owner/repo").');
        }
        ['owner' => $owner, 'repo' => $repo] = self::splitGithubRepository($githubRepository);

        $client = new GitHubGitDataClient($owner, $repo, $auth, $http);

        $baseBranch     = self::getEnvString('GITHUB_BASE_BRANCH') ?: 'development';
        $committerName  = self::getEnvString('GITHUB_APP_COMMITTER_NAME') ?: 'Litcal Publisher';
        $committerEmail = self::getEnvString('GITHUB_APP_COMMITTER_EMAIL')
            ?: 'litcal-publisher[bot]@users.noreply.github.com';

        return new self($repository, $client, $baseBranch, $committerName, $committerEmail, $logger);
    }

    /**
     * Get an environment variable as a string, or '' if unset/empty. Mirrors
     * {@see \LiturgicalCalendar\Api\Services\GitHub\GitHubAppAuth}'s own private helper of the
     * same name (duplicated rather than shared — same precedent as
     * {@see \LiturgicalCalendar\Api\Services\OpenFgaClient}'s own copy).
     */
    private static function getEnvString(string $name): string
    {
        $value = $_ENV[$name] ?? null;
        if (is_string($value) && '' !== trim($value)) {
            return trim($value);
        }

        $envValue = getenv($name);
        if (is_string($envValue) && '' !== trim($envValue)) {
            return trim($envValue);
        }

        return '';
    }
}
