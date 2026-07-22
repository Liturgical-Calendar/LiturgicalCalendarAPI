<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Models\Calendar\Temporale;

use LiturgicalCalendar\Api\DateTime;
use LiturgicalCalendar\Api\Enum\LitColor;
use LiturgicalCalendar\Api\Enum\LitEventType;
use LiturgicalCalendar\Api\Enum\LitGrade;
use LiturgicalCalendar\Api\Enum\LitLocale;
use LiturgicalCalendar\Api\Enum\LitSeason;
use LiturgicalCalendar\Api\Http\Exception\ServiceUnavailableException;
use LiturgicalCalendar\Api\LatinUtils;
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
        $this->calculateAfterEpiphanySundays($ctx);
        $this->calculateAfterEpiphanyWeekdays($ctx);
        $this->calculateAfterPentecostSundays($ctx);
        $this->calculateAdventWeekdays($ctx);
        $this->calculateChristmasWeekdays($ctx);
        $this->calculateLentWeekdays($ctx);
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
     * computation for the Dedication of the Duomo di Milano, and by
     * `martyrdomAnchor()` to guard the Aug 29 -> Sep 1 postponement of the
     * Martyrdom of St John the Baptist when Aug 29 falls on a Sunday.
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

    /**
     * Martyrdom of St John the Baptist (n. 42a): Aug 29, postponed to Sep 1 when
     * Aug 29 falls on a Sunday.
     */
    private function martyrdomAnchor(int $year): DateTime
    {
        $aug29 = DateTime::fromFormat('29-8-' . $year);
        return self::dateIsSunday($aug29) ? DateTime::fromFormat('1-9-' . $year) : $aug29;
    }

    /**
     * After-Pentecost Sundays (n. 42), in three sub-blocks with per-block numbering:
     *   (a) dopo Pentecoste     — 1st Sunday after Pentecost … Sat before the 1st Sunday after the Martyrdom
     *   (b) dopo il Martirio    — that Sunday … Sat before the Dedication (3rd Sunday of October)
     *   (c) dopo la Dedicazione — 1st Sunday after the Dedication … Sat before Advent I (ends at Christ the King)
     * DedicationDuomo and ChristKing are anchors already placed; Sundays already in
     * the calendar are skipped, so those two are not re-emitted as numbered Sundays.
     */
    private function calculateAfterPentecostSundays(TemporaleContext $ctx): void
    {
        $year         = $ctx->params->Year;
        $pentecost    = Utilities::calcGregEaster($year)->add(new \DateInterval('P49D'));
        $martyrdomSun = ( clone $this->martyrdomAnchor($year) )->modify('next Sunday'); // 1st Sunday after the Martyrdom
        $dedication   = $ctx->cal->getLiturgicalEvent('DedicationDuomo')->date
            ?? throw new ServiceUnavailableException('DedicationDuomo anchor must be placed before after-Pentecost Sundays');
        $advent1      = $this->adventOne($year);

        // (a) dopo Pentecoste
        $this->numberSundayBlock(
            $ctx,
            'AfterPentecost',
            ( clone $pentecost )->modify('next Sunday'),
            $martyrdomSun,
            fn (int $ordinal): string => $this->afterPentecostSundayName($ordinal, $ctx)
        );
        // (b) dopo il Martirio
        $this->numberSundayBlock(
            $ctx,
            'AfterPentecostMartyrdom',
            clone $martyrdomSun,
            $dedication,
            fn (int $ordinal): string => $this->afterMartyrdomSundayName($ordinal, $ctx)
        );
        // (c) dopo la Dedicazione
        $this->numberSundayBlock(
            $ctx,
            'AfterPentecostDedication',
            ( clone $dedication )->modify('next Sunday'),
            $advent1,
            fn (int $ordinal): string => $this->afterDedicationSundayName($ordinal, $ctx)
        );
    }

    /**
     * Emit consecutive numbered Sundays [$firstSunday, $endExclusive) under $keyStem,
     * numbering from 1, skipping Sundays already occupied by an anchor. $nameBuilder
     * is the per-block localized name builder (see below), invoked with the ordinal.
     *
     * @param \Closure(int): string $nameBuilder
     */
    private function numberSundayBlock(TemporaleContext $ctx, string $keyStem, DateTime $firstSunday, DateTime $endExclusive, \Closure $nameBuilder): void
    {
        $ordinal = 1;
        $sunday  = clone $firstSunday;
        while ($sunday < $endExclusive) {
            if (false === $ctx->cal->inCalendar($sunday)) {
                $this->synthesizeSunday($ctx, $keyStem . $ordinal, clone $sunday, $nameBuilder($ordinal));
            }
            $ordinal++;
            $sunday = ( clone $sunday )->modify('next Sunday');
        }
    }

    /**
     * Create a synthesized numbered Sunday (not drawn from the Proprium de Tempore
     * data file): a dominical FEAST_LORD in green, season-stamped from its key.
     * Used for the after-Epiphany and after-Pentecost Sunday blocks whose exact
     * names/numbering are validated against a published ordo in a later plan.
     */
    private function synthesizeSunday(TemporaleContext $ctx, string $key, DateTime $date, string $name): LiturgicalEvent
    {
        $event               = new LiturgicalEvent($name, $date, LitColor::GREEN, LitEventType::MOBILE, LitGrade::FEAST_LORD);
        $event->is_dominical = true;
        $ctx->cal->addLiturgicalEvent($key, $event);
        $this->stampSeason($event);
        return $event;
    }

    /**
     * After-Epiphany Sundays (n. 40): every Sunday strictly after BaptismLord
     * (the Sunday after Jan 6) and strictly before Lent I (Easter − 42d).
     * Numbered from 2 — BaptismLord is the block's 1st Sunday.
     */
    private function calculateAfterEpiphanySundays(TemporaleContext $ctx): void
    {
        $year    = $ctx->params->Year;
        $baptism = DateTime::fromFormat('6-1-' . $year)->modify('next Sunday');
        $lent1   = Utilities::calcGregEaster($year)->sub(new \DateInterval('P' . ( 6 * 7 ) . 'D'));

        $ordinal = 2;
        $sunday  = ( clone $baptism )->modify('next Sunday');
        while ($sunday < $lent1) {
            $key = 'AfterEpiphany' . $ordinal;
            $this->synthesizeSunday($ctx, $key, clone $sunday, $this->afterEpiphanySundayName($ordinal, $ctx));
            $ordinal++;
            $sunday = ( clone $sunday )->modify('next Sunday');
        }
    }

    /**
     * Localized display name for an after-Epiphany Sunday, e.g. (it) "II domenica
     * dopo l'Epifania", (la) "Dominica II post Epiphaniam". Exact ordo wording is
     * validated in a later plan.
     */
    private function afterEpiphanySundayName(int $ordinal, TemporaleContext $ctx): string
    {
        if (LitLocale::LATIN_PRIMARY_LANGUAGE === LitLocale::$PRIMARY_LANGUAGE) {
            return sprintf('Dominica %s post Epiphaniam', LatinUtils::LATIN_ORDINAL[$ordinal]);
        }
        $ordinalStr = Utilities::getOrdinal($ordinal, $ctx->localeDateFormatter->getLocale(), $this->ordinalFormatter($ctx), LatinUtils::LATIN_ORDINAL);
        return sprintf("%s domenica dopo l'Epifania", $ordinalStr);
    }

    /**
     * Fill ferial weekdays over [$from, $to), skipping Sundays and any date already
     * occupied (Sundays, anchors). Each free ferial day becomes a WEEKDAY event,
     * season-stamped from its key. $keyBuilder(DateTime $date): string produces the
     * event key; $nameBuilder(DateTime $date): string the display name. On Lenten
     * Fridays, when $lentenAliturgicalFridays is true, is_aliturgical is set.
     *
     * @param callable(DateTime):string $keyBuilder
     * @param callable(DateTime):string $nameBuilder
     */
    private function fillFerialWeekdays(TemporaleContext $ctx, DateTime $from, DateTime $to, LitColor $color, callable $keyBuilder, callable $nameBuilder, bool $lentenAliturgicalFridays = false): void
    {
        $day = clone $from;
        while ($day < $to) {
            $isSunday = (int) $day->format('N') === 7;
            if (false === $isSunday && false === $ctx->cal->inCalendar($day)) {
                $key   = $keyBuilder($day);
                $name  = $nameBuilder($day);
                $event = new LiturgicalEvent($name, clone $day, $color, LitEventType::MOBILE, LitGrade::WEEKDAY);
                if ($lentenAliturgicalFridays && (int) $day->format('N') === 5) {
                    $event->is_aliturgical = true;
                }
                $ctx->cal->addLiturgicalEvent($key, $event);
                $this->stampSeason($event);
            }
            $day = ( clone $day )->add(new \DateInterval('P1D'));
        }
    }

    /** English weekday name (Monday…Saturday) for locale-independent keys. */
    private function englishWeekday(DateTime $date): string
    {
        return match ((int) $date->format('N')) {
            1       => 'Monday',
            2       => 'Tuesday',
            3       => 'Wednesday',
            4       => 'Thursday',
            5       => 'Friday',
            6       => 'Saturday',
            default => 'Sunday',
        };
    }

    /**
     * Localized ferial name, e.g. (it) "Lunedì della II settimana dopo Pentecoste",
     * (la) "Feria II hebdomadæ II post Pentecosten". When $weekNumber is null the
     * week clause is omitted (e.g. de Exceptáto ferie named by date in Task 6).
     */
    private function weekdayName(DateTime $date, string $seasonPhraseIt, string $seasonPhraseLa, ?int $weekNumber, TemporaleContext $ctx): string
    {
        if (LitLocale::LATIN_PRIMARY_LANGUAGE === LitLocale::$PRIMARY_LANGUAGE) {
            $feria = LatinUtils::LATIN_DAYOFTHEWEEK[$date->format('w')]; // e.g. "Feria II"
            if (null === $weekNumber) {
                return sprintf('%s %s', $feria, $seasonPhraseLa);
            }
            return sprintf('%s hebdomadæ %s %s', $feria, LatinUtils::LATIN_ORDINAL[$weekNumber], $seasonPhraseLa);
        }
        $weekday = $ctx->localeDateFormatter->getDayOfTheWeekFormatter()->format($date->format('U'));
        $weekday = Utilities::ucfirst($weekday);
        if (null === $weekNumber) {
            return sprintf('%s %s', $weekday, $seasonPhraseIt);
        }
        $ordinalStr = Utilities::getOrdinal($weekNumber, $ctx->localeDateFormatter->getLocale(), $this->ordinalFormatter($ctx), LatinUtils::LATIN_ORDINAL);
        return sprintf('%s della %s settimana %s', $weekday, $ordinalStr, $seasonPhraseIt);
    }

    /**
     * After-Epiphany ferie: Monday after Baptism … Saturday before Lent I. First
     * caller of `fillFerialWeekdays()`; keys as `AfterEpiphanyWeekday{N}{EnglishDay}`
     * where N matches the after-Epiphany Sunday numbering (BaptismLord = week 1).
     */
    private function calculateAfterEpiphanyWeekdays(TemporaleContext $ctx): void
    {
        $year    = $ctx->params->Year;
        $baptism = DateTime::fromFormat('6-1-' . $year)->modify('next Sunday');
        $lent1   = Utilities::calcGregEaster($year)->sub(new \DateInterval('P' . ( 6 * 7 ) . 'D'));
        $from    = ( clone $baptism )->add(new \DateInterval('P1D')); // Monday after Baptism
        $this->fillFerialWeekdays(
            $ctx,
            $from,
            $lent1,
            LitColor::GREEN,
            fn (DateTime $d): string => 'AfterEpiphanyWeekday' . $this->afterEpiphanyWeekNumber($d, $baptism) . $this->englishWeekday($d),
            fn (DateTime $d): string => $this->weekdayName($d, "dopo l'Epifania", 'post Epiphaniam', $this->afterEpiphanyWeekNumber($d, $baptism), $ctx)
        );
    }

    /**
     * Week number of a ferial day in the after-Epiphany block: the ordinal of the
     * Sunday that closes its week, matching the Sunday numbering (BaptismLord = week 1,
     * so the first Monday after Baptism is week 2).
     */
    private function afterEpiphanyWeekNumber(DateTime $date, DateTime $baptism): int
    {
        $daysSinceBaptism = (int) $baptism->diff($date)->format('%a');
        return (int) floor(( $daysSinceBaptism - 1 ) / 7) + 2;
    }

    /** Advent ferie: Monday after Advent I … Saturday before Christmas; Dec 17(18)–23 are de Exceptáto (n. 39). */
    private function calculateAdventWeekdays(TemporaleContext $ctx): void
    {
        $year = $ctx->params->Year;
        $from = ( clone $this->adventOne($year) )->add(new \DateInterval('P1D'));
        $to   = DateTime::fromFormat('25-12-' . $year); // Christmas (exclusive)
        $this->fillFerialWeekdays(
            $ctx,
            $from,
            $to,
            LitColor::MORELLO,
            // Advent runs Nov->Dec, so day-of-month alone collides (e.g. both Nov 17
            // and Dec 17 fall inside the block); key on month+day ('md') instead so
            // every ferial day gets a distinct key. Season classification only needs
            // the 'AdventWeekday' prefix (LitSeason::ADVENT_PATTERNS), so the exact
            // suffix shape is free.
            fn (DateTime $d): string => 'AdventWeekday' . $d->format('md'),
            fn (DateTime $d): string => $this->adventWeekdayName($d, $ctx)
        );
    }

    /**
     * Ferial name for an Advent weekday: the de Exceptáto phrase for Dec 17–23
     * (n. 39), otherwise the generic Advent phrase. Named by phrase, not week
     * number, so $weekNumber is null in both branches.
     */
    private function adventWeekdayName(DateTime $date, TemporaleContext $ctx): string
    {
        $md = (int) $date->format('nd'); // e.g. 1217 for Dec 17
        if ($md >= 1217 && $md <= 1223) {
            return $this->weekdayName($date, 'de Exceptáto', 'de Exceptato', null, $ctx);
        }
        return $this->weekdayName($date, "d'Avvento", 'Adventus', null, $ctx);
    }

    /**
     * Christmas ferie: Dec 26 … Dec 31 of the same civil year.
     *
     * The norm's upper bound is "the Saturday before Baptism", but within a
     * single-civil-year engine call Baptism/Circoncisione/Epiphany are always
     * dated in *January of this same $year* (see `calculateChristmasEpiphany()`),
     * i.e. structurally *before* Dec 26 of that same $year, not after it —
     * RomanTemporale's `Epiphany`/`Christmas` follow the identical same-$year
     * convention, and `CalendarHandler::calculateWeekdaysChristmasOctave()`
     * mirrors this exact boundary by stopping its own Christmas-octave weekday
     * fill at `31-12-$year` rather than reaching into January. So the true
     * Dec 26->Baptism span only exists once a caller merges this engine's
     * output for $year with the *next* call's output for $year+1 (whose
     * Circoncisione/Epiphany/BaptismLord anchors land on the correct forward
     * dates) — real cross-year continuity is out of scope here and deferred,
     * like the rest of the handler-level year semantics, to Plan 7. Bounding
     * at Dec 31 keeps this call's output correct and self-contained: it never
     * touches (or spuriously duplicates) the Jan 1 / Jan 6 anchors already
     * placed by `calculateChristmasEpiphany()` for this same $year.
     */
    private function calculateChristmasWeekdays(TemporaleContext $ctx): void
    {
        $year = $ctx->params->Year;
        $from = DateTime::fromFormat('26-12-' . $year);
        $to   = DateTime::fromFormat('1-1-' . ( $year + 1 )); // Dec 31 $year inclusive, exclusive bound
        $this->fillFerialWeekdays(
            $ctx,
            $from,
            $to,
            LitColor::WHITE,
            // Bounded to a single month (Dec), so day-of-month alone would not
            // collide here, but 'md' matches the Advent fill's key shape for
            // consistency and stays collision-safe if the bound ever changes.
            fn (DateTime $d): string => 'ChristmasWeekday' . $d->format('md'),
            fn (DateTime $d): string => $this->weekdayName($d, 'del tempo di Natale', 'Nativitatis', null, $ctx)
        );
    }

    /**
     * Localized display name for an after-Pentecost Sunday in sub-block (a)
     * "dopo Pentecoste", e.g. (it) "II domenica dopo Pentecoste", (la) "Dominica
     * II post Pentecosten".
     */
    private function afterPentecostSundayName(int $ordinal, TemporaleContext $ctx): string
    {
        return $this->afterPentecostFamilyName($ordinal, $ctx, 'dopo Pentecoste', 'post Pentecosten');
    }

    /**
     * Localized display name for an after-Pentecost Sunday in sub-block (b)
     * "dopo il Martirio" (of St John the Baptist).
     */
    private function afterMartyrdomSundayName(int $ordinal, TemporaleContext $ctx): string
    {
        return $this->afterPentecostFamilyName($ordinal, $ctx, 'dopo il Martirio', 'post Martyrium');
    }

    /**
     * Localized display name for an after-Pentecost Sunday in sub-block (c)
     * "dopo la Dedicazione" (of the Duomo di Milano).
     */
    private function afterDedicationSundayName(int $ordinal, TemporaleContext $ctx): string
    {
        return $this->afterPentecostFamilyName($ordinal, $ctx, 'dopo la Dedicazione', 'post Dedicationem');
    }

    /**
     * Shared name-building logic for the three after-Pentecost sub-block name
     * builders above.
     */
    private function afterPentecostFamilyName(int $ordinal, TemporaleContext $ctx, string $phraseIt, string $phraseLa): string
    {
        if (LitLocale::LATIN_PRIMARY_LANGUAGE === LitLocale::$PRIMARY_LANGUAGE) {
            return sprintf('Dominica %s %s', LatinUtils::LATIN_ORDINAL[$ordinal], $phraseLa);
        }
        $ordinalStr = Utilities::getOrdinal($ordinal, $ctx->localeDateFormatter->getLocale(), $this->ordinalFormatter($ctx), LatinUtils::LATIN_ORDINAL);
        return sprintf('%s domenica %s', $ordinalStr, $phraseIt);
    }

    /** Lenten ferie: Lent I (excl.) … Saturday before Palm Sunday; Fridays are aliturgical (nn. 24–27). */
    private function calculateLentWeekdays(TemporaleContext $ctx): void
    {
        $year    = $ctx->params->Year;
        $lent1   = Utilities::calcGregEaster($year)->sub(new \DateInterval('P' . ( 6 * 7 ) . 'D'));
        $palmSun = Utilities::calcGregEaster($year)->sub(new \DateInterval('P7D'));
        $from    = ( clone $lent1 )->add(new \DateInterval('P1D'));
        $this->fillFerialWeekdays(
            $ctx,
            $from,
            clone $palmSun, // up to (not incl.) Palm Sunday; SabatoTradSymb anchor is skipped by inCalendar()
            LitColor::MORELLO,
            fn (DateTime $d): string => 'LentWeekday' . $this->lentWeekNumber($d, $lent1) . $this->englishWeekday($d),
            fn (DateTime $d): string => $this->weekdayName($d, 'di Quaresima', 'Quadragesimæ', $this->lentWeekNumber($d, $lent1), $ctx),
            true
        );
    }

    /** Lenten week number: Lent I is week 1; the following Monday begins week 1's ferie. */
    private function lentWeekNumber(DateTime $date, DateTime $lent1): int
    {
        $daysSince = (int) $lent1->diff($date)->format('%a');
        return (int) floor(( $daysSince - 1 ) / 7) + 1;
    }

    /**
     * Feminine \NumberFormatter for ordinal rendering, cached per engine call.
     */
    private ?\NumberFormatter $ordinalFormatterCache = null;

    private function ordinalFormatter(TemporaleContext $ctx): \NumberFormatter
    {
        if (null === $this->ordinalFormatterCache) {
            $this->ordinalFormatterCache = new \NumberFormatter($ctx->localeDateFormatter->getLocale(), \NumberFormatter::SPELLOUT);
            $this->ordinalFormatterCache->setTextAttribute(\NumberFormatter::DEFAULT_RULESET, '%spellout-ordinal-feminine');
        }
        return $this->ordinalFormatterCache;
    }
}
