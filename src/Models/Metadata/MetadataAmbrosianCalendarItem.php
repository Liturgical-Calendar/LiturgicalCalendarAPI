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
 * @phpstan-import-type RiteCalendarSettingsArray from MetadataRiteCalendarSettings
 * @phpstan-import-type RiteCalendarSettingsObject from MetadataRiteCalendarSettings
 */
final class MetadataAmbrosianCalendarItem extends AbstractJsonRepresentation
{
    public string $calendar_id;

    public string $rite;

    /** @var string[] */
    public array $locales;

    /**
     * The settings the rite fixes for every calendar computed under it, announced so that a
     * client can discover them without first issuing a calculation request (issue #776).
     *
     * Shaped exactly like `national_calendars[].settings` and `diocesan_calendars[].settings`
     * so clients parse all three tiers with the same code.
     */
    public MetadataRiteCalendarSettings $settings;

    /**
     * Initializes a MetadataAmbrosianCalendarItem object.
     *
     * @param string $calendar_id The path segment identifying the comune calendar (e.g. `ambrosian`, used as `/calendar/{$calendar_id}`).
     * @param string $rite The {@see \LiturgicalCalendar\Api\Enum\Rite} value this comune calendar is computed under.
     * @param string[] $locales The locales supported by the comune calendar.
     * @param MetadataRiteCalendarSettings $settings The calendar settings the rite fixes.
     */
    public function __construct(
        string $calendar_id,
        string $rite,
        array $locales,
        MetadataRiteCalendarSettings $settings
    ) {
        $this->calendar_id = $calendar_id;
        $this->rite        = $rite;
        $this->locales     = $locales;
        $this->settings    = $settings;
    }

    /**
     * Converts the MetadataAmbrosianCalendarItem object to an associative array
     * for JSON serialization.
     *
     * The array contains the following keys:
     * - calendar_id: The path segment identifying the comune calendar.
     * - rite: The rite this comune calendar is computed under.
     * - locales: An array of locales supported by the comune calendar.
     * - settings: The calendar settings the rite fixes.
     *
     * @return array{calendar_id:string,rite:string,locales:string[],settings:RiteCalendarSettingsArray} The associative array representation of the object.
     */
    public function jsonSerialize(): array
    {
        return [
            'calendar_id' => $this->calendar_id,
            'rite'        => $this->rite,
            'locales'     => $this->locales,
            'settings'    => $this->settings->jsonSerialize(),
        ];
    }

    /**
     * Creates an instance of MetadataAmbrosianCalendarItem from an associative array.
     *
     * The array must have the following keys:
     * - calendar_id (string): The path segment identifying the comune calendar.
     * - rite (string): The rite this comune calendar is computed under.
     * - locales (string[]): The locales supported by the comune calendar.
     * - settings (array): The calendar settings the rite fixes.
     *
     * @param array{calendar_id:string,rite:string,locales:string[],settings:RiteCalendarSettingsArray} $data
     * @return static
     */
    protected static function fromArrayInternal(array $data): static
    {
        return new static(
            $data['calendar_id'],
            $data['rite'],
            $data['locales'],
            MetadataRiteCalendarSettings::fromArray($data['settings'])
        );
    }

    /**
     * Creates an instance of MetadataAmbrosianCalendarItem from a stdClass object.
     *
     * The object should have the following properties:
     * - calendar_id (string): The path segment identifying the comune calendar.
     * - rite (string): The rite this comune calendar is computed under.
     * - locales (string[]): The locales supported by the comune calendar.
     * - settings (object): The calendar settings the rite fixes.
     *
     * @param \stdClass&object{calendar_id:string,rite:string,locales:string[],settings:RiteCalendarSettingsObject} $data
     * @return static
     */
    protected static function fromObjectInternal(\stdClass $data): static
    {
        return new static(
            $data->calendar_id,
            $data->rite,
            $data->locales,
            MetadataRiteCalendarSettings::fromObject($data->settings)
        );
    }
}
