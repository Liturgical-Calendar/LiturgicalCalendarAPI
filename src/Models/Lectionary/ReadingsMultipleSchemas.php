<?php

namespace LiturgicalCalendar\Api\Models\Lectionary;

/**
 * Lectionary readings where multiple schemas are available
 *
 * An example of this is the Commemoration of the Faithful Departed on November 2nd
 * (event_key: 'AllSouls').
 *
 * @phpstan-import-type ReadingsFestiveArray from ReadingsMap
 * @phpstan-import-type ReadingsMultipleSchemasArray from ReadingsMap
 */
final class ReadingsMultipleSchemas implements \JsonSerializable
{
    public readonly ReadingsFestive $schema_one;
    public readonly ReadingsFestive $schema_two;
    public readonly ReadingsFestive $schema_three;

    private function __construct(ReadingsFestive $schema_one, ReadingsFestive $schema_two, ReadingsFestive $schema_three)
    {
        $this->schema_one   = $schema_one;
        $this->schema_two   = $schema_two;
        $this->schema_three = $schema_three;
    }

    /**
     * @param ReadingsMultipleSchemasArray $readings
     */
    public static function fromArray(array $readings): self
    {
        return new self(
            ReadingsFestive::fromArray($readings['schema_one']),
            ReadingsFestive::fromArray($readings['schema_two']),
            ReadingsFestive::fromArray($readings['schema_three'])
        );
    }

    /**
     * {@inheritDoc}
     *
     * Returns an associative array containing the properties of this object,
     * with the following keys:
     * - schema_one (array): The first schema for a multiple schemas day
     * - schema_two (array): The second schema for a multiple schemas day
     * - schema_three (array): The third schema for a multiple schemas day
     * @return array{schema_one:ReadingsFestiveArray,schema_two:ReadingsFestiveArray,schema_three:ReadingsFestiveArray}
     */
    public function jsonSerialize(): array
    {
        return [
            'schema_one'   => $this->schema_one->jsonSerialize(),
            'schema_two'   => $this->schema_two->jsonSerialize(),
            'schema_three' => $this->schema_three->jsonSerialize()
        ];
    }
}
