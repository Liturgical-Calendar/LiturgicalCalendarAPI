<?php

namespace LiturgicalCalendar\Api\Models;

/**
 * Represents a conditional rule for liturgical events
 * @phpstan-type ConditionalRuleConditionObject \stdClass&object{if_weekday?:?string,if_grade?:?int}
 * @phpstan-type ConditionalRuleConditionArray array{if_weekday?:?string,if_grade?:?int}
 * @phpstan-type ConditionalRuleActionObject \stdClass&object{move?:?string,move_to?:?string}
 * @phpstan-type ConditionalRuleActionArray array{move?:?string,move_to?:?string}
 * @phpstan-type ConditionalRuleObject \stdClass&object{condition:ConditionalRuleConditionObject,then:ConditionalRuleActionObject}
 * @phpstan-type ConditionalRuleArray array{condition:ConditionalRuleConditionArray,then:ConditionalRuleActionArray}
 */
final class ConditionalRule extends AbstractJsonSrcData
{
    public readonly ConditionalRuleCondition $condition;
    public readonly ConditionalRuleAction $then;

    private function __construct(ConditionalRuleCondition $condition, ConditionalRuleAction $then)
    {
        $this->condition = $condition;
        $this->then      = $then;
    }

    /**
     * @param ConditionalRuleObject $data
     * @return static
     */
    protected static function fromObjectInternal(\stdClass $data): static
    {
        if (!property_exists($data, 'condition') || !property_exists($data, 'then')) {
            throw new \InvalidArgumentException('ConditionalRule must have both `condition` and `then` properties');
        }

        return new static(
            ConditionalRuleCondition::fromObject($data->condition),
            ConditionalRuleAction::fromObject($data->then)
        );
    }

    /**
     * @param ConditionalRuleArray $data
     * @return static
     */
    protected static function fromArrayInternal(array $data): static
    {
        if (!array_key_exists('condition', $data) || !array_key_exists('then', $data)) {
            throw new \InvalidArgumentException('ConditionalRule must have both `condition` and `then` properties');
        }

        return new static(
            ConditionalRuleCondition::fromArray($data['condition']),
            ConditionalRuleAction::fromArray($data['then'])
        );
    }
}
