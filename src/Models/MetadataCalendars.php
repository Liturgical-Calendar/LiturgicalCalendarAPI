<?php

namespace LiturgicalCalendar\Api\Models;

class MetadataCalendars implements \JsonSerializable
{
    /** @var MetadataNationalCalendarItem[] */
    public readonly array $national_calendars;
    /** @var string[] */
    public readonly array $national_calendars_keys;
    /** @var MetadataDiocesanCalendarItem[] */
    public readonly array $diocesan_calendars;
    /** @var string[] */
    public readonly array $diocesan_calendars_keys;
    /** @var MetadataDiocesanGroupItem[] */
    public readonly array $diocesan_groups;
    /** @var MetadataWiderRegionItem[] */
    public readonly array $wider_regions;
    /** @var string[] */
    public readonly array $wider_regions_keys;
    /** @var string[] */
    public readonly array $locales;

    public function __construct(
        array $national_calendars,
        array $national_calendars_keys,
        array $diocesan_calendars,
        array $diocesan_calendars_keys,
        array $diocesan_groups,
        array $wider_regions,
        array $wider_regions_keys,
        array $locales
    ) {
        $this->national_calendars      = $national_calendars;
        $this->national_calendars_keys = $national_calendars_keys;
        $this->diocesan_calendars      = $diocesan_calendars;
        $this->diocesan_calendars_keys = $diocesan_calendars_keys;
        $this->diocesan_groups         = $diocesan_groups;
        $this->wider_regions           = $wider_regions;
        $this->wider_regions_keys      = $wider_regions_keys;
        $this->locales                 = $locales;
    }


    /**
     * Converts the object to an array that can be json encoded, containing the following keys:
     *
     * - national_calendars: The national calendars, each serialized to an array.
     * - national_calendars_keys: The keys for the national calendars.
     * - diocesan_calendars: The diocesan calendars, each serialized to an array.
     * - diocesan_calendars_keys: The keys for the diocesan calendars.
     * - diocesan_groups: The diocesan groups.
     * - wider_regions: The wider regions, each serialized to an array.
     * - wider_regions_keys: The keys for the wider regions.
     * - locales: The locales supported by the national calendars.
     *
     * @return array{national_calendars:array<NationalCalendarMetadataItem>,national_calendars_keys:string[],diocesan_calendars:array<DiocesanCalendarMetadataItem>,diocesan_calendars_keys:string[],diocesan_groups:array<string>,wider_regions:array<WiderRegionMetadataItem>,wider_regions_keys:string[],locales:string[]} The associative array containing the national and diocesan calendars.
     */
    public function jsonSerialize(): array
    {
        return [
            'national_calendars'      => array_map(fn($nc) => $nc->jsonSerialize(), $this->national_calendars),
            'national_calendars_keys' => $this->national_calendars_keys,
            'diocesan_calendars'      => array_map(fn($dc) => $dc->jsonSerialize(), $this->diocesan_calendars),
            'diocesan_calendars_keys' => $this->diocesan_calendars_keys,
            'diocesan_groups'         => $this->diocesan_groups,
            'wider_regions'           => array_map(fn($wr) => $wr->jsonSerialize(), $this->wider_regions),
            'wider_regions_keys'      => $this->wider_regions_keys,
            'locales'                 => $this->locales
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            array_map([MetadataNationalCalendarItem::class, 'fromArray'], $data['national_calendars']),
            $data['national_calendars_keys'],
            array_map([MetadataDiocesanCalendarItem::class, 'fromArray'], $data['diocesan_calendars']),
            $data['diocesan_calendars_keys'],
            array_map([MetadataDiocesanGroupItem::class, 'fromArray'], $data['diocesan_groups']),
            array_map([MetadataWiderRegionItem::class, 'fromArray'], $data['wider_regions']),
            $data['wider_regions_keys'],
            $data['locales']
        );
    }

    public static function fromObject(\stdClass $data): self
    {
        return new self(
            array_map([MetadataNationalCalendarItem::class, 'fromObject'], $data->national_calendars),
            $data['national_calendars_keys'],
            array_map([MetadataDiocesanCalendarItem::class, 'fromObject'], $data->diocesan_calendars),
            $data['diocesan_calendars_keys'],
            array_map([MetadataDiocesanGroupItem::class, 'fromObject'], $data->diocesan_groups),
            array_map([MetadataWiderRegionItem::class, 'fromObject'], $data->wider_regions),
            $data['wider_regions_keys'],
            $data['locales']
        );
    }
}
