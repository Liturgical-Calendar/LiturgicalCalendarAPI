<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests;

use LiturgicalCalendar\Api\Enum\ProtocolErrorCode;
use LiturgicalCalendar\Api\Health;
use LiturgicalCalendar\Api\Router;
use LiturgicalCalendar\Tests\Support\HealthQueueIsolationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ratchet\ConnectionInterface;

/**
 * What `Health` does with a message it cannot act on.
 *
 * Every test here drives `onMessage()` with a real JSON string. The private validator is never
 * invoked directly: #825's lesson was that an emitter can be correct and tested while nothing routes
 * to it, and a test that calls the right function directly passes against exactly that bug.
 */
#[CoversClass(Health::class)]
final class HealthProtocolValidationTest extends TestCase
{
    use HealthQueueIsolationTrait;

    public static function setUpBeforeClass(): void
    {
        Router::getApiPaths();
    }

    private static function createStubConnection(int $resourceId = 1)
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
     * Send one raw message and return the frames it produced.
     *
     * @return list<\stdClass>
     */
    private function frames(string $raw): array
    {
        $conn = self::createStubConnection();
        ob_start();
        $this->newHealth()->onMessage($conn, $raw);
        ob_end_clean();

        return array_map(static fn (string $f): \stdClass => json_decode($f), $conn->sent);
    }

    public function testARejectionIsATypedProtocolErrorRatherThanAnEchobot(): void
    {
        $frames = $this->frames((string) json_encode(['action' => 'validateSource', 'target' => ['id' => 'nation:roman:ZZ']]));

        self::assertCount(1, $frames);
        self::assertSame('protocolError', $frames[0]->type, 'rejections are typed now, not echoes');
        self::assertSame(ProtocolErrorCode::UNKNOWN_TARGET_ID->value, $frames[0]->errorCode);
        self::assertSame('Unknown validation target: nation:roman:ZZ', $frames[0]->text, 'the prose is unchanged; only the envelope is typed');
    }

    /**
     * The reason a message was refused must reach the client, not only the server log.
     *
     * `errorCode` classifies the failure but does not describe it: `INVALID_JSON` cannot say
     * "Control character error", which is the part that tells whoever is debugging the client what
     * to look at. An earlier revision of this branch sent the reason to stdout and echoed only the
     * raw message back, which is why this is asserted rather than assumed.
     *
     * Asserted against the literal string `'Syntax error'`, not a fresh `json_last_error_msg()` call
     * made here: `frames()` json_decodes the server's (valid JSON) *response* after `onMessage()`
     * returns, and that decode succeeds, so by the time this method runs, the global JSON error state
     * has already been reset to `JSON_ERROR_NONE` — a call here would read "No error", not the
     * decode failure being asserted against. `'Syntax error'` is what PHP 8.4 actually produces for
     * this malformed input, confirmed by running this test.
     */
    public function testTheReasonForARefusalReachesTheClientAndNotOnlyTheLog(): void
    {
        $frames = $this->frames('{ this is not json');

        self::assertSame('protocolError', $frames[0]->type);
        self::assertSame(ProtocolErrorCode::INVALID_JSON->value, $frames[0]->errorCode);
        self::assertStringContainsString(
            'Syntax error',
            (string) $frames[0]->text,
            'the specific decode failure has no errorCode equivalent, so it has to be in the text'
        );
    }

    /**
     * The crash vectors. Each of these terminated the WebSocket process before this work: Ratchet's
     * `IoServer::handleData` catches `\Exception`, and `TypeError`, `ValueError` and `Error` are not
     * `\Exception`. The assertion is therefore two-part — a typed refusal came back, *and* nothing
     * escaped — because a test that only checked the frame would pass on a process that had already
     * died in a real server.
     *
     * @return array<string, array{array<string, mixed>}>
     */
    public static function crashVectorProvider(): array
    {
        return [
            'year as a non-numeric string'                => [['action' => 'validateCalendar', 'calendar' => 'IT', 'year' => 'not-a-year', 'category' => 'nationalcalendar', 'responsetype' => 'JSON']],
            'category as an array'                        => [['action' => 'validateCalendar', 'calendar' => 'IT', 'year' => 2024, 'category' => [], 'responsetype' => 'JSON']],
            'unknown response format'                     => [['action' => 'validateCalendar', 'calendar' => 'IT', 'year' => 2024, 'category' => 'nationalcalendar', 'responsetype' => 'NOT_A_FORMAT']],
            'test as an object'                           => [['action' => 'executeUnitTest', 'test' => ['a' => 1], 'calendar' => 'IT', 'year' => 2024, 'category' => 'nationalcalendar']],
            'executeValidation category as an object'     => [['action' => 'executeValidation', 'category' => ['k' => 'v'], 'validate' => 'x', 'sourceFile' => 'jsondata/x.json']],
            // A number too large for PHP's int is still an integer *by value* under JSON Schema, so
            // the schema correctly accepts it — json_decode() then hands PHP a float, because the
            // value is outside PHP_INT_MAX, and these two arms used to unpack it straight into a
            // typed `int $year` parameter. That is a TypeError, an \Error Ratchet does not catch. See
            // Health::readYear(), now shared by all four arms that read a `year` for exactly this.
            'validateCalendar year too large for PHP int' => [['action' => 'validateCalendar', 'calendar' => 'IT', 'year' => 1.0e30, 'category' => 'nationalcalendar', 'responsetype' => 'JSON']],
            'executeUnitTest year too large for PHP int'  => [['action' => 'executeUnitTest', 'test' => 'X', 'calendar' => 'IT', 'year' => 1.0e30, 'category' => 'nationalcalendar']],
        ];
    }

    /**
     * @param array<string, mixed> $message
     */
    #[DataProvider('crashVectorProvider')]
    public function testAMessageThatUsedToKillTheProcessIsRefused(array $message): void
    {
        $frames = $this->frames((string) json_encode($message));

        self::assertCount(1, $frames, 'a refused message must not also start work');
        self::assertSame('protocolError', $frames[0]->type);
        self::assertSame(ProtocolErrorCode::INVALID_MESSAGE->value, $frames[0]->errorCode);
    }

    /**
     * The gate, both directions, in one place: the same message is accepted without a `requestId`
     * and refused with one. Asserting only the refusal would pass on a build that refused everything.
     */
    public function testAnUndeclaredPropertyIsRefusedOnlyWhenTheMessageOptedIn(): void
    {
        $lenient = [
            'action'     => 'executeValidation',
            'category'   => 'sourceDataCheck',
            'validate'   => 'proprium-de-tempore',
            'sourceFile' => 'jsondata/sourcedata/rite/roman/missals/propriumdetempore/propriumdetempore.json',
            'notAThing'  => 'whatever'
        ];

        $acceptedFrames = $this->frames((string) json_encode($lenient));
        self::assertNotEmpty($acceptedFrames, 'a v1 message with a stray property must still be served');
        self::assertNotSame('protocolError', $acceptedFrames[0]->type, "a v1 message was refused: {$acceptedFrames[0]->text}");

        $refusedFrames = $this->frames((string) json_encode($lenient + ['requestId' => 'req-1']));
        self::assertSame('protocolError', $refusedFrames[0]->type, 'a message carrying a requestId opted into strictness');
        self::assertSame(ProtocolErrorCode::INVALID_MESSAGE->value, $refusedFrames[0]->errorCode);
        self::assertStringContainsString('notAThing', (string) $refusedFrames[0]->text, 'the refusal must name the offending property');
    }

    /**
     * `rite` on an `executeValidation` is the concrete case: the shipped client spreads it onto every
     * source-data check and the server never reads it. It is declared, so it survives even under the
     * strict gate — which is what stops a future tidy-up from removing it from the schema.
     */
    public function testTheSpreadRiteSurvivesEvenUnderTheStrictGate(): void
    {
        $frames = $this->frames((string) json_encode([
            'action'     => 'executeValidation',
            'rite'       => 'roman',
            'category'   => 'sourceDataCheck',
            'validate'   => 'proprium-de-tempore',
            'sourceFile' => 'jsondata/sourcedata/rite/roman/missals/propriumdetempore/propriumdetempore.json',
            'requestId'  => 'req-2'
        ]));

        self::assertNotSame('protocolError', $frames[0]->type, "rite was refused: {$frames[0]->text}");
    }

    /**
     * Every action the schema declares has a dispatch arm and vice versa. Adding an action without a
     * schema entry would otherwise fall through to `unknown_action` in production rather than in CI.
     */
    public function testEverySchemaShapeHasADispatchArmAndEveryArmHasAShape(): void
    {
        $raw     = json_decode((string) file_get_contents(\LiturgicalCalendar\Api\Enum\LitSchema::WEBSOCKET_MESSAGE->path()));
        $actions = [];
        foreach ((array) $raw->definitions as $definition) {
            if ($definition instanceof \stdClass && isset($definition->properties->action->const)) {
                $actions[(string) $definition->properties->action->const] = true;
            }
        }

        // Scoped to onMessage()'s own body, not the whole file: Health.php has several other
        // switch statements over plain-letter string literals ('diocesan', 'national', 'XML',
        // 'universalcalendar', …) that are not action names at all, and a file-wide scan would
        // false-positive on every one of them.
        $source         = (string) file_get_contents(__DIR__ . '/../src/Health.php');
        $onMessageStart = strpos($source, 'public function onMessage(');
        $onMessageEnd   = strpos($source, 'public function onClose(', (int) $onMessageStart);
        self::assertNotFalse($onMessageStart, 'onMessage() moved or was renamed');
        self::assertNotFalse($onMessageEnd, 'onClose() moved or was renamed; used as the end-of-method marker');
        $dispatchSource = substr($source, $onMessageStart, $onMessageEnd - $onMessageStart);
        preg_match_all("/case '([a-zA-Z]+)':/", $dispatchSource, $matches);
        $dispatched = array_unique($matches[1]);

        self::assertEqualsCanonicalizing(
            array_keys($actions),
            array_values(array_intersect($dispatched, array_keys($actions))),
            'schema shapes and dispatch arms have drifted apart'
        );
        foreach ($dispatched as $arm) {
            self::assertArrayHasKey($arm, $actions, "the dispatch handles {$arm} but the schema does not declare it");
        }
    }

    /**
     * A half-migrated message hears the sentence that helps it, not the generic one.
     *
     * `rejectRetiredProperties()` runs inside the handler, so it is reached only if the validator
     * declines to report the property first. Without the deferral this comes back as
     * INVALID_MESSAGE and the client is told the property is unknown rather than what replaced it.
     */
    public function testARetiredPropertyIsDiagnosedByTheHandlerNotTheSchema(): void
    {
        $frames = $this->frames((string) json_encode([
            'action'    => 'validateSource',
            'target'    => ['id' => 'temporale:roman'],
            'category'  => 'sourceDataCheck',
            'requestId' => 'req-4'
        ]));

        self::assertSame('protocolError', $frames[0]->type);
        self::assertSame(ProtocolErrorCode::RETIRED_PROPERTY->value, $frames[0]->errorCode, 'the generic schema complaint won the race');
        self::assertSame('category is not part of a validateSource message: target.id replaces it.', $frames[0]->text);
    }

    /**
     * An unknown action and a malformed known action are different failures, and a client acts on
     * them differently: the first means "you are speaking a protocol I do not have", the second
     * means "fix this message". Schema validation alone would report both as INVALID_MESSAGE,
     * because an unrecognised action matches no arm of the top-level oneOf.
     */
    public function testAnUnknownActionIsDistinguishedFromAMalformedKnownOne(): void
    {
        $unknown = $this->frames((string) json_encode(['action' => 'danceTheFandango']));
        self::assertSame(ProtocolErrorCode::UNKNOWN_ACTION->value, $unknown[0]->errorCode);

        $malformed = $this->frames((string) json_encode(['action' => 'cancelRun', 'runToken' => ['an', 'array']]));
        self::assertSame(ProtocolErrorCode::INVALID_MESSAGE->value, $malformed[0]->errorCode);
    }

    /**
     * A rejection must not tell an unauthenticated client where the server keeps its files.
     *
     * The validator quotes the schema library's own message, and that message embeds the absolute
     * path of the schema it was validating against. Asserting on the project root rather than on
     * one known path keeps the check honest if the wording changes.
     */
    public function testARejectionNeverLeaksTheServerFilesystemPath(): void
    {
        foreach (
            [
                ['action' => 'runTest', 'test' => 'X', 'calendar' => ['kind' => 'national', 'id' => 'IT', 'rite' => 'roman'], 'year' => '2026'],
                ['action' => 'validateCalendar', 'calendar' => 'IT', 'year' => 'nope', 'category' => 'nationalcalendar', 'responsetype' => 'JSON'],
            ] as $message
        ) {
            $frames = $this->frames((string) json_encode($message));
            self::assertStringNotContainsString(
                rtrim(Router::$apiFilePath, '/'),
                (string) $frames[0]->text,
                'a rejection leaked the server filesystem path'
            );
        }
    }

    /**
     * The two rejections judged directly against live output while this wording was designed,
     * pinned as literals. This is client-facing wording now: `runTest.year: Integer expected, "2026"
     * received` reads as a message a client can act on, not a schema-library stack trace. A
     * `swaggest/json-schema` upgrade, a schema restructuring, or a change to
     * {@see \LiturgicalCalendar\Api\Services\WebSocketMessageValidator::humanize()} changing either
     * of these has to be a deliberate, reviewed choice, not a side effect nobody noticed.
     */
    public function testTheHumanizedRejectionTextIsExactlyThis(): void
    {
        $runTestFrames = $this->frames((string) json_encode([
            'action'   => 'runTest',
            'test'     => 'X',
            'calendar' => ['kind' => 'national', 'id' => 'IT', 'rite' => 'roman'],
            'year'     => '2026'
        ]));
        self::assertSame('runTest.year: Integer expected, "2026" received', (string) $runTestFrames[0]->text);

        $validateCalendarFrames = $this->frames((string) json_encode([
            'action'         => 'validateCalendar',
            'calendar'       => ['kind' => 'widerregion', 'id' => 'Europe', 'rite' => 'roman'],
            'year'           => 2026,
            'responseFormat' => 'JSON'
        ]));
        self::assertSame(
            'validateCalendar.calendar.kind: Enum failed, enum: ["general","national","diocesan","rite"], data: "widerregion"',
            (string) $validateCalendarFrames[0]->text
        );
    }

    /**
     * Every message `crashVectorProvider()` supplies, plus messages that trip the *other* rejection
     * path this class has — the `requestId`-gated undeclared-property check — which the crash
     * vectors alone never reach, because none of them carries a `requestId`. Both paths build their
     * own sentence, and both have to honour the same rule.
     *
     * The typed `validateCalendar` row is not incidental: `validateCalendarTyped` is the one
     * internal definition name that does not equal its action's spelling anywhere else, which is
     * exactly why it is the row that caught I2 and none of the others would have.
     *
     * @return array<string, array{array<string, mixed>}>
     */
    public static function vocabularyLeakProvider(): array
    {
        return array_merge(self::crashVectorProvider(), [
            'gated: typed validateCalendar with a stray property' => [
                [
                    'action'         => 'validateCalendar',
                    'calendar'       => ['kind' => 'national', 'id' => 'IT', 'rite' => 'roman'],
                    'year'           => 2026,
                    'responseFormat' => 'JSON',
                    'zzz'            => 'stray',
                    'requestId'      => 'req-vocab-1'
                ]
            ],
            'gated: executeValidation with a stray property'      => [
                [
                    'action'     => 'executeValidation',
                    'category'   => 'sourceDataCheck',
                    'validate'   => 'proprium-de-tempore',
                    'sourceFile' => 'jsondata/sourcedata/rite/roman/missals/propriumdetempore/propriumdetempore.json',
                    'notAThing'  => 'whatever',
                    'requestId'  => 'req-vocab-2'
                ]
            ],
            'gated: runTest with a stray property'                => [
                [
                    'action'    => 'runTest',
                    'test'      => 'X',
                    'calendar'  => ['kind' => 'national', 'id' => 'IT', 'rite' => 'roman'],
                    'year'      => 2026,
                    'zzz'       => 'stray',
                    'requestId' => 'req-vocab-3'
                ]
            ],
        ]);
    }

    /**
     * The schema's own internal vocabulary — which `allOf`/`anyOf` branch matched, `$ref` pointers
     * into `definitions`, and every definition name that is not also a valid action — must never
     * reach a client. It describes how the schema *document* is assembled, not what is wrong with
     * the *message*, and a client cannot look any of it up: `WebSocketMessage.json` publishes shapes
     * by action name, never by these internal traversal or definition terms.
     *
     * The forbidden definition names are read from the schema itself, minus whichever of them are
     * also a `properties.action.const` somewhere in it — those are legitimate, client-facing action
     * words (`executeValidation` names both a definition and the action a client sends) and must
     * stay allowed. What is left — `validateCalendarLegacy`, `validateCalendarTyped`,
     * `calendarIdentity`, `correlationId` at the time of writing — exists only inside this codebase
     * and the schema document, never on the wire, and a new definition added later is covered
     * automatically rather than requiring this list to be kept in sync by hand.
     *
     * @param array<string, mixed> $message
     */
    #[DataProvider('vocabularyLeakProvider')]
    public function testARejectionNeverLeaksSchemaInternalVocabulary(array $message): void
    {
        $frames = $this->frames((string) json_encode($message));
        $text   = (string) $frames[0]->text;

        foreach (['allOf', '$ref', 'definitions'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $text, "the rejection leaked schema internal vocabulary: {$forbidden}");
        }

        $raw             = json_decode((string) file_get_contents(\LiturgicalCalendar\Api\Enum\LitSchema::WEBSOCKET_MESSAGE->path()));
        $definitionNames = array_keys((array) $raw->definitions);
        $actionNames     = [];
        foreach ((array) $raw->definitions as $definition) {
            if (isset($definition->properties->action->const)) {
                $actionNames[] = (string) $definition->properties->action->const;
            }
        }
        $internalOnlyNames = array_diff($definitionNames, $actionNames);

        foreach ($internalOnlyNames as $internalName) {
            self::assertStringNotContainsString(
                (string) $internalName,
                $text,
                "the rejection leaked the internal definition name {$internalName}"
            );
        }
    }

    /**
     * `humanize()`'s balanced-brace recursion over an echoed `data: {...}` clause can hit PCRE's
     * backtrack/recursion limit on a large enough message — measured fine around 5.6 KB, broken
     * around 14 KB — at which point `preg_replace()` returns `null`. A guard that fell back to `''`
     * would pass every other test here while silently discarding the entire reason on this one input,
     * which is exactly the failure Task 2's `testTheReasonForARefusalReachesTheClientAndNotOnlyTheLog`
     * exists to catch for JSON decode errors; this is the same guarantee for this later stage.
     */
    public function testALargeMessageStillCarriesAReasonWhenTheTransformCannotRun(): void
    {
        $frames = $this->frames((string) json_encode([
            'action'     => 'executeValidation',
            'category'   => ['k' => 'v'],
            'validate'   => 'x',
            'sourceFile' => 'jsondata/x.json',
            'padding'    => str_repeat('x', 20000)
        ]));

        self::assertSame('protocolError', $frames[0]->type);
        self::assertSame(ProtocolErrorCode::INVALID_MESSAGE->value, $frames[0]->errorCode);
        self::assertNotSame('', (string) $frames[0]->text, 'the reason must not be discarded when the transform cannot run');
        self::assertStringContainsString(
            'Required property missing: sourceFolder',
            (string) $frames[0]->text,
            'the untransformed reason must still be there'
        );
    }

    /**
     * The backstop, isolated from the gate that normally stands in front of it.
     *
     * Pointing the validator at a permissive schema lets a crash vector through to the dispatch, so
     * this asserts what happens when the schema is *wrong* — which is the only circumstance in which
     * the backstop matters, and the reason it is not redundant with Task 3's tests. Without it the
     * process dies; with it, one request fails and the daemon keeps serving.
     *
     * Two things differ from a naive reading of the schema-only tests above:
     *
     *  - The vector is `executeValidation` with `category` as an object, not `validateCalendar` with
     *    a non-numeric `year`. `year` is now read through `readYear()`, which does its own `is_int()`
     *    check on both legacy arms, so that message would be refused cleanly even with the schema
     *    bypassed — proving nothing about this catch block. `executeValidation()` does
     *    `(string) $validation->category` before any of its own checks, and casting a `stdClass` to
     *    string throws `\Error` (`Object of class stdClass could not be converted to string`), which
     *    is not a `\TypeError` from a typed parameter but is still an `\Error` — not `\Exception` —
     *    so it reaches this catch exactly the way the documented vectors do.
     *  - The permissive schema carries the same nine `definitions` keys as the real
     *    `WebSocketMessage.json` (`correlationId`, `calendarIdentity`, `executeValidation`,
     *    `validateCalendarLegacy`, `validateCalendarTyped`, `executeUnitTest`, `runTest`, `cancelRun`,
     *    `validateSource`), each one permissive. `WebSocketMessageValidator::raw()` requires a
     *    `definitions` key to exist at all, and `schemaFor()` requires the claimed shape's own entry
     *    to exist within it — a bare `{"type": "object"}` document satisfies neither, so it would
     *    fail *inside* the validator rather than letting the message through.
     */
    public function testAHandlerThatThrowsIsContainedRatherThanKillingTheProcess(): void
    {
        $shapes     = ['correlationId', 'calendarIdentity', 'executeValidation', 'validateCalendarLegacy', 'validateCalendarTyped', 'executeUnitTest', 'runTest', 'cancelRun', 'validateSource'];
        $permissive = sys_get_temp_dir() . '/permissive-ws-schema-' . getmypid() . '.json';
        file_put_contents($permissive, (string) json_encode([
            '$schema'     => 'https://json-schema.org/draft-07/schema#',
            'type'        => 'object',
            'definitions' => array_fill_keys($shapes, (object) ['type' => 'object'])
        ]));

        $health    = $this->newHealth();
        $validator = new \ReflectionProperty(Health::class, 'messageValidator');
        $validator->setValue($health, new \LiturgicalCalendar\Api\Services\WebSocketMessageValidator($permissive));
        \LiturgicalCalendar\Api\Services\WebSocketMessageValidator::reset();

        $conn = self::createStubConnection();
        ob_start();
        try {
            $health->onMessage($conn, (string) json_encode([
                'action'     => 'executeValidation',
                'category'   => ['k' => 'v'],
                'validate'   => 'x',
                'sourceFile' => 'jsondata/x.json'
            ]));
        } finally {
            ob_end_clean();
            @unlink($permissive);
            \LiturgicalCalendar\Api\Services\WebSocketMessageValidator::reset();
        }

        $frames = array_map(static fn (string $f): \stdClass => json_decode($f), $conn->sent);
        self::assertNotEmpty($frames, 'the connection was told nothing at all');
        $last = $frames[count($frames) - 1];
        self::assertSame('protocolError', $last->type);
        self::assertSame(ProtocolErrorCode::INTERNAL_ERROR->value, $last->errorCode);
    }
}
