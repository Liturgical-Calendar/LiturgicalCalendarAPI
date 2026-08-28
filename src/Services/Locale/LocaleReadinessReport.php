<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services\Locale;

/**
 * Whether a locale has everything an officially supported locale must have.
 *
 * `ready` is the gate: an operator may only promote a locale for which this is
 * true, because promotion turns missing data from a quiet degradation into a
 * hard failure (see {@see \LiturgicalCalendar\Api\Services\SupportedLocales}).
 */
final readonly class LocaleReadinessReport implements \JsonSerializable
{
    /**
     * @param list<LocaleReadinessCheck> $checks
     */
    public function __construct(
        public string $locale,
        public bool $official,
        public array $checks
    ) {
    }

    /**
     * True when every probe passed, i.e. the locale may be promoted safely.
     */
    public function ready(): bool
    {
        foreach ($this->checks as $check) {
            if (false === $check->passed) {
                return false;
            }
        }

        return true;
    }

    /**
     * The probes that failed, for callers that only want the problems.
     *
     * @return list<LocaleReadinessCheck>
     */
    public function failures(): array
    {
        return array_values(array_filter($this->checks, static fn (LocaleReadinessCheck $c): bool => !$c->passed));
    }

    /**
     * A one-line human summary, used by Health and by CI failure messages.
     */
    public function describe(): string
    {
        if ($this->ready()) {
            return sprintf('%s: ready (%d checks passed)', $this->locale, count($this->checks));
        }

        $names = array_map(static fn (LocaleReadinessCheck $c): string => $c->name, $this->failures());

        return sprintf('%s: NOT ready — failing %s', $this->locale, implode(', ', $names));
    }

    /**
     * @return array{locale: string, official: bool, ready: bool, checks: list<LocaleReadinessCheck>}
     */
    public function jsonSerialize(): array
    {
        return [
            'locale'   => $this->locale,
            'official' => $this->official,
            'ready'    => $this->ready(),
            'checks'   => $this->checks,
        ];
    }
}
