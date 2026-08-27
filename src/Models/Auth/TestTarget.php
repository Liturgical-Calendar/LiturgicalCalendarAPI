<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Models\Auth;

/**
 * What a message wants validated, in the terms a permission check would need.
 *
 * Threaded through {@see \LiturgicalCalendar\Api\Services\TestRunPolicy::mayRun()} from day one even
 * though the coarse policy ignores it. Adding the parameter later — at the moment a fine-grained
 * policy arrives — would mean moving plumbing through every call site in the same change that alters
 * who may do what, which is the worst possible time to be doing it.
 */
final readonly class TestTarget
{
    public function __construct(
        public ?string $kind,
        public ?string $rite,
        public ?string $calendarId
    ) {
    }

    /**
     * Read the target off a message, or null when the message names none.
     *
     * Takes `mixed` for the same reason {@see \LiturgicalCalendar\Api\Health::declaredCorrelationId()}
     * does: the caller holds a raw `json_decode()` result, and narrowing it here would narrow it for
     * every guard downstream.
     */
    public static function fromMessage(mixed $message): ?self
    {
        if (false === $message instanceof \stdClass || false === property_exists($message, 'calendar')) {
            return null;
        }

        $calendar = $message->calendar;
        if (false === $calendar instanceof \stdClass) {
            return null;
        }

        $read = static function (string $property) use ($calendar): ?string {
            if (false === property_exists($calendar, $property)) {
                return null;
            }

            $value = $calendar->{$property};

            return is_string($value) ? $value : null;
        };

        $kind = $read('kind');
        $rite = $read('rite');
        // The id property is named for the kind it belongs to; the first one present wins.
        $calendarId = $read('calendar') ?? $read('nation') ?? $read('diocese');

        if (null === $kind && null === $rite && null === $calendarId) {
            return null;
        }

        return new self($kind, $rite, $calendarId);
    }
}
