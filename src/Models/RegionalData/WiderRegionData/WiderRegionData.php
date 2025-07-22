<?php

namespace LiturgicalCalendar\Api\Models\RegionalData\WiderRegionData;

use LiturgicalCalendar\Api\Models\AbstractJsonSrcData;
use LiturgicalCalendar\Api\Models\RegionalData\LitCalItemCollection;
use LiturgicalCalendar\Api\Models\RegionalData\NationalData\LitCalItemCreateNewFixed;
use LiturgicalCalendar\Api\Models\RegionalData\NationalData\LitCalItemCreateNewMobile;
use LiturgicalCalendar\Api\Models\RegionalData\NationalData\LitCalItemMakePatron;
use LiturgicalCalendar\Api\Models\RegionalData\NationalData\LitCalItemSetPropertyName;
use LiturgicalCalendar\Api\Models\RegionalData\Translations;

final class WiderRegionData extends AbstractJsonSrcData
{
    public readonly LitCalItemCollection $litcal;

    /** @var array<string, string> */
    public readonly array $national_calendars;

    public readonly WiderRegionMetadata $metadata;

    public readonly ?Translations $i18n;

    public function __construct(LitCalItemCollection $litcal, array $national_calendars, WiderRegionMetadata $metadata, ?\stdClass $i18n = null)
    {
        $this->litcal             = $litcal;
        $this->national_calendars = $national_calendars;
        $this->metadata           = $metadata;
        if (null !== $i18n) {
            $this->loadTranslations($i18n);
        }
    }


    protected static function fromArrayInternal(array $data): static
    {
        if (!isset($data['litcal']) || !isset($data['national_calendars']) || !isset($data['metadata'])) {
            throw new \ValueError('litcal, national_calendars and metadata parameters are required');
        }

        if (false === is_array($data['litcal']) || 0 === count($data['litcal'])) {
            throw new \TypeError('litcal parameter must be an array and must not be empty: ' . json_encode($data['litcal']));
        }

        if (false === is_array($data['national_calendars']) || 0 === count($data['national_calendars'])) {
            throw new \TypeError('national_calendars parameter must be an array and must not be empty: ' . json_encode($data['national_calendars']));
        }

        if (false === is_array($data['metadata']) || 0 === count($data['metadata'])) {
            throw new \TypeError('metadata parameter must be an array and must not be empty: ' . json_encode($data['metadata']));
        }

        return new static(
            LitCalItemCollection::fromArray($data['litcal']),
            $data['national_calendars'],
            WiderRegionMetadata::fromArray($data['metadata']),
            $data['i18n'] ?? null
        );
    }

    protected static function fromObjectInternal(\stdClass $data): static
    {
        if (!isset($data->litcal) || !isset($data->national_calendars) || !isset($data->metadata)) {
            throw new \ValueError('litcal, national_calendars and metadata parameters are required');
        }

        if (false === is_array($data->litcal) || 0 === count($data->litcal)) {
            throw new \TypeError('litcal parameter must be an array and must not be empty: ' . json_encode($data->litcal));
        }

        if (false === $data->national_calendars instanceof \stdClass) {
            throw new \TypeError('national_calendars parameter must be an object: ' . json_encode($data->national_calendars));
        }

        $nationalCalendarsArray = (array) $data->national_calendars;
        if (false === is_array($nationalCalendarsArray) || 0 === count($nationalCalendarsArray)) {
            throw new \TypeError('national_calendars parameter must be an array and must not be empty: ' . json_encode($data->national_calendars));
        }

        if (false === $data->metadata instanceof \stdClass || !isset($data->metadata->locales) || !isset($data->metadata->wider_region)) {
            throw new \TypeError('metadata parameter must be an object with locales and wider_region properties: ' . json_encode($data->metadata));
        }

        return new static(
            LitCalItemCollection::fromArray($data->litcal),
            $nationalCalendarsArray,
            WiderRegionMetadata::fromObject($data->metadata),
            $data->i18n ?? null
        );
    }

    public function loadTranslations(\stdClass $i18n): void
    {
        /** @var string[] $i18nProps */
        $i18nProps = array_keys(get_object_vars($i18n));
        sort($i18nProps);
        if (implode(',', $i18nProps) !== implode(',', $this->metadata->locales)) {
            throw new \ValueError('keys of i18n parameter must be the same as the values of metadata.locales');
        }

        $this->i18n = Translations::fromObject($i18n);
    }

    /**
     * Applies translations to each liturgical item in the collection.
     *
     * This method iterates over the liturgical calendar items and sets their name
     * based on the translations available for the specified locale.
     *
     * @param string $locale The locale to use for retrieving translations.
     *
     * @throws ValueError if a translation is not available for a given event key.
     */
    public function applyTranslations(string $locale): void
    {
        foreach ($this->litcal as $litcalItem) {
            $litcalItem->setName($this->i18n->getTranslation($litcalItem->getEventKey(), $locale));
        }
    }

    /**
     * Sets the names of each liturgical item in the collection.
     *
     * This method takes an associative array of translations as its parameter,
     * where the keys are the event keys of the liturgical items and the values
     * are the translated names. The method then iterates over the liturgical
     * calendar items and sets their name based on the translations available
     * for the specified event key.
     *
     * If a translation is not available for a given event key, the method will
     * set the name of the liturgical item to null.
     *
     * @param array<string, string|null> $translations The translations to use for setting the names.
     */
    public function setNames(array $translations): void
    {
        foreach ($this->litcal as $litcalItem) {
            if (
                $litcalItem->liturgical_event instanceof LitCalItemCreateNewFixed
                || $litcalItem->liturgical_event instanceof LitCalItemCreateNewMobile
                || $litcalItem->liturgical_event instanceof LitCalItemMakePatron
                || $litcalItem->liturgical_event instanceof LitCalItemSetPropertyName
            ) {
                $eventKey = $litcalItem->getEventKey();
                if (false === array_key_exists($eventKey, $translations)) {
                    throw new \ValueError('translation for event key ' . $eventKey . ' not found, available translations: ' . implode(',', array_keys($translations)));
                }
                $litcalItem->setName($translations[$eventKey]);
            }
        }
    }
}
