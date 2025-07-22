<?php

namespace LiturgicalCalendar\Api\Models\RegionalData\DiocesanData;

use LiturgicalCalendar\Api\Models\AbstractJsonSrcData;
use LiturgicalCalendar\Api\Models\Metadata\MetadataDiocesanCalendarSettings;
use LiturgicalCalendar\Api\Models\RegionalData\Translations;

final class DiocesanData extends AbstractJsonSrcData
{
    public readonly DiocesanLitCalItemCollection $litcal;

    public readonly DiocesanMetadata $metadata;

    public readonly ?MetadataDiocesanCalendarSettings $settings;

    public readonly ?Translations $i18n;

    public function __construct(
        DiocesanLitCalItemCollection $litcal,
        DiocesanMetadata $metadata,
        ?MetadataDiocesanCalendarSettings $settings = null,
        ?\stdClass $i18n = null
    ) {
        $this->litcal   = $litcal;
        $this->metadata = $metadata;
        $this->settings = $settings;
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
            $litcalItem->setName($translations[$litcalItem->getEventKey()] ?? null);
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
     * @param array{litcal:array,metadata:array,settings:array|null,i18n:array|null} $data
     * @return static
     */
    protected static function fromArrayInternal(array $data): static
    {
        return new static(
            DiocesanLitCalItemCollection::fromArray($data['litcal']),
            DiocesanMetadata::fromArray($data['metadata']),
            null !== $data['settings'] ? MetadataDiocesanCalendarSettings::fromArray($data['settings']) : null,
            $data['i18n'] ?? null
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
            DiocesanLitCalItemCollection::fromArray($data->litcal),
            DiocesanMetadata::fromObject($data->metadata),
            property_exists($data, 'settings') && is_object($data->settings) ? MetadataDiocesanCalendarSettings::fromObject($data->settings) : null,
            $data->i18n ?? null
        );
    }

    public function hasSettings(): bool
    {
        return null !== $this->settings;
    }
}
