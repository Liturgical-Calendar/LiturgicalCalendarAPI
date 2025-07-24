<?php

namespace LiturgicalCalendar\Api\Models\RegionalData\WiderRegionData;

use LiturgicalCalendar\Api\Models\AbstractJsonSrcData;
use LiturgicalCalendar\Api\Models\RegionalData\LitCalItemCollection;
use LiturgicalCalendar\Api\Models\RegionalData\NationalData\LitCalItemCreateNewFixed;
use LiturgicalCalendar\Api\Models\RegionalData\NationalData\LitCalItemCreateNewMobile;
use LiturgicalCalendar\Api\Models\RegionalData\NationalData\LitCalItemMakePatron;
use LiturgicalCalendar\Api\Models\RegionalData\NationalData\LitCalItemSetPropertyName;
use LiturgicalCalendar\Api\Models\RegionalData\Translations;

/**
 * @phpstan-import-type LiturgicalEventItem from \LiturgicalCalendar\Api\Paths\EventsPath
 */
final class WiderRegionData extends AbstractJsonSrcData
{
    public readonly LitCalItemCollection $litcal;

    /** @var array<string, string> */
    public readonly array $national_calendars;

    public readonly WiderRegionMetadata $metadata;

    public Translations $i18n;

    /**
     * Constructs a WiderRegionData object.
     *
     * @param LitCalItemCollection $litcal The collection of liturgical calendar items.
     * @param array<string,string> $national_calendars An associative array mapping national calendar identifiers to their respective names.
     * @param WiderRegionMetadata $metadata The metadata containing locales and the wider region identifier.
     * @param \stdClass|null $i18n Optional translations object. If provided, it will be validated and set.
     */
    private function __construct(LitCalItemCollection $litcal, array $national_calendars, WiderRegionMetadata $metadata, ?\stdClass $i18n = null)
    {
        $this->litcal             = $litcal;
        $this->national_calendars = $national_calendars;
        $this->metadata           = $metadata;

        if (null !== $i18n) {
            $this->validateTranslations($i18n);
            $this->i18n = Translations::fromObject($i18n);
        }
    }


    /**
     * Creates an instance from an associative array.
     *
     * Validates the structure of the provided array to ensure that it contains
     * the required keys: 'litcal', 'national_calendars', and 'metadata'. Each
     * of these keys must map to a non-empty array. If any key is missing or
     * does not meet the criteria, an appropriate error is thrown.
     *
     * @param array{litcal:LiturgicalEventItem[],national_calendars:array<string,string>,metadata:array{locales:string[],wider_region:string},i18n?:\stdClass} $data The associative array containing the data for the
     *                     WiderRegionData instance. It must include:
     *                     - 'litcal': An array representing liturgical calendar items.
     *                     - 'national_calendars': An associative array mapping national calendar identifiers to their names.
     *                     - 'metadata': An array containing metadata information.
     *                     - 'i18n' (optional): A translations object.
     *
     * @return static A new instance of WiderRegionData initialized with the
     *                provided data.
     *
     * @throws \ValueError If any of the required keys ('litcal', 'national_calendars', 'metadata') are not present.
     * @throws \TypeError If 'litcal', 'national_calendars', or 'metadata' are not arrays or are empty.
     */
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

    /**
     * Creates an instance of WiderRegionData from an object.
     *
     * The object should have the following properties:
     * - litcal (array): The liturgical calendar items.
     * - national_calendars (array): The national calendars, where the key is the identifier and the value is the name.
     * - metadata (object): The metadata for the wider region, with locales and wider_region properties.
     * - i18n (object|null): The translations for the wider region. If not provided, it will default to null.
     *
     * @param \stdClass $data The object containing the properties of the wider region.
     * @return static A new instance of WiderRegionData initialized with the provided data.
     *
     * @throws \ValueError If any of the required keys ('litcal', 'national_calendars', 'metadata') are not present.
     * @throws \TypeError If 'litcal', 'national_calendars', or 'metadata' are not of the expected type or are empty.
     */
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
        if (0 === count($nationalCalendarsArray)) {
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
