<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services\GitHub;

use GuzzleHttp\Psr7\Request;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * Thin wrapper over the GitHub Git Data and Pulls REST endpoints, used to assemble and land a
 * commit for one approved source-data change request batch.
 *
 * Every call authenticates with a fresh {@see GitHubAppAuth::installationToken()} (cached inside
 * that collaborator, so this class never caches a token itself) and talks to a single
 * `{$owner}/{$repo}` target fixed at construction.
 *
 * `getRef()` is the only method here that treats a 404 as a value rather than an error: a missing
 * branch means "create it". Every other non-2xx response — including a 404 anywhere else — raises
 * {@see GitHubApiException}.
 */
final class GitHubGitDataClient
{
    private const API_BASE_URL = 'https://api.github.com';

    private const USER_AGENT = 'LiturgicalCalendarAPI-GitHubApp';

    private const API_VERSION = '2022-11-28';

    public function __construct(
        private readonly string $owner,
        private readonly string $repo,
        private readonly GitHubAppAuth $auth,
        private readonly ClientInterface $http
    ) {
    }

    /**
     * Fetch the head sha of `heads/{$branch}`, or null if the branch does not exist.
     *
     * A missing branch is the normal, expected state before the first publication of a given
     * resource — it is what tells the caller to {@see createRef()} rather than update. Every
     * other caller in this class treats a 404 as a real failure.
     *
     * @throws GitHubApiException If GitHub responds with a non-2xx, non-404 status
     */
    public function getRef(string $branch): ?string
    {
        $response = $this->send('GET', '/git/ref/heads/' . self::encodeRefSegment($branch), null);

        if ($response->getStatusCode() === 404) {
            return null;
        }

        $decoded = $this->decodeOrThrow($response);

        $object = $decoded['object'] ?? null;
        $sha    = is_array($object) ? ( $object['sha'] ?? null ) : null;
        if (!is_string($sha) || $sha === '') {
            throw new GitHubApiException(
                $response->getStatusCode(),
                'GitHub returned a ref with no usable object.sha'
            );
        }

        return $sha;
    }

    /**
     * Create `refs/heads/{$branch}` pointing at `$fromSha`.
     *
     * @throws GitHubApiException If GitHub responds with a non-2xx status
     */
    public function createRef(string $branch, string $fromSha): void
    {
        $this->request('POST', '/git/refs', [
            'ref' => 'refs/heads/' . $branch,
            'sha' => $fromSha,
        ]);
    }

    /**
     * Create a blob from UTF-8 text content and return its sha.
     *
     * @throws GitHubApiException If GitHub responds with a non-2xx status
     */
    public function createBlob(string $content): string
    {
        $response = $this->send('POST', '/git/blobs', [
            'content'  => $content,
            'encoding' => 'utf-8',
        ]);

        return $this->extractSha($response, 'blob');
    }

    /**
     * Create a tree layered on `$baseTreeSha` and return its sha.
     *
     * A tree entry with `sha: null` deletes the path from the resulting tree — that is the Git
     * Data API's mechanism for expressing a `delete` change request, and it is the reason the
     * body must be built (and JSON-encoded) without ever stripping null values out of an entry.
     *
     * @param list<array{path: string, mode: string, type: string, sha: string|null}> $entries
     * @throws GitHubApiException If GitHub responds with a non-2xx status
     */
    public function createTree(string $baseTreeSha, array $entries): string
    {
        $response = $this->send('POST', '/git/trees', [
            'base_tree' => $baseTreeSha,
            'tree'      => $entries,
        ]);

        return $this->extractSha($response, 'tree');
    }

    /**
     * Create a single-parent commit and return its sha.
     *
     * `$committer` is a required, separate argument rather than left to GitHub's default —
     * GitHub's Create-a-commit endpoint defaults an omitted `committer` to the value of
     * `author`, which would silently collapse SourceDataPublisher's author/committer split
     * (the editor as author, the App as committer) the moment either side forgot to pass it.
     *
     * @param array{name: string, email: string, date?: string} $author
     * @param array{name: string, email: string, date?: string} $committer
     * @throws GitHubApiException If GitHub responds with a non-2xx status
     */
    public function createCommit(string $message, string $treeSha, string $parentSha, array $author, array $committer): string
    {
        $response = $this->send('POST', '/git/commits', [
            'message'   => $message,
            'tree'      => $treeSha,
            'parents'   => [$parentSha],
            'author'    => $author,
            'committer' => $committer,
        ]);

        return $this->extractSha($response, 'commit');
    }

    /**
     * The tree sha a commit points at, needed as `createTree()`'s `$baseTreeSha`.
     *
     * `getRef()` only ever returns a commit sha (a branch head), but `createTree()`'s
     * `base_tree` parameter is a Git tree object sha, not a commit sha — the two are
     * different object types and GitHub's API is strict about it. This is the one extra
     * round trip that resolves a branch head into the tree sha to layer new entries onto.
     *
     * @throws GitHubApiException If GitHub responds with a non-2xx status, or the commit
     *                            carries no usable tree.sha
     */
    public function getCommitTreeSha(string $commitSha): string
    {
        $response = $this->send('GET', '/git/commits/' . rawurlencode($commitSha), null);
        $decoded  = $this->decodeOrThrow($response);

        $tree = $decoded['tree'] ?? null;
        $sha  = is_array($tree) ? ( $tree['sha'] ?? null ) : null;
        if (!is_string($sha) || $sha === '') {
            throw new GitHubApiException(
                $response->getStatusCode(),
                'GitHub returned a commit with no usable tree.sha'
            );
        }

        return $sha;
    }

    /**
     * Fast-forward `heads/{$branch}` to `$commitSha`.
     *
     * `force` is hardcoded to `false` and is not exposed as a parameter: a non-fast-forward
     * update must fail with a retryable 422 rather than silently clobbering another editor's
     * commit landed on the same branch between our {@see getRef()} and this call.
     *
     * @throws GitHubApiException If GitHub responds with a non-2xx status (including a
     *                            non-fast-forward 422)
     */
    public function updateRef(string $branch, string $commitSha): void
    {
        $this->request('PATCH', '/git/refs/heads/' . self::encodeRefSegment($branch), [
            'sha'   => $commitSha,
            'force' => false,
        ]);
    }

    /**
     * Open a pull request from `$branch` onto `$base` and return its number.
     *
     * @throws GitHubApiException If GitHub responds with a non-2xx status
     */
    public function openPullRequest(string $branch, string $base, string $title, string $body): int
    {
        $response = $this->send('POST', '/pulls', [
            'title' => $title,
            'body'  => $body,
            'head'  => $branch,
            'base'  => $base,
        ]);
        $decoded  = $this->decodeOrThrow($response);

        $number = $decoded['number'] ?? null;
        if (!is_int($number)) {
            throw new GitHubApiException(
                $response->getStatusCode(),
                'GitHub returned a pull request with no usable number'
            );
        }

        return $number;
    }

    /**
     * Find the number of an already-open pull request whose head is `$branch`, or null if none.
     *
     * @throws GitHubApiException If GitHub responds with a non-2xx status
     */
    public function findOpenPullRequest(string $branch): ?int
    {
        $query = http_build_query([
            'state' => 'open',
            'head'  => $this->owner . ':' . $branch,
        ]);

        $decoded = $this->request('GET', '/pulls?' . $query, null);

        $first = $decoded[0] ?? null;
        if (!is_array($first)) {
            return null;
        }

        $number = $first['number'] ?? null;

        return is_int($number) ? $number : null;
    }

    /**
     * URL-encode a `/`-separated branch name segment-by-segment, preserving the slashes as path
     * hierarchy instead of collapsing them into `%2F` — a plain {@see rawurlencode()} of the
     * whole branch name would turn every `/` into `%2F` and 404 against a ref path built from
     * `litcal-data/<resource_type>/<resource_id>`.
     */
    private static function encodeRefSegment(string $branch): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $branch)));
    }

    /**
     * @throws GitHubApiException If the response status is not 2xx, or the sha is missing
     */
    private function extractSha(ResponseInterface $response, string $what): string
    {
        $decoded = $this->decodeOrThrow($response);

        $sha = $decoded['sha'] ?? null;
        if (!is_string($sha) || $sha === '') {
            throw new GitHubApiException(
                $response->getStatusCode(),
                sprintf('GitHub created a %s but returned no usable sha', $what)
            );
        }

        return $sha;
    }

    /**
     * Send a request, decode its JSON body, and throw on any non-2xx status.
     *
     * @param array<string, mixed>|null $body
     * @return array<int|string, mixed>
     * @throws GitHubApiException If GitHub responds with a non-2xx status
     */
    private function request(string $method, string $path, ?array $body): array
    {
        $response = $this->send($method, $path, $body);

        return $this->decodeOrThrow($response);
    }

    /**
     * @return array<int|string, mixed>
     * @throws GitHubApiException If the response status is not 2xx
     */
    private function decodeOrThrow(ResponseInterface $response): array
    {
        $status  = $response->getStatusCode();
        $decoded = $this->decode($response);

        if ($status < 200 || $status >= 300) {
            $message = isset($decoded['message']) && is_string($decoded['message'])
                ? $decoded['message']
                : 'Unknown error';

            throw new GitHubApiException($status, sprintf('GitHub API error (HTTP %d): %s', $status, $message));
        }

        return $decoded;
    }

    /**
     * @return array<int|string, mixed>
     */
    private function decode(ResponseInterface $response): array
    {
        /** @var mixed $decoded */
        $decoded = json_decode((string) $response->getBody(), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed>|null $body
     * @throws RuntimeException If the transport itself fails (never for a non-2xx status, which
     *                          is handled by the caller once it has a response to inspect)
     */
    private function send(string $method, string $path, ?array $body): ResponseInterface
    {
        $headers = [
            'Authorization'        => 'Bearer ' . $this->auth->installationToken(),
            'Accept'               => 'application/vnd.github+json',
            'X-GitHub-Api-Version' => self::API_VERSION,
            // GitHub rejects a request with no User-Agent as 403. Guzzle injects a default,
            // but this class takes any PSR-18 ClientInterface by design, and a bare one sends
            // none — so set it here rather than depending on which client was injected.
            // Matches GitHubAppAuth, which identifies itself the same way.
            'User-Agent'           => self::USER_AGENT,
        ];

        $requestBody = null;
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
            $requestBody             = json_encode($body, JSON_THROW_ON_ERROR);
        }

        $url     = self::API_BASE_URL . '/repos/' . $this->owner . '/' . $this->repo . $path;
        $request = new Request($method, $url, $headers, $requestBody);

        try {
            return $this->http->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw new RuntimeException(sprintf('GitHub API request failed: %s', $e->getMessage()), 0, $e);
        }
    }
}
