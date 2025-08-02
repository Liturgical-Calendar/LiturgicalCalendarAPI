<?php

namespace LiturgicalCalendar\Api\Models\Lectionary;

use LiturgicalCalendar\Api\Models\AbstractJsonRepresentation;

abstract class ReadingsAbstract extends AbstractJsonRepresentation
{
    public readonly string $first_reading;
    public readonly string $responsorial_psalm;
    public readonly string $alleluia_verse;
    public readonly string $gospel;

    protected function __construct(string $first_reading, string $responsorial_psalm, string $alleluia_verse, string $gospel)
    {
        $this->first_reading      = $first_reading;
        $this->responsorial_psalm = $responsorial_psalm;
        $this->alleluia_verse     = $alleluia_verse;
        $this->gospel             = $gospel;
    }

    abstract protected static function fromObjectInternal(\stdClass $data): static;

    abstract protected static function fromArrayInternal(array $data): static;

    /**
     * @return array<string,string>
     */
    abstract public function jsonSerialize(): array;
}
