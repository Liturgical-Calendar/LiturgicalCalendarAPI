<?php

namespace LiturgicalCalendar\Api\Models\EventsPath;

use LiturgicalCalendar\Api\Enum\LitColor;
use LiturgicalCalendar\Api\Enum\LitEventType;
use LiturgicalCalendar\Api\Enum\LitLocale;
use LiturgicalCalendar\Api\Enum\LitGrade;
use LiturgicalCalendar\Api\Enum\LitCommon;
use LiturgicalCalendar\Api\Enum\LitMassVariousNeeds;
use LiturgicalCalendar\Api\Models\Calendar\LitCommons;
use LiturgicalCalendar\Api\Models\DerivesLiturgicalFieldsTrait;
use LiturgicalCalendar\Api\Models\Decrees\DecreeEventData;
use LiturgicalCalendar\Api\Models\LiturgicalEventData;
use LiturgicalCalendar\Api\Models\PropriumDeSanctisEvent;
use LiturgicalCalendar\Api\Models\PropriumDeTemporeEvent;

abstract class LiturgicalEventAbstract implements \JsonSerializable
{
    /**
     * `$color_lcl`, `$grade_lcl`, `$grade_abbr` and `$common_lcl` — the fields derived from
     * `$color`, `$grade` and `$common` — together with `$locale` and the one implementation of
     * each derivation, shared with the `/calendar` model. Before #872 this class carried its own
     * hand-written copy of all of it, which is how `/events` and `/calendar` came to disagree
     * about the same event.
     */
    use DerivesLiturgicalFieldsTrait;

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
        $this->event_idx = self::$internal_index++;
        $this->event_key = $event_key;
        $this->name      = $name;
        $this->color     = is_array($color) ? $color : [$color];
        $this->type      = $type;
        $this->grade     = $grade;

        // Assigned raw: an explicit display override is not derivable from the grade. deriveAllFields()
        // below applies the one rule that IS grade-coupled, clearing it for a HIGHER_SOLEMNITY.
        $this->grade_display = $displayGrade;

        $commons = $common instanceof LitCommons || $common instanceof LitMassVariousNeeds || $litMassVariousNeedsArray
                    ? $common
                    : ( is_array($common) ? LitCommons::create($common) : LitCommons::create([$common]) );
        if ($commons instanceof LitCommons) {
            $this->common = $commons;
        } elseif ($commons instanceof LitMassVariousNeeds) {
            $this->common = [$commons];
        } elseif ($litMassVariousNeedsArray) {
            /** @var LitMassVariousNeeds[] $commons */
            $this->common = $commons;
        } else {
            // Defensive: LitCommons::create() only returns null for a LitMassVariousNeeds array,
            // which the branch above already caught, so this is unreachable for the declared types.
            /** @var LitCommons $commons */
            $commons      = LitCommons::create([LitCommon::NONE]);
            $this->common = $commons;
        }

        // Derive color_lcl / grade_lcl / grade_abbr / grade_display / common_lcl from the properties
        // just assigned. The same call re-runs after any later write to one of them, so a constructed
        // catalog entry and a mutated one describe themselves identically.
        $this->deriveAllFields();
    }

    /**
     * Changes the grade of this catalog entry, re-deriving every field that describes the grade
     * (`grade_lcl`, `grade_abbr`, and `grade_display`'s one grade-coupled rule).
     *
     * @param LitGrade $grade The new grade.
     */
    public function applyGrade(LitGrade $grade): void
    {
        $this->grade = $grade;
        $this->rederiveDependentsOf('grade');
    }

    /**
     * Changes the Common of this catalog entry, re-deriving the localized Common string.
     *
     * @param LitCommons $common The new Common.
     */
    public function applyCommon(LitCommons $common): void
    {
        $this->common = $common;
        $this->rederiveDependentsOf('common');
    }

    /**
     * Changes the display name of this catalog entry. No derived field depends on the name.
     *
     * @param string $name The new name.
     */
    public function applyName(string $name): void
    {
        $this->name = $name;
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
