<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Models\Calendar\Temporale;

use LiturgicalCalendar\Api\DateTime;
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
        // Tasks 7-8 append: calculateEasterCycle, calculateAfterPentecostAnchors.
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
        return $event;
    }

    /**
     * Not yet called within this task's scope (Advent's Sunday anchors are
     * produced directly via `modify('next Sunday')`); Tasks 5–8 reuse this for
     * the Christmas/Epiphany "Second Sunday" style lookups, mirroring RomanTemporale.
     */
    // @phpstan-ignore method.unused
    private static function dateIsSunday(DateTime $dt): bool
    {
        return (int) $dt->format('N') === 7;
    }

    /**
     * Advent — 6 Sundays. Advent I = the Sunday strictly after Nov 11 (St Martin);
     * Advent II–VI follow at weekly intervals. Advent VI = "dell'Incarnazione /
     * della Divina Maternità". The Nov-11-on-Sunday edge is deferred (spec §4).
     */
    private function calculateAdvent(TemporaleContext $ctx): void
    {
        $year    = $ctx->params->Year;
        $advent1 = DateTime::fromFormat('11-11-' . $year)->modify('next Sunday');
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
}
