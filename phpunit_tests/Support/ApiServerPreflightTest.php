<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Support;

use PHPUnit\Framework\TestCase;

/**
 * The preflight's whole value is that it separates three outcomes a bare TCP probe
 * conflates, so each of the three is asserted here through the injected transport seam
 * rather than against a live port (#922).
 *
 * No #[CoversClass]: ApiServerPreflight is test-support code under phpunit_tests/, which is
 * outside the coverage whitelist (src/ only). Naming it as a coverage target makes the
 * coverage run emit "is not a valid target for code coverage" — eight warnings that fail
 * `composer test:coverage` in CI while `composer test:quick`, which collects no coverage,
 * stays green.
 */
final class ApiServerPreflightTest extends TestCase
{
    protected function tearDown(): void
    {
        ApiServerPreflight::reset();
    }

    /**
     * @param array{int, array<string, string>, string}|null $response
     * @return callable(string, string): (array{int, array<string, string>, string}|null)
     */
    private static function transportReturning(?array $response): callable
    {
        return static fn (string $baseUri, string $path): ?array => $response;
    }

    public function testNothingListeningIsNeitherOkNorForeign(): void
    {
        $preflight = ApiServerPreflight::inspect('http', 'localhost', 8000, self::transportReturning(null));

        self::assertSame(ApiServerPreflight::NOT_LISTENING, $preflight->status);
        self::assertFalse($preflight->ok());
        // The caller must be free to skip: an absent server is the ordinary
        // "I haven't run `composer start`" case, not a broken environment.
        self::assertFalse($preflight->isForeign());
    }

    public function testA404FromAForeignServerIsReportedWithItsSignature(): void
    {
        // The exact shape observed in #922: a containerised PHP holding the port and
        // 404ing every path, with a patch version different from the local CLI's.
        $preflight = ApiServerPreflight::inspect('http', 'localhost', 8000, self::transportReturning([
            404,
            ['X-Powered-By' => 'PHP/8.4.20', 'Content-Type' => 'text/html; charset=UTF-8'],
            '<!doctype html><html><head><title>404 Not Found</title></head><body><h1>Not Found</h1></body></html>',
        ]));

        self::assertTrue($preflight->isForeign());
        self::assertFalse($preflight->ok());

        $message = $preflight->message();
        self::assertStringContainsString('is not this API', $message);
        self::assertStringContainsString('http://localhost:8000/calendars', $message);
        self::assertStringContainsString('HTTP 404', $message);
        self::assertStringContainsString('PHP/8.4.20', $message);
        self::assertStringContainsString('litcal_metadata', $message);
        self::assertStringContainsString('404 Not Found', $message, 'the body excerpt is the evidence of what answered');
        self::assertStringContainsString('force-recreate', $message, 'the diagnostic must name a remedy');
    }

    public function testA200ThatIsNotOurPayloadIsForeign(): void
    {
        // The dangerous variant: a healthy-looking JSON 200 from something else entirely.
        $preflight = ApiServerPreflight::inspect('http', 'localhost', 8000, self::transportReturning([
            200,
            ['Content-Type' => 'application/json'],
            '{"status":"ok"}',
        ]));

        self::assertTrue($preflight->isForeign());
    }

    public function testOurApiIsRecognised(): void
    {
        $preflight = ApiServerPreflight::inspect('http', 'localhost', 8000, self::transportReturning([
            200,
            ['Content-Type' => 'application/json'],
            '{"litcal_metadata":{"national_calendars_keys":["VA"]}}',
        ]));

        self::assertTrue($preflight->ok());
        self::assertFalse($preflight->isForeign());
        self::assertStringNotContainsString('is not this API', $preflight->message());
    }

    public function testAnInjectedTransportNeverPoisonsTheProcessMemo(): void
    {
        $foreign = ApiServerPreflight::inspect('http', 'localhost', 8000, self::transportReturning([404, [], 'nope']));
        self::assertTrue($foreign->isForeign());

        $ours = ApiServerPreflight::inspect('http', 'localhost', 8000, self::transportReturning([
            200,
            ['Content-Type' => 'application/json'],
            '{"litcal_metadata":{}}',
        ]));
        self::assertTrue($ours->ok(), 'a memoised injected result would have returned the foreign verdict here');
    }

    /**
     * A 3xx is FOREIGN, and is reported with its target rather than followed.
     *
     * Guzzle follows redirects by default, which would make every reported field describe the
     * redirect target while the message still named this base URI — and a redirect that happened
     * to land on a healthy API would be classified OK, certifying a server the tests never talk to.
     */
    public function testARedirectIsForeignAndNamesItsTargetWithoutBeingFollowed(): void
    {
        $preflight = ApiServerPreflight::inspect('http', 'localhost', 8000, self::transportReturning([
            302,
            ['Location' => 'https://api.example.test/calendars', 'Content-Type' => 'text/html'],
            '<html><body>Moved</body></html>',
        ]));

        self::assertTrue($preflight->isForeign(), 'a 3xx is not this API answering /calendars');
        self::assertFalse($preflight->ok());
        self::assertSame(302, $preflight->httpStatus, 'the 3xx itself must survive to evaluate()');
        self::assertSame('https://api.example.test/calendars', $preflight->location);
        self::assertStringContainsString('https://api.example.test/calendars', $preflight->message());
        self::assertStringContainsString('not followed', $preflight->message());
    }

    /** A Location on an otherwise-OK response is not reported: there is nothing to explain. */
    public function testLocationIsNotReportedWhenTheServerIsOurs(): void
    {
        $preflight = ApiServerPreflight::inspect('http', 'localhost', 8000, self::transportReturning([
            200,
            ['Location' => 'https://api.example.test/elsewhere', 'Content-Type' => 'application/json'],
            '{"litcal_metadata":{}}',
        ]));

        self::assertTrue($preflight->ok());
        self::assertSame('', $preflight->location);
    }

    public function testBuildDriftIsSilentWhenTheServedFileMatchesTheWorkingTree(): void
    {
        $root  = dirname(__DIR__, 2);
        $local = (string) file_get_contents($root . '/jsondata/schemas/LitCal.json');

        $preflight = ApiServerPreflight::inspect('http', 'localhost', 8000, self::transportReturning([
            200,
            ['Content-Type' => 'application/json'],
            '{"litcal_metadata":{}}',
        ]));

        self::assertNull($preflight->buildDrift($root, self::transportReturning([200, [], $local])));
    }

    public function testBuildDriftReportsAServerServingADifferentCheckout(): void
    {
        $root = dirname(__DIR__, 2);

        $preflight = ApiServerPreflight::inspect('http', 'localhost', 8000, self::transportReturning([
            200,
            ['Content-Type' => 'application/json'],
            '{"litcal_metadata":{}}',
        ]));

        $drift = $preflight->buildDrift($root, self::transportReturning([200, [], '{"title":"a different build"}']));

        self::assertIsString($drift);
        self::assertStringContainsString('build drift', $drift);
        self::assertStringContainsString('different checkout', $drift);
    }

    public function testBuildDriftSaysNothingWhenTheServerIsNotEvenOurs(): void
    {
        $preflight = ApiServerPreflight::inspect('http', 'localhost', 8000, self::transportReturning([404, [], 'nope']));

        // A foreign server is already reported as an error; a second, weaker complaint
        // about its files would only add noise.
        self::assertNull($preflight->buildDrift(dirname(__DIR__, 2), self::transportReturning([200, [], 'anything'])));
    }
}
