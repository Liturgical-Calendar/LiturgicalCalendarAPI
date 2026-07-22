<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Models\Calendar\Temporale;

use LiturgicalCalendar\Api\DateTime;
use LiturgicalCalendar\Api\Enum\LitSeason;
use LiturgicalCalendar\Api\Http\Exception\ServiceUnavailableException;
use LiturgicalCalendar\Api\Models\Calendar\LiturgicalEvent;
use LiturgicalCalendar\Api\Utilities;

/**
 * Ambrosian-rite temporale engine (2024 edition, major mobile-anchor block).
 *
 * Mirrors RomanTemporale's scope: it dates the contiguous major mobile block of
 * the Ambrosian liturgical year (Advent through Christ the King) drawn from the
 * Ambrosian Proprium de Tempore, and adds the events to the shared calendar.
 * The after-Epiphany / after-Pentecost Sunday-numbering and weekday fill are
 * handler-level (like Roman Ordinary-Time numbering) and are NOT computed here.
 *
 * Re-runnable per civil year; holds no per-request state between calls.
 */
final class AmbrosianTemporale implements TemporaleEngine
{
    public function buildTemporale(TemporaleContext $ctx): void
    {
        $this->calculateAdvent($ctx);
        $this->calculateChristmasEpiphany($ctx);
        $this->calculateLent($ctx);
        $this->calculateEasterCycle($ctx);
        $this->calculateAfterPentecostAnchors($ctx);
    }

    /**
     * Creates a LiturgicalEvent from the Ambrosian Proprium de Tempore by key and
     * adds it to the calendar. (Duplicated from RomanTemporale; shared-helper
     * de-dup tracked as existing debt.)
     */
    private function createPropriumDeTemporeLiturgicalEventByKey(?string $key, TemporaleContext $ctx): LiturgicalEvent
    {
        if (null === $key || false === $ctx->propriumDeTempore->offsetExists($key)) {
            throw new ServiceUnavailableException("createPropriumDeTemporeLiturgicalEventByKey requires a key from the Proprium de Tempore, instead got $key");
        }
        $event = LiturgicalEvent::fromObject($ctx->propriumDeTempore[$key]);
        $ctx->cal->addLiturgicalEvent($key, $event);
        $this->stampSeason($event);
        return $event;
    }

    /**
     * Stamp the Ambrosian liturgical season onto an event from its key. Called on
     * every event the engine creates, because the Roman
     * `LiturgicalEventCollection::setSeasonsAndHolyDaysOfObligation()` cannot run
     * for the Ambrosian rite (it requires an AshWednesday event and knows only the
     * six Roman seasons).
     */
    private function stampSeason(LiturgicalEvent $event): void
    {
        // `ChristKing` is a shared temporale key: in the Ambrosian rite it is the
        // last Sunday after the Dedication (AFTER_PENTECOST), but in the Roman rite
        // it is the last Sunday of Ordinary Time. `LitSeason::forEventKey()` is
        // rite-agnostic and is also consumed by the Roman /temporale endpoint, so we
        // must NOT globally reclassify `ChristKing` there — override it locally here.
        $event->liturgical_season = 'ChristKing' === $event->event_key
            ? LitSeason::AFTER_PENTECOST
            : LitSeason::forEventKey($event->event_key);
    }

    /**
     * True if the given date falls on a Sunday. Used by
     * `calculateAfterPentecostAnchors()` to guard the 3rd-Sunday-of-October
     * computation for the Dedication of the Duomo di Milano.
     */
    private static function dateIsSunday(DateTime $dt): bool
    {
        return (int) $dt->format('N') === 7;
    }

    /**
     * Advent I anchor: the Sunday strictly after Nov 11 (St Martin).
     * Single source of truth so calculateAdvent() and the Christ-the-King
     * anchor cannot drift when the deferred Nov-11-on-Sunday edge is implemented.
     */
    private function adventOne(int $year): DateTime
    {
        return DateTime::fromFormat('11-11-' . $year)->modify('next Sunday');
    }

    /**
     * Advent — 6 Sundays. Advent I = the Sunday strictly after Nov 11 (St Martin);
     * Advent II–VI follow at weekly intervals. Advent VI = "dell'Incarnazione /
     * della Divina Maternità". The Nov-11-on-Sunday edge is deferred (spec §4).
     */
    private function calculateAdvent(TemporaleContext $ctx): void
    {
        $year    = $ctx->params->Year;
        $advent1 = $this->adventOne($year);
        for ($i = 1; $i <= 6; $i++) {
            $key  = 'Advent' . $i;
            $date = ( clone $advent1 )->add(new \DateInterval('P' . ( ( $i - 1 ) * 7 ) . 'D'));
            $ctx->propriumDeTempore[$key]->setDate($date);
            $this->createPropriumDeTemporeLiturgicalEventByKey($key, $ctx);
        }
    }

    /**
     * Christmas (Dec 25), Circoncisione (Jan 1, octave day), Epiphany (fixed Jan 6),
     * and Baptism of the Lord (Sunday after Jan 6). Ambrosian Epiphany has no
     * date-option logic; the Dec 26–28 vigil shift is deferred (spec §4).
     */
    private function calculateChristmasEpiphany(TemporaleContext $ctx): void
    {
        $year = $ctx->params->Year;

        $ctx->propriumDeTempore['Christmas']->setDate(DateTime::fromFormat('25-12-' . $year));
        $this->createPropriumDeTemporeLiturgicalEventByKey('Christmas', $ctx);

        $ctx->propriumDeTempore['Circoncisione']->setDate(DateTime::fromFormat('1-1-' . $year));
        $this->createPropriumDeTemporeLiturgicalEventByKey('Circoncisione', $ctx);

        $epiphany = DateTime::fromFormat('6-1-' . $year);
        $ctx->propriumDeTempore['Epiphany']->setDate($epiphany);
        $this->createPropriumDeTemporeLiturgicalEventByKey('Epiphany', $ctx);

        $ctx->propriumDeTempore['BaptismLord']->setDate(( clone $epiphany )->modify('next Sunday'));
        $this->createPropriumDeTemporeLiturgicalEventByKey('BaptismLord', $ctx);
    }

    /**
     * Lent — begins on a Sunday (Lent I = Easter − 42d); NO Ash Wednesday. Ashes
     * are imposed the Monday after Lent I. Lent II–V are the named Sundays
     * (Samaritana / Abramo / Cieco / Lazzaro, naming from data). Palm Sunday =
     * Easter − 7d; Sabato "in traditione symboli" = the Saturday before it
     * (Easter − 8d). Aliturgical Lenten Fridays are weekday-fill (deferred).
     */
    private function calculateLent(TemporaleContext $ctx): void
    {
        $year = $ctx->params->Year;

        $lent1 = Utilities::calcGregEaster($year)->sub(new \DateInterval('P' . ( 6 * 7 ) . 'D'));
        for ($i = 1; $i <= 5; $i++) {
            $key  = 'Lent' . $i;
            $date = ( clone $lent1 )->add(new \DateInterval('P' . ( ( $i - 1 ) * 7 ) . 'D'));
            $ctx->propriumDeTempore[$key]->setDate($date);
            $this->createPropriumDeTemporeLiturgicalEventByKey($key, $ctx);
        }

        $ctx->propriumDeTempore['AshesMonday']->setDate(( clone $lent1 )->add(new \DateInterval('P1D')));
        $this->createPropriumDeTemporeLiturgicalEventByKey('AshesMonday', $ctx);

        $ctx->propriumDeTempore['PalmSun']->setDate(Utilities::calcGregEaster($year)->sub(new \DateInterval('P7D')));
        $this->createPropriumDeTemporeLiturgicalEventByKey('PalmSun', $ctx);

        $ctx->propriumDeTempore['SabatoTradSymb']->setDate(Utilities::calcGregEaster($year)->sub(new \DateInterval('P8D')));
        $this->createPropriumDeTemporeLiturgicalEventByKey('SabatoTradSymb', $ctx);
    }

    /**
     * Easter cycle: Triduum (Easter − 3..−1), Easter, the octave "in albis"
     * (Easter + 1..6), Easter Sundays II–VII (Easter + 1..6 weeks), Ascension
     * (Easter + 39d, Thursday) and Pentecost (Easter + 49d). The Ambrosian rite
     * keeps Ascension on Thursday; the Ascension request param has no effect.
     */
    private function calculateEasterCycle(TemporaleContext $ctx): void
    {
        $year = $ctx->params->Year;

        $ctx->propriumDeTempore['HolyThurs']->setDate(Utilities::calcGregEaster($year)->sub(new \DateInterval('P3D')));
        $ctx->propriumDeTempore['GoodFri']->setDate(Utilities::calcGregEaster($year)->sub(new \DateInterval('P2D')));
        $ctx->propriumDeTempore['EasterVigil']->setDate(Utilities::calcGregEaster($year)->sub(new \DateInterval('P1D')));
        $ctx->propriumDeTempore['Easter']->setDate(Utilities::calcGregEaster($year));
        $this->createPropriumDeTemporeLiturgicalEventByKey('HolyThurs', $ctx);
        $this->createPropriumDeTemporeLiturgicalEventByKey('GoodFri', $ctx);
        $this->createPropriumDeTemporeLiturgicalEventByKey('EasterVigil', $ctx);
        $this->createPropriumDeTemporeLiturgicalEventByKey('Easter', $ctx);

        $octaveKeys = ['MonOctaveEaster', 'TueOctaveEaster', 'WedOctaveEaster', 'ThuOctaveEaster', 'FriOctaveEaster', 'SatOctaveEaster'];
        foreach ($octaveKeys as $offset => $key) {
            $ctx->propriumDeTempore[$key]->setDate(Utilities::calcGregEaster($year)->add(new \DateInterval('P' . ( $offset + 1 ) . 'D')));
            $this->createPropriumDeTemporeLiturgicalEventByKey($key, $ctx);
        }

        for ($i = 2; $i <= 7; $i++) {
            $key = 'Easter' . $i;
            $ctx->propriumDeTempore[$key]->setDate(Utilities::calcGregEaster($year)->add(new \DateInterval('P' . ( 7 * ( $i - 1 ) ) . 'D')));
            $this->createPropriumDeTemporeLiturgicalEventByKey($key, $ctx);
        }

        $ctx->propriumDeTempore['Ascension']->setDate(Utilities::calcGregEaster($year)->add(new \DateInterval('P39D')));
        $this->createPropriumDeTemporeLiturgicalEventByKey('Ascension', $ctx);

        $ctx->propriumDeTempore['Pentecost']->setDate(Utilities::calcGregEaster($year)->add(new \DateInterval('P49D')));
        $this->createPropriumDeTemporeLiturgicalEventByKey('Pentecost', $ctx);
    }

    /**
     * After-Pentecost anchors: Dedication of the Duomo di Milano (3rd Sunday of
     * October) and Christ the King (the Sunday before Advent I = the last Sunday
     * after the Dedication). The after-Pentecost Sunday-numbering / sub-block fill
     * is handler-level and deferred (see plan Global Constraints).
     */
    private function calculateAfterPentecostAnchors(TemporaleContext $ctx): void
    {
        $year = $ctx->params->Year;

        // 3rd Sunday of October = 1st Sunday on/after Oct 1, plus 2 weeks.
        $firstSundayOct = DateTime::fromFormat('1-10-' . $year);
        if (false === self::dateIsSunday($firstSundayOct)) {
            $firstSundayOct = $firstSundayOct->modify('next Sunday');
        }
        $dedication = ( clone $firstSundayOct )->add(new \DateInterval('P14D'));
        $ctx->propriumDeTempore['DedicationDuomo']->setDate($dedication);
        $this->createPropriumDeTemporeLiturgicalEventByKey('DedicationDuomo', $ctx);

        // Christ the King = the Sunday before Advent I (Advent I = Sunday after Nov 11).
        $advent1    = $this->adventOne($year);
        $christKing = ( clone $advent1 )->sub(new \DateInterval('P7D'));
        $ctx->propriumDeTempore['ChristKing']->setDate($christKing);
        $this->createPropriumDeTemporeLiturgicalEventByKey('ChristKing', $ctx);
    }
}
