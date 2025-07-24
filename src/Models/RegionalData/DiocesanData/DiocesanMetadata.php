<?php

namespace LiturgicalCalendar\Api\Models\RegionalData\DiocesanData;

use LiturgicalCalendar\Api\Enum\LitLocale;
use LiturgicalCalendar\Api\Models\AbstractJsonSrcData;

final class DiocesanMetadata extends AbstractJsonSrcData
{
    /** @var string[] */
    public readonly array $locales;

    public readonly string $nation;

    public readonly string $diocese_id;

    public readonly string $diocese_name;

    public readonly string $timezone;

    /**
     * @param string $nation The nation that the diocese is located in.
     * @param string $diocese_id The unique identifier for the diocese.
     * @param string $diocese_name The name of the diocese.
     * @param array<string> $locales The locales supported by the diocese.
     * @param string $timezone The timezone for the diocese.
     */
    private function __construct(string $nation, string $diocese_id, string $diocese_name, array $locales, string $timezone)
    {
        $this->nation       = $nation;
        $this->diocese_id   = $diocese_id;
        $this->diocese_name = $diocese_name;
        $this->timezone     = $timezone;
        $this->locales      = $locales;
    }


    /**
     * Creates an instance of DiocesanMetadata from an associative array.
     *
     * The array must have the following keys:
     * - nation (string): The nation that the diocese is located in.
     * - diocese_id (string): The unique identifier for the diocese.
     * - diocese_name (string): The name of the diocese.
     * - locales (array<string>): The locales supported by the diocese.
     * - timezone (string): The timezone for the diocese.
     *
     * @param array{
     *      nation: string,
     *      diocese_id: string,
     *      diocese_name: string,
     *      locales: array<string>,
     *      timezone: string
     * } $data
     * @return static
     */
    protected static function fromArrayInternal(array $data): static
    {
        if (!isset($data['locales']) || 0 === count($data['locales'])) {
            throw new \TypeError('locales parameter must be an array and must not be empty');
        }

        if (false === isset($data['nation']) || false === is_string($data['nation'])) {
            throw new \TypeError('nation parameter must set and must be of type string');
        }

        if (false === isset($data['diocese_id']) || false === is_string($data['diocese_id'])) {
            throw new \TypeError('diocese_id parameter must be set and must be of type string');
        }

        if (false === isset($data['diocese_name']) || false === is_string($data['diocese_name'])) {
            throw new \TypeError('diocese_name parameter must be set and must be of type string');
        }

        if (false === isset($data['timezone']) || false === is_string($data['timezone'])) {
            throw new \TypeError('timezone parameter must be set and must be of type string');
        }

        if (false === LitLocale::areValid($data['locales'])) {
            throw new \TypeError('locales parameter must contain valid locale strings supported by the current server: ' . implode(', ', LitLocale::getSupportedLocales()));
        }

        return new static(
            $data['nation'],
            $data['diocese_id'],
            $data['diocese_name'],
            $data['locales'],
            $data['timezone']
        );
    }

    /**
     * Creates an instance of DiocesanMetadata from an object.
     *
     * The object should have the following properties:
     * - nation (string): The nation that the diocese is located in.
     * - diocese_id (string): The unique identifier for the diocese.
     * - diocese_name (string): The name of the diocese.
     * - locales (array<string>): The locales supported by the diocese.
     * - timezone (string): The timezone for the diocese.
     *
     * @param \stdClass $data The object containing the properties of the diocesan calendar.
     * @return static
     */
    protected static function fromObjectInternal(\stdClass $data): static
    {
        if (!isset($data->locales) || 0 === count($data->locales)) {
            throw new \TypeError('locales parameter must be an array and must not be empty');
        }

        if (false === isset($data->nation) || false === is_string($data->nation)) {
            throw new \TypeError('nation parameter must set and must be of type string');
        }

        if (false === isset($data->diocese_id) || false === is_string($data->diocese_id)) {
            throw new \TypeError('diocese_id parameter must be set and must be of type string');
        }

        if (false === isset($data->diocese_name) || false === is_string($data->diocese_name)) {
            throw new \TypeError('diocese_name parameter must be set and must be of type string');
        }

        if (false === isset($data->timezone) || false === is_string($data->timezone)) {
            throw new \TypeError('timezone parameter must be set and must be of type string');
        }

        if (false === LitLocale::areValid($data->locales)) {
            throw new \TypeError('locales parameter must contain valid locale strings supported by the current server: ' . implode(', ', LitLocale::getSupportedLocales()));
        }

        return new static(
            $data->nation,
            $data->diocese_id,
            $data->diocese_name,
            $data->locales,
            $data->timezone
        );
    }
}
