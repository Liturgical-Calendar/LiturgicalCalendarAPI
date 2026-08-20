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
 */
final class WebSocketMessageValidator
{
    private static ?Schema $schema = null;

    /** @var array<string, list<string>>|null */
    private static ?array $propertyNames = null;

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
        try {
            $this->schema()->in($message);
        } catch (\Throwable $e) {
            return $e->getMessage();
        }

        if (false === property_exists($message, 'requestId')) {
            return null;
        }

        $shape = self::shapeOf($message);
        if (null === $shape) {
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
            $raw = json_decode((string) file_get_contents($this->schemaPath));
            if (false === $raw instanceof \stdClass || false === isset($raw->definitions)) {
                throw new \RuntimeException("WebSocket message schema at {$this->schemaPath} has no definitions.");
            }
            $names = [];
            foreach ((array) $raw->definitions as $name => $definition) {
                if ($definition instanceof \stdClass && isset($definition->properties)) {
                    $names[(string) $name] = array_map('strval', array_keys((array) $definition->properties));
                }
            }
            self::$propertyNames = $names;
        }

        return self::$propertyNames[$shape] ?? [];
    }

    private function schema(): Schema
    {
        if (null === self::$schema) {
            $imported = Schema::import($this->schemaPath);
            if (false === $imported instanceof Schema) {
                throw new \RuntimeException("WebSocket message schema at {$this->schemaPath} could not be imported.");
            }
            self::$schema = $imported;
        }

        return self::$schema;
    }

    /**
     * Drop the memoized schema. Tests that point the validator at a different file need this; nothing
     * in production does.
     */
    public static function reset(): void
    {
        self::$schema        = null;
        self::$propertyNames = null;
    }

    /**
     * Import the schema now rather than on the first message. A server that cannot validate is
     * misconfigured, and should fail where an operator sees it rather than answering every message
     * with an internal error.
     */
    public function warm(): void
    {
        $this->schema();
        $this->propertyNamesFor('validateSource');
    }
}
