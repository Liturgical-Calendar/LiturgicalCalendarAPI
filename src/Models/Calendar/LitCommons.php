<?php

namespace LiturgicalCalendar\Api\Models\Calendar;

use LiturgicalCalendar\Api\Enum\LitCommon;
use LiturgicalCalendar\Api\Enum\LitLocale;
use ValueError;

final class LitCommons implements \JsonSerializable
{
    /** @var LitCommonItem[] */
    public array $commons = [];

    /**
     * LitCommons collection constructor.
     *
     * @param array<LitCommon|string>|LitCommon|string $litCommons
     */
    public function __construct(array|string|LitCommon $litCommons)
    {
        if (is_array($litCommons)) {
            if (count($litCommons) === 0) {
                $this->commons[] = new LitCommonItem(LitCommon::NONE);
                return;
            }

            if (count($litCommons) === 1) {
                if ($litCommons[0] === LitCommon::NONE || $litCommons[0] === LitCommon::NONE->value) {
                    $this->commons[] = new LitCommonItem(LitCommon::NONE);
                    return;
                }

                if ($litCommons[0] === LitCommon::PROPRIO || $litCommons[0] === LitCommon::PROPRIO->value) {
                    $this->commons[] = new LitCommonItem(LitCommon::PROPRIO);
                    return;
                }
            }

            $valueTypes = array_values(array_unique(array_map('gettype', $litCommons)));
            if (count($valueTypes) !== 1) {
                throw new ValueError('`common` array must contain values of the same type, instead you passed types ' . implode(', ', $valueTypes));
            }

            if ($valueTypes[0] !== 'string' && false === $litCommons[0] instanceof LitCommon) {
                throw new ValueError('`common` array must contain values of type LitCommon or string, instead you passed types ' . implode(', ', array_map('gettype', $litCommons)));
            }

            foreach ($litCommons as $litCommon) {
                if ($this->hasNoneOrProper()) {
                    throw new ValueError('`common` array cannot contain any other values when either `Proper` or `None` (empty string) is set');
                }
                if ($litCommon instanceof LitCommon) {
                    $this->commons[] = new LitCommonItem($litCommon);
                } else {
                    $this->splitGeneralSpecific($litCommon);
                }
            }
        } else {
            if ($litCommons === LitCommon::NONE || $litCommons === LitCommon::NONE->value) {
                $this->commons[] = new LitCommonItem(LitCommon::NONE);
                return;
            }
            if ($litCommons === LitCommon::PROPRIO || $litCommons === LitCommon::PROPRIO->value) {
                $this->commons[] = new LitCommonItem(LitCommon::PROPRIO);
                return;
            }
            if (is_string($litCommons)) {
                $this->splitGeneralSpecific($litCommons);
            } elseif ($litCommons instanceof LitCommon) {
                $this->commons[] = new LitCommonItem($litCommons);
            } else {
                throw new ValueError('`common` must be of type LitCommon or string, instead you passed value of type ' . gettype($litCommons));
            }
        }
    }

    /**
     * Split a single "Common" value into general and specific values, and add to the array of commons.
     *
     * If the value is a string containing a colon (:), it is split into separate general and specific values,
     * and those values are used to create a new LitCommonItem object.
     *
     * If the value is an empty string, or contains only whitespace, or contains the string "Proper",
     * or if the array of commons is not empty and already contains either "Proper" or an empty string,
     * a ValueError is thrown.
     *
     * If the value does not contain a colon (:), it is taken as a single general value,
     * and a new LitCommonItem object is created with that value.
     *
     * @param string $litCommon The value to split and add.
     */
    private function splitGeneralSpecific(string $litCommon): void
    {
        if (strpos($litCommon, ':') !== false) {
            /**
             * @var string $litCommonGeneral
             * @var string $litCommonSpecific
             */
            [$litCommonGeneral, $litCommonSpecific] = array_map('trim', explode(':', $litCommon));

            if ($this->isNotEmpty() && $this->isNoneOrProperValue($litCommonGeneral)) {
                throw new ValueError('`common` array cannot contain any other values when either `Proper` or `None` (empty string) is set');
            }
            $this->commons[] = new LitCommonItem(LitCommon::from($litCommonGeneral), LitCommon::from($litCommonSpecific));
        } else {
            if ($this->isNotEmpty() && $this->isNoneOrProperValue($litCommon)) {
                throw new ValueError('`common` array cannot contain any other values when either `Proper` or `None` (empty string) is set');
            }
            $this->commons[] = new LitCommonItem(LitCommon::from($litCommon));
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
     * Checks if the given liturgical common value is either 'Proper' or 'None'.
     *
     * @param string $litCommon The liturgical common value to check.
     * @return bool True if the value is 'Proper' or 'None', false otherwise.
     */
    private function isNoneOrProperValue(string $litCommon): bool
    {
        return $litCommon === LitCommon::PROPRIO->value || $litCommon === LitCommon::NONE->value;
    }

    /**
     * Determines if the array of LitCommonItem is not empty.
     * @return bool true if the array is not empty, false otherwise.
     */
    private function isNotEmpty(): bool
    {
        return count($this->commons) > 0;
    }

    /**
     * @param LitCommon $litCommon
     * @return bool
     */
    private function has(LitCommon $litCommon): bool
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
    public function fullTranslate(string $locale = LitLocale::LATIN): string
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

        $fromTheCommon = $locale === LitLocale::LATIN ? 'De Commune' : _('From the Common');

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
            || (count($this->commons) === 1 && $this->commons[0]->commonGeneral === LitCommon::NONE)
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
        return $locale === LitLocale::LATIN
            ? LitCommon::LATIN[$litCommon->name]
            : $litCommon->translate();
    }

    /**
     * Retrieves the possessive form for a given liturgical common, as long as it is a General Common.
     *
     * If the current locale is Latin, returns an empty string,
     * since the possessive is already indicated in the translated liturgical common.
     * For all other locales, returns the appropriate possessive form for the given liturgical common,
     * according to the rules defined in the {@see LitCommon::possessive()} method.
     * @param LitCommon $litCommon the liturgical common for which we want to get the possessive form
     * @param string $locale the locale in which to get the possessive form
     * @return string the possessive form for the given liturgical common
     */
    private static function getPossessive(LitCommon $litCommon, $locale): string
    {
        return $locale === LitLocale::LATIN
            ? ''
            : $litCommon->possessive();
    }
}
