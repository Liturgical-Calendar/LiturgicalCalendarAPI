<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services\Lectionary;

use LiturgicalCalendar\Api\Models\Lectionary\LectionarySources;
use LiturgicalCalendar\Api\Services\Lectionary\LectionarySourceProbe;
use PHPUnit\Framework\TestCase;

/**
 * The probe that keeps a source's published range current (#1074), against a fake site.
 */
final class LectionarySourceProbeTest extends TestCase
{
    private const USCCB = [
        'day_page'    => 'https://bible.usccb.org/bible/readings/{MM}{DD}{YY}.cfm',
        'linked_page' => 'https://bible.usccb.org/bible/readings/{page}',
        'first_date'  => '2013-01-01',
        'last_date'   => '2027-12-01',
        'calendars'   => ['US'],
        'pages'       => [],
    ];

    private const CEI = [
        'day_page'    => 'https://www.chiesacattolica.it/liturgia-del-giorno/?data-liturgia={YYYY}{MM}{DD}',
        'linked_page' => 'https://www.chiesacattolica.it/liturgia-del-giorno/?data-liturgia={YYYY}{MM}{DD}#{page}',
        'first_date'  => '2016-01-01',
        'last_date'   => '2027-02-03',
        'calendars'   => ['IT'],
        'pages'       => [],
    ];

    /** @var list<string> */
    private array $requested = [];

    /**
     * A fake site publishing readings for every day in [$from, $to].
     *
     * @param array{day_page:string} $source
     * @param callable(string): array{status:int, body:string} $missing the answer for a day outside the range
     * @return \Closure(string): array{status:int, body:string}
     */
    private function site(array $source, string $from, string $to, callable $missing, ?int $refuseAt = null): \Closure
    {
        $published = [];
        for ($d = new \DateTimeImmutable('2012-06-01'); $d <= new \DateTimeImmutable('2028-06-01'); $d = $d->modify('+1 day')) {
            $published[LectionarySources::fillDate($source['day_page'], $d)] = $d->format('Y-m-d');
        }
        return function (string $url) use ($published, $from, $to, $missing, $refuseAt): array {
            $this->requested[] = $url;
            if ($refuseAt !== null && count($this->requested) === $refuseAt) {
                return ['status' => 403, 'body' => 'Checking connection'];
            }
            $day = $published[$url] ?? null;
            self::assertNotNull($day, "unexpected URL {$url}");
            return $day >= $from && $day <= $to ? ['status' => 200, 'body' => '<h2>Prima Lettura</h2> Reading 1'] : $missing($url);
        };
    }

    private static function notFound(): \Closure
    {
        return static fn (string $url): array => ['status' => 404, 'body' => ''];
    }

    private static function emptyPage(): \Closure
    {
        return static fn (string $url): array => ['status' => 200, 'body' => '<html>nessuna liturgia</html>'];
    }

    public function testForwardProbeAdvancesLastDate(): void
    {
        $probe  = new LectionarySourceProbe($this->site(self::USCCB, '2013-01-01', '2027-12-05', self::notFound()), 0);
        $result = $probe->probe('usccb', self::USCCB);
        self::assertSame('2027-12-05', $result->lastDate);
        self::assertNull($result->firstDate);
        self::assertNull($result->stoppedAt);
    }

    public function testStopsAtFirstRefusal(): void
    {
        $probe  = new LectionarySourceProbe($this->site(self::USCCB, '2013-01-01', '2027-12-05', self::notFound(), 2), 0);
        $result = $probe->probe('usccb', self::USCCB);
        self::assertNotNull($result->stoppedAt);
        self::assertStringContainsString('403', $result->stoppedAt);
        self::assertNull($result->lastDate);
        self::assertNull($result->firstDate);
        self::assertCount(2, $this->requested);
    }

    public function testCeiEmptyPageIsMissing(): void
    {
        $probe  = new LectionarySourceProbe($this->site(self::CEI, '2016-01-01', '2027-02-03', self::emptyPage()), 0);
        $result = $probe->probe('cei', self::CEI);
        self::assertNull($result->lastDate);
        self::assertNull($result->firstDate);
        self::assertNull($result->stoppedAt);
    }

    public function testFirstDateMovesBack(): void
    {
        $probe  = new LectionarySourceProbe($this->site(self::CEI, '2015-12-30', '2027-02-03', self::emptyPage()), 0);
        $result = $probe->probe('cei', self::CEI);
        self::assertSame('2015-12-30', $result->firstDate);
        self::assertNull($result->lastDate);
    }

    public function testFirstDateMovesForwardWhenTheSiteDropsOldPages(): void
    {
        $probe  = new LectionarySourceProbe($this->site(self::USCCB, '2013-01-05', '2027-12-01', self::notFound()), 0);
        $result = $probe->probe('usccb', self::USCCB);
        self::assertSame('2013-01-05', $result->firstDate);
    }
}
