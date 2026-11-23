<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Models\Lectionary;

use LiturgicalCalendar\Api\Enum\Rite;

/**
 * The bishops' conference sites that publish the readings of the day (#1074), read from
 * `lectionary_sources.json`, and the resolver from an event to its page on one of them.
 *
 * An event of a calendar whose nation a source follows, dated within the range the source
 * publishes, links to:
 * - the page its `pages` entry names, when there is one for the event (and, for a value keyed
 *   by cycle, for the event's weekday or Sunday cycle);
 * - otherwise the source's day page for its date, except for a vigil Mass: a vigil is dated the
 *   evening before, so that day's page would show the previous day's readings.
 * - for a vigil Mass, the range, the cycle and the page's date are those of its celebration's day.
 * - a commemoration always links the day page: it is celebrated with the weekday's readings.
 *
 * @phpstan-type SourceArray array{day_page:string, linked_page:string, first_date:string, last_date:string, calendars:list<string>, pages:array<string,string|array<string,string>>}
 */
final class LectionarySources
{
    /** Sunday cycle by liturgical year modulo 3 (year 2023 is A). */
    private const SUNDAY_CYCLES = ['C', 'A', 'B'];

    /**
     * @param array<string, SourceArray> $sources
     */
    private function __construct(private readonly array $sources)
    {
    }

    /**
     * @throws \RuntimeException when the file cannot be read or decoded
     */
    public static function fromFile(string $path): self
    {
        $raw = is_readable($path) ? file_get_contents($path) : false;
        if ($raw === false) {
            throw new \RuntimeException("Cannot read the lectionary sources file {$path}");
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException("Cannot decode the lectionary sources file {$path}: {$e->getMessage()}", 0, $e);
        }
        if (!is_array($decoded)) {
            throw new \RuntimeException("The lectionary sources file {$path} is not a JSON object");
        }
        /** @var array<string, SourceArray> $decoded */
        return self::fromArray($decoded);
    }

    /**
     * @param array<string, SourceArray> $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data);
    }

    /**
     * The URL of the event's readings page, or null when no source covers it.
     *
     * A commemoration (a memorial impeded by a privileged weekday, such as St John of God in Lent)
     * is celebrated with the weekday's readings, so it links the day page, never its own page.
     */
    public function urlFor(string $nation, Rite $rite, string $eventKey, \DateTimeInterface $date, bool $isVigilMass, bool $isCommemoration = false): ?string
    {
        if ($rite !== Rite::ROMAN) {
            return null;
        }
        $source = array_find($this->sources, static fn (array $s): bool => in_array($nation, $s['calendars'], true));
        if ($source === null) {
            return null;
        }
        // A vigil Mass is dated the evening before, but its readings belong to its celebration's
        // day: that day decides the range, the cycle and the page.
        $day = $isVigilMass ? \DateTimeImmutable::createFromInterface($date)->modify('+1 day') : $date;
        $ymd = $day->format('Y-m-d');
        if ($ymd < $source['first_date'] || $ymd > $source['last_date']) {
            return null;
        }

        $page = $isCommemoration ? null : $this->pageFor($source['pages'][$eventKey] ?? null, $day);
        if ($page === null) {
            return $isVigilMass ? null : self::fillDate($source['day_page'], $date);
        }
        $page = str_replace('{day}', $day->format('mdy'), $page);
        return self::fillDate(str_replace('{page}', $page, $source['linked_page']), $day);
    }

    /**
     * What is wrong with a sources document beyond what its schema can say, one line per problem
     * (an empty list when nothing is): a national calendar followed by more than one source, which
     * would make the link depend on the order of the file; and a range whose first date is after
     * its last.
     *
     * It takes the decoded file as it comes, before any shape is assumed, so that the scripts that
     * write the file can check it; the schema checks the shape.
     *
     * @param array<mixed> $data
     * @return list<string>
     */
    public static function problems(array $data): array
    {
        $problems = [];
        $byNation = [];
        foreach ($data as $key => $source) {
            if (!is_array($source)) {
                continue;
            }
            $first = $source['first_date'] ?? null;
            $last  = $source['last_date'] ?? null;
            if (is_string($first) && is_string($last) && $first > $last) {
                $problems[] = "{$key}: first_date {$first} is after last_date {$last}";
            }
            foreach (is_array($source['calendars'] ?? null) ? $source['calendars'] : [] as $nation) {
                if (is_string($nation)) {
                    $byNation[$nation][] = (string) $key;
                }
            }
        }
        foreach ($byNation as $nation => $keys) {
            if (count($keys) > 1) {
                $problems[] = "{$nation} is followed by more than one source: " . implode(', ', $keys);
            }
        }
        return $problems;
    }

    /**
     * Fill the date placeholders {YYYY}, {YY}, {MM} and {DD} of a URL pattern.
     */
    public static function fillDate(string $pattern, \DateTimeInterface $date): string
    {
        return strtr($pattern, [
            '{YYYY}' => $date->format('Y'),
            '{YY}'   => $date->format('y'),
            '{MM}'   => $date->format('m'),
            '{DD}'   => $date->format('d'),
        ]);
    }

    /**
     * The liturgical year a date belongs to: its civil year, or the next one from the First Sunday
     * of Advent on (the fourth Sunday before Christmas).
     */
    public static function liturgicalYearOf(\DateTimeInterface $date): int
    {
        $year      = (int) $date->format('Y');
        $christmas = new \DateTimeImmutable("{$year}-12-25", $date->getTimezone());
        $back      = (int) $christmas->format('N') % 7;
        $advent1   = $christmas->modify('-' . ( ( $back === 0 ? 7 : $back ) + 21 ) . ' days');
        return $date->format('Y-m-d') >= $advent1->format('Y-m-d') ? $year + 1 : $year;
    }

    /**
     * @param string|array<string,string>|null $value
     */
    private function pageFor(string|array|null $value, \DateTimeInterface $date): ?string
    {
        if ($value === null || is_string($value)) {
            return $value;
        }
        $year = self::liturgicalYearOf($date);
        return $value[$year % 2 === 1 ? 'I' : 'II'] ?? $value[self::SUNDAY_CYCLES[$year % 3]] ?? null;
    }
}
