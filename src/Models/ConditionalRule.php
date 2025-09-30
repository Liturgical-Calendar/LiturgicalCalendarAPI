<?php

namespace LiturgicalCalendar\Api\Models;

/**
 * Represents a conditional rule for liturgical events
 */
final class ConditionalRule
{
    public function __construct(
        public readonly ConditionalRuleCondition $condition,
        public readonly ConditionalRuleAction $then
    ) {}

    public static function fromObject(\stdClass $data): self
    {
        if (!property_exists($data, 'condition') || !property_exists($data, 'then')) {
            throw new \InvalidArgumentException('ConditionalRule must have both condition and then properties');
        }

        return new self(
            ConditionalRuleCondition::fromObject($data->condition),
            ConditionalRuleAction::fromObject($data->then)
        );
    }
}
