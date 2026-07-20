<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Models\Calendar\Temporale;

use LiturgicalCalendar\Api\DateTime;
use LiturgicalCalendar\Api\Utilities;
use LiturgicalCalendar\Api\Enum\Ascension;
use LiturgicalCalendar\Api\Enum\CorpusChristi;
use LiturgicalCalendar\Api\Enum\Epiphany;
use LiturgicalCalendar\Api\Enum\LitLocale;
use LiturgicalCalendar\Api\Http\Exception\ServiceUnavailableException;
use LiturgicalCalendar\Api\Models\Calendar\LiturgicalEvent;

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
     * Creates a new LiturgicalEvent from the Proprium de Tempore, keyed by the
     * given event key, and adds it to the calendar.
     *
     * @param ?string $key The key of the event in the Proprium de Tempore
     * @param TemporaleContext $ctx The shared temporale context
     * @return LiturgicalEvent The new LiturgicalEvent object
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
     * Returns true when the given date falls on a Sunday.
     */
    private static function dateIsSunday(DateTime $dt): bool
    {
        return (int) $dt->format('N') === 7;
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
        $ctx->propriumDeTempore['HolyThurs']->setDate(Utilities::calcGregEaster($ctx->params->Year)->sub(new \DateInterval('P3D')));
        $ctx->propriumDeTempore['GoodFri']->setDate(Utilities::calcGregEaster($ctx->params->Year)->sub(new \DateInterval('P2D')));
        $ctx->propriumDeTempore['EasterVigil']->setDate(Utilities::calcGregEaster($ctx->params->Year)->sub(new \DateInterval('P1D')));
        $ctx->propriumDeTempore['Easter']->setDate(Utilities::calcGregEaster($ctx->params->Year));
        $this->createPropriumDeTemporeLiturgicalEventByKey('HolyThurs', $ctx);
        $this->createPropriumDeTemporeLiturgicalEventByKey('GoodFri', $ctx);
        $this->createPropriumDeTemporeLiturgicalEventByKey('EasterVigil', $ctx);
        $this->createPropriumDeTemporeLiturgicalEventByKey('Easter', $ctx);
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
        $ctx->propriumDeTempore['Christmas']->setDate(DateTime::fromFormat('25-12-' . $ctx->params->Year));
        $this->createPropriumDeTemporeLiturgicalEventByKey('Christmas', $ctx);

        // Calculate Epiphany (and the "Second Sunday of Christmas" if applicable)
        switch ($ctx->params->Epiphany) {
            case Epiphany::JAN6:
                $ctx->propriumDeTempore['Epiphany']->setDate(DateTime::fromFormat('6-1-' . $ctx->params->Year));
                $this->createPropriumDeTemporeLiturgicalEventByKey('Epiphany', $ctx);

                // if a Sunday falls between Jan. 2 and Jan. 5, it is called the "Second Sunday of Christmas"
                $christmas2Day = array_find(
                    [2, 3, 4, 5],
                    fn(int $day): bool => self::dateIsSunday(DateTime::fromFormat($day . '-1-' . $ctx->params->Year))
                );
                if (null !== $christmas2Day) {
                    $ctx->propriumDeTempore['Christmas2']->setDate(DateTime::fromFormat($christmas2Day . '-1-' . $ctx->params->Year));
                    $this->createPropriumDeTemporeLiturgicalEventByKey('Christmas2', $ctx);
                }
                break;
            case Epiphany::SUNDAY_JAN2_JAN8:
                //If January 2nd is a Sunday, then go with Jan 2nd
                $dateTime = DateTime::fromFormat('2-1-' . $ctx->params->Year);
                if (self::dateIsSunday($dateTime)) {
                    $ctx->propriumDeTempore['Epiphany']->setDate($dateTime);
                    $this->createPropriumDeTemporeLiturgicalEventByKey('Epiphany', $ctx);
                } else {
                    //otherwise find the Sunday following Jan 2nd
                    $SundayOfEpiphany = $dateTime->modify('next Sunday');
                    $ctx->propriumDeTempore['Epiphany']->setDate($SundayOfEpiphany);
                    $this->createPropriumDeTemporeLiturgicalEventByKey('Epiphany', $ctx);
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
            $ctx->propriumDeTempore['Ascension']->setDate(Utilities::calcGregEaster($ctx->params->Year)->add(new \DateInterval('P39D')));
            $this->createPropriumDeTemporeLiturgicalEventByKey('Ascension', $ctx);
            $ctx->propriumDeTempore['Easter7']->setDate(Utilities::calcGregEaster($ctx->params->Year)
                ->add(new \DateInterval('P' . ( 7 * 6 ) . 'D')));
            $this->createPropriumDeTemporeLiturgicalEventByKey('Easter7', $ctx);
        } elseif ($ctx->params->Ascension === Ascension::SUNDAY) {
            $ctx->propriumDeTempore['Ascension']->setDate(Utilities::calcGregEaster($ctx->params->Year)
                ->add(new \DateInterval('P' . ( 7 * 6 ) . 'D')));
            $this->createPropriumDeTemporeLiturgicalEventByKey('Ascension', $ctx);
        }

        $ctx->propriumDeTempore['Pentecost']->setDate(Utilities::calcGregEaster($ctx->params->Year)
            ->add(new \DateInterval('P' . ( 7 * 7 ) . 'D')));
        $this->createPropriumDeTemporeLiturgicalEventByKey('Pentecost', $ctx);
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

        $ctx->propriumDeTempore['Advent1']->setDate(DateTime::fromFormat($christmasDateStr)
            ->modify('last Sunday')->sub(new \DateInterval('P' . ( 3 * 7 ) . 'D')));
        $ctx->propriumDeTempore['Advent2']->setDate(DateTime::fromFormat($christmasDateStr)
            ->modify('last Sunday')->sub(new \DateInterval('P' . ( 2 * 7 ) . 'D')));
        $ctx->propriumDeTempore['Advent3']->setDate(DateTime::fromFormat($christmasDateStr)
            ->modify('last Sunday')->sub(new \DateInterval('P7D')));
        $ctx->propriumDeTempore['Advent4']->setDate(DateTime::fromFormat($christmasDateStr)
            ->modify('last Sunday'));
        $this->createPropriumDeTemporeLiturgicalEventByKey('Advent1', $ctx);
        $this->createPropriumDeTemporeLiturgicalEventByKey('Advent2', $ctx);
        $this->createPropriumDeTemporeLiturgicalEventByKey('Advent3', $ctx);
        $this->createPropriumDeTemporeLiturgicalEventByKey('Advent4', $ctx);

        //We calculate Sundays of Lent, Palm Sunday, Sundays of Easter, Trinity Sunday and Corpus Christi based on Easter
        $ctx->propriumDeTempore['Lent1']->setDate(Utilities::calcGregEaster($ctx->params->Year)
            ->sub(new \DateInterval('P' . ( 6 * 7 ) . 'D')));
        $ctx->propriumDeTempore['Lent2']->setDate(Utilities::calcGregEaster($ctx->params->Year)
            ->sub(new \DateInterval('P' . ( 5 * 7 ) . 'D')));
        $ctx->propriumDeTempore['Lent3']->setDate(Utilities::calcGregEaster($ctx->params->Year)
            ->sub(new \DateInterval('P' . ( 4 * 7 ) . 'D')));
        $ctx->propriumDeTempore['Lent4']->setDate(Utilities::calcGregEaster($ctx->params->Year)
            ->sub(new \DateInterval('P' . ( 3 * 7 ) . 'D')));
        $ctx->propriumDeTempore['Lent5']->setDate(Utilities::calcGregEaster($ctx->params->Year)
            ->sub(new \DateInterval('P' . ( 2 * 7 ) . 'D')));
        $this->createPropriumDeTemporeLiturgicalEventByKey('Lent1', $ctx);
        $this->createPropriumDeTemporeLiturgicalEventByKey('Lent2', $ctx);
        $this->createPropriumDeTemporeLiturgicalEventByKey('Lent3', $ctx);
        $this->createPropriumDeTemporeLiturgicalEventByKey('Lent4', $ctx);
        $this->createPropriumDeTemporeLiturgicalEventByKey('Lent5', $ctx);
        $ctx->propriumDeTempore['PalmSun']->setDate(Utilities::calcGregEaster($ctx->params->Year)
            ->sub(new \DateInterval('P7D')));
        $ctx->propriumDeTempore['Easter2']->setDate(Utilities::calcGregEaster($ctx->params->Year)
            ->add(new \DateInterval('P7D')));
        $ctx->propriumDeTempore['Easter3']->setDate(Utilities::calcGregEaster($ctx->params->Year)
            ->add(new \DateInterval('P' . ( 7 * 2 ) . 'D')));
        $ctx->propriumDeTempore['Easter4']->setDate(Utilities::calcGregEaster($ctx->params->Year)
            ->add(new \DateInterval('P' . ( 7 * 3 ) . 'D')));
        $ctx->propriumDeTempore['Easter5']->setDate(Utilities::calcGregEaster($ctx->params->Year)
            ->add(new \DateInterval('P' . ( 7 * 4 ) . 'D')));
        $ctx->propriumDeTempore['Easter6']->setDate(Utilities::calcGregEaster($ctx->params->Year)
            ->add(new \DateInterval('P' . ( 7 * 5 ) . 'D')));
        $ctx->propriumDeTempore['Trinity']->setDate(Utilities::calcGregEaster($ctx->params->Year)
            ->add(new \DateInterval('P' . ( 7 * 8 ) . 'D')));
        $this->createPropriumDeTemporeLiturgicalEventByKey('PalmSun', $ctx);
        $this->createPropriumDeTemporeLiturgicalEventByKey('Easter2', $ctx);
        $this->createPropriumDeTemporeLiturgicalEventByKey('Easter3', $ctx);
        $this->createPropriumDeTemporeLiturgicalEventByKey('Easter4', $ctx);
        $this->createPropriumDeTemporeLiturgicalEventByKey('Easter5', $ctx);
        $this->createPropriumDeTemporeLiturgicalEventByKey('Easter6', $ctx);
        $this->createPropriumDeTemporeLiturgicalEventByKey('Trinity', $ctx);
        if ($ctx->params->CorpusChristi === CorpusChristi::THURSDAY) {
            $ctx->propriumDeTempore['CorpusChristi']->setDate(Utilities::calcGregEaster($ctx->params->Year)
                ->add(new \DateInterval('P' . ( 7 * 8 + 4 ) . 'D')));
            $this->createPropriumDeTemporeLiturgicalEventByKey('CorpusChristi', $ctx);
            //Seeing the Sunday is not taken by Corpus Christi, it should be later taken by a Sunday of Ordinary Time
            // (they are calculated back to Pentecost)
        } elseif ($ctx->params->CorpusChristi === CorpusChristi::SUNDAY) {
            $ctx->propriumDeTempore['CorpusChristi']->setDate(Utilities::calcGregEaster($ctx->params->Year)
                ->add(new \DateInterval('P' . ( 7 * 9 ) . 'D')));
            $this->createPropriumDeTemporeLiturgicalEventByKey('CorpusChristi', $ctx);
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
        $ctx->propriumDeTempore['AshWednesday']->setDate(Utilities::calcGregEaster($ctx->params->Year)
            ->sub(new \DateInterval('P46D')));
        $this->createPropriumDeTemporeLiturgicalEventByKey('AshWednesday', $ctx);
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
        $ctx->propriumDeTempore['MonHolyWeek']->setDate(Utilities::calcGregEaster($ctx->params->Year)
            ->sub(new \DateInterval('P6D')));
        $ctx->propriumDeTempore['TueHolyWeek']->setDate(Utilities::calcGregEaster($ctx->params->Year)
            ->sub(new \DateInterval('P5D')));
        $ctx->propriumDeTempore['WedHolyWeek']->setDate(Utilities::calcGregEaster($ctx->params->Year)
            ->sub(new \DateInterval('P4D')));
        $ctx->propriumDeTempore['HolyThursChrism']->setDate(Utilities::calcGregEaster($ctx->params->Year)
            ->sub(new \DateInterval('P3D')));
        $this->createPropriumDeTemporeLiturgicalEventByKey('MonHolyWeek', $ctx);
        $this->createPropriumDeTemporeLiturgicalEventByKey('TueHolyWeek', $ctx);
        $this->createPropriumDeTemporeLiturgicalEventByKey('WedHolyWeek', $ctx);
        $this->createPropriumDeTemporeLiturgicalEventByKey('HolyThursChrism', $ctx);
    }

    /**
     * Calculates the dates for Monday to Saturday of the Octave of Easter
     * and creates the corresponding LiturgicalEvents in the calendar
     *
     * @return void
     */
    private function calculateEasterOctave(TemporaleContext $ctx): void
    {
        $ctx->propriumDeTempore['MonOctaveEaster']->setDate(Utilities::calcGregEaster($ctx->params->Year)
            ->add(new \DateInterval('P1D')));
        $ctx->propriumDeTempore['TueOctaveEaster']->setDate(Utilities::calcGregEaster($ctx->params->Year)
            ->add(new \DateInterval('P2D')));
        $ctx->propriumDeTempore['WedOctaveEaster']->setDate(Utilities::calcGregEaster($ctx->params->Year)
            ->add(new \DateInterval('P3D')));
        $ctx->propriumDeTempore['ThuOctaveEaster']->setDate(Utilities::calcGregEaster($ctx->params->Year)
            ->add(new \DateInterval('P4D')));
        $ctx->propriumDeTempore['FriOctaveEaster']->setDate(Utilities::calcGregEaster($ctx->params->Year)
            ->add(new \DateInterval('P5D')));
        $ctx->propriumDeTempore['SatOctaveEaster']->setDate(Utilities::calcGregEaster($ctx->params->Year)
            ->add(new \DateInterval('P6D')));
        $this->createPropriumDeTemporeLiturgicalEventByKey('MonOctaveEaster', $ctx);
        $this->createPropriumDeTemporeLiturgicalEventByKey('TueOctaveEaster', $ctx);
        $this->createPropriumDeTemporeLiturgicalEventByKey('WedOctaveEaster', $ctx);
        $this->createPropriumDeTemporeLiturgicalEventByKey('ThuOctaveEaster', $ctx);
        $this->createPropriumDeTemporeLiturgicalEventByKey('FriOctaveEaster', $ctx);
        $this->createPropriumDeTemporeLiturgicalEventByKey('SatOctaveEaster', $ctx);
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
        $ctx->propriumDeTempore['SacredHeart']->setDate(Utilities::calcGregEaster($ctx->params->Year)
            ->add(new \DateInterval('P' . ( 7 * 9 + 5 ) . 'D')));
        $this->createPropriumDeTemporeLiturgicalEventByKey('SacredHeart', $ctx);

        //Christ the King is calculated backwards from the first sunday of advent
        $ctx->propriumDeTempore['ChristKing']->setDate(DateTime::fromFormat('25-12-' . $ctx->params->Year)->modify('last Sunday')->sub(new \DateInterval('P' . ( 4 * 7 ) . 'D')));
        $this->createPropriumDeTemporeLiturgicalEventByKey('ChristKing', $ctx);
    }
}
