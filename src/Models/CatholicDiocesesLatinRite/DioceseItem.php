<?php

namespace LiturgicalCalendar\Api\Models\CatholicDiocesesLatinRite;

use LiturgicalCalendar\Api\Models\AbstractJsonSrcData;

final class DioceseItem extends AbstractJsonSrcData
{
    public readonly string $diocese_name;
    public readonly string $diocese_id;
    public readonly ?string $province;

    private function __construct(
        string $diocese_name,
        string $diocese_id,
        ?string $province = null
    ) {
        $this->diocese_name = $diocese_name;
        $this->diocese_id   = $diocese_id;
        $this->province     = $province;
    }

    /**
     * Creates a new DioceseItem from an associative array.
     *
     * The array must have the following keys:
     * - diocese_name (string): The name of the diocese.
     * - diocese_id (string): The unique identifier for the diocese.
     *
     * Optional keys:
     * - province (string|null): The ecclesiastical province that the diocese belongs to, if applicable.
     *
     * @param array{diocese_name:string,diocese_id:string,province?:string|null} $data The associative array containing the properties of the class.
     * @return static A new instance of the class.
     */
    protected static function fromArrayInternal(array $data): static
    {
        return new static(
            $data['diocese_name'],
            $data['diocese_id'],
            array_key_exists('province', $data) ? $data['province'] : null
        );
    }

    /**
     * Creates a new DioceseItem from an object.
     *
     * The object should have the following properties:
     * - diocese_name (string): The name of the diocese.
     * - diocese_id (string): The unique identifier for the diocese.
     * - province (string|null): The ecclesiastical province that the diocese belongs to, if applicable.
     *
     * @param \stdClass&object{diocese_name:string,diocese_id:string,province?:string|null} $data The object containing the properties of the class.
     * @return static A new instance of the class.
     */
    protected static function fromObjectInternal(\stdClass $data): static
    {
        return new static(
            $data->diocese_name,
            $data->diocese_id,
            property_exists($data, 'province') ? $data->province : null
        );
    }
}
