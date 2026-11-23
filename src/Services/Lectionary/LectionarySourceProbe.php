<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services\Lectionary;

use LiturgicalCalendar\Api\Models\Lectionary\LectionarySources;

/**
 * Probes a bishops' conference readings site for the edges of the range it publishes (#1074),
 * so that `lectionary_sources.json` can be kept current through a reviewed PR.
 *
 * - **A missing day** is a 404 on the USCCB, and a 200 page without a "Prima Lettura" section on
 *   the CEI (which answers every date).
 * - **A refusal** (403 or 429), or any other unexpected status, stops the probe at once: the
 *   dates found so far are discarded and nothing is retried.
 * - **Forward:** from the day after `last_date`, while days exist (up to `$maxDays`).
 * - **Backward:** `first_date` and the day before it. The first date moves only if the site has
 *   dropped `first_date` (searching forward) or added the day before it (searching back).
 */
final class LectionarySourceProbe
{
    /** @var list<string> */
    private array $log = [];

    /**
     * @param \Closure(string): array{status:int, body:string} $fetch
     * @param int $pauseMs pause between requests
     */
    public function __construct(private readonly \Closure $fetch, private readonly int $pauseMs = 3000)
    {
    }

    /**
     * @param array{day_page:string, first_date:string, last_date:string} $source
     */
    public function probe(string $sourceKey, array $source, int $maxDays = 62): ProbeResult
    {
        $this->log = [];
        try {
            $last    = new \DateTimeImmutable($source['last_date']);
            $edge    = $this->walk($sourceKey, $source['day_page'], $last, 1, $maxDays, true);
            $newLast = $edge->format('Y-m-d') === $source['last_date'] ? null : $edge->format('Y-m-d');

            $first    = new \DateTimeImmutable($source['first_date']);
            $newFirst = null;
            if (false === $this->exists($sourceKey, $source['day_page'], $first)) {
                $found    = $this->walk($sourceKey, $source['day_page'], $first, 1, $maxDays, false);
                $newFirst = $found->format('Y-m-d') === $source['first_date'] ? null : $found->format('Y-m-d');
            } elseif ($this->exists($sourceKey, $source['day_page'], $first->modify('-1 day'))) {
                $edge     = $this->walk($sourceKey, $source['day_page'], $first->modify('-1 day'), -1, $maxDays, true);
                $newFirst = $edge->format('Y-m-d');
            }
        } catch (ProbeRefused $refused) {
            return new ProbeResult(null, null, $refused->getMessage(), $this->log);
        }
        return new ProbeResult($newFirst, $newLast, null, $this->log);
    }

    /**
     * With $whileExists, step from $from ($direction +1 forward, -1 back) while the next day exists and return the last existing
     * day (which is $from when the very next day is missing). Without it, step from $from until a
     * day exists and return that day ($from when none is found within $maxDays).
     */
    private function walk(string $sourceKey, string $dayPage, \DateTimeImmutable $from, int $direction, int $maxDays, bool $whileExists): \DateTimeImmutable
    {
        $edge = $from;
        for ($i = 1; $i <= $maxDays; $i++) {
            $day    = $from->modify(sprintf('%+d days', $direction * $i));
            $exists = $this->exists($sourceKey, $dayPage, $day);
            if ($whileExists) {
                if (!$exists) {
                    return $edge;
                }
                $edge = $day;
            } elseif ($exists) {
                return $day;
            }
        }
        return $edge;
    }

    /**
     * @throws ProbeRefused
     */
    private function exists(string $sourceKey, string $dayPage, \DateTimeImmutable $day): bool
    {
        if ($this->log !== [] && $this->pauseMs > 0) {
            usleep($this->pauseMs * 1000);
        }
        $url         = LectionarySources::fillDate($dayPage, $day);
        $answer      = ( $this->fetch )($url);
        $status      = $answer['status'];
        $this->log[] = "{$url} {$status}";
        if ($status === 404) {
            return false;
        }
        if ($status !== 200) {
            throw new ProbeRefused("{$url} answered {$status}");
        }
        return $sourceKey !== 'cei' || str_contains($answer['body'], 'Prima Lettura');
    }
}
