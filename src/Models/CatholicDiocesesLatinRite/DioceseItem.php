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

    protected static function fromArrayInternal(array $data): static
    {
        return new static(
            $data['diocese_name'],
            $data['diocese_id'],
            array_key_exists('province', $data) ? $data['province'] : null
        );
    }

    protected static function fromObjectInternal(\stdClass $data): static
    {
        return new static(
            $data->diocese_name,
            $data->diocese_id,
            property_exists($data, 'province') ? $data->province : null
        );
    }
}
