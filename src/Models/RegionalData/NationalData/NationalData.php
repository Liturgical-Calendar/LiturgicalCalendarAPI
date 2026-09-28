<?php

namespace LiturgicalCalendar\Api\Models\RegionalData\NationalData;

use LiturgicalCalendar\Api\Models\AbstractJsonSrcData;
use LiturgicalCalendar\Api\Models\Metadata\MetadataNationalCalendarSettings;
use LiturgicalCalendar\Api\Models\LitCalItemCollection;
use LiturgicalCalendar\Api\Models\RegionalData\Translations;

/**
 * @phpstan-import-type NationalCalendarSettingsObject from \LiturgicalCalendar\Api\Models\Metadata\MetadataNationalCalendarSettings
 * @phpstan-import-type NationalCalendarSettingsArray from \LiturgicalCalendar\Api\Models\Metadata\MetadataNationalCalendarSettings
 * @phpstan-import-type NationalMetadataObject from NationalMetadata
 * @phpstan-import-type NationalMetadataArray from NationalMetadata
 * @phpstan-import-type LitCalItemObject from \LiturgicalCalendar\Api\Models\LitCalItem
 * @phpstan-import-type LitCalItemArray from \LiturgicalCalendar\Api\Models\LitCalItem
 * @phpstan-type I18nObject \stdClass&object<string,\stdClass&object<string,string>>
 * @phpstan-type I18nArray array<string,array<string,string>>
 * @phpstan-type NationalCalendarDataObject \stdClass&object{
 *      litcal:LitCalItemObject[],
 *      settings:NationalCalendarSettingsObject,
 *      metadata:NationalMetadataObject,
 *      i18n?:I18nObject
 * }
 * @phpstan-type NationalCalendarDataArray array{
 *      litcal:LitCalItemArray[],
 *      settings:NationalCalendarSettingsArray,
 *      metadata:NationalMetadataArray,
 *      i18n?:I18nArray
 * }
 */
final class NationalData extends AbstractJsonSrcData
{
    public readonly LitCalItemCollection $litcal;

    public readonly MetadataNationalCalendarSettings $settings;

    public readonly NationalMetadata $metadata;

    public Translations $i18n;

    private const REQUIRED_PROPS = ['litcal', 'metadata', 'settings'];

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
     * @param \stdClass&object<string,\stdClass&object<string,string>> $i18n The object containing the translations to apply.
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
     * @param \stdClass&object<string,\stdClass&object<string,string>> $i18n The translations object whose keys need to be validated.
     *
     * @throws \ValueError If the keys of the i18n parameter do not match the values
     *                     of metadata.locales.
     */
    private function validateTranslations(\stdClass $i18n): void
    {
        /** @var string[] $i18nProps */
        $i18nProps = array_keys(get_object_vars($i18n));
        sort($i18nProps);
        // Both sides sorted: `metadata.locales` is stored in whatever order the calendar
        // was authored in (Europe's is not alphabetical), and the check is about the
        // same set of locales, not the same order.
        $declaredLocales = $this->metadata->locales;
        sort($declaredLocales);
        if (implode(',', $i18nProps) !== implode(',', $declaredLocales)) {
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
            $translation = $this->i18n->getTranslation($litcalItem->getEventKey(), $locale);
            if (null === $translation) {
                throw new \ValueError('translation not found for event key: ' . $litcalItem->getEventKey());
            }
            $litcalItem->setName($translation);
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
     * @param array<string,string> $translations The translations to use for setting the names.
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
     * Construct a NationalData instance from an associative array representation.
     *
     * The input array must include the keys: `litcal`, `settings`, and `metadata`. An optional
     * `i18n` key may be provided for translations and will be converted to an stdClass when present.
     *
     * @param NationalCalendarDataArray $data Associative array with required keys `litcal`, `settings`, and `metadata`, and optional `i18n`.
     * @return static A new NationalData populated from the provided array.
     * @throws \ValueError If one or more required properties are missing from `$data`.
     */
    protected static function fromArrayInternal(array $data): static
    {
        self::validateRequiredKeys($data, self::REQUIRED_PROPS);

        $i18n = null;
        if (isset($data['i18n'])) {
            /** @var \stdClass $i18n Deep cast nested arrays to stdClass for Translations::fromObject() */
            $i18n = json_decode(json_encode($data['i18n'], JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
        }

        return new static(
            LitCalItemCollection::fromArray($data['litcal']),
            MetadataNationalCalendarSettings::fromArray($data['settings']),
            NationalMetadata::fromArray($data['metadata']),
            $i18n
        );
    }

    /**
     * Create a NationalData instance from a stdClass representation.
     *
     * Expects the object to contain the properties `litcal`, `settings`, and `metadata`. An optional
     * `i18n` property may be provided for translations.
     *
     * @param NationalCalendarDataObject $data The stdClass containing the national calendar properties.
     * @return static The constructed NationalData instance.
     * @throws \ValueError If one or more required properties are missing.
     */
    protected static function fromObjectInternal(\stdClass $data): static
    {
        self::validateRequiredProps($data, self::REQUIRED_PROPS);

        return new static(
            LitCalItemCollection::fromObject($data->litcal),
            MetadataNationalCalendarSettings::fromObject($data->settings),
            NationalMetadata::fromObject($data->metadata),
            isset($data->i18n) ? $data->i18n : null
        );
    }

    /**
     * Whether the national calendar declares at least one wider region.
     */
    public function hasWiderRegion(): bool
    {
        return $this->metadata->wider_regions !== [];
    }
}
