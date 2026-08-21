<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests;

use LiturgicalCalendar\Api\Health;
use LiturgicalCalendar\Api\Models\ValidationsPath\CheckableInventory;
use LiturgicalCalendar\Api\Router;
use LiturgicalCalendar\Tests\Support\HealthQueueIsolationTrait;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ratchet\ConnectionInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * #833: a URL-based check must not report `exists = success` for a non-2xx HTTP status.
 *
 * `Health`'s Guzzle client is built with `'http_errors' => false`, so a 4xx or 5xx resolves
 * {@see Health::cachedGet()}'s promise instead of rejecting it, and before this fix the status was
 * discarded entirely: every consumer's fulfil handler had nothing to check and reported `exists` as
 * passed unconditionally. Observed live on Ambrosian years 1970-1975, which the API correctly refuses
 * with a 400 whose body is an RFC 9457 problem document explaining exactly why — none of it reached
 * the client.
 *
 * Every arm here drives {@see Health::onMessage()} end-to-end and settles the queued HTTP request by
 * hand, exactly as {@see HealthTerminalFrameTest} does — nothing here drives the ReactPHP loop.
 *
 * @see HealthHelpersTest for the codec-level coverage of the cached-path trap (point 2 of the issue):
 *      {@see Health::encodeHttpCacheEntry()} and {@see Health::decodeHttpCacheEntry()} are unit-tested
 *      there without a real cache backend, since the status only survives a second run if the value
 *      written to the cache on the first one can be decoded back correctly.
 */
#[CoversClass(Health::class)]
final class HealthHttpStatusExistsTest extends TestCase
{
    use HealthQueueIsolationTrait;

    /** The exact 400 the issue quotes, refusing an Ambrosian year before the rite's lower bound. */
    private const AMBROSIAN_400_BODY = '{"type":"https://www.rfc-editor.org/rfc/rfc9110.html#name-400-bad-request","title":"Bad Request","status":400,"detail":"The Ambrosian rite is only available from 1976 onward (the first reformed Ambrosian Missal); requested year 1970."}';

    /** A 404 with no problem-document `detail`, so the fallback-to-bare-status wording is exercised too. */
    private const PLAIN_404_BODY = '{"type":"about:blank","status":404}';

    public static function setUpBeforeClass(): void
    {
        Router::getApiPaths();
        CheckableInventory::reset();
    }

    public static function tearDownAfterClass(): void
    {
        CheckableInventory::reset();
    }

    private ?string $appEnvBackup = null;

    protected function setUp(): void
    {
        parent::setUp();
        // Keeps handleHttpResponse() out of its development-only debug-logging branch.
        $this->appEnvBackup = isset($_ENV['APP_ENV']) && is_string($_ENV['APP_ENV']) ? $_ENV['APP_ENV'] : null;
        $_ENV['APP_ENV']    = 'test';
    }

    protected function tearDown(): void
    {
        if (null === $this->appEnvBackup) {
            unset($_ENV['APP_ENV']);
        } else {
            $_ENV['APP_ENV'] = $this->appEnvBackup;
        }
        parent::tearDown();
    }

    // ---------------------------------------------------------------- harness

    /**
     * A minimal Ratchet connection that records every outbound frame, matching the stub convention
     * used across the Health suite rather than a PHPUnit mock, which would trigger a
     * dynamic-property deprecation over `resourceId`.
     */
    private static function stubConnection(int $resourceId = 1)
    {
        return new class ($resourceId) implements ConnectionInterface {
            /** @var list<string> */
            public array $sent = [];

            public function __construct(public int $resourceId)
            {
            }

            public function send($data)
            {
                $this->sent[] = (string) $data;

                return $this;
            }

            public function close()
            {
            }
        };
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function send(Health $health, ConnectionInterface $conn, array $payload): void
    {
        $health->onMessage($conn, (string) json_encode($payload));
    }

    /**
     * @return list<\stdClass>
     */
    private static function framesOf(ConnectionInterface $conn): array
    {
        /** @var object{sent: list<string>} $conn */
        return array_map(
            static fn (string $raw): \stdClass => json_decode($raw),
            $conn->sent
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function queuedRequests(Health $health): array
    {
        /** @var list<array<string, mixed>> */
        return ( new \ReflectionProperty(Health::class, 'queue') )->getValue($health);
    }

    /**
     * Settle one outstanding request with the given status and body — the shape a real upstream
     * refusal takes, since `'http_errors' => false` means Guzzle resolves rather than rejects for
     * every status this test drives.
     */
    private static function fulfilQueuedRequestWithStatus(Health $health, int $index, int $status, string $body): void
    {
        $queued = self::queuedRequests($health);
        self::assertArrayHasKey($index, $queued, "expected a queued request at index {$index}");
        /** @var \Closure(ResponseInterface):void $resolve */
        $resolve = $queued[$index]['resolve'];

        ob_start();
        $resolve(new Response($status, ['Content-Type' => 'application/problem+json'], $body));
        ob_end_clean();
    }

    // ---------------------------------------------------------------- validateCalendar

    /**
     * The arm the issue is named after: a calendar year the API refuses with a 400. `exists` must
     * fail, quoting the problem document's `detail` verbatim, and `parses`/`validates` must still be
     * reported — failed, not skipped — per #821's one-frame-per-step contract.
     */
    public function testACalendarRefusedWithA400FailsExistsAndQuotesTheProblemDetail(): void
    {
        $health = $this->newHealth();
        $conn   = self::stubConnection();

        self::send($health, $conn, [
            'action'         => 'validateCalendar',
            'calendar'       => ['kind' => 'rite', 'id' => 'ambrosian', 'rite' => 'ambrosian'],
            'year'           => 1970,
            'responseFormat' => 'JSON',
            'requestId'      => 'req-alpha'
        ]);
        self::fulfilQueuedRequestWithStatus($health, 0, 400, self::AMBROSIAN_400_BODY);

        $frames = self::framesOf($conn);
        self::assertSame(
            ['exists', 'parses', 'validates', 'complete'],
            array_map(static fn (\stdClass $f): ?string => property_exists($f, 'step') && is_string($f->step) ? $f->step : null, $frames),
            'a refused calendar still reports every published step, then terminates'
        );

        [$exists, $parses, $validates, $complete] = $frames;

        self::assertSame('fail', $exists->status, 'the defect: exists must not pass for a 400');
        self::assertStringContainsString('HTTP 400', $exists->text);
        self::assertStringContainsString(
            'The Ambrosian rite is only available from 1976 onward (the first reformed Ambrosian Missal); requested year 1970.',
            $exists->text,
            'the API\'s own problem-document detail must reach the client verbatim'
        );

        self::assertSame('fail', $parses->status, 'a refused request was never parsed, so this must not be a silent skip');
        self::assertSame('fail', $validates->status, 'a refused request was never schema-checked either');

        self::assertSame('req-alpha', $complete->requestId);
    }

    /**
     * A 404 with no `detail` in its body still fails `exists`, falling back to the bare status rather
     * than fabricating an explanation the response never gave.
     */
    public function testACalendarRefusedWithA404WithNoDetailFallsBackToTheBareStatus(): void
    {
        $health = $this->newHealth();
        $conn   = self::stubConnection();

        self::send($health, $conn, [
            'action'         => 'validateCalendar',
            'calendar'       => ['kind' => 'national', 'id' => 'ZZ', 'rite' => 'roman'],
            'year'           => 2026,
            'responseFormat' => 'JSON',
            'requestId'      => 'req-beta'
        ]);
        self::fulfilQueuedRequestWithStatus($health, 0, 404, self::PLAIN_404_BODY);

        $frames = self::framesOf($conn);
        self::assertSame('fail', $frames[0]->status);
        self::assertStringContainsString('HTTP 404', $frames[0]->text);
        self::assertStringNotContainsString(': ', explode('HTTP 404', $frames[0]->text)[1] ?? '', 'no detail was available, so none is invented');
    }

    /**
     * A 200 is unaffected: the happy path this fix must not have broken.
     */
    public function testACalendarThatExistsStillPassesOnA200(): void
    {
        $health = $this->newHealth();
        $conn   = self::stubConnection();

        self::send($health, $conn, [
            'action'         => 'validateCalendar',
            'calendar'       => ['kind' => 'national', 'id' => 'IT', 'rite' => 'roman'],
            'year'           => 2026,
            'responseFormat' => 'JSON',
            'requestId'      => 'req-gamma'
        ]);
        self::fulfilQueuedRequestWithStatus($health, 0, 200, '{"litcal":[],"settings":{},"metadata":{},"messages":[]}');

        $frames = self::framesOf($conn);
        self::assertSame('pass', $frames[0]->status, 'precondition: a 200 must still be reported as existing');
    }

    // ---------------------------------------------------------------- resourceDataCheck (executeValidation)

    /**
     * The scope the issue calls out explicitly: `executeValidation` with `category: "resourceDataCheck"`,
     * whose `sourceFile` is a URL. It reaches {@see \LiturgicalCalendar\Api\Health::processValidationData()}
     * on a 2xx and {@see \LiturgicalCalendar\Api\Health::handleValidationHttpFailure()} otherwise — the
     * URL-based sibling of the unreadable-file arm {@see HealthValidationDataErrorTest} pins.
     */
    public function testAResourceUrlRefusedWithA400FailsAllThreePublishedSteps(): void
    {
        $health = $this->newHealth();
        $conn   = self::stubConnection();

        self::send($health, $conn, [
            'action'     => 'executeValidation',
            'category'   => 'resourceDataCheck',
            'validate'   => 'calendar-ambrosian-IT',
            'sourceFile' => 'https://example.test/calendar/ambrosian/IT/1970',
            'requestId'  => 'req-alpha'
        ]);
        self::fulfilQueuedRequestWithStatus($health, 0, 400, self::AMBROSIAN_400_BODY);

        $frames = self::framesOf($conn);
        self::assertSame(
            ['exists', 'parses', 'validates', 'complete'],
            array_map(static fn (\stdClass $f): ?string => property_exists($f, 'step') && is_string($f->step) ? $f->step : null, $frames)
        );

        [$exists, $parses, $validates] = $frames;
        self::assertSame('fail', $exists->status);
        self::assertStringContainsString('HTTP 400', $exists->text);
        self::assertStringContainsString(
            'The Ambrosian rite is only available from 1976 onward (the first reformed Ambrosian Missal); requested year 1970.',
            $exists->text
        );
        self::assertSame('fail', $parses->status, 'not attempted must be reported the same as failed, per #821');
        self::assertSame('fail', $validates->status);
    }

    /**
     * A 2xx resource is unaffected and still reaches `processValidationData()`, which is what
     * actually reports `exists` as passed for it.
     */
    public function testAResourceUrlThatExistsStillPassesOnA200(): void
    {
        $health = $this->newHealth();
        $conn   = self::stubConnection();

        self::send($health, $conn, [
            'action'     => 'executeValidation',
            'category'   => 'resourceDataCheck',
            'validate'   => 'calendar-roman-IT',
            'sourceFile' => 'https://example.test/calendar/nation/IT/2026',
            'requestId'  => 'req-beta'
        ]);
        self::fulfilQueuedRequestWithStatus($health, 0, 200, '{"litcal":[]}');

        $frames = self::framesOf($conn);
        self::assertSame('pass', $frames[0]->status);
    }

    // ---------------------------------------------------------------- runTest

    /**
     * A test run carries one step (`validates`), not three — but a refused calendar must still fail
     * it rather than be handed to `LitTestRunner` as if the problem document were calendar data.
     */
    public function testARunTestAgainstARefusedCalendarFailsRatherThanRunningOverAProblemDocument(): void
    {
        $health = $this->newHealth();
        $conn   = self::stubConnection();

        self::send($health, $conn, [
            'action'    => 'runTest',
            'test'      => 'SomeAmbrosianTest',
            'calendar'  => ['kind' => 'rite', 'id' => 'ambrosian', 'rite' => 'ambrosian'],
            'year'      => 1970,
            'requestId' => 'req-alpha'
        ]);
        self::fulfilQueuedRequestWithStatus($health, 0, 400, self::AMBROSIAN_400_BODY);

        $frames = self::framesOf($conn);
        self::assertSame(
            ['validates', 'complete'],
            array_map(static fn (\stdClass $f): ?string => property_exists($f, 'step') && is_string($f->step) ? $f->step : null, $frames)
        );
        self::assertSame('fail', $frames[0]->status);
        self::assertStringContainsString('HTTP 400', $frames[0]->text);
        self::assertStringContainsString(
            'The Ambrosian rite is only available from 1976 onward (the first reformed Ambrosian Missal); requested year 1970.',
            $frames[0]->text
        );
    }
}
