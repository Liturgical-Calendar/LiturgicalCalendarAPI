<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services\Locale;

/**
 * The outcome of one readiness probe against one locale.
 *
 * `missing` is deliberately a list of concrete identifiers — file paths, event
 * keys — rather than a count. An operator deciding whether to promote a locale
 * needs to know *what* is absent, and a count cannot be acted on.
 */
final readonly class LocaleReadinessCheck implements \JsonSerializable
{
    /**
     * @param list<string> $missing
     */
    public function __construct(
        public string $name,
        public bool $passed,
        public string $summary,
        public array $missing = [],
        public bool $advisory = false
    ) {
    }

    /**
     * @param list<string>            $missing
     * @param \Closure(int): string   $whenFailed Receives the count, so the caller
     *                                            can agree number with its own noun.
     */
    public static function of(
        string $name,
        array $missing,
        string $whenPassed,
        \Closure $whenFailed,
        bool $advisory = false
    ): self {
        $passed = $missing === [];

        return new self(
            $name,
            $passed,
            $passed ? $whenPassed : $whenFailed(count($missing)),
            $missing,
            $advisory
        );
    }

    /**
     * "1 event" / "3 events" — used by callers building failure summaries.
     */
    public static function plural(int $count, string $singular, string $plural): string
    {
        return $count . ' ' . ( $count === 1 ? $singular : $plural );
    }

    /**
     * @return array{name: string, passed: bool, advisory: bool, summary: string, missing: list<string>}
     */
    public function jsonSerialize(): array
    {
        return [
            'name'     => $this->name,
            'passed'   => $this->passed,
            'advisory' => $this->advisory,
            'summary'  => $this->summary,
            'missing'  => $this->missing,
        ];
    }
}
