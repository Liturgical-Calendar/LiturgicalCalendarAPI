<?php

namespace LiturgicalCalendar\Api\Models\RegionalData\DiocesanData;

use LiturgicalCalendar\Api\Models\AbstractJsonSrcData;
use LiturgicalCalendar\Api\Models\Metadata\MetadataDiocesanCalendarSettings;
use LiturgicalCalendar\Api\Models\RegionalData\Translations;

/**
 * @phpstan-import-type DiocesanLitCalItemArray from DiocesanLitCalItem
 * @phpstan-import-type DiocesanLitCalItemObject from DiocesanLitCalItem
 * @phpstan-import-type DiocesanCalendarSettingsObject from \LiturgicalCalendar\Api\Models\Metadata\MetadataDiocesanCalendarSettings
 * @phpstan-import-type DiocesanCalendarSettingsArray from \LiturgicalCalendar\Api\Models\Metadata\MetadataDiocesanCalendarSettings
 * @phpstan-import-type DiocesanMetadataObject from DiocesanMetadata
 * @phpstan-import-type DiocesanMetadataArray from DiocesanMetadata
 * @phpstan-import-type TranslationMapObject from \LiturgicalCalendar\Api\Models\RegionalData\TranslationMap
 * @phpstan-import-type TranslationObject from \LiturgicalCalendar\Api\Models\RegionalData\Translations
 * @phpstan-type I18nObject \stdClass&object<string,\stdClass&object<string,string>>
 * @phpstan-type I18nArray array<string,array<string,string>>
 * @phpstan-type DiocesanCalendarDataArray array{
 *      litcal:DiocesanLitCalItemArray[],
 *      metadata:DiocesanMetadataArray,
 *      settings?:DiocesanCalendarSettingsArray,
 *      i18n?:I18nArray
 * }
 * @phpstan-type DiocesanCalendarDataObject \stdClass&object{
 *      litcal:DiocesanLitCalItemObject[],
 *      metadata:DiocesanMetadataObject,
 *      settings?:DiocesanCalendarSettingsObject,
 *      i18n?:I18nObject
 * }
 */
final class DiocesanData extends AbstractJsonSrcData
{
    public readonly DiocesanLitCalItemCollection $litcal;

    public readonly DiocesanMetadata $metadata;

    public readonly ?MetadataDiocesanCalendarSettings $settings;

    public Translations $i18n;

    private function __construct(
        DiocesanLitCalItemCollection $litcal,
        DiocesanMetadata $metadata,
        ?MetadataDiocesanCalendarSettings $settings = null,
        ?\stdClass $i18n = null
    ) {
        $this->litcal   = $litcal;
        $this->metadata = $metadata;
        $this->settings = $settings;

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
     * @param TranslationMapObject $i18n The object containing the translations to apply.
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
     * @param TranslationMapObject $i18n The translations object whose keys need to be validated.
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
            $name = $this->i18n->getTranslation($litcalItem->getEventKey(), $locale);
            if (null === $name) {
                throw new \ValueError('translation not found for event key: ' . $litcalItem->getEventKey());
            }
            $litcalItem->setName($name);
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
     * @param array<string,string|null> $translations The translations to use for setting the names.
     */
    public function setNames(array $translations): void
    {
        foreach ($this->litcal as $litcalItem) {
            $name = $translations[$litcalItem->getEventKey()] ?? null;
            if (null === $name) {
                throw new \ValueError('translation not found for event key: ' . $litcalItem->getEventKey());
            }
            $litcalItem->setName($name);
        }
    }

    /**
     * Creates an instance of DiocesanData from an associative array.
     *
     * The array must have the following keys:
     * - litcal (array): The liturgical calendar items.
     * - metadata (array): The metadata for the diocesan calendar.
     * - settings (array|null): The settings for the diocesan calendar.
     * - i18n (array|null): The translations for the diocesan calendar.
     *
     * @param DiocesanCalendarDataArray $data
     * @return static
     */
    protected static function fromArrayInternal(array $data): static
    {
        $i18n = null;
        if (isset($data['i18n'])) {
            /** @var \stdClass $i18n Deep cast nested arrays to stdClass for Translations::fromObject() */
            $i18n = json_decode(json_encode($data['i18n'], JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
        }

        return new static(
            DiocesanLitCalItemCollection::fromArray($data['litcal']),
            DiocesanMetadata::fromArray($data['metadata']),
            isset($data['settings']) ? MetadataDiocesanCalendarSettings::fromArray($data['settings']) : null,
            $i18n
        );
    }

    /**
     * Creates an instance of DiocesanData from a stdClass object.
     *
     * The object should have the following properties:
     * - litcal (array): The liturgical calendar items.
     * - metadata (\stdClass): The metadata for the diocesan calendar.
     * - settings (\stdClass|null): The settings for the diocesan calendar.
     * - i18n (\stdClass|null): The translations for the diocesan calendar.
     *
     * @param DiocesanCalendarDataObject $data The stdClass object containing the properties of the diocesan calendar.
     * @return static
     */
    protected static function fromObjectInternal(\stdClass $data): static
    {
        return new static(
            DiocesanLitCalItemCollection::fromObject($data->litcal),
            DiocesanMetadata::fromObject($data->metadata),
            property_exists($data, 'settings') && $data->settings instanceof \stdClass ? MetadataDiocesanCalendarSettings::fromObject($data->settings) : null,
            isset($data->i18n) ? $data->i18n : null
        );
    }

    public function hasSettings(): bool
    {
        return null !== $this->settings;
    }
}
