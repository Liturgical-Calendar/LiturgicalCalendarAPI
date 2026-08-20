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
            return $this->sanitize($e->getMessage());
        }

        if (null === $shape || false === property_exists($message, 'requestId')) {
            return null;
        }

        $allowed = $this->propertyNamesFor($shape);
        foreach (array_keys((array) $message) as $property) {
            if (in_array((string) $property, $deferToHandler, true)) {
                continue;
            }
            if (false === in_array((string) $property, $allowed, true)) {
                return sprintf(
                    '%s is not a property of a %s message. A message carrying a requestId may only use the properties the contract declares: %s.',
                    (string) $property,
                    $shape,
                    implode(', ', $allowed)
                );
            }
        }

        return null;
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
     * of the irrelevant trace text this could appear in; this removes what is left.
     */
    private function sanitize(string $message): string
    {
        return str_replace($this->schemaPath, basename($this->schemaPath), $message);
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
