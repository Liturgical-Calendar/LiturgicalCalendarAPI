<?php

namespace LiturgicalCalendar\Api\Models\RegionalData\DiocesanData;

use LiturgicalCalendar\Api\Models\AbstractJsonSrcData;
use LiturgicalCalendar\Api\Models\Metadata\MetadataDiocesanCalendarSettings;
use LiturgicalCalendar\Api\Models\RegionalData\Translations;

/**
 * @phpstan-import-type LiturgicalEventItem from \LiturgicalCalendar\Api\Models\LitCalItemCollection
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
     * @param array<string, string|null> $translations The translations to use for setting the names.
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
     * @param array{litcal:LiturgicalEventItem[],metadata:array{diocese_id:string,diocese_name:string,nation:string,locales:string[],timezone:string,group?:string},settings:array<string,string>|null,i18n:array<string,string>|null} $data
     * @return static
     */
    protected static function fromArrayInternal(array $data): static
    {
        /** @var \stdClass|null $i18n */
        $i18n = is_null($data['i18n']) ? null : (object) $data['i18n'];
        return new static(
            DiocesanLitCalItemCollection::fromArray($data['litcal']),
            DiocesanMetadata::fromArray($data['metadata']),
            null !== $data['settings'] ? MetadataDiocesanCalendarSettings::fromArray($data['settings']) : null,
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
     * @param \stdClass $data The stdClass object containing the properties of the diocesan calendar.
     * @return static
     */
    protected static function fromObjectInternal(\stdClass $data): static
    {
        return new static(
            DiocesanLitCalItemCollection::fromObject($data->litcal),
            DiocesanMetadata::fromObject($data->metadata),
            property_exists($data, 'settings') && $data->settings instanceof \stdClass ? MetadataDiocesanCalendarSettings::fromObject($data->settings) : null,
            $data->i18n ?? null
        );
    }

    public function hasSettings(): bool
    {
        return null !== $this->settings;
    }
}
