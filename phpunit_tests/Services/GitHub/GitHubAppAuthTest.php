<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services\GitHub;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use LiturgicalCalendar\Api\Services\GitHub\GitHubApiException;
use LiturgicalCalendar\Api\Services\GitHub\GitHubAppAuth;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * No GitHub App exists yet — Task 3 builds the credential layer against a mocked HTTP client
 * and a throwaway RSA key generated at runtime, so the suite needs no committed key material
 * and no network access. Real credentials are wired in Task 8.
 */
#[CoversClass(GitHubAppAuth::class)]
final class GitHubAppAuthTest extends TestCase
{
    private static string $keyDir;
    private static string $keyPath;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        $keyDir = sys_get_temp_dir() . '/github-app-auth-test-' . bin2hex(random_bytes(8));
        if (!mkdir($keyDir, 0700, true) && !is_dir($keyDir)) {
            self::fail(sprintf('Unable to create temp directory "%s"', $keyDir));
        }
        self::$keyDir = $keyDir;

        $resource = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        if ($resource === false) {
            self::fail('Unable to generate a throwaway RSA key for the test suite');
        }

        $exported = openssl_pkey_export($resource, $privateKeyPem);
        if (!$exported || !is_string($privateKeyPem)) {
            self::fail('Unable to export the throwaway RSA private key');
        }

        $keyPath = $keyDir . '/github-app.pem';
        file_put_contents($keyPath, $privateKeyPem);
        self::$keyPath = $keyPath;
    }

    public static function tearDownAfterClass(): void
    {
        // Child-first: a previous task on this branch leaked temp directories because cleanup
        // only reached one level deep.
        if (isset(self::$keyPath) && file_exists(self::$keyPath)) {
            unlink(self::$keyPath);
        }
        if (isset(self::$keyDir) && is_dir(self::$keyDir)) {
            rmdir(self::$keyDir);
        }

        parent::tearDownAfterClass();
    }

    private static function keyPath(): string
    {
        return self::$keyPath;
    }

    /** @param array<int, GuzzleResponse> $responses */
    private function auth(array $responses, CacheItemPoolInterface $cache): GitHubAppAuth
    {
        $guzzle = new GuzzleClient(['handler' => HandlerStack::create(new MockHandler($responses))]);

        return new GitHubAppAuth('12345', '67890', self::keyPath(), $guzzle, $cache);
    }

    public function testItExchangesTheAppJwtForAnInstallationToken(): void
    {
        $auth = $this->auth(
            [new GuzzleResponse(201, [], json_encode(['token' => 'ghs_abc', 'expires_at' => '2026-01-01T00:00:00Z']))],
            new ArrayAdapter()
        );

        self::assertSame('ghs_abc', $auth->installationToken());
    }

    public function testTheTokenIsCachedRatherThanFetchedEveryCall(): void
    {
        // One response only: a second HTTP call would throw "queue is empty".
        $auth = $this->auth(
            [new GuzzleResponse(201, [], json_encode(['token' => 'ghs_abc', 'expires_at' => '2026-01-01T00:00:00Z']))],
            new ArrayAdapter()
        );

        self::assertSame('ghs_abc', $auth->installationToken());
        self::assertSame('ghs_abc', $auth->installationToken());
    }

    public function testDifferentInstallationsDoNotShareACacheEntry(): void
    {
        $cache  = new ArrayAdapter();
        $guzzle = new GuzzleClient([
            'handler' => HandlerStack::create(new MockHandler([
                new GuzzleResponse(201, [], json_encode(['token' => 'ghs_one', 'expires_at' => '2026-01-01T00:00:00Z'])),
                new GuzzleResponse(201, [], json_encode(['token' => 'ghs_two', 'expires_at' => '2026-01-01T00:00:00Z'])),
            ])),
        ]);

        $authOne = new GitHubAppAuth('12345', '11111', self::keyPath(), $guzzle, $cache);
        $authTwo = new GitHubAppAuth('12345', '22222', self::keyPath(), $guzzle, $cache);

        self::assertSame('ghs_one', $authOne->installationToken());
        self::assertSame('ghs_two', $authTwo->installationToken());
    }

    public function testAFailedExchangeRaisesGitHubApiExceptionCarryingTheStatus(): void
    {
        $auth = $this->auth([new GuzzleResponse(401, [], json_encode(['message' => 'Bad credentials']))], new ArrayAdapter());

        try {
            $auth->installationToken();
            self::fail('a 401 must not be swallowed');
        } catch (GitHubApiException $e) {
            self::assertSame(401, $e->status);
            self::assertStringContainsString('Bad credentials', $e->getMessage());
        }
    }

    public function testIsConfiguredReflectsTheEnvironment(): void
    {
        $original = [
            'GITHUB_APP_ID'               => $_ENV['GITHUB_APP_ID'] ?? null,
            'GITHUB_APP_INSTALLATION_ID'  => $_ENV['GITHUB_APP_INSTALLATION_ID'] ?? null,
            'GITHUB_APP_PRIVATE_KEY_PATH' => $_ENV['GITHUB_APP_PRIVATE_KEY_PATH'] ?? null,
        ];

        try {
            unset($_ENV['GITHUB_APP_ID'], $_ENV['GITHUB_APP_INSTALLATION_ID'], $_ENV['GITHUB_APP_PRIVATE_KEY_PATH']);
            self::assertFalse(GitHubAppAuth::isConfigured());

            $_ENV['GITHUB_APP_ID']               = '12345';
            $_ENV['GITHUB_APP_INSTALLATION_ID']  = '67890';
            $_ENV['GITHUB_APP_PRIVATE_KEY_PATH'] = self::keyPath();
            self::assertTrue(GitHubAppAuth::isConfigured());
        } finally {
            foreach ($original as $name => $value) {
                if ($value === null) {
                    unset($_ENV[$name]);
                } else {
                    $_ENV[$name] = $value;
                }
            }
        }
    }
}
