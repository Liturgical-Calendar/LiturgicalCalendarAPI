<?php

namespace LiturgicalCalendar\Api\Test;

use LiturgicalCalendar\Api\Enum\LitEventTestAssertion;

class AssertionItem
{
    public int $year;
    public ?string $expected_value;
    public LitEventTestAssertion $assert;
    public string $assertion;
    public ?string $comment = null;

    public const REQUIRED_PROPERTIES = ['year', 'expected_value', 'assert', 'assertion'];

    /**
     * @param object{year:int,expected_value:string|null,assert:string,assertion:string,comment?:string} $assertionItem
     */
    public function __construct(object $assertionItem)
    {
        foreach (self::REQUIRED_PROPERTIES as $property) {
            if (!property_exists($assertionItem, $property)) {
                throw new \InvalidArgumentException("Missing required property: $property");
            }
        }

        if (false === is_int($assertionItem->year)) {
            throw new \InvalidArgumentException('Property `year` must be an integer');
        }

        if (false === is_string($assertionItem->expected_value) && false === is_null($assertionItem->expected_value)) {
            throw new \InvalidArgumentException('Property `expected_value` must be a string or null');
        }

        // If expected_value is a string, ensure it's a valid RFC 3339 (ISO 8601) date-time string
        if (is_string($assertionItem->expected_value)) {
            $parsed = \DateTime::createFromFormat(\DateTime::ATOM, $assertionItem->expected_value);
            if (false === $parsed || $parsed->format(\DateTime::ATOM) !== $assertionItem->expected_value) {
                throw new \InvalidArgumentException('Property `expected_value` must be a valid RFC 3339 (ISO 8601) date-time string');
            }
        }

        if (false === is_string($assertionItem->assert)) {
            throw new \InvalidArgumentException('Property `assert` must be a string');
        }

        if (false === is_string($assertionItem->assertion)) {
            throw new \InvalidArgumentException('Property `assertion` must be a string');
        }

        $this->year           = $assertionItem->year;
        $this->expected_value = $assertionItem->expected_value;
        $this->assert         = LitEventTestAssertion::from($assertionItem->assert);
        $this->assertion      = $assertionItem->assertion;

        if (property_exists($assertionItem, 'comment')) {
            $this->comment = $assertionItem->comment;
        }
    }
}
