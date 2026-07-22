<?php

namespace LiturgicalCalendar\Api\Models\Metadata;

use LiturgicalCalendar\Api\Models\AbstractJsonRepresentation;

/**
 * Metadata for a non-Roman-rite comune calendar (currently only the
 * Ambrosian rite's `/calendar/ambrosian` comune).
 *
 * Unlike {@see MetadataNationalCalendarItem} and
 * {@see MetadataDiocesanCalendarItem} (which are always implicitly Roman
 * rite), items in this collection carry an explicit `rite` value because
 * they exist specifically to represent a non-Roman rite's calendar.
 *
 * @see \LiturgicalCalendar\Api\Enum\Rite
 */
final class MetadataAmbrosianCalendarItem extends AbstractJsonRepresentation
{
    public string $calendar_id;

    public string $rite;

    /** @var string[] */
    public array $locales;

    /**
     * Initializes a MetadataAmbrosianCalendarItem object.
     *
     * @param string $calendar_id The path segment identifying the comune calendar (e.g. `ambrosian`, used as `/calendar/{$calendar_id}`).
     * @param string $rite The {@see \LiturgicalCalendar\Api\Enum\Rite} value this comune calendar is computed under.
     * @param string[] $locales The locales supported by the comune calendar.
     */
    public function __construct(
        string $calendar_id,
        string $rite,
        array $locales
    ) {
        $this->calendar_id = $calendar_id;
        $this->rite        = $rite;
        $this->locales     = $locales;
    }

    /**
     * Converts the MetadataAmbrosianCalendarItem object to an associative array
     * for JSON serialization.
     *
     * The array contains the following keys:
     * - calendar_id: The path segment identifying the comune calendar.
     * - rite: The rite this comune calendar is computed under.
     * - locales: An array of locales supported by the comune calendar.
     *
     * @return array{calendar_id:string,rite:string,locales:string[]} The associative array representation of the object.
     */
    public function jsonSerialize(): array
    {
        return [
            'calendar_id' => $this->calendar_id,
            'rite'        => $this->rite,
            'locales'     => $this->locales,
        ];
    }

    /**
     * Creates an instance of MetadataAmbrosianCalendarItem from an associative array.
     *
     * The array must have the following keys:
     * - calendar_id (string): The path segment identifying the comune calendar.
     * - rite (string): The rite this comune calendar is computed under.
     * - locales (string[]): The locales supported by the comune calendar.
     *
     * @param array{calendar_id:string,rite:string,locales:string[]} $data
     * @return static
     */
    protected static function fromArrayInternal(array $data): static
    {
        return new static(
            $data['calendar_id'],
            $data['rite'],
            $data['locales']
        );
    }

    /**
     * Creates an instance of MetadataAmbrosianCalendarItem from a stdClass object.
     *
     * The object should have the following properties:
     * - calendar_id (string): The path segment identifying the comune calendar.
     * - rite (string): The rite this comune calendar is computed under.
     * - locales (string[]): The locales supported by the comune calendar.
     *
     * @param \stdClass&object{calendar_id:string,rite:string,locales:string[]} $data
     * @return static
     */
    protected static function fromObjectInternal(\stdClass $data): static
    {
        return new static(
            $data->calendar_id,
            $data->rite,
            $data->locales
        );
    }
}
