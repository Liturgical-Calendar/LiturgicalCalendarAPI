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
            $valueTypes = array_values(array_unique(array_map('gettype', $common)));
            if (count($valueTypes) > 1) {
                throw new \InvalidArgumentException('Incoherent liturgical common value types provided to create LiturgicalEvent: found multiple types ' . implode(', ', $valueTypes));
            }
            $litMassVariousNeedsArray = $common[0] instanceof LitMassVariousNeeds;
        }
        $this->event_idx     = self::$internal_index++;
        $this->event_key     = $event_key;
        $this->name          = $name;
        $this->color         = is_array($color) ? $color : [$color];
        $this->color_lcl     = array_map(
            function (LitColor $item): string {
                return $item->i18n(self::$locale);
            },
            $this->color
        );
        $this->type          = $type;
        $this->grade         = $grade;
        $this->grade_lcl     = $this->grade->i18n(self::$locale, false, false);
        $this->grade_abbr    = $this->grade->i18n(self::$locale, false, true);
        $this->grade_display = $this->grade === LitGrade::HIGHER_SOLEMNITY ? '' : $displayGrade;
        $commons             = $common instanceof LitCommons || $common instanceof LitMassVariousNeeds || $litMassVariousNeedsArray
                                ? $common
                                : ( is_array($common) ? LitCommons::create($common) : LitCommons::create([$common]) );
        if ($commons instanceof LitCommons) {
            /** @var LitCommons $commons */
            $this->common     = $commons;
            $this->common_lcl = $commons->fullTranslate(self::$locale);
        } elseif ($commons instanceof LitMassVariousNeeds) {
            /** @var LitMassVariousNeeds $commons */
            $this->common     = [$commons];
            $this->common_lcl = $commons->fullTranslate(self::$locale === LitLocale::LATIN_PRIMARY_LANGUAGE);
        } elseif ($litMassVariousNeedsArray) {
            /** @var LitMassVariousNeeds[] $commons */
            $this->common = $commons;
            $commonsLcl   = array_map(
                function (LitMassVariousNeeds $item): string {
                    return $item->fullTranslate(self::$locale === LitLocale::LATIN_PRIMARY_LANGUAGE);
                },
                $commons
            );
            /**translators: when there are multiple possible commons, this will be the glue "[; or] From the Common of..." */
            $or               = self::$locale === LitLocale::LATIN_PRIMARY_LANGUAGE ? 'vel' : _('or');
            $this->common_lcl = implode('; ' . $or . ' ', $commonsLcl);
        } else {
            /** @var LitCommons $commons */
            $commons          = LitCommons::create([LitCommon::NONE]);
            $this->common     = $commons;
            $this->common_lcl = '???';
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
            /** @var LitCommons $commons */
            $commons = LitCommons::create([LitCommon::NONE]);
            return $commons;
        }

        $valueTypes = array_values(array_unique(array_map('gettype', $common)));

        if (count($valueTypes) > 1) {
            throw new \InvalidArgumentException('Incoherent liturgical common value types provided to create LiturgicalEvent: found multiple types ' . implode(', ', $valueTypes));
        }

        if ($valueTypes[0] === 'string') {
            /** @var string[] $common */
            $commons = LitCommons::create($common) ?? array_map(
                function (string $value): LitMassVariousNeeds {
                    return LitMassVariousNeeds::from($value);
                },
                $common
            );
            if (false === $commons instanceof LitCommons && false === static::allInstancesOf($commons, LitMassVariousNeeds::class)) {
                throw new \InvalidArgumentException('Invalid common value type provided to create LiturgicalEvent: expected an array of string, of LitCommon cases, or of LitMassVariousNeeds cases');
            }
            return $commons;
        }

        if (static::allInstancesOf($common, LitCommon::class)) {
            /** @var LitCommon[] $common */
            $commons = LitCommons::create($common);
            if (false === $commons instanceof LitCommons) {
                throw new \InvalidArgumentException('Invalid common value type provided to create LiturgicalEvent: expected an array of string, of LitCommon cases, or of LitMassVariousNeeds cases');
            }
            return $commons;
        }

        if (static::allInstancesOf($common, LitMassVariousNeeds::class)) {
            /** @var LitMassVariousNeeds[] $common */
            return $common;
        }

        throw new \InvalidArgumentException('Invalid common value type provided to create LiturgicalEvent: expected an array of string, of LitCommon cases, or of LitMassVariousNeeds cases');
    }

    /**
     * @template T
     * @param array<mixed> $array
     * @param class-string<T> $className
     * @return bool
     */
    protected static function allInstancesOf(array $array, string $className): bool
    {
        foreach ($array as $item) {
            if (!$item instanceof $className) {
                return false;
            }
        }
        return true;
    }

    /**
     * @return string The liturgical commons localized for the current locale.
     */
    public function getCommonLcl(): string
    {
        return $this->common_lcl;
    }

    /**
     * Takes an array of string values representing colors, and returns an array of LitColor objects.
     *
     * @param string[] $colorStrArr An array of string values representing colors.
     * @return LitColor[] An array of LitColor objects.
     */
    public static function colorStringArrayToLitColorArray(array $colorStrArr): array
    {
        /** @var LitColor[] */
        $colors = array_map(
            static function (string $value): LitColor {
                return LitColor::from($value);
            },
            $colorStrArr
        );
        return $colors;
    }

    /** @param array{event_key:string,day?:int,month?:int,strotime?:string,color:string[],type:string,grade:int,common?:string[],grade_display?:?string} $arr */
    abstract public static function fromArray(array $arr): static;

    abstract public static function fromObject(\stdClass|LiturgicalEventData|DecreeEventData|PropriumDeSanctisEvent $obj): static;

    /** @return array{event_key:string,name:string,day?:int,month?:int,strotime?:string,color:string[],type:string,grade:int,grade_lcl:string,grade_abbr:string,common?:string[],common_lcl:string,grade_display?:?string} */
    abstract public function jsonSerialize(): array;
}
