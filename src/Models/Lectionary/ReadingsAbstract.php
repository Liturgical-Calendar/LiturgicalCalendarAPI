<?php

namespace LiturgicalCalendar\Api\Models\Lectionary;

use LiturgicalCalendar\Api\Models\AbstractJsonRepresentation;

abstract class ReadingsAbstract extends AbstractJsonRepresentation
{
    public readonly string $first_reading;
    public readonly string $responsorial_psalm;
    public readonly string $gospel_acclamation;
    public readonly string $gospel;

    protected function __construct(string $first_reading, string $responsorial_psalm, string $gospel_acclamation, string $gospel)
    {
        $this->first_reading      = $first_reading;
        $this->responsorial_psalm = $responsorial_psalm;
        $this->gospel_acclamation = $gospel_acclamation;
        $this->gospel             = $gospel;
    }

    abstract protected static function fromObjectInternal(\stdClass $data): static;

    abstract protected static function fromArrayInternal(array $data): static;

    /**
     * @return array<string,string>
     */
    abstract public function jsonSerialize(): array;
}
