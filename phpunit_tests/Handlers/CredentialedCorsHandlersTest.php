<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers;

use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Handlers\DecreesHandler;
use LiturgicalCalendar\Api\Handlers\RegionalDataHandler;
use LiturgicalCalendar\Api\Handlers\TestsHandler;
use LiturgicalCalendar\Api\Router;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\ResponseInterface;

/**
 * Handlers that accept cookie-authenticated writes from the browser must not answer
 * with a wildcard Access-Control-Allow-Origin.
 *
 * A credentialed cross-origin request (`credentials: 'include'`) is refused by the
 * browser at preflight when the origin is `*`, no matter what the endpoint would
 * otherwise have done. /decrees shipped that way: it served POST/PUT/PATCH/DELETE
 * but never enabled credentials, so writing a decree from the staging frontend to
 * the production API failed before the request was ever sent.
 *
 * These handlers all share the same shape — a public GET plus authenticated writes —
 * so they are asserted together to keep the family consistent.
 */
#[CoversClass(DecreesHandler::class)]
final class CredentialedCorsHandlersTest extends AbstractHandlerTestCase
{
    private const ORIGIN = 'https://litcal-staging.example.test';

    /** @return array<string,array{callable():object,string}> */
    public static function credentialedHandlerProvider(): array
    {
        return [
            'decrees' => [static fn (): object => new DecreesHandler(), '/decrees'],
            'data'    => [static fn (): object => new RegionalDataHandler([], Rite::ROMAN), '/data'],
            'tests'   => [static fn (): object => new TestsHandler(), '/tests'],
        ];
    }

    /** @param callable():object $factory */
    private function preflight(callable $factory, string $path, string $method): ResponseInterface
    {
        /** @var \LiturgicalCalendar\Api\Handlers\AbstractHandler $handler */
        $handler = $factory();
        return $handler->handle($this->requestFor('OPTIONS', $path, [
            'Origin'                        => self::ORIGIN,
            'Access-Control-Request-Method' => $method,
        ]));
    }

    /** @param callable():object $factory */
    #[DataProvider('credentialedHandlerProvider')]
    public function testWritePreflightEchoesTheOriginInsteadOfWildcard(callable $factory, string $path): void
    {
        $response = $this->preflight($factory, $path, 'PUT');

        self::assertSame(204, $response->getStatusCode());
        self::assertSame(
            self::ORIGIN,
            $response->getHeaderLine('Access-Control-Allow-Origin'),
            "{$path} must echo the origin: a wildcard makes the browser refuse a credentialed write"
        );
        self::assertSame('true', $response->getHeaderLine('Access-Control-Allow-Credentials'));
    }

    /**
     * Echoing the origin makes the response origin-dependent, so it must advertise that
     * to shared caches or one origin can be served another origin's cached CORS headers.
     *
     * @param callable():object $factory
     */
    #[DataProvider('credentialedHandlerProvider')]
    public function testWritePreflightVariesOnOrigin(callable $factory, string $path): void
    {
        $vary = $this->preflight($factory, $path, 'PUT')->getHeader('Vary');

        self::assertContains('Origin', $vary, "{$path} echoes the origin, so it must Vary on it");
    }

    /**
     * A GET preflight takes the same treatment. The public read path is expected to be
     * called with `credentials: 'omit'`, which an echoed origin serves just as well as a
     * wildcard, so nothing breaks by being consistent here.
     *
     * @param callable():object $factory
     */
    #[DataProvider('credentialedHandlerProvider')]
    public function testReadPreflightIsAlsoCredentialSafe(callable $factory, string $path): void
    {
        $response = $this->preflight($factory, $path, 'GET');

        self::assertNotSame('*', $response->getHeaderLine('Access-Control-Allow-Origin'));
    }

    /**
     * The allow-list is what actually refuses a hostile cross-origin write, and the
     * preflight is the only place it can do so: restricting the origin on the write
     * response governs whether the response can be READ, never whether the request RUNS.
     * So a preflight from a disallowed origin must not be answered with that origin.
     *
     * @param callable():object $factory
     */
    #[DataProvider('credentialedHandlerProvider')]
    public function testDisallowedOriginIsNotEchoedOnAWritePreflight(callable $factory, string $path): void
    {
        /** @var \LiturgicalCalendar\Api\Handlers\AbstractHandler $handler */
        $handler = $factory();
        $handler->setAllowedOrigins(['https://allowed.example.test']);

        $response = $handler->handle($this->requestFor('OPTIONS', $path, [
            'Origin'                        => 'https://evil.example.test',
            'Access-Control-Request-Method' => 'DELETE',
        ]));

        self::assertNotSame(
            'https://evil.example.test',
            $response->getHeaderLine('Access-Control-Allow-Origin'),
            "{$path} must not clear a credentialed write for an origin outside the allow-list"
        );
        self::assertNotSame('*', $response->getHeaderLine('Access-Control-Allow-Origin'));
    }

    /** @param callable():object $factory */
    #[DataProvider('credentialedHandlerProvider')]
    public function testAllowedOriginIsEchoedOnAWritePreflight(callable $factory, string $path): void
    {
        /** @var \LiturgicalCalendar\Api\Handlers\AbstractHandler $handler */
        $handler = $factory();
        $handler->setAllowedOrigins([self::ORIGIN]);

        $response = $handler->handle($this->requestFor('OPTIONS', $path, [
            'Origin'                        => self::ORIGIN,
            'Access-Control-Request-Method' => 'DELETE',
        ]));

        self::assertSame(self::ORIGIN, $response->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertSame('true', $response->getHeaderLine('Access-Control-Allow-Credentials'));
    }

    /**
     * Router gates the allow-list on the method. A preflight is an OPTIONS request, so
     * gating on that alone never covered it — the gap this closes.
     */
    public function testAllowListCoversWritePreflightsNotJustWrites(): void
    {
        // Writes themselves: restricted, as before.
        self::assertTrue(Router::restrictsOriginsForWrite('PUT'));
        self::assertTrue(Router::restrictsOriginsForWrite('PATCH'));
        self::assertTrue(Router::restrictsOriginsForWrite('DELETE'));

        // The preflight that clears a write: restricted now, previously not.
        self::assertTrue(Router::restrictsOriginsForWrite('OPTIONS', 'PUT'));
        self::assertTrue(Router::restrictsOriginsForWrite('OPTIONS', 'PATCH'));
        self::assertTrue(Router::restrictsOriginsForWrite('OPTIONS', 'DELETE'));
        self::assertTrue(Router::restrictsOriginsForWrite('options', 'delete'), 'method matching is case-insensitive');

        // Reads and their preflights stay open, so public cross-origin GETs are unaffected.
        self::assertFalse(Router::restrictsOriginsForWrite('GET'));
        self::assertFalse(Router::restrictsOriginsForWrite('POST'));
        self::assertFalse(Router::restrictsOriginsForWrite('OPTIONS', 'GET'));
        self::assertFalse(Router::restrictsOriginsForWrite('OPTIONS', ''));
    }
}
