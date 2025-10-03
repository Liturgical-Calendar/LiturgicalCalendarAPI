<?php

namespace LiturgicalCalendar\Api\Models\RegionalData\NationalData;

use LiturgicalCalendar\Api\Enum\CalEventAction;
use LiturgicalCalendar\Api\Models\ConditionalRule;
use LiturgicalCalendar\Api\Models\LiturgicalEventMetadata;

/**
 * @phpstan-import-type ConditionalRuleObject from \LiturgicalCalendar\Api\Models\ConditionalRule
 * @phpstan-import-type ConditionalRuleArray from \LiturgicalCalendar\Api\Models\ConditionalRule
 */
final class LitCalItemCreateNewMetadata extends LiturgicalEventMetadata
{
    public readonly CalEventAction $action;

    /** @var ConditionalRule[] */
    public readonly array $rules;

    /**
     * Creates a new LitCalItemCreateNewMetadata object.
     *
     * @param int $since_year The year from which the liturgical event is celebrated.
     * @param int|null $until_year The year until which the liturgical event is celebrated, or null if there is no end year.
     * @param ConditionalRule[] $rules An array of ConditionalRule instances that define conditions for the event.
     */
    private function __construct(int $since_year, ?int $until_year = null, array $rules = [])
    {
        parent::__construct($since_year, $until_year ?? null);
        $this->action = CalEventAction::CreateNew;
        $this->rules  = $rules;
    }

    /**
     * Creates an instance from a StdClass object.
     *
     * @param \stdClass&object{since_year:int,until_year?:int,rules:ConditionalRuleObject[]} $data The StdClass object to create an instance from.
     * It must have the following properties:
     * - since_year (int): The year since when the liturgical event was added.
     * - until_year (int|null): The year until when the liturgical event was added.
     *
     * @return static A new instance created from the given data.
     */
    protected static function fromObjectInternal(\stdClass $data): static
    {

        $rules = [];
        if (property_exists($data, 'rules') && is_array($data->rules)) {
            foreach ($data->rules as $ruleData) {
                $rules[] = ConditionalRule::fromObject($ruleData);
            }
        }

        return new static(
            $data->since_year,
            $data->until_year ?? null,
            $rules
        );
    }

    /**
     * Creates an instance from an associative array.
     *
     * The array must have the following key:
     * - since_year (int): The year since when the liturgical event was added.
     *
     * Optional keys:
     * - until_year (int|null): The year until when the liturgical event was added.
     *
     * @param array{since_year:int,until_year?:int,rules:ConditionalRuleArray[]} $data The associative array containing the properties of the class.
     * @return static A new instance of the class.
     */
    protected static function fromArrayInternal(array $data): static
    {
        $rules = [];
        if (array_key_exists('rules', $data) && is_array($data['rules'])) {
            foreach ($data['rules'] as $ruleData) {
                $rules[] = ConditionalRule::fromArray($ruleData);
            }
        }

        return new static(
            $data['since_year'],
            $data['until_year'] ?? null,
            $rules
        );
    }
}
