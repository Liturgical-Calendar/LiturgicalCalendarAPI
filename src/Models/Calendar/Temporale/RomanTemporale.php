<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Models\Calendar\Temporale;

use LiturgicalCalendar\Api\DateTime;
use LiturgicalCalendar\Api\Utilities;
use LiturgicalCalendar\Api\Enum\Ascension;
use LiturgicalCalendar\Api\Enum\CorpusChristi;
use LiturgicalCalendar\Api\Enum\Epiphany;
use LiturgicalCalendar\Api\Enum\LitLocale;
use LiturgicalCalendar\Api\Models\Calendar\LiturgicalEventCollection;

/**
 * Roman-rite temporale engine.
 *
 * Holds the contiguous Roman temporale computation (Easter Triduum through the
 * mobile Solemnities of the Lord) extracted verbatim from CalendarHandler.
 * Behaviour MUST remain byte-identical to the original inline version — the
 * golden-master regression suite is the proof.
 */
final class RomanTemporale implements TemporaleEngine
{
    public function buildTemporale(TemporaleContext $ctx): void
    {
        $this->calculateEasterTriduum($ctx);
        $this->calculateChristmasEpiphany($ctx);
        $this->calculateAscensionPentecost($ctx);
        $this->calculateSundaysMajorSeasons($ctx);
        $this->calculateAshWednesday($ctx);
        $this->calculateWeekdaysHolyWeek($ctx);
        $this->calculateEasterOctave($ctx);
        $this->calculateMobileSolemnitiesOfTheLord($ctx);
    }

    /**
     * Calculates the dates for Holy Thursday, Good Friday, Easter Vigil and Easter Sunday
     * and creates the corresponding LiturgicalEvents in the calendar
     *
     * **General Norms for the Liturgical Year and the Calendar**
     *
     * I.
     * 1. ***Easter Triduum of the Lord's Passion and Resurrection***
     * 2. Christmas, Epiphany, Ascension, and Pentecost
     */
    private function calculateEasterTriduum(TemporaleContext $ctx): void
    {
        $ctx->createPropriumDeTemporeEvent('HolyThurs', Utilities::calcGregEaster($ctx->params->Year)->sub(new \DateInterval('P3D')));
        $ctx->createPropriumDeTemporeEvent('GoodFri', Utilities::calcGregEaster($ctx->params->Year)->sub(new \DateInterval('P2D')));
        $ctx->createPropriumDeTemporeEvent('EasterVigil', Utilities::calcGregEaster($ctx->params->Year)->sub(new \DateInterval('P1D')));
        $ctx->createPropriumDeTemporeEvent('Easter', Utilities::calcGregEaster($ctx->params->Year));
    }

    /**
     * Calculates the dates for Christmas and Epiphany
     * and creates the corresponding LiturgicalEvents in the calendar
     *
     * **General Norms for the Liturgical Year and the Calendar**
     *
     * I.
     * 1. Easter Triduum of the Lord's Passion and Resurrection
     * 2. ***Christmas, Epiphany, Ascension, and Pentecost***
     */
    private function calculateChristmasEpiphany(TemporaleContext $ctx): void
    {
        // Calculate Christmas
        $ctx->createPropriumDeTemporeEvent('Christmas', DateTime::fromFormat('25-12-' . $ctx->params->Year));

        // Calculate Epiphany (and the "Second Sunday of Christmas" if applicable)
        switch ($ctx->params->Epiphany) {
            case Epiphany::JAN6:
                $ctx->createPropriumDeTemporeEvent('Epiphany', DateTime::fromFormat('6-1-' . $ctx->params->Year));

                // if a Sunday falls between Jan. 2 and Jan. 5, it is called the "Second Sunday of Christmas"
                $christmas2Day = array_find(
                    [2, 3, 4, 5],
                    fn(int $day): bool => LiturgicalEventCollection::dateIsSunday(DateTime::fromFormat($day . '-1-' . $ctx->params->Year))
                );
                if (null !== $christmas2Day) {
                    $ctx->createPropriumDeTemporeEvent('Christmas2', DateTime::fromFormat($christmas2Day . '-1-' . $ctx->params->Year));
                }
                break;
            case Epiphany::SUNDAY_JAN2_JAN8:
                //If January 2nd is a Sunday, then go with Jan 2nd
                $dateTime = DateTime::fromFormat('2-1-' . $ctx->params->Year);
                if (LiturgicalEventCollection::dateIsSunday($dateTime)) {
                    $ctx->createPropriumDeTemporeEvent('Epiphany', $dateTime);
                } else {
                    //otherwise find the Sunday following Jan 2nd
                    $SundayOfEpiphany = $dateTime->modify('next Sunday');
                    $ctx->createPropriumDeTemporeEvent('Epiphany', $SundayOfEpiphany);
                }
                break;
        }
    }

    /**
     * Calculates the dates for Ascension and Pentecost and creates the corresponding LiturgicalEvents in the calendar
     *
     * Ascension can be either Thursday or Sunday, depending on the calendar settings,
     * so call either calculateAscensionThursday or calculateAscensionSunday
     *
     * Pentecost is fixed date, so just create a LiturgicalEvent
     *
     * **General Norms for the Liturgical Year and the Calendar**
     *
     * I.
     * 1. Easter Triduum of the Lord's Passion and Resurrection
     * 2. ***Christmas, Epiphany, Ascension, and Pentecost***
     *
     * @return void
     */
    private function calculateAscensionPentecost(TemporaleContext $ctx): void
    {
        if ($ctx->params->Ascension === Ascension::THURSDAY) {
            $ctx->createPropriumDeTemporeEvent('Ascension', Utilities::calcGregEaster($ctx->params->Year)->add(new \DateInterval('P39D')));
            $ctx->createPropriumDeTemporeEvent('Easter7', Utilities::calcGregEaster($ctx->params->Year)
                ->add(new \DateInterval('P' . ( 7 * 6 ) . 'D')));
        } elseif ($ctx->params->Ascension === Ascension::SUNDAY) {
            $ctx->createPropriumDeTemporeEvent('Ascension', Utilities::calcGregEaster($ctx->params->Year)
                ->add(new \DateInterval('P' . ( 7 * 6 ) . 'D')));
        }

        $ctx->createPropriumDeTemporeEvent('Pentecost', Utilities::calcGregEaster($ctx->params->Year)
            ->add(new \DateInterval('P' . ( 7 * 7 ) . 'D')));
    }

    /**
     * Calculates the dates for Sundays of Advent, Lent, Easter, Ordinary Time, and special Sundays like Palm Sunday, Corpus Christi, and Trinity Sunday
     * and creates the corresponding LiturgicalEvents in the calendar
     *
     * **General Norms for the Liturgical Year and the Calendar**
     *
     * I.
     * 1. Easter Triduum of the Lord's Passion and Resurrection
     * 2. Christmas, Epiphany, Ascension, and Pentecost;
     *    ***Sundays of Advent, Lent and Easter***
     *
     * @return void
     */
    private function calculateSundaysMajorSeasons(TemporaleContext $ctx): void
    {
        //We calculate Sundays of Advent based on Christmas
        $christmasDateStr = '25-12-' . $ctx->params->Year;

        $ctx->createPropriumDeTemporeEvent('Advent1', DateTime::fromFormat($christmasDateStr)
            ->modify('last Sunday')->sub(new \DateInterval('P' . ( 3 * 7 ) . 'D')));
        $ctx->createPropriumDeTemporeEvent('Advent2', DateTime::fromFormat($christmasDateStr)
            ->modify('last Sunday')->sub(new \DateInterval('P' . ( 2 * 7 ) . 'D')));
        $ctx->createPropriumDeTemporeEvent('Advent3', DateTime::fromFormat($christmasDateStr)
            ->modify('last Sunday')->sub(new \DateInterval('P7D')));
        $ctx->createPropriumDeTemporeEvent('Advent4', DateTime::fromFormat($christmasDateStr)
            ->modify('last Sunday'));

        //We calculate Sundays of Lent, Palm Sunday, Sundays of Easter, Trinity Sunday and Corpus Christi based on Easter
        $ctx->createPropriumDeTemporeEvent('Lent1', Utilities::calcGregEaster($ctx->params->Year)
            ->sub(new \DateInterval('P' . ( 6 * 7 ) . 'D')));
        $ctx->createPropriumDeTemporeEvent('Lent2', Utilities::calcGregEaster($ctx->params->Year)
            ->sub(new \DateInterval('P' . ( 5 * 7 ) . 'D')));
        $ctx->createPropriumDeTemporeEvent('Lent3', Utilities::calcGregEaster($ctx->params->Year)
            ->sub(new \DateInterval('P' . ( 4 * 7 ) . 'D')));
        $ctx->createPropriumDeTemporeEvent('Lent4', Utilities::calcGregEaster($ctx->params->Year)
            ->sub(new \DateInterval('P' . ( 3 * 7 ) . 'D')));
        $ctx->createPropriumDeTemporeEvent('Lent5', Utilities::calcGregEaster($ctx->params->Year)
            ->sub(new \DateInterval('P' . ( 2 * 7 ) . 'D')));
        $ctx->createPropriumDeTemporeEvent('PalmSun', Utilities::calcGregEaster($ctx->params->Year)
            ->sub(new \DateInterval('P7D')));
        $ctx->createPropriumDeTemporeEvent('Easter2', Utilities::calcGregEaster($ctx->params->Year)
            ->add(new \DateInterval('P7D')));
        $ctx->createPropriumDeTemporeEvent('Easter3', Utilities::calcGregEaster($ctx->params->Year)
            ->add(new \DateInterval('P' . ( 7 * 2 ) . 'D')));
        $ctx->createPropriumDeTemporeEvent('Easter4', Utilities::calcGregEaster($ctx->params->Year)
            ->add(new \DateInterval('P' . ( 7 * 3 ) . 'D')));
        $ctx->createPropriumDeTemporeEvent('Easter5', Utilities::calcGregEaster($ctx->params->Year)
            ->add(new \DateInterval('P' . ( 7 * 4 ) . 'D')));
        $ctx->createPropriumDeTemporeEvent('Easter6', Utilities::calcGregEaster($ctx->params->Year)
            ->add(new \DateInterval('P' . ( 7 * 5 ) . 'D')));
        $ctx->createPropriumDeTemporeEvent('Trinity', Utilities::calcGregEaster($ctx->params->Year)
            ->add(new \DateInterval('P' . ( 7 * 8 ) . 'D')));
        if ($ctx->params->CorpusChristi === CorpusChristi::THURSDAY) {
            $ctx->createPropriumDeTemporeEvent('CorpusChristi', Utilities::calcGregEaster($ctx->params->Year)
                ->add(new \DateInterval('P' . ( 7 * 8 + 4 ) . 'D')));
            //Seeing the Sunday is not taken by Corpus Christi, it should be later taken by a Sunday of Ordinary Time
            // (they are calculated back to Pentecost)
        } elseif ($ctx->params->CorpusChristi === CorpusChristi::SUNDAY) {
            $ctx->createPropriumDeTemporeEvent('CorpusChristi', Utilities::calcGregEaster($ctx->params->Year)
                ->add(new \DateInterval('P' . ( 7 * 9 ) . 'D')));
        }

        if ($ctx->params->Year >= 2000) {
            // Modify name of the second Sunday of Easter to include Divine Mercy Sunday
            $easter2Name = $ctx->propriumDeTempore['Easter2']->name;
            if (LitLocale::$PRIMARY_LANGUAGE === LitLocale::LATIN_PRIMARY_LANGUAGE) {
                $divineMercySunday = $easter2Name . ' vel Dominica Divinæ Misericordiæ';
            } else {
                /**translators: context alternate name for a liturgical event, e.g. Second Sunday of Easter `or` Divine Mercy Sunday*/
                $or                = _('or');
                $divineMercySunday = $easter2Name
                    . " $or "
                    /**translators: as instituted on the day of the canonization of St Faustina Kowalska by Pope John Paul II in the year 2000 */
                    . _('Divine Mercy Sunday');
            }
            $ctx->cal->setProperty('Easter2', 'name', $divineMercySunday);
        }
    }

    /**
     * Calculates the date for Ash Wednesday
     * and creates the corresponding LiturgicalEvent in the calendar
     *
     * @return void
     */
    private function calculateAshWednesday(TemporaleContext $ctx): void
    {
        $ctx->createPropriumDeTemporeEvent('AshWednesday', Utilities::calcGregEaster($ctx->params->Year)
            ->sub(new \DateInterval('P46D')));
    }

    /**
     * Calculates the dates for Weekdays of Holy Week from Monday to Thursday inclusive
     * and creates the corresponding LiturgicalEvents in the calendar
     *
     * @return void
     */
    private function calculateWeekdaysHolyWeek(TemporaleContext $ctx): void
    {
        //Weekdays of Holy Week from Monday to Thursday inclusive
        // ( that is, thursday morning chrism Mass... the In Coena Domini Mass begins the Easter Triduum )
        $ctx->createPropriumDeTemporeEvent('MonHolyWeek', Utilities::calcGregEaster($ctx->params->Year)
            ->sub(new \DateInterval('P6D')));
        $ctx->createPropriumDeTemporeEvent('TueHolyWeek', Utilities::calcGregEaster($ctx->params->Year)
            ->sub(new \DateInterval('P5D')));
        $ctx->createPropriumDeTemporeEvent('WedHolyWeek', Utilities::calcGregEaster($ctx->params->Year)
            ->sub(new \DateInterval('P4D')));
        $ctx->createPropriumDeTemporeEvent('HolyThursChrism', Utilities::calcGregEaster($ctx->params->Year)
            ->sub(new \DateInterval('P3D')));
    }

    /**
     * Calculates the dates for Monday to Saturday of the Octave of Easter
     * and creates the corresponding LiturgicalEvents in the calendar
     *
     * @return void
     */
    private function calculateEasterOctave(TemporaleContext $ctx): void
    {
        $ctx->createPropriumDeTemporeEvent('MonOctaveEaster', Utilities::calcGregEaster($ctx->params->Year)
            ->add(new \DateInterval('P1D')));
        $ctx->createPropriumDeTemporeEvent('TueOctaveEaster', Utilities::calcGregEaster($ctx->params->Year)
            ->add(new \DateInterval('P2D')));
        $ctx->createPropriumDeTemporeEvent('WedOctaveEaster', Utilities::calcGregEaster($ctx->params->Year)
            ->add(new \DateInterval('P3D')));
        $ctx->createPropriumDeTemporeEvent('ThuOctaveEaster', Utilities::calcGregEaster($ctx->params->Year)
            ->add(new \DateInterval('P4D')));
        $ctx->createPropriumDeTemporeEvent('FriOctaveEaster', Utilities::calcGregEaster($ctx->params->Year)
            ->add(new \DateInterval('P5D')));
        $ctx->createPropriumDeTemporeEvent('SatOctaveEaster', Utilities::calcGregEaster($ctx->params->Year)
            ->add(new \DateInterval('P6D')));
    }

    /**
     * Calculates the dates for Sacred Heart and Christ the King and creates the corresponding LiturgicalEvents in the calendar
     *
     * **General Norms for the Liturgical Year and the Calendar**
     *
     * I.
     * 1. Easter Triduum of the Lord's Passion and Resurrection
     * 2. Christmas, Epiphany, Ascension, and Pentecost
     * 3. ***Solemnities of the Lord, of the Blessed Virgin Mary, and of saints listed in the General Calendar***
     *
     * @return void
     */
    private function calculateMobileSolemnitiesOfTheLord(TemporaleContext $ctx): void
    {
        $ctx->createPropriumDeTemporeEvent('SacredHeart', Utilities::calcGregEaster($ctx->params->Year)
            ->add(new \DateInterval('P' . ( 7 * 9 + 5 ) . 'D')));

        //Christ the King is calculated backwards from the first sunday of advent
        $ctx->createPropriumDeTemporeEvent('ChristKing', DateTime::fromFormat('25-12-' . $ctx->params->Year)->modify('last Sunday')->sub(new \DateInterval('P' . ( 4 * 7 ) . 'D')));
    }
}
