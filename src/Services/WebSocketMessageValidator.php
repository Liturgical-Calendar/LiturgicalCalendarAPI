<?php

namespace LiturgicalCalendar\Api\Services;

use LiturgicalCalendar\Api\Enum\LitSchema;
use Swaggest\JsonSchema\Schema;

/**
 * Validates an inbound Health WebSocket message against the published contract.
 *
 * Two rules, deliberately different in reach:
 *
 *  - **Types, enums and required properties** are checked for every message. A message that fails
 *    these is one that would otherwise reach a typed parameter and throw a `TypeError` — an
 *    `\Error`, which Ratchet's `IoServer::handleData` does not catch, so it would kill the process
 *    rather than fail the request. Refusing it is better for a v1 client too.
 *  - **Undeclared properties** are refused only for messages carrying a `requestId`, the same v2
 *    opt-in the terminal `complete` frame is gated on. The shipped client sends `runToken` on every
 *    message and spreads `rite` onto an `executeValidation` that never reads it; a uniform rule
 *    would take the test interface down.
 *
 * The second rule is applied here rather than in the schema because `swaggest/json-schema` v0.12.43
 * implements draft-07, where `additionalProperties` sees only the properties declared in the same
 * schema object — so the natural `if`/`then`/`unevaluatedProperties` spelling needs 2019-09. The
 * allowed *names* still come from the schema; only the gate is here. See issue #826.
 *
 * **Validation is scoped to the one arm a message claims to be**, not run against the whole
 * top-level `oneOf`. `shapeOf()` already has to resolve that arm to check `requestId`-gated
 * properties, and reusing it here does more than save work: `swaggest/json-schema`'s `oneOf`
 * failure text lists every arm's failure, including ones that have nothing to do with the message
 * — a `runTest` with a bad `year` was, before this, told it was missing `executeValidation`'s
 * `sourceFolder`. Scoping the validated schema to the claimed arm means the failure text can only
 * ever be about that arm.
 *
 * **The failure text itself is rewritten before it reaches a client**, by {@see self::humanize()}:
 * `swaggest/json-schema`'s own wording is kept — "Integer expected", "Enum failed" and so on are
 * not reworded, which would put this class back in the business of maintaining a second vocabulary
 * — but the internal `$ref`/`allOf`/`anyOf` trail that names *where* in the schema document the
 * failure occurred is replaced with a dotted path built from the client's own vocabulary: the
 * action it sent, plus the property names on its own message. `runTest.year: Integer expected, …`,
 * not `… at #->allOf[0]->$ref[#/definitions/runTest]->properties:year`.
 */
final class WebSocketMessageValidator
{
    /**
     * The definition names {@see self::warm()} pre-imports. Not the same list `shapeOf()` matches
     * action names against: `validateCalendar` is one action but two shapes, so this has seven
     * entries where that has five action names plus a special case.
     *
     * @var list<string>
     */
    private const KNOWN_SHAPES = [
        'executeValidation',
        'validateCalendarLegacy',
        'validateCalendarTyped',
        'executeUnitTest',
        'runTest',
        'cancelRun',
        'validateSource'
    ];

    /**
     * The protocol versions this server can read — #806 section F.
     *
     * An absent `protocol` is not in this list and is not meant to be: it means the legacy shapes,
     * which predate versioning and are handled exactly as before. This list governs what a client
     * may *declare*, and the highest entry is what {@see \LiturgicalCalendar\Api\Health::onOpen()}
     * advertises in its `hello` frame.
     *
     * Kept here rather than on `Health` because this class is what refuses a message the server
     * cannot read, and because the published schema's `protocolVersion` enum is pinned to this
     * constant by a test — the two are one statement, and they belong next to the other place the
     * schema is the authority.
     *
     * @var list<int>
     */
    public const SUPPORTED_PROTOCOL_VERSIONS = [1];

    /**
     * The whole-document schema, used only when a message's shape cannot be determined at all.
     * Unreachable through {@see \LiturgicalCalendar\Api\Health::onMessage()}, which already refuses
     * an unrecognised action with `UNKNOWN_ACTION` before `validate()` ever runs — kept so a direct
     * caller still gets a sensible answer instead of a fatal on a null shape.
     */
    private static ?Schema $topLevelSchema = null;

    /** @var array<string, Schema> One imported Schema per shape, built once and reused. */
    private static array $shapeSchemas = [];

    /** @var array<string, list<string>>|null */
    private static ?array $propertyNames = null;

    private static ?\stdClass $rawDocument = null;

    private string $schemaPath;

    public function __construct(?string $schemaPath = null)
    {
        $this->schemaPath = $schemaPath ?? LitSchema::WEBSOCKET_MESSAGE->path();
    }

    /**
     * @param list<string> $deferToHandler Property names this must not report, because a handler says
     *        something better about them. See the note on retired properties below.
     * @return string|null null when the message is acceptable, otherwise the reason, phrased for the
     *         client that sent it.
     */
    public function validate(\stdClass $message, array $deferToHandler = []): ?string
    {
        $shape = self::shapeOf($message);

        try {
            if (null !== $shape) {
                $this->schemaFor($shape)->in($message);
            } else {
                $this->schema()->in($message);
            }
        } catch (\Throwable $e) {
            $action = ( null !== $shape && property_exists($message, 'action') && is_string($message->action) )
                ? $message->action
                : 'message';

            return $this->humanize($this->sanitize($e->getMessage()), $action);
        }

        if (null === $shape || false === property_exists($message, 'requestId')) {
            return null;
        }

        $allowed = $this->propertyNamesFor($shape);
        // `$message->action` — the client's own word — not `$shape`: `$shape` is
        // `validateCalendarTyped`/`validateCalendarLegacy`, an internal definition name a client
        // sending `action: "validateCalendar"` has no way to look up in the published schema.
        // Guaranteed present and a string here: `$shape` is non-null only when `shapeOf()` already
        // confirmed both, and `$shape` is checked non-null just above.
        /** @var string $action */
        $action = $message->action;
        foreach (array_keys((array) $message) as $property) {
            if (in_array((string) $property, $deferToHandler, true)) {
                continue;
            }
            if (false === in_array((string) $property, $allowed, true)) {
                return sprintf(
                    '%s is not a property of %s %s message. A message carrying a requestId may only use the properties the contract declares: %s.',
                    (string) $property,
                    self::article($action),
                    $action,
                    implode(', ', $allowed)
                );
            }
        }

        return null;
    }

    /**
     * "a" or "an", for the sentence above. A plain vowel-letter check is enough: every action name
     * this ever sees comes from `WebSocketMessage.json`'s fixed, known set — `executeValidation`,
     * `validateCalendar`, `executeUnitTest`, `runTest`, `cancelRun`, `validateSource` — not free
     * text a client chose the spelling of.
     */
    private static function article(string $word): string
    {
        return in_array($word[0] ?? '', ['a', 'e', 'i', 'o', 'u', 'A', 'E', 'I', 'O', 'U'], true) ? 'an' : 'a';
    }

    /**
     * Why a message's declared protocol cannot be read, or null when it can — #806 section F.
     *
     * Deliberately **not** part of {@see self::validate()}, and the separation is the point. `validate()`
     * answers "is this a well-formed message of a shape I know", which is a question that only makes
     * sense once both ends agree on how to read it. The protocol version is that agreement, so it is
     * settled first, by a caller that has not yet decided what the message even is — see the ordering
     * note in {@see \LiturgicalCalendar\Api\Health::onMessage()}.
     *
     * **The type check is as load-bearing as the value check.** `1.0` decodes to a PHP float that is
     * numerically the supported version; accepting it on that basis would let a float through to a
     * typed parameter, and coercive typing refuses that with a `\TypeError` — an `\Error`, which
     * Ratchet's `IoServer::handleData` does not catch, taking the process down for every connected
     * client. Same hazard `Health::VALIDATABLE_RESPONSE_FORMATS` and `Health::cancelRun()` document,
     * reached through a third door.
     */
    public static function protocolViolation(\stdClass $message): ?string
    {
        if (false === property_exists($message, 'protocol')) {
            return null;
        }

        /** @var mixed $declared */
        $declared = $message->protocol;

        if (is_int($declared) && in_array($declared, self::SUPPORTED_PROTOCOL_VERSIONS, true)) {
            return null;
        }

        return sprintf(
            'This server speaks protocol %s. Send an integer protocol it supports, or omit the property entirely for the legacy contract; the hello frame sent on connect advertises what it accepts.',
            implode(', ', array_map('strval', self::SUPPORTED_PROTOCOL_VERSIONS))
        );
    }

    /**
     * The action names the contract declares, read from the schema so that nothing carries a second
     * list of them.
     *
     * `validateCalendar` has two definitions — a legacy and a typed shape — and appears once here: the
     * wire carries actions, and `validateCalendarTyped` is an internal definition name no client can
     * send. See {@see self::KNOWN_SHAPES}, which is the other list and counts the other way.
     *
     * @return list<string>
     */
    public function supportedActions(): array
    {
        $actions = [];
        foreach ((array) $this->raw()->definitions as $definition) {
            if (false === $definition instanceof \stdClass || false === isset($definition->properties)) {
                continue;
            }

            $properties = $definition->properties;
            if (false === $properties instanceof \stdClass || false === isset($properties->action)) {
                continue;
            }

            $action = $properties->action;
            if ($action instanceof \stdClass && isset($action->const) && is_string($action->const)) {
                $actions[] = $action->const;
            }
        }

        return array_values(array_unique($actions));
    }

    /**
     * Which published shape a message claims to be. Mirrors the dispatch: the action name, plus the
     * type of `calendar` for the one action that carries two shapes.
     */
    public static function shapeOf(\stdClass $message): ?string
    {
        if (false === property_exists($message, 'action') || false === is_string($message->action)) {
            return null;
        }

        if ('validateCalendar' === $message->action) {
            return property_exists($message, 'calendar') && $message->calendar instanceof \stdClass
                ? 'validateCalendarTyped'
                : 'validateCalendarLegacy';
        }

        return in_array($message->action, ['executeValidation', 'executeUnitTest', 'runTest', 'cancelRun', 'validateSource'], true)
            ? $message->action
            : null;
    }

    /**
     * The property names the contract declares for a shape, read from the schema itself so that this
     * class carries no list of its own.
     *
     * @return list<string>
     */
    private function propertyNamesFor(string $shape): array
    {
        if (null === self::$propertyNames) {
            $names = [];
            foreach ((array) $this->raw()->definitions as $name => $definition) {
                if ($definition instanceof \stdClass && isset($definition->properties)) {
                    $names[(string) $name] = array_map('strval', array_keys((array) $definition->properties));
                }
            }
            self::$propertyNames = $names;
        }

        return self::$propertyNames[$shape] ?? [];
    }

    /**
     * The schema document, decoded once and reused by both {@see self::propertyNamesFor()} and
     * {@see self::schemaFor()} — the latter needs `definitions` alongside the arm it wraps, so the
     * two must agree on what "the schema" is.
     */
    private function raw(): \stdClass
    {
        if (null === self::$rawDocument) {
            $decoded = json_decode((string) file_get_contents($this->schemaPath));
            if (false === $decoded instanceof \stdClass || false === isset($decoded->definitions)) {
                throw new \RuntimeException("WebSocket message schema at {$this->schemaPath} has no definitions.");
            }
            self::$rawDocument = $decoded;
        }

        return self::$rawDocument;
    }

    /**
     * A schema scoped to exactly one published shape, imported once per shape and reused after
     * that — {@see self::warm()} is what keeps importing off the per-message path.
     *
     * The arm is wrapped rather than validated as a bare `$ref`: several definitions `$ref` their
     * siblings (`calendarIdentity`, `correlationId`), and a definition lifted out on its own takes
     * its unresolvable pointers with it. Keeping `definitions` alongside preserves them. Same
     * construction {@see \LiturgicalCalendar\Tests\Schemas\WebSocketMessageSchemaTest::testEachMessageMatchesExactlyOneArm()}
     * uses to check the schema itself.
     */
    private function schemaFor(string $shape): Schema
    {
        if (false === isset(self::$shapeSchemas[$shape])) {
            if (false === isset($this->raw()->definitions->{$shape})) {
                throw new \RuntimeException("WebSocket message schema at {$this->schemaPath} has no definition for {$shape}.");
            }

            $armDocument = (object) [
                '$schema'     => 'https://json-schema.org/draft-07/schema#',
                'allOf'       => [(object) ['$ref' => '#/definitions/' . $shape]],
                'definitions' => $this->raw()->definitions
            ];

            $imported = Schema::import($armDocument);
            if (false === $imported instanceof Schema) {
                throw new \RuntimeException("WebSocket message schema shape {$shape} could not be imported.");
            }
            self::$shapeSchemas[$shape] = $imported;
        }

        return self::$shapeSchemas[$shape];
    }

    private function schema(): Schema
    {
        if (null === self::$topLevelSchema) {
            $imported = Schema::import($this->schemaPath);
            if (false === $imported instanceof Schema) {
                throw new \RuntimeException("WebSocket message schema at {$this->schemaPath} could not be imported.");
            }
            self::$topLevelSchema = $imported;
        }

        return self::$topLevelSchema;
    }

    /**
     * Strip the schema file's absolute filesystem path out of a validation-failure message before
     * it reaches the client.
     *
     * `swaggest/json-schema` embeds the path a schema document was imported from inside `$ref[...]`
     * trace segments of its own failure text. An unauthenticated WebSocket client — this validator
     * runs ahead of any auth check, by design — has no business learning where on disk the server
     * keeps its files. Scoping validation to one arm (see the class docblock) already removes most
     * of the irrelevant trace text this could appear in, and {@see self::humanize()} removes the
     * trace text itself; this stays as the backstop that guards the *class* of leak — a schema path
     * reaching a client — rather than relying on either of those removing every route to it.
     */
    private function sanitize(string $message): string
    {
        return str_replace($this->schemaPath, basename($this->schemaPath), $message);
    }

    /**
     * Turn a `swaggest/json-schema` failure into `<path>: <expectation>` — the client's own
     * vocabulary, not the schema's internals.
     *
     * Two rules:
     *
     *  - **The path is prefixed with the action the client actually sent, never the internal
     *    definition name.** A client that sent `action: "validateCalendar"` has no way to look up
     *    `validateCalendarTyped` in the published schema's `oneOf` — that name exists only inside
     *    this codebase and `WebSocketMessage.json`'s `definitions`, never on the wire.
     *  - **Every `properties:<name>` segment of a trail becomes one dotted path component, in
     *    order; `allOf`, `$ref` and `anyOf[n]` segments are dropped.** They describe how the schema
     *    is assembled, not what is wrong with the client's data. A trail with no `properties:`
     *    segment at all — a whole-message failure such as a missing required property — humanizes
     *    to the action alone.
     *
     * A message may carry more than one trail (`oneOf`/`anyOf` failures report one per candidate
     * branch); every trail is stripped, and the *last* one — always the outermost, closing one, by
     * where `swaggest/json-schema` places it — supplies the path. A `data: {...}` clause whose value
     * is a JSON *object* is also dropped: it is the library restating a chunk of the message the
     * property path already names, most visibly for a root-level failure where it restates the
     * entire message. A scalar `data: "widerregion"` clause is the offending value itself, and is
     * kept — it is exactly what a client needs to see.
     */
    private function humanize(string $message, string $action): string
    {
        // `data: {…}` — braces may nest (e.g. the value is itself an object with its own object
        // properties) — but never `data: [...]` or `data: "…"`/`data: 42`, which are the useful,
        // short cases and are left alone.
        //
        // `preg_replace()` returns `null` on failure — most concretely here, PCRE's backtrack/
        // recursion limit on the balanced-brace recursion for a large echoed object (measured:
        // fine around 5.6 KB, broken around 14 KB) — and `(string) null` used to silently become
        // `''`, discarding the whole reason rather than merely failing to trim it. Falling back to
        // the untransformed input keeps the reason on the wire; it is still `sanitize()`d, since
        // that already ran on `$message` before this method was called.
        $balancedObject = '(?P<bal>\{(?:[^{}]|(?P>bal))*\})';
        $withoutEchoes  = preg_replace('/,?\s*data:\s*' . $balancedObject . '/', '', $message) ?? $message;

        $trailPattern = '/ at (#(?:->\S+)*)/';
        preg_match_all($trailPattern, $withoutEchoes, $trails);
        /** @var list<non-falsy-string> $trailList */
        $trailList = $trails[1];
        $path      = [] === $trailList ? $action : $this->pathFromTrail((string) end($trailList), $action);

        $withoutTrails = preg_replace($trailPattern, '', $withoutEchoes) ?? $withoutEchoes;

        return $path . ': ' . trim($withoutTrails);
    }

    /**
     * The dotted client-facing path a single trail names. See {@see self::humanize()}.
     */
    private function pathFromTrail(string $trail, string $action): string
    {
        $properties = [];
        foreach (explode('->', $trail) as $segment) {
            if (str_starts_with($segment, 'properties:')) {
                $properties[] = substr($segment, strlen('properties:'));
            }
        }

        return [] === $properties ? $action : $action . '.' . implode('.', $properties);
    }

    /**
     * Drop the memoized schema. Tests that point the validator at a different file need this; nothing
     * in production does.
     */
    public static function reset(): void
    {
        self::$topLevelSchema = null;
        self::$shapeSchemas   = [];
        self::$propertyNames  = null;
        self::$rawDocument    = null;
    }

    /**
     * Import every published shape now rather than on the first message that claims it. A server
     * that cannot validate is misconfigured, and should fail where an operator sees it rather than
     * answering every message with an internal error.
     */
    public function warm(): void
    {
        foreach (self::KNOWN_SHAPES as $shape) {
            $this->schemaFor($shape);
        }
        $this->propertyNamesFor('validateSource');
    }
}
