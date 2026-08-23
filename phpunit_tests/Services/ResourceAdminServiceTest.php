<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use LiturgicalCalendar\Api\Services\OpenFgaClient;
use LiturgicalCalendar\Api\Services\ResourceAdminService;
use LiturgicalCalendar\Tests\Support\CollectingLogger;
use LiturgicalCalendar\Tests\Support\ThrowingLogger;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[CoversClass(ResourceAdminService::class)]
final class ResourceAdminServiceTest extends TestCase
{
    /**
     * @param array<int, GuzzleResponse> $responses Queued, replayed in order.
     * @param LoggerInterface|null $logger Optional PSR-3 spy; when null the service falls
     *                                     back to its own lazily-created logger.
     */
    private function serviceWith(array $responses, ?LoggerInterface $logger = null): ResourceAdminService
    {
        $stack  = HandlerStack::create(new MockHandler($responses));
        $guzzle = new GuzzleClient(['handler' => $stack]);
        $psr17  = new Psr17Factory();
        $client = new OpenFgaClient(
            apiUrl: 'http://openfga.test',
            storeId: 'test-store',
            modelId: 'test-model',
            httpClient: $guzzle,
            requestFactory: $psr17,
            streamFactory: $psr17,
            apiToken: 'test-token'
        );
        return new ResourceAdminService($client, $logger ?? new CollectingLogger());
    }

    /**
     * $count distinct 500 responses — one per (relation, object type) probe.
     *
     * Distinct instances matter: a single GuzzleResponse reused across queue slots
     * shares one body stream, which is exhausted after the first read.
     *
     * @return array<int, GuzzleResponse>
     */
    private static function serverErrors(int $count): array
    {
        return array_map(static fn(): GuzzleResponse => new GuzzleResponse(500, [], 'boom'), range(1, $count));
    }

    /**
     * OpenFGA's response to a request naming an object type that is absent from the
     * deployed authorization model — the exact failure that caused issue #793.
     */
    private static function typeNotFound(string $type): GuzzleResponse
    {
        return new GuzzleResponse(
            400,
            [],
            sprintf('{"code":"type_not_found","message":"type \'%s\' not found"}', $type)
        );
    }

    public function testResolveScopesUnionsAdminTuplesAcrossTypes(): void
    {
        // One list-objects response per ADMIN_OBJECT_TYPES entry, in order:
        // national_calendar, diocesan_calendar, wider_region, general_roman_calendar
        $service = $this->serviceWith([
            new GuzzleResponse(200, [], '{"objects":["national_calendar:IT"]}'),
            new GuzzleResponse(200, [], '{"objects":[]}'),
            new GuzzleResponse(200, [], '{"objects":[]}'),
            new GuzzleResponse(200, [], '{"objects":[]}'),
        ]);

        self::assertSame(
            [['object_type' => 'national_calendar', 'object_id' => 'IT']],
            $service->resolveScopes('cei-admin')
        );
    }

    public function testResolveScopesFailsClosedOnOpenFgaError(): void
    {
        // Every type fails -> the union is empty. One 500 per ADMIN_OBJECT_TYPES entry:
        // each type is probed independently, so each needs its own queued response.
        $service = $this->serviceWith(self::serverErrors(count(ResourceAdminService::ADMIN_OBJECT_TYPES)));

        self::assertSame([], $service->resolveScopes('cei-admin'));
    }

    public function testResolveScopesIsolatesFailureToTheOffendingObjectType(): void
    {
        // Regression guard for issue #793: a single failing object type used to
        // discard the scopes already collected for every other type. Here
        // diocesan_calendar (the 2nd of ADMIN_OBJECT_TYPES) blows up; the
        // national_calendar and general_roman_calendar scopes must survive.
        $logger  = new CollectingLogger();
        $service = $this->serviceWith([
            new GuzzleResponse(200, [], '{"objects":["national_calendar:IT"]}'),
            self::typeNotFound('diocesan_calendar'),
            new GuzzleResponse(200, [], '{"objects":[]}'),
            new GuzzleResponse(200, [], '{"objects":["general_roman_calendar:temporale"]}'),
        ], $logger);

        self::assertSame(
            [
                ['object_type' => 'national_calendar', 'object_id' => 'IT'],
                ['object_type' => 'general_roman_calendar', 'object_id' => 'temporale'],
            ],
            $service->resolveScopes('cei-admin')
        );

        $errors = $logger->recordsAtLevel('error');
        self::assertCount(1, $errors);
        self::assertStringContainsString('diocesan_calendar', $errors[0]['message']);
        self::assertSame('diocesan_calendar', $errors[0]['context']['object_type']);
        self::assertSame('admin', $errors[0]['context']['relation']);
    }

    public function testFilterByAdminAccessKeepsOnlyFullyAdministeredRequests(): void
    {
        // check() calls, in request order:
        // req A perm national_calendar:IT -> allowed
        // req B perm national_calendar:US -> denied
        $service = $this->serviceWith([
            new GuzzleResponse(200, [], '{"allowed":true}'),
            new GuzzleResponse(200, [], '{"allowed":false}'),
        ]);

        $requests = [
            ['id' => 'A', 'permissions' => [['object_type' => 'national_calendar', 'object_id' => 'IT', 'relation' => 'editor']]],
            ['id' => 'B', 'permissions' => [['object_type' => 'national_calendar', 'object_id' => 'US', 'relation' => 'editor']]],
            ['id' => 'C', 'permissions' => []],
        ];

        $filtered = $service->filterByAdminAccess($requests, 'cei-admin');

        self::assertSame(['A'], array_column($filtered, 'id'));
    }

    public function testResolveScopesUnionsMultipleTypesWithResults(): void
    {
        // One list-objects response per ADMIN_OBJECT_TYPES entry, in order:
        // national_calendar, diocesan_calendar, wider_region, general_roman_calendar.
        // Two types return results; the union must include both in ADMIN_OBJECT_TYPES order.
        // (The existing happy-path test covers a single type; this proves the cross-type union.)
        $service = $this->serviceWith([
            new GuzzleResponse(200, [], '{"objects":["national_calendar:IT"]}'),
            new GuzzleResponse(200, [], '{"objects":["diocesan_calendar:ROMA"]}'),
            new GuzzleResponse(200, [], '{"objects":[]}'),
            new GuzzleResponse(200, [], '{"objects":[]}'),
        ]);

        self::assertSame(
            [
                ['object_type' => 'national_calendar', 'object_id' => 'IT'],
                ['object_type' => 'diocesan_calendar', 'object_id' => 'ROMA'],
            ],
            $service->resolveScopes('multi-admin')
        );
    }

    public function testFilterByAdminAccessCachesDuplicateResourceChecks(): void
    {
        // The same resource (national_calendar:IT) appears twice in the permissions
        // array of a single request. Without per-call caching, filterByAdminAccess
        // would call check() twice, consuming two queued responses. With caching it
        // calls check() only once per unique "{type}:{id}" key.
        //
        // Proof mechanism: Guzzle MockHandler throws OutOfBoundsException when the
        // response queue is exhausted. Queuing exactly ONE response and observing
        // that the test completes without exception — and that the request is kept —
        // demonstrates the second check was served from the in-memory cache rather
        // than making a second network call.
        $service = $this->serviceWith([
            new GuzzleResponse(200, [], '{"allowed":true}'),
        ]);

        $requests = [
            [
                'id'          => 'A',
                'permissions' => [
                    ['object_type' => 'national_calendar', 'object_id' => 'IT', 'relation' => 'editor'],
                    ['object_type' => 'national_calendar', 'object_id' => 'IT', 'relation' => 'editor'],
                ],
            ],
        ];

        $filtered = $service->filterByAdminAccess($requests, 'cei-admin');

        self::assertSame(['A'], array_column($filtered, 'id'));
    }

    public function testFilterByAdminAccessFailsClosedOnRuntimeException(): void
    {
        // filterByAdminAccess fails closed (like resolveScopes): a 500 response
        // from OpenFGA raises OpenFgaApiException (extends RuntimeException),
        // which is caught per-request so the offending request is excluded
        // rather than surfacing a 500 to the caller.
        $service = $this->serviceWith([
            new GuzzleResponse(500, [], 'boom'),
        ]);

        $requests = [
            ['id' => 'A', 'permissions' => [['object_type' => 'national_calendar', 'object_id' => 'IT', 'relation' => 'editor']]],
        ];

        $filtered = $service->filterByAdminAccess($requests, 'cei-admin');
        $this->assertSame([], $filtered);
    }

    public function testResolveTestScopesGroupsEditorThenAdmin(): void
    {
        // Order: editor for each TEST_OBJECT_TYPES entry, then admin for each —
        // national_calendar_test, diocesan_calendar_test, general_roman_calendar_test,
        // rite_calendar_test. The last of each group returns a rite object so the
        // new probe is observable rather than silently empty.
        $service = $this->serviceWith([
            new GuzzleResponse(200, [], '{"objects":["national_calendar_test:roman/USA"]}'),
            new GuzzleResponse(200, [], '{"objects":[]}'),
            new GuzzleResponse(200, [], '{"objects":[]}'),
            new GuzzleResponse(200, [], '{"objects":["rite_calendar_test:ambrosian"]}'),
            new GuzzleResponse(200, [], '{"objects":["national_calendar_test:roman/USA"]}'),
            new GuzzleResponse(200, [], '{"objects":[]}'),
            new GuzzleResponse(200, [], '{"objects":[]}'),
            new GuzzleResponse(200, [], '{"objects":["rite_calendar_test:ambrosian"]}'),
        ]);

        $scopes = $service->resolveTestScopes('cei-admin');

        self::assertSame(
            [
                ['object_type' => 'national_calendar_test', 'object_id' => 'roman/USA'],
                ['object_type' => 'rite_calendar_test', 'object_id' => 'ambrosian'],
            ],
            $scopes['editor']
        );
        self::assertSame(
            [
                ['object_type' => 'national_calendar_test', 'object_id' => 'roman/USA'],
                ['object_type' => 'rite_calendar_test', 'object_id' => 'ambrosian'],
            ],
            $scopes['admin']
        );
    }

    public function testResolveTestScopesFailsClosedOnError(): void
    {
        // Two relations (editor, admin) probed per TEST_OBJECT_TYPES entry, each
        // independently -> one queued 500 per (relation, type) pair.
        $service = $this->serviceWith(self::serverErrors(count(ResourceAdminService::TEST_OBJECT_TYPES) * 2));

        self::assertSame(['editor' => [], 'admin' => []], $service->resolveTestScopes('x'));
    }

    public function testResolveTestScopesIsolatesFailurePerTypeAndPerRelation(): void
    {
        // rite_calendar_test — the type whose premature addition triggered #793 —
        // is missing from the model, failing under BOTH relations. The other three
        // test types must still resolve, and the two log lines must name the
        // relation that failed so the offending probe is identifiable.
        //
        // Order: editor for each of the 4 TEST_OBJECT_TYPES, then admin for each.
        $logger  = new CollectingLogger();
        $service = $this->serviceWith([
            new GuzzleResponse(200, [], '{"objects":["national_calendar_test:roman/USA"]}'),
            new GuzzleResponse(200, [], '{"objects":[]}'),
            new GuzzleResponse(200, [], '{"objects":["general_roman_calendar_test:temporale"]}'),
            self::typeNotFound('rite_calendar_test'),
            new GuzzleResponse(200, [], '{"objects":["national_calendar_test:roman/USA"]}'),
            new GuzzleResponse(200, [], '{"objects":[]}'),
            new GuzzleResponse(200, [], '{"objects":[]}'),
            self::typeNotFound('rite_calendar_test'),
        ], $logger);

        $scopes = $service->resolveTestScopes('cei-admin');

        self::assertSame(
            [
                ['object_type' => 'national_calendar_test', 'object_id' => 'roman/USA'],
                ['object_type' => 'general_roman_calendar_test', 'object_id' => 'temporale'],
            ],
            $scopes['editor']
        );
        self::assertSame(
            [['object_type' => 'national_calendar_test', 'object_id' => 'roman/USA']],
            $scopes['admin']
        );

        $errors = $logger->recordsAtLevel('error');
        self::assertCount(2, $errors);
        self::assertSame(['rite_calendar_test', 'rite_calendar_test'], array_column(array_column($errors, 'context'), 'object_type'));
        self::assertSame(['editor', 'admin'], array_column(array_column($errors, 'context'), 'relation'));
        self::assertStringContainsString('rite_calendar_test', $errors[0]['message']);
        self::assertStringContainsString('"editor"', $errors[0]['message']);
        self::assertStringContainsString('"admin"', $errors[1]['message']);
    }

    public function testResolveViewerScopesReturnsIdsKeyedByType(): void
    {
        // One list-objects response per VIEWER_OBJECT_TYPES entry, in order:
        // general_roman_calendar, national_calendar_test, diocesan_calendar_test,
        // general_roman_calendar_test, rite_calendar_test
        $service = $this->serviceWith([
            new GuzzleResponse(200, [], '{"objects":["general_roman_calendar:temporale","general_roman_calendar:decrees"]}'),
            new GuzzleResponse(200, [], '{"objects":["national_calendar_test:roman/IT"]}'),
            new GuzzleResponse(200, [], '{"objects":[]}'),
            new GuzzleResponse(200, [], '{"objects":[]}'),
            new GuzzleResponse(200, [], '{"objects":["rite_calendar_test:ambrosian"]}'),
        ]);

        self::assertSame(
            [
                'general_roman_calendar'      => ['temporale', 'decrees'],
                'national_calendar_test'      => ['roman/IT'],
                'diocesan_calendar_test'      => [],
                'general_roman_calendar_test' => [],
                'rite_calendar_test'          => ['ambrosian'],
            ],
            $service->resolveViewerScopes('grc-editor')
        );
    }

    public function testResolveViewerScopesFailsClosedOnOpenFgaError(): void
    {
        // One 500 per VIEWER_OBJECT_TYPES entry: every type fails, so every key is
        // present but empty.
        $service = $this->serviceWith(self::serverErrors(count(ResourceAdminService::VIEWER_OBJECT_TYPES)));

        self::assertSame(
            [
                'general_roman_calendar'      => [],
                'national_calendar_test'      => [],
                'diocesan_calendar_test'      => [],
                'general_roman_calendar_test' => [],
                'rite_calendar_test'          => [],
            ],
            $service->resolveViewerScopes('grc-editor')
        );
    }

    public function testResolveViewerScopesIsolatesFailureAndKeepsEveryKey(): void
    {
        // The dashboard-card outage of issue #793 in miniature: rite_calendar_test
        // (last of VIEWER_OBJECT_TYPES) is unknown to the deployed model. Only its
        // list may be emptied — the four other cards keep their visibility — and
        // its key must still be present, per the documented contract.
        $logger  = new CollectingLogger();
        $service = $this->serviceWith([
            new GuzzleResponse(200, [], '{"objects":["general_roman_calendar:temporale","general_roman_calendar:decrees"]}'),
            new GuzzleResponse(200, [], '{"objects":["national_calendar_test:roman/IT"]}'),
            new GuzzleResponse(200, [], '{"objects":[]}'),
            new GuzzleResponse(200, [], '{"objects":["general_roman_calendar_test:sanctorale"]}'),
            self::typeNotFound('rite_calendar_test'),
        ], $logger);

        $scopes = $service->resolveViewerScopes('grc-editor');

        self::assertSame(
            [
                'general_roman_calendar'      => ['temporale', 'decrees'],
                'national_calendar_test'      => ['roman/IT'],
                'diocesan_calendar_test'      => [],
                'general_roman_calendar_test' => ['sanctorale'],
                'rite_calendar_test'          => [],
            ],
            $scopes
        );
        self::assertArrayHasKey('rite_calendar_test', $scopes);

        $errors = $logger->recordsAtLevel('error');
        self::assertCount(1, $errors);
        self::assertStringContainsString('rite_calendar_test', $errors[0]['message']);
        self::assertSame('rite_calendar_test', $errors[0]['context']['object_type']);
        self::assertSame('viewer', $errors[0]['context']['relation']);
        self::assertSame('user:grc-editor', $errors[0]['context']['user']);
    }

    public function testFilterByAdminAccessLogsTheFailureItSwallows(): void
    {
        // filterByAdminAccess already failed closed per request; it did so silently.
        // The exclusion must now leave a trace, for the same reason #793 was hard to
        // diagnose: a fail-closed path that logs nothing is indistinguishable from a
        // legitimately empty result.
        $logger  = new CollectingLogger();
        $service = $this->serviceWith([new GuzzleResponse(500, [], 'boom')], $logger);

        $requests = [
            ['id' => 'A', 'permissions' => [['object_type' => 'national_calendar', 'object_id' => 'IT', 'relation' => 'editor']]],
        ];

        self::assertSame([], $service->filterByAdminAccess($requests, 'cei-admin'));

        $errors = $logger->recordsAtLevel('error');
        self::assertCount(1, $errors);
        self::assertSame('user:cei-admin', $errors[0]['context']['user']);
    }

    /**
     * A broken logging backend must not defeat the fail-closed guarantee.
     *
     * `LoggerFactory::create()` throws `\RuntimeException` when the logs directory
     * cannot be created, and Monolog's stream handlers throw when the stream cannot
     * be opened. Both are reachable in production, and `\RuntimeException` is the
     * very type these catch blocks are handling — so an unguarded log call inside
     * the recovery path would propagate straight out and turn a degraded lookup
     * into an unhandled 500. That is the same amplification #793 exists to prevent.
     */
    public function testResolveScopesStillFailsClosedWhenTheLoggerItselfThrows(): void
    {
        $service = $this->serviceWith(self::serverErrors(count(ResourceAdminService::ADMIN_OBJECT_TYPES)), new ThrowingLogger());

        self::assertSame([], $service->resolveScopes('cei-admin'));
    }

    public function testResolveTestScopesStillFailsClosedWhenTheLoggerItselfThrows(): void
    {
        // Two probes per type: the editor loop and the admin loop.
        $service = $this->serviceWith(self::serverErrors(count(ResourceAdminService::TEST_OBJECT_TYPES) * 2), new ThrowingLogger());

        self::assertSame(['editor' => [], 'admin' => []], $service->resolveTestScopes('cei-admin'));
    }

    public function testResolveViewerScopesStillReturnsEveryKeyWhenTheLoggerItselfThrows(): void
    {
        $service = $this->serviceWith(self::serverErrors(count(ResourceAdminService::VIEWER_OBJECT_TYPES)), new ThrowingLogger());

        $scopes = $service->resolveViewerScopes('cei-admin');

        self::assertSame(ResourceAdminService::VIEWER_OBJECT_TYPES, array_keys($scopes));
        foreach ($scopes as $type => $objectIds) {
            self::assertSame([], $objectIds, "expected an empty list for {$type}");
        }
    }

    public function testFilterByAdminAccessStillFailsClosedWhenTheLoggerItselfThrows(): void
    {
        $service = $this->serviceWith([new GuzzleResponse(500, [], 'boom')], new ThrowingLogger());

        $requests = [
            ['id' => 'A', 'permissions' => [['object_type' => 'national_calendar', 'object_id' => 'IT', 'relation' => 'editor']]],
        ];

        self::assertSame([], $service->filterByAdminAccess($requests, 'cei-admin'));
    }

    public function testAThrowingLoggerDoesNotMaskARealResult(): void
    {
        // Guard against "fixing" the above by swallowing everything: when OpenFGA
        // succeeds, no logging happens, so a throwing logger is simply never reached
        // and the real scopes must come back intact.
        $responses = array_map(
            static fn(string $type): GuzzleResponse => new GuzzleResponse(200, [], '{"objects":["' . $type . ':IT"]}'),
            ResourceAdminService::ADMIN_OBJECT_TYPES
        );
        $service   = $this->serviceWith(array_values($responses), new ThrowingLogger());

        self::assertCount(count(ResourceAdminService::ADMIN_OBJECT_TYPES), $service->resolveScopes('cei-admin'));
    }

    /**
     * The MockHandler behind the most recent {@see serviceWithClock()} service.
     * Its remaining count is how a test proves a call was never dialed.
     */
    private ?MockHandler $mockQueue = null;

    /**
     * A service whose fan-out clock the test drives by hand.
     *
     * The clock reads a by-reference float rather than the wall clock, and the
     * queued responses are callables that advance it — so "this OpenFGA call took
     * two seconds" is expressed deterministically, with no sleeping.
     *
     * @param array<int, GuzzleResponse|callable> $responses Queued, replayed in order.
     * @param float $now Advanced by the queued callables; passed by reference so the clock closure sees it move.
     */
    private function serviceWithClock(array $responses, float &$now, float $budget, ?LoggerInterface $logger = null): ResourceAdminService
    {
        $mock   = new MockHandler($responses);
        $guzzle = new GuzzleClient(['handler' => HandlerStack::create($mock)]);
        $psr17  = new Psr17Factory();
        $client = new OpenFgaClient(
            apiUrl: 'http://openfga.test',
            storeId: 'test-store',
            modelId: 'test-model',
            httpClient: $guzzle,
            requestFactory: $psr17,
            streamFactory: $psr17,
            apiToken: 'test-token'
        );

        $this->mockQueue = $mock;

        return new ResourceAdminService(
            $client,
            $logger ?? new CollectingLogger(),
            budgetSeconds: $budget,
            clock: static function () use (&$now): float {
                return $now;
            }
        );
    }

    /**
     * A queued response that costs $seconds of fan-out budget.
     */
    private static function costing(float &$now, float $seconds, GuzzleResponse $response): callable
    {
        return static function () use (&$now, $seconds, $response): GuzzleResponse {
            $now += $seconds;
            return $response;
        };
    }

    public function testResolveViewerScopesStopsDialingOnceTheFanOutBudgetIsSpent(): void
    {
        $now = 0.0;
        // Two calls at 2s each exhaust a 3s budget: the first is dialed at t=0, the
        // second at t=2 (still inside), and by t=4 the budget is gone.
        $service = $this->serviceWithClock([
            self::costing($now, 2.0, new GuzzleResponse(200, [], '{"objects":["general_roman_calendar:temporale"]}')),
            self::costing($now, 2.0, new GuzzleResponse(200, [], '{"objects":["national_calendar_test:IT"]}')),
            new GuzzleResponse(200, [], '{"objects":["diocesan_calendar_test:romamo_it"]}'),
            new GuzzleResponse(200, [], '{"objects":["general_roman_calendar_test:temporale"]}'),
            new GuzzleResponse(200, [], '{"objects":["rite_calendar_test:ambrosian"]}'),
        ], $now, 3.0);

        $scopes = $service->resolveViewerScopes('cei-admin');

        // Every key is still present — the dashboard distinguishes "empty" from "missing".
        self::assertSame(ResourceAdminService::VIEWER_OBJECT_TYPES, array_keys($scopes));
        self::assertSame(['temporale'], $scopes['general_roman_calendar']);
        self::assertSame(['IT'], $scopes['national_calendar_test']);
        self::assertSame([], $scopes['diocesan_calendar_test']);
        self::assertSame([], $scopes['general_roman_calendar_test']);
        self::assertSame([], $scopes['rite_calendar_test']);

        // The point of the budget: the remaining three were never dialed at all.
        self::assertCount(3, $this->mockQueue);
    }

    public function testResolveScopesKeepsWhatItResolvedBeforeTheBudgetWasSpent(): void
    {
        $now     = 0.0;
        $service = $this->serviceWithClock([
            self::costing($now, 5.0, new GuzzleResponse(200, [], '{"objects":["national_calendar:IT"]}')),
            new GuzzleResponse(200, [], '{"objects":["diocesan_calendar:romamo_it"]}'),
            new GuzzleResponse(200, [], '{"objects":["wider_region:Europe"]}'),
            new GuzzleResponse(200, [], '{"objects":["general_roman_calendar:temporale"]}'),
        ], $now, 3.0);

        self::assertSame(
            [['object_type' => 'national_calendar', 'object_id' => 'IT']],
            $service->resolveScopes('cei-admin')
        );
        self::assertCount(3, $this->mockQueue);
    }

    public function testResolveTestScopesBudgetSpansBothRelationLoops(): void
    {
        $now = 0.0;
        // One 5s editor probe spends the whole budget, so neither the remaining
        // editor types nor any admin type is dialed.
        $service = $this->serviceWithClock(
            array_merge(
                [self::costing($now, 5.0, new GuzzleResponse(200, [], '{"objects":["national_calendar_test:IT"]}'))],
                array_map(
                    static fn(): GuzzleResponse => new GuzzleResponse(200, [], '{"objects":["national_calendar_test:XX"]}'),
                    range(1, 7)
                )
            ),
            $now,
            3.0
        );

        self::assertSame(
            [
                'editor' => [['object_type' => 'national_calendar_test', 'object_id' => 'IT']],
                'admin'  => [],
            ],
            $service->resolveTestScopes('cei-admin')
        );
        self::assertCount(7, $this->mockQueue);
    }

    public function testFilterByAdminAccessFailsClosedOnceTheBudgetIsSpent(): void
    {
        $now      = 0.0;
        $service  = $this->serviceWithClock([
            self::costing($now, 5.0, new GuzzleResponse(200, [], '{"allowed":true}')),
            new GuzzleResponse(200, [], '{"allowed":true}'),
        ], $now, 3.0);
        $requests = [
            ['id' => 'A', 'permissions' => [['object_type' => 'national_calendar', 'object_id' => 'IT', 'relation' => 'admin']]],
            ['id' => 'B', 'permissions' => [['object_type' => 'national_calendar', 'object_id' => 'US', 'relation' => 'admin']]],
        ];

        // A was checked inside the budget and survives; B is excluded without a call.
        $kept = $service->filterByAdminAccess($requests, 'cei-admin');

        self::assertSame(['A'], array_column($kept, 'id'));
        self::assertCount(1, $this->mockQueue);
    }

    public function testFanOutBudgetExhaustionIsLoggedOnceWithTheSkippedCount(): void
    {
        $now     = 0.0;
        $logger  = new CollectingLogger();
        $service = $this->serviceWithClock([
            self::costing($now, 5.0, new GuzzleResponse(200, [], '{"objects":[]}')),
            new GuzzleResponse(200, [], '{"objects":[]}'),
            new GuzzleResponse(200, [], '{"objects":[]}'),
            new GuzzleResponse(200, [], '{"objects":[]}'),
        ], $now, 3.0, $logger);

        $service->resolveScopes('cei-admin');

        $errors = $logger->recordsAtLevel('error');
        self::assertCount(1, $errors, 'one line per exhausted fan-out, not one per skipped unit');
        self::assertStringContainsString('budget', $errors[0]['message']);
        self::assertSame(3, $errors[0]['context']['skipped'] ?? null);
    }

    public function testTheDefaultBudgetDoesNotTripOnANormalFanOut(): void
    {
        // Guard against a budget so tight (or a clock so wrong) that healthy calls
        // are skipped: with the real clock and the default budget, all four types
        // are dialed and returned.
        $service = $this->serviceWith(array_map(
            static fn(string $type): GuzzleResponse => new GuzzleResponse(200, [], '{"objects":["' . $type . ':IT"]}'),
            ResourceAdminService::ADMIN_OBJECT_TYPES
        ));

        self::assertCount(count(ResourceAdminService::ADMIN_OBJECT_TYPES), $service->resolveScopes('cei-admin'));
    }

    public function testTheBudgetSpansEveryFanOutOfOneServiceInstance(): void
    {
        $now = 0.0;
        // The /auth/dashboard-scopes shape: resolveScopes() then resolveViewerScopes()
        // on ONE service instance. A 5s first lookup must exhaust the budget for both,
        // not hand the second call a fresh 3s window — otherwise the endpoint's worst
        // case is two budgets plus two read timeouts, which is the availability hole
        // #878 exists to close.
        $service = $this->serviceWithClock(
            array_merge(
                [self::costing($now, 5.0, new GuzzleResponse(200, [], '{"objects":["national_calendar:IT"]}'))],
                array_map(
                    static fn(): GuzzleResponse => new GuzzleResponse(200, [], '{"objects":["national_calendar:XX"]}'),
                    range(1, 8)
                )
            ),
            $now,
            3.0
        );

        $adminScopes  = $service->resolveScopes('cei-admin');
        $viewerScopes = $service->resolveViewerScopes('cei-admin');

        self::assertSame([['object_type' => 'national_calendar', 'object_id' => 'IT']], $adminScopes);
        self::assertSame(array_fill_keys(ResourceAdminService::VIEWER_OBJECT_TYPES, []), $viewerScopes);
        self::assertCount(8, $this->mockQueue, 'only the first lookup should ever have been dialed');
    }

    public function testTheBudgetAlsoSpansAResolveThenFilterSequence(): void
    {
        $now = 0.0;
        // The GET /admin/notifications shape: resolveScopes() gates admission and
        // filterByAdminAccess() then scopes the list, both on one instance.
        $service  = $this->serviceWithClock(
            array_merge(
                [self::costing($now, 5.0, new GuzzleResponse(200, [], '{"objects":["national_calendar:IT"]}'))],
                array_map(
                    static fn(): GuzzleResponse => new GuzzleResponse(200, [], '{"allowed":true}'),
                    range(1, 4)
                )
            ),
            $now,
            3.0
        );
        $requests = [
            ['id' => 'A', 'permissions' => [['object_type' => 'national_calendar', 'object_id' => 'IT', 'relation' => 'admin']]],
        ];

        self::assertNotSame([], $service->resolveScopes('cei-admin'));
        self::assertSame([], $service->filterByAdminAccess($requests, 'cei-admin'));
        self::assertCount(4, $this->mockQueue, 'the check sweep should not have been dialed');
    }
}
