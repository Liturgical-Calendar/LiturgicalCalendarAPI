<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services\GitHub;

use Firebase\JWT\JWT;
use GuzzleHttp\Psr7\Request;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use RuntimeException;

/**
 * Authenticates as a GitHub App and exchanges the App JWT for an installation access token.
 *
 * The private key is read from a filesystem path rather than an environment variable: in
 * production it lives outside the deployed tree (`/etc/litcal/github-app.pem`), and a key
 * threaded through the environment would end up in process listings and crash dumps.
 *
 * Installation tokens are cached (PSR-6) for 3000 seconds — 50 minutes — against GitHub's
 * one-hour token life, so a token already in flight is never used in its final ten minutes.
 */
final class GitHubAppAuth
{
    private const API_BASE_URL = 'https://api.github.com';

    /**
     * GitHub rejects an App JWT whose `exp` is more than 10 minutes out. 540 seconds leaves
     * headroom for the 60-second backdated `iat` plus request latency while staying under
     * that ceiling.
     */
    private const JWT_TTL_SECONDS = 540;

    private const JWT_CLOCK_SKEW_SECONDS = 60;

    /** Installation tokens live one hour; cache for 50 minutes so the final 10 are never served. */
    private const CACHE_TTL_SECONDS = 3000;

    public function __construct(
        private readonly string $appId,
        private readonly string $installationId,
        private readonly string $privateKeyPath,
        private readonly ClientInterface $http,
        private readonly CacheItemPoolInterface $cache
    ) {
    }

    /**
     * Check if the GitHub App environment variables are configured.
     *
     * Mirrors {@see \LiturgicalCalendar\Api\Services\OpenFgaClient::isConfigured()}.
     */
    public static function isConfigured(): bool
    {
        $appId          = self::getEnvString('GITHUB_APP_ID');
        $installationId = self::getEnvString('GITHUB_APP_INSTALLATION_ID');
        $privateKeyPath = self::getEnvString('GITHUB_APP_PRIVATE_KEY_PATH');

        return $appId !== '' && $installationId !== '' && $privateKeyPath !== '';
    }

    /**
     * Get an environment variable as a string.
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
     * Return a cached installation access token, fetching and caching a fresh one if needed.
     *
     * @throws GitHubApiException If the token exchange fails
     * @throws RuntimeException   If the private key cannot be read
     */
    public function installationToken(): string
    {
        $cacheKey = $this->cacheKey();
        $item     = $this->cache->getItem($cacheKey);

        if ($item->isHit()) {
            $cached = $item->get();
            if (is_string($cached) && $cached !== '') {
                return $cached;
            }
        }

        $token = $this->fetchInstallationToken();

        $item->set($token);
        $item->expiresAfter(self::CACHE_TTL_SECONDS);
        $this->cache->save($item);

        return $token;
    }

    /**
     * PSR-6 cache keys are restricted to `[A-Za-z0-9_.]`, and the key must include the
     * installation id so that two installations sharing a cache pool cannot collide.
     */
    private function cacheKey(): string
    {
        $safeInstallationId = preg_replace('/[^A-Za-z0-9_.]/', '_', $this->installationId);

        return 'github_app_installation_token_' . $safeInstallationId;
    }

    /**
     * RS256-sign an App JWT and exchange it for an installation access token.
     *
     * @throws GitHubApiException If GitHub responds with a non-2xx status
     * @throws RuntimeException   If the private key cannot be read
     */
    private function fetchInstallationToken(): string
    {
        $privateKey = file_get_contents($this->privateKeyPath);
        if ($privateKey === false) {
            throw new RuntimeException(
                sprintf('Unable to read GitHub App private key at "%s"', $this->privateKeyPath)
            );
        }

        $now = time();
        $jwt = JWT::encode(
            [
                'iat' => $now - self::JWT_CLOCK_SKEW_SECONDS,
                'exp' => $now + self::JWT_TTL_SECONDS,
                'iss' => $this->appId,
            ],
            $privateKey,
            'RS256'
        );

        $url     = self::API_BASE_URL . '/app/installations/' . rawurlencode($this->installationId) . '/access_tokens';
        $request = new Request(
            'POST',
            $url,
            [
                'Authorization' => 'Bearer ' . $jwt,
                'Accept'        => 'application/vnd.github+json',
                'User-Agent'    => 'LiturgicalCalendarAPI-GitHubApp',
            ]
        );

        try {
            $response = $this->http->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw new RuntimeException(
                sprintf('GitHub installation token request failed: %s', $e->getMessage()),
                0,
                $e
            );
        }

        $status       = $response->getStatusCode();
        $responseBody = (string) $response->getBody();

        /** @var mixed $decoded */
        $decoded = json_decode($responseBody, true);
        $decoded = is_array($decoded) ? $decoded : [];

        if ($status < 200 || $status >= 300) {
            $message = isset($decoded['message']) && is_string($decoded['message'])
                ? $decoded['message']
                : 'Unknown error';

            throw new GitHubApiException($status, sprintf('GitHub API error (HTTP %d): %s', $status, $message));
        }

        $token = $decoded['token'] ?? null;
        if (!is_string($token) || $token === '') {
            throw new GitHubApiException(
                $status,
                'GitHub installation token exchange succeeded but the response carried no token'
            );
        }

        return $token;
    }
}
