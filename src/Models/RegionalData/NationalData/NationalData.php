<?php

namespace LiturgicalCalendar\Api\Models\RegionalData\NationalData;

use LiturgicalCalendar\Api\Models\AbstractJsonSrcData;
use LiturgicalCalendar\Api\Models\Metadata\MetadataNationalCalendarSettings;
use LiturgicalCalendar\Api\Models\LitCalItemCollection;
use LiturgicalCalendar\Api\Models\RegionalData\Translations;

final class NationalData extends AbstractJsonSrcData
{
    public readonly LitCalItemCollection $litcal;

    public readonly MetadataNationalCalendarSettings $settings;

    public readonly NationalMetadata $metadata;

    public Translations $i18n;

    private const ALLOWED_PROPS = ['i18n', 'litcal', 'metadata', 'settings'];

    private function __construct(LitCalItemCollection $litcal, MetadataNationalCalendarSettings $settings, NationalMetadata $metadata, ?\stdClass $i18n)
    {
        $this->litcal   = $litcal;
        $this->settings = $settings;
        $this->metadata = $metadata;

        if (null !== $i18n) {
            $this->validateTranslations($i18n);
            $this->i18n = Translations::fromObject($i18n);
        }
    }

    /**
     * Applies translations to the collection of liturgical items.
     *
     * Validates the i18n parameter to ensure that the keys are the same as the
     * values of metadata.locales, and then sets the $this->i18n property
     * to a Translations object constructed from the validated i18n parameter.
     *
     * @param \stdClass $i18n The object containing the translations to apply.
     *                        The keys of the object must be the same as the
     *                        values of metadata.locales.
     *
     * @throws \ValueError If the keys of the i18n parameter are not the same as
     *                    the values of metadata.locales.
     */
    public function loadTranslations(\stdClass $i18n): void
    {
        $this->validateTranslations($i18n);
        $this->unlock();
        $this->i18n = Translations::fromObject($i18n);
        $this->lock();
    }

    /**
     * Validates the i18n parameter to ensure its keys match the metadata locales.
     *
     * This function extracts the keys from the provided i18n object, sorts them,
     * and compares them to the sorted values of the metadata.locales. If they do
     * not match, a ValueError is thrown.
     *
     * @param \stdClass $i18n The translations object whose keys need to be validated.
     *
     * @throws \ValueError If the keys of the i18n parameter do not match the values
     *                     of metadata.locales.
     */
    private function validateTranslations(\stdClass $i18n): void
    {
        /** @var string[] $i18nProps */
        $i18nProps = array_keys(get_object_vars($i18n));
        sort($i18nProps);
        if (implode(',', $i18nProps) !== implode(',', $this->metadata->locales)) {
            throw new \ValueError('keys of i18n parameter must be the same as the values of metadata.locales');
        }
    }

    /**
     * Applies translations to each liturgical item in the collection.
     *
     * This method iterates over the liturgical calendar items and sets their name
     * based on the translations available for the specified locale.
     *
     * @param string $locale The locale to use for retrieving translations.
     *
     * @throws \ValueError if a translation is not available for a given event key.
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
     * @param array<string, string> $translations The translations to use for setting the names.
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

    /**
     * Creates an instance of NationalData from an associative array.
     *
     * The array must have the following keys:
     * - litcal (array): The liturgical calendar items.
     * - settings (array): The settings for the national calendar.
     * - metadata (array): The metadata for the national calendar.
     * - i18n (array|unset): The translations for the national calendar.
     *
     * @param array{litcal:array{event_key:string,day:int,month:int,color:string[],grade:int,common:string[]},settings:array{epiphany:string,ascension:string,corpus_christi:string,eternal_high_priest:bool},metadata:array{nation:string,wider_region:string,locales:string[],missals:string[]},i18n?:array<string,string>} $data
     * @return static
     * @throws \ValueError if the keys of the data parameter do not match the expected keys.
     */
    protected static function fromArrayInternal(array $data): static
    {
        $keys        = array_keys($data);
        $missingKeys = array_diff(self::ALLOWED_PROPS, $keys);
        if (!empty($missingKeys) || count($keys) !== count(self::ALLOWED_PROPS)) {
            throw new \ValueError('the keys of data parameter must match ' . implode(',', self::ALLOWED_PROPS));
        }
        return new static(
            LitCalItemCollection::fromArray($data['litcal']),
            MetadataNationalCalendarSettings::fromArray($data['settings']),
            NationalMetadata::fromArray($data['metadata']),
            array_key_exists('i18n', $data) ? (object) $data['i18n'] : null
        );
    }

    /**
     * Creates an instance of NationalData from a stdClass object.
     *
     * The object should have the following properties:
     * - litcal (array): The liturgical calendar items.
     * - settings (\stdClass): The settings for the national calendar.
     * - metadata (\stdClass): The metadata for the national calendar.
     * - i18n (\stdClass|unset): The translations for the national calendar.
     *
     * @param \stdClass $data The stdClass object containing the properties of the national calendar.
     * @return static
     */
    protected static function fromObjectInternal(\stdClass $data): static
    {
        return new static(
            LitCalItemCollection::fromArray($data->litcal),
            MetadataNationalCalendarSettings::fromObject($data->settings),
            NationalMetadata::fromObject($data->metadata),
            property_exists($data, 'i18n') ? $data->i18n : null
        );
    }

    /**
     * Determines if the national calendar has a wider region.
     *
     * @return bool true if the national calendar has a wider region, false otherwise.
     */
    public function hasWiderRegion(): bool
    {
        return property_exists($this->metadata, 'wider_region');
    }
}
