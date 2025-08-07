<?php

namespace LiturgicalCalendar\Api\Models\Calendar;

use LiturgicalCalendar\Api\Enum\LitCommon;
use LiturgicalCalendar\Api\Enum\LitLocale;
use LiturgicalCalendar\Api\Enum\LitMassVariousNeeds;

final class LitCommons implements \JsonSerializable
{
    /** @var LitCommonItem[] */
    public array $commons = [];

    /**
     * LitCommons collection constructor.
     *
     * @param LitCommonItem[] $litCommonItems
     */
    private function __construct(array $litCommonItems)
    {
        foreach ($litCommonItems as $litCommonItem) {
            if ($this->hasNoneOrProper()) {
                throw new \ValueError('`common` array cannot contain any other values when either `Proper` or `None` (empty string) is set');
            }
            $this->commons[] = $litCommonItem;
        }
    }

    /**
     * Creates a new LitCommons collection.
     *
     * When we read liturgical event data from a JSON file,
     * we will always have an array of strings.
     *
     * When we generate a LiturgicalEvent directly in our calendar calculation,
     * without reading from a JSON file, we will have a LitCommon enum case or an array of LitCommon enum cases.
     * We should never have a LitMassVariousNeeds enum case when generating a LiturgicalEvent directly in our calendar calculation.
     *
     * @throws \ValueError
     * @param array<string|LitCommon|LitMassVariousNeeds> $litCommons
     * @return ?LitCommons
     */
    public static function create(array $litCommons): ?static
    {
        if (count($litCommons) === 0) {
            return new static([new LitCommonItem(LitCommon::NONE)]);
        }

        if (count($litCommons) === 1) {
            if ($litCommons[0] === LitCommon::NONE || $litCommons[0] === LitCommon::NONE->value) {
                return new static([new LitCommonItem(LitCommon::NONE)]);
            }

            if ($litCommons[0] === LitCommon::PROPRIO || $litCommons[0] === LitCommon::PROPRIO->value) {
                return new static([new LitCommonItem(LitCommon::PROPRIO)]);
            }
        }

        $valueTypes = array_values(array_unique(array_map('gettype', $litCommons)));
        if (count($valueTypes) !== 1) {
            throw new \ValueError('`common` array must contain values of the same type, instead you passed types ' . implode(', ', $valueTypes));
        }

        if ($litCommons[0] instanceof LitMassVariousNeeds) {
            return null;
        }

        if ($litCommons[0] instanceof LitCommon) {
            /**
             * @var LitCommon[] $litCommons
             */
            $commons = array_values(array_map(fn(LitCommon $litCommon) => new LitCommonItem($litCommon), $litCommons));
            return new static($commons);
        }

        if ($valueTypes[0] === 'string') {
            /**
             * @var string[] $litCommons
             */
            // We try to cast the values to LitCommonItems, and if that fails,
            // we may be dealing with LitMassVariousNeeds cases so we return null
            // to allow for a null coalescent operation
            $commons = [];
            foreach ($litCommons as $litCommon) {
                $litCommonItem = self::splitGeneralSpecific($litCommon);
                if ($litCommonItem === null) {
                    return null;
                }
                $commons[] = $litCommonItem;
            }
            return new static($commons);
        } else {
            throw new \ValueError('`common` array must contain values of type string or LitCommon, instead you passed types ' . implode(', ', $valueTypes));
        }
    }


    /**
     * Attempt to cast a string value to a LitCommon case or cases, and return a new LitCommonItem object.
     *
     * If the string contains a colon (:), it is split on the colon,
     * and attempts are made to cast the resulting values to General and Specific LitCommon cases.
     * If the casts succeed, a new LitCommonItem object is created with those values and returned.
     *
     * If the value does not contain a colon (:), it is taken as a General LitCommon value,
     * and an attempt to cast it to a LitCommon case is made.
     * If the cast succeeds, a new LitCommonItem object is created with that value and returned.
     *
     * If any cast fails, we return null.
     *
     * @param string $litCommon The value to split and cast.
     * @return LitCommonItem|null
     */
    private static function splitGeneralSpecific(string $litCommon): ?LitCommonItem
    {
        if (strpos($litCommon, ':') !== false) {
            /**
             * @var string $litCommonGeneral
             * @var string $litCommonSpecific
             */
            [$litCommonGeneral, $litCommonSpecific] = explode(':', $litCommon);

            $commonGeneral  = LitCommon::tryFrom($litCommonGeneral);
            $commonSpecific = LitCommon::tryFrom($litCommonSpecific);
            if ($commonGeneral === null || $commonSpecific === null) {
                return null;
            }
            return new LitCommonItem($commonGeneral, $commonSpecific);
        } else {
            $commonGeneral = LitCommon::tryFrom($litCommon);
            if ($commonGeneral === null) {
                return null;
            }
            return new LitCommonItem($commonGeneral);
        }
    }

    /**
     * Checks if either LitCommon::PROPRIO or LitCommon::NONE is in the internal array.
     *
     * @return bool True if either LitCommon::PROPRIO or LitCommon::NONE is in the internal array, false otherwise.
     */
    private function hasNoneOrProper(): bool
    {
        return $this->has(LitCommon::PROPRIO) || $this->has(LitCommon::NONE);
    }

    /**
     * @param LitCommon $litCommon
     * @return bool
     */
    public function has(LitCommon $litCommon): bool
    {
        foreach ($this->commons as $litCommonItem) {
            if ($litCommonItem->commonGeneral === $litCommon) {
                return true;
            }
            if ($litCommonItem->commonSpecific === $litCommon) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns a translated human readable string of the Commons (or the Proper)
     *
     * @param string $locale the locale to translate to
     * @return string the translated human readable string
     */
    public function fullTranslate(string $locale = LitLocale::LATIN_PRIMARY_LANGUAGE): string
    {
        if (count($this->commons) === 0) {
            return '';
        }

        if (count($this->commons) === 1 && $this->commons[0]->commonGeneral === LitCommon::NONE) {
            return '';
        }

        if (count($this->commons) === 1 && $this->commons[0]->commonGeneral === LitCommon::PROPRIO) {
            //$fromTheProper = $locale === LitLocale::LATIN ? 'De Proprio festivitatis' : _('From the Proper of the festivity');
            return LitCommons::i18n($this->commons[0]->commonGeneral, $locale);
        }


        /*
         * This won't work, we wouldn't have "From the Common" nor would we have the possessive "of|of the"
        if (count($this->commons) === 1 && in_array($this->commons[0]->commonGeneral, LitCommon::COMMUNES_GENERALIS)) {
            return LitCommons::i18n($this->commons[0]->commonGeneral, $locale);
        }
        */

        $fromTheCommon = $locale === LitLocale::LATIN_PRIMARY_LANGUAGE ? 'De Commune' : _('From the Common');

        /** @var string[] $commonsLcl */
        $commonsLcl = array_map(function ($litCommonItem) use ($locale, $fromTheCommon): string {
            $commonGeneralStringParts = [ $fromTheCommon ];

            $possessive = self::getPossessive($litCommonItem->commonGeneral, $locale);

            if ($possessive !== LitCommon::NONE->value) {
                array_push($commonGeneralStringParts, $possessive);
            }

            $commonGeneralLcl = self::i18n($litCommonItem->commonGeneral, $locale);

            if ($commonGeneralLcl !== LitCommon::NONE->value) {
                array_push($commonGeneralStringParts, $commonGeneralLcl);
            }

            $commonGeneralString = implode(' ', $commonGeneralStringParts);

            $commonSpecificLcl = $litCommonItem->commonSpecific !== null
                ? ': ' . self::i18n($litCommonItem->commonSpecific, $locale)
                : '';

            return $commonGeneralString . $commonSpecificLcl;
        }, $this->commons);

        /**translators: when there are multiple possible commons, this will be the glue "[; or] From the Common of..." */
        return implode('; ' . _('or') . ' ', $commonsLcl);
    }

    /**
     * Converts the LitCommons object to an array of json serialized LitCommonItem objects.
     *
     * @return string[] An array of json serialized LitCommonItem objects
     */
    public function jsonSerialize(): array
    {
        if (
            count($this->commons) === 0
            || ( count($this->commons) === 1 && $this->commons[0]->commonGeneral === LitCommon::NONE )
        ) {
            return [];
        }
        return array_map(fn (LitCommonItem $litCommonItem) => $litCommonItem->jsonSerialize(), $this->commons);
    }

    /**
     * Translates a given liturgical common into the currently set locale.
     *
     * @param LitCommon $litCommon The liturgical common to translate
     * @param string $locale The locale to translate to
     * @return string The translated value
     */
    public static function i18n(LitCommon $litCommon, string $locale): string
    {
        return $locale === LitLocale::LATIN || $locale === LitLocale::LATIN_PRIMARY_LANGUAGE
            ? LitCommon::LATIN[$litCommon->name]
            : $litCommon->translate();
    }

    /**
     * Retrieves the possessive form for a given liturgical common, as long as it is a General Common.
     *
     * If the current locale is Latin, returns an empty string,
     * since the possessive is already indicated in the translated liturgical common.
     * For all other locales, returns the appropriate possessive form for the given liturgical common,
     * according to the rules defined in the {@see \LiturgicalCalendar\Api\Enum\LitCommon::possessive()} method.
     * @param LitCommon $litCommon the liturgical common for which we want to get the possessive form
     * @param string $locale the locale in which to get the possessive form
     * @return string the possessive form for the given liturgical common
     */
    private static function getPossessive(LitCommon $litCommon, $locale): string
    {
        return $locale === LitLocale::LATIN || $locale === LitLocale::LATIN_PRIMARY_LANGUAGE
            ? ''
            : $litCommon->possessive();
    }
}
