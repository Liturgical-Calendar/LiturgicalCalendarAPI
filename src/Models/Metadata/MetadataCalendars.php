<?php

namespace LiturgicalCalendar\Api\Models\Metadata;

use LiturgicalCalendar\Api\Models\AbstractJsonRepresentation;

/**
 * @phpstan-type MetadataCalendarsObject \stdClass&object{
 *     national_calendars:array<\stdClass&object{
 *         calendar_id:string,
 *         locales:string[],
 *         missals:string[],
 *         settings:\stdClass&object{epiphany:string,ascension:string,corpus_christi:string,eternal_high_priest?:bool},
 *         wider_region?:string,
 *         dioceses?:string[]
 *     }>,
 *     national_calendars_keys:string[],
 *     diocesan_calendars:array<\stdClass&object{
 *         calendar_id:string,
 *         diocese:string,
 *         nation:string,
 *         locales:string[],
 *         timezone:string,
 *         group?:string,
 *         settings?:\stdClass&object{epiphany?:string,ascension?:string,corpus_christi?:string,eternal_high_priest?:bool}
 *     }>,
 *     diocesan_calendars_keys:string[],
 *     diocesan_groups:array<\stdClass&object{
 *         group_name:string,
 *         dioceses:string[]
 *     }>,
 *     wider_regions:array<\stdClass&object{
 *         name:string,
 *         locales:string[],
 *         api_path:string
 *     }>,
 *     wider_regions_keys:string[],
 *     locales:string[]
 * }
 *
 * @phpstan-type MetadataCalendarsArray array{
 *     national_calendars:array<array{
 *         calendar_id:string,
 *         locales:string[],
 *         missals:string[],
 *         settings:array{epiphany:string,ascension:string,corpus_christi:string,eternal_high_priest?:bool},
 *         wider_region?:string,
 *         dioceses?:string[]
 *     }>,
 *     national_calendars_keys:string[],
 *     diocesan_calendars:array<array{
 *         calendar_id:string,
 *         diocese:string,
 *         nation:string,
 *         locales:string[],
 *         timezone:string,
 *         group?:string,
 *         settings?:array{epiphany?:string,ascension?:string,corpus_christi?:string,eternal_high_priest?:bool}
 *     }>,
 *     diocesan_calendars_keys:string[],
 *     diocesan_groups:array<array{
 *         group_name:string,
 *         dioceses:string[]
 *     }>,
 *     wider_regions:array<array{
 *         name:string,
 *         locales:string[],
 *         api_path:string
 *     }>,
 *     wider_regions_keys:string[],
 *     locales:string[]
 * }
 */
final class MetadataCalendars extends AbstractJsonRepresentation
{
    /** @var MetadataNationalCalendarItem[] */
    public array $national_calendars;

    /** @var string[] */
    public array $national_calendars_keys;

    /** @var MetadataDiocesanCalendarItem[] */
    public array $diocesan_calendars;

    /** @var string[] */
    public array $diocesan_calendars_keys;

    /** @var MetadataDiocesanGroupItem[] */
    public array $diocesan_groups;

    /** @var MetadataWiderRegionItem[] */
    public array $wider_regions;

    /** @var string[] */
    public array $wider_regions_keys;

    /** @var string[] */
    public array $locales;

    /**
     * Constructs a MetadataCalendars object.
     *
     * @param MetadataNationalCalendarItem[] $national_calendars National calendars, each serialized to an array.
     * @param string[] $national_calendars_keys The keys for the national calendars.
     * @param MetadataDiocesanCalendarItem[] $diocesan_calendars Diocesan calendars, each serialized to an array.
     * @param string[] $diocesan_calendars_keys The keys for the diocesan calendars.
     * @param MetadataDiocesanGroupItem[] $diocesan_groups Diocesan groups, each serialized to an array.
     * @param MetadataWiderRegionItem[] $wider_regions Wider regions, each serialized to an array.
     * @param string[] $wider_regions_keys The keys for the wider regions.
     * @param string[] $locales The locales supported by the national calendars.
     */
    public function __construct(
        array $national_calendars      = [],
        array $national_calendars_keys = [],
        array $diocesan_calendars      = [],
        array $diocesan_calendars_keys = [],
        array $diocesan_groups         = [],
        array $wider_regions           = [],
        array $wider_regions_keys      = [],
        array $locales                 = []
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
     * @return MetadataCalendarsArray The associative array containing the metadata or index of all available calendars (whether General Roman, national, or diocesan), diocesan groups, wider regions, and locales.
     *
     */
    public function jsonSerialize(): array
    {
        return [
            'national_calendars'      => array_map(
                /** @return array{calendar_id:string,locales:string[],missals:string[],settings:array{epiphany:string,ascension:string,corpus_christi:string,eternal_high_priest:bool},wider_region?:string,dioceses?:string[]} */
                function (MetadataNationalCalendarItem $nc): array {
                    return $nc->jsonSerialize();
                },
                $this->national_calendars
            ),
            'national_calendars_keys' => $this->national_calendars_keys,
            'diocesan_calendars'      => array_map(
                /** @return array{calendar_id:string,diocese:string,nation:string,locales:string[],timezone:string,group?:string,settings?:array{epiphany?:string,ascension?:string,corpus_christi?:string,eternal_high_priest?:bool}} */
                function (MetadataDiocesanCalendarItem $dc): array {
                    return $dc->jsonSerialize();
                },
                $this->diocesan_calendars
            ),
            'diocesan_calendars_keys' => $this->diocesan_calendars_keys,
            'diocesan_groups'         => array_map(
                /** @return array{group_name:string,dioceses:string[]} */
                function (MetadataDiocesanGroupItem $dg): array {
                    return $dg->jsonSerialize();
                },
                $this->diocesan_groups
            ),
            'wider_regions'           => array_map(
                /** @return array{name:string,locales:string[],api_path:string} */
                function (MetadataWiderRegionItem $wr): array {
                    return $wr->jsonSerialize();
                },
                $this->wider_regions
            ),
            'wider_regions_keys'      => $this->wider_regions_keys,
            'locales'                 => $this->locales
        ];
    }

    /**
     * Creates an instance of MetadataCalendars from an associative array.
     *
     * The array should have the following keys:
     * - national_calendars (array): An array of associative arrays that can be used to create MetadataNationalCalendarItem objects.
     * - national_calendars_keys (string[]): An array of strings that are the keys of the national calendars.
     * - diocesan_calendars (array): An array of associative arrays that can be used to create MetadataDiocesanCalendarItem objects.
     * - diocesan_calendars_keys (string[]): An array of strings that are the keys of the diocesan calendars.
     * - diocesan_groups (array): An array of associative arrays that can be used to create MetadataDiocesanGroupItem objects.
     * - wider_regions (array): An array of associative arrays that can be used to create MetadataWiderRegionItem objects.
     * - wider_regions_keys (string[]): An array of strings that are the keys of the wider regions.
     * - locales (string[]): An array of strings that are the locales supported by this set of calendars.
     *
     * @param MetadataCalendarsArray $data The associative array containing the metadata or index of all available calendars (whether General Roman, national, or diocesan), diocesan groups, wider regions, and locales.
     * @return static
     */
    protected static function fromArrayInternal(array $data): static
    {
        return new static(
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

    /**
     * Creates an instance of CalendarsMetadata from an object.
     *
     * The object should have the following properties:
     * - national_calendars: The national calendars, each serialized to an object.
     * - national_calendars_keys: The keys for the national calendars.
     * - diocesan_calendars: The diocesan calendars, each serialized to an object.
     * - diocesan_calendars_keys: The keys for the diocesan calendars.
     * - diocesan_groups: The diocesan groups, each serialized to an object.
     * - wider_regions: The wider regions, each serialized to an object.
     * - wider_regions_keys: The keys for the wider regions.
     * - locales: The locales supported by the national calendars.
     *
     * @param MetadataCalendarsObject $data The \stdClass object containing the metadata or index of all available calendars (whether General Roman, national, or diocesan), diocesan groups, wider regions, and locales.
     * @return static
     */
    protected static function fromObjectInternal(\stdClass $data): static
    {
        return new static(
            array_map([MetadataNationalCalendarItem::class, 'fromObject'], $data->national_calendars),
            $data->national_calendars_keys,
            array_map([MetadataDiocesanCalendarItem::class, 'fromObject'], $data->diocesan_calendars),
            $data->diocesan_calendars_keys,
            array_map([MetadataDiocesanGroupItem::class, 'fromObject'], $data->diocesan_groups),
            array_map([MetadataWiderRegionItem::class, 'fromObject'], $data->wider_regions),
            $data->wider_regions_keys,
            $data->locales
        );
    }

    /**
     * Adds a national calendar metadata item to the collection.
     *
     * This function appends a given MetadataNationalCalendarItem to the list of
     * national calendars and its corresponding calendar_id to the list of national
     * calendar keys.
     *
     * @param MetadataNationalCalendarItem $metadata The national calendar metadata
     *                                                item to add.
     */
    public function pushNationalCalendarMetadata(MetadataNationalCalendarItem $metadata): void
    {
        $this->national_calendars[]      = $metadata;
        $this->national_calendars_keys[] = $metadata->calendar_id;
    }

    /**
     * Adds a diocesan calendar metadata item to the collection.
     *
     * This function appends a given MetadataDiocesanCalendarItem to the list of
     * diocesan calendars and its corresponding calendar_id to the list of diocesan
     * calendar keys.
     *
     * If the MetadataDiocesanCalendarItem has a "group" property, it adds the
     * calendar_id to the group of dioceses that it belongs to.
     *
     * @param MetadataDiocesanCalendarItem $metadata The diocesan calendar metadata
     *                                                item to add.
     */
    public function pushDiocesanCalendarMetadata(MetadataDiocesanCalendarItem $metadata): void
    {
        $this->diocesan_calendars[]      = $metadata;
        $this->diocesan_calendars_keys[] = $metadata->calendar_id;
        if ($metadata->group !== null) {
            $diocesanGroup = array_find($this->diocesan_groups, fn ($el) => $el->group_name === $metadata->group);
            if ($diocesanGroup === null) {
                $this->diocesan_groups[] = new MetadataDiocesanGroupItem($metadata->group, [$metadata->calendar_id]);
            } else {
                $diocesanGroup->dioceses[] = $metadata->calendar_id;
            }
        }
        // Push the diocese to the nation that it belongs to
        foreach ($this->national_calendars as $calendar) {
            if ($calendar->calendar_id === $metadata->nation) {
                $calendar->dioceses[] = $metadata->calendar_id;
                break;
            }
        }
    }

    public function pushWiderRegionMetadata(MetadataWiderRegionItem $metadata): void
    {
        $this->wider_regions[]      = $metadata;
        $this->wider_regions_keys[] = $metadata->name;
    }
}
