<?php

namespace LiturgicalCalendar\Api\Models;

class MetadataWiderRegionItem implements \JsonSerializable
{
    public readonly string $name;
    /** @var string[] */
    public readonly array $locales;
    public readonly string $api_path;

    public function __construct(
        string $name,
        array $locales,
        string $api_path
    ) {
        $this->name     = $name;
        $this->locales  = $locales;
        $this->api_path = $api_path;
    }

    public function jsonSerialize(): array
    {
        return [
            'name'     => $this->name,
            'locales'  => $this->locales,
            'api_path' => $this->api_path
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            $data['name'],
            $data['locales'],
            $data['api_path']
        );
    }

    public static function fromObject(\stdClass $data): self
    {
        return new self(
            $data->name,
            $data->locales,
            $data->api_path
        );
    }
}
