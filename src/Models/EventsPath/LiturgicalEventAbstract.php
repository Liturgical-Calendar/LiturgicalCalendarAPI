<?php

namespace LiturgicalCalendar\Api\Models\EventsPath;

use LiturgicalCalendar\Api\Enum\LitColor;
use LiturgicalCalendar\Api\Enum\LitEventType;
use LiturgicalCalendar\Api\Enum\LitLocale;
use LiturgicalCalendar\Api\Enum\LitGrade;
use LiturgicalCalendar\Api\Enum\LitCommon;
use LiturgicalCalendar\Api\Enum\LitMassVariousNeeds;
use LiturgicalCalendar\Api\Models\Calendar\LitCommons;
use LiturgicalCalendar\Api\Models\Decrees\DecreeEventData;
use LiturgicalCalendar\Api\Models\LiturgicalEventData;
use LiturgicalCalendar\Api\Models\PropriumDeSanctisEvent;
use LiturgicalCalendar\Api\Models\PropriumDeTemporeEvent;

abstract class LiturgicalEventAbstract implements \JsonSerializable
{
    public int $event_idx;

    /** The following properties are generally passed in the constructor */
    public string $event_key;
    public string $name;
    /** @var LitColor[] */
    public $color = [];
    public LitEventType $type;
    public LitGrade $grade;
    public ?string $grade_display;
    /** @var LitCommons|LitMassVariousNeeds[] */
    public LitCommons|array $common;  //["Proper"] or one or more Commons

    /** The following properties are set based on properties passed in the constructor or on other properties */
    protected string $grade_lcl;
    /** @var string[] */
    protected array $color_lcl;
    protected string $grade_abbr;
    protected string $common_lcl;

    protected static string $locale      = LitLocale::LATIN_PRIMARY_LANGUAGE;
    protected static int $internal_index = 0;

    /**
     * @param string $event_key
     * @param string $name
     * @param LitColor|LitColor[] $color
     * @param LitEventType $type
     * @param LitGrade $grade
     * @param LitCommons|LitCommon|LitCommon[]|LitMassVariousNeeds|LitMassVariousNeeds[] $common
     * @param string|null $displayGrade
     */
    public function __construct(
        string $event_key,
        string $name,
        LitColor|array $color = LitColor::GREEN,
        LitEventType $type = LitEventType::FIXED,
        LitGrade $grade = LitGrade::WEEKDAY,
        LitCommons|LitCommon|LitMassVariousNeeds|array $common = LitCommon::NONE,
        ?string $displayGrade = null
    ) {
        $litMassVariousNeedsArray = false;
        if (is_array($common)) {
            $valueTypes = array_values(array_unique(array_map(fn($value) => gettype($value), $common)));
            if (count($valueTypes) > 1) {
                throw new \InvalidArgumentException('Incoherent liturgical common value types provided to create LiturgicalEvent: found multiple types ' . implode(', ', $valueTypes));
            }
            $litMassVariousNeedsArray = $common[0] instanceof LitMassVariousNeeds;
        }
        $this->event_idx     = self::$internal_index++;
        $this->event_key     = $event_key;
        $this->name          = $name;
        $this->color         = is_array($color) ? $color : [$color];
        $this->color_lcl     = array_map(fn($item) => LitColor::i18n($item, self::$locale), $this->color);
        $this->type          = $type;
        $this->grade         = $grade;
        $this->grade_lcl     = LitGrade::i18n($this->grade, self::$locale, false, false);
        $this->grade_abbr    = LitGrade::i18n($this->grade, self::$locale, false, true);
        $this->grade_display = $this->grade === LitGrade::HIGHER_SOLEMNITY ? '' : $displayGrade;
        $commons             = $common instanceof LitCommons || $common instanceof LitMassVariousNeeds || $litMassVariousNeedsArray
                                ? $common
                                : ( is_array($common) ? LitCommons::create($common) : LitCommons::create([$common]) );
        if ($commons instanceof LitCommons) {
            /** @var LitCommons $commons */
            $this->common_lcl = $commons->fullTranslate(self::$locale);
            $this->common     = $commons;
        } elseif ($commons instanceof LitMassVariousNeeds) {
            /** @var LitMassVariousNeeds $commons */
            $this->common_lcl = $commons->fullTranslate(self::$locale === LitLocale::LATIN_PRIMARY_LANGUAGE);
            $this->common     = [$commons];
        } elseif ($litMassVariousNeedsArray) {
            /** @var LitMassVariousNeeds[] $commons */
            $commonsLcl       = array_map(fn($item) => $item->fullTranslate(self::$locale === LitLocale::LATIN_PRIMARY_LANGUAGE), $commons);
            $this->common_lcl = implode('; ' . _('or') . ' ', $commonsLcl);
            $this->common     = $commons;
        } else {
            $this->common_lcl = '???';
            $this->common     = LitCommons::create([LitCommon::NONE]);
        }
    }

    /**
     * Set the abbreviation for the grade of this liturgical event.
     *
     * @param string $abbreviation The abbreviation for the grade of this liturgical event.
     * @return void
     */
    public function setGradeAbbreviation(string $abbreviation): void
    {
        $this->grade_abbr = $abbreviation;
    }

    /**
     * Sets the localized grade for this liturgical event.
     *
     * @param string $grade_lcl The localized name of the grade for the liturgical event.
     * @return void
     */
    public function setGradeLocalization(string $grade_lcl): void
    {
        $this->grade_lcl = $grade_lcl;
    }

    /**
     * Sets the locale for this LiturgicalEvent class, affecting the translations of
     * common liturgical texts and the formatting of dates.
     *
     * @param string $locale A valid locale string.
     * @return void
     */
    public static function setLocale(string $locale): void
    {
        if (LitLocale::isValid($locale)) {
            self::$locale = $locale;
        }
    }

    /**
     * @param LitCommons|array<LitMassVariousNeeds|LitCommon|string> $common
     * @return LitCommons|array<LitMassVariousNeeds>
     */
    protected static function transformCommons(LitCommons|array $common): LitCommons|array
    {
        if ($common instanceof LitCommons) {
            return $common;
        }

        if (count($common) === 0) {
            return LitCommons::create([LitCommon::NONE]);
        }

        $valueTypes = array_values(array_unique(array_map(fn($value) => gettype($value), $common)));

        if (count($valueTypes) > 1) {
            throw new \InvalidArgumentException('Incoherent liturgical common value types provided to create LiturgicalEvent: found multiple types ' . implode(', ', $valueTypes));
        }

        if ($valueTypes[0] === 'string') {
            /** @var string[] $common */
            return LitCommons::create($common)
                    ?? array_values(array_map(
                        fn(string $value): LitMassVariousNeeds => LitMassVariousNeeds::from($value),
                        $common
                    ));
        }

        if ($common[0] instanceof LitCommon) {
            return LitCommons::create($common);
        }

        if ($common[0] instanceof LitMassVariousNeeds) {
            /** @var LitMassVariousNeeds[] $common */
            return $common;
        }

        throw new \InvalidArgumentException('Invalid common value type provided to create LiturgicalEvent: expected an array of string, of LitCommon cases, or of LitMassVariousNeeds cases');
    }

    public function getCommonLcl(): string
    {
        return $this->common_lcl;
    }

    /** @param array{event_key:string,day?:int,month?:int,strotime?:string,color:string[],type:string,grade:int,common?:string[],grade_display?:?string} $arr */
    abstract public static function fromArray(array $arr): static;
    abstract public static function fromObject(\stdClass|LiturgicalEventData|DecreeEventData|PropriumDeSanctisEvent $obj): static;
    /** @return array{event_key:string,name:string,day?:int,month?:int,strotime?:string,color:string[],type:string,grade:int,grade_lcl:string,grade_abbr:string,common?:string[],common_lcl:string,grade_display?:?string} */
    abstract public function jsonSerialize(): array;
}
