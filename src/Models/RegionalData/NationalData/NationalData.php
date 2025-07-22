<?php

namespace LiturgicalCalendar\Api\Models\RegionalData\NationalData;

use LiturgicalCalendar\Api\Models\AbstractJsonSrcData;
use LiturgicalCalendar\Api\Models\Metadata\MetadataNationalCalendarSettings;
use LiturgicalCalendar\Api\Models\RegionalData\LitCalItemCollection;
use LiturgicalCalendar\Api\Models\RegionalData\Translations;

class NationalData extends AbstractJsonSrcData
{
    public readonly Translations $i18n;

    public readonly LitCalItemCollection $litcal;

    public readonly MetadataNationalCalendarSettings $settings;

    public readonly LitCalItemMetadata $metadata;

    private const ALLOWED_PROPS = ['i18n', 'litcal', 'metadata', 'settings'];

    public function __construct(LitCalItemCollection $litcal, MetadataNationalCalendarSettings $settings, LitCalItemMetadata $metadata, ?\stdClass $i18n)
    {
        $this->litcal   = $litcal;
        $this->settings = $settings;
        $this->metadata = $metadata;

        if (null !== $i18n) {
            $this->loadTranslations($i18n);
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
     * - settings (\stdClass): The settings for the national calendar.
     * - metadata (\stdClass): The metadata for the national calendar.
     * - i18n (\stdClass|null): The translations for the national calendar.
     *
     * @param array<string, mixed> $data
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
            MetadataNationalCalendarSettings::fromarray($data['settings']),
            LitCalItemMetadata::fromArray($data['metadata']),
            $data['i18n'] ?? null
        );
    }

    /**
     * Creates an instance of NationalData from a stdClass object.
     *
     * The object should have the following properties:
     * - litcal (array): The liturgical calendar items.
     * - settings (\stdClass): The settings for the national calendar.
     * - metadata (\stdClass): The metadata for the national calendar.
     * - i18n (\stdClass|null): The translations for the national calendar.
     *
     * @param \stdClass $data The stdClass object containing the properties of the national calendar.
     * @return static
     */
    protected static function fromObjectInternal(\stdClass $data): static
    {
        return new static(
            LitCalItemCollection::fromArray($data->litcal),
            MetadataNationalCalendarSettings::fromObject($data->settings),
            LitCalItemMetadata::fromObject($data->metadata),
            $data->i18n ?? null
        );
    }

    public function hasWiderRegion(): bool
    {
        return property_exists($this->metadata, 'wider_region');
    }
}
