<?php

namespace LiturgicalCalendar\Api\Models;

use LiturgicalCalendar\Api\DateTime;
use LiturgicalCalendar\Api\Enum\LitGrade;
use LiturgicalCalendar\Api\Models\Calendar\LiturgicalEventCollection;

/**
 * Represents a condition for a conditional rule
 * @phpstan-import-type ConditionalRuleConditionObject from \LiturgicalCalendar\Api\Models\ConditionalRule
 * @phpstan-import-type ConditionalRuleConditionArray from \LiturgicalCalendar\Api\Models\ConditionalRule
 */
final class ConditionalRuleCondition extends AbstractJsonSrcData
{
    public readonly ?string $if_weekday;
    public readonly ?LitGrade $if_grade;

    private function __construct(?string $if_weekday = null, ?LitGrade $if_grade = null)
    {
        $this->if_weekday = $if_weekday;
        $this->if_grade   = $if_grade;
    }

    /**
     * @param ConditionalRuleConditionObject $data
     * @return static
     */
    protected static function fromObjectInternal(\stdClass $data): static
    {
        if (!property_exists($data, 'if_weekday') && !property_exists($data, 'if_grade')) {
            throw new \InvalidArgumentException('ConditionalRuleCondition must have at least one of `if_weekday` or `if_grade` properties');
        }

        if (property_exists($data, 'if_weekday') && property_exists($data, 'if_grade')) {
            throw new \InvalidArgumentException('ConditionalRuleCondition cannot have both `if_weekday` and `if_grade` properties at the same time');
        }

        $if_weekday = null;
        $if_grade   = null;

        if (property_exists($data, 'if_weekday')) {
            if (!is_string($data->if_weekday)) {
                throw new \InvalidArgumentException('`if_weekday` property must have a value of type string, received ' . gettype($data->if_weekday));
            }
            if (!in_array(strtolower($data->if_weekday), ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'], true)) {
                throw new \InvalidArgumentException('`if_weekday` property must have a value that is a valid weekday name, received ' . $data->if_weekday);
            }
            $if_weekday = strtolower($data->if_weekday);
        }

        if (property_exists($data, 'if_grade')) {
            if (!is_int($data->if_grade)) {
                throw new \InvalidArgumentException('`if_grade` property must have a value of type int, received ' . gettype($data->if_grade));
            }
            if (!in_array($data->if_grade, LitGrade::values(), true)) {
                throw new \InvalidArgumentException('`if_grade` property must have a value that is a valid LitGrade enum value, received ' . $data->if_grade);
            }
            $if_grade = LitGrade::from($data->if_grade);
        }

        return new static($if_weekday, $if_grade);
    }

    /**
     * @param ConditionalRuleConditionArray $data
     * @return static
     */
    protected static function fromArrayInternal(array $data): static
    {
        if (!array_key_exists('if_weekday', $data) && !array_key_exists('if_grade', $data)) {
            throw new \InvalidArgumentException('ConditionalRuleCondition must have at least one of `if_weekday` or `if_grade` properties');
        }

        if (array_key_exists('if_weekday', $data) && array_key_exists('if_grade', $data)) {
            throw new \InvalidArgumentException('ConditionalRuleCondition cannot have both `if_weekday` and `if_grade` properties at the same time');
        }

        $if_weekday = null;
        $if_grade   = null;

        if (array_key_exists('if_weekday', $data)) {
            if (!is_string($data['if_weekday'])) {
                throw new \InvalidArgumentException('`if_weekday` property must have a value of type string, received ' . gettype($data['if_weekday']));
            }
            if (!in_array(strtolower($data['if_weekday']), ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'], true)) {
                throw new \InvalidArgumentException('`if_weekday` property must have a value that is a valid weekday name, received ' . $data['if_weekday']);
            }
            $if_weekday = strtolower($data['if_weekday']);
        }

        if (array_key_exists('if_grade', $data)) {
            if (!is_int($data['if_grade'])) {
                throw new \InvalidArgumentException('`if_grade` property must have a value of type int, received ' . gettype($data['if_grade']));
            }
            if (!in_array($data['if_grade'], LitGrade::values(), true)) {
                throw new \InvalidArgumentException('`if_grade` property must have a value that is a valid LitGrade enum value, received ' . $data['if_grade']);
            }
            $if_grade = LitGrade::from($data['if_grade']);
        }

        return new static($if_weekday, $if_grade);
    }

    public function matches(DateTime $date, LiturgicalEventCollection $calendar): bool
    {
        if ($this->if_weekday !== null) {
            // Check day of week condition
            $dayName = strtolower($date->format('l'));
            if ($dayName === strtolower($this->if_weekday)) {
                return true;
            }
        } elseif ($this->if_grade !== null) {
            // Check liturgical grade condition
            $hasCoincidence = false;
            switch ($this->if_grade) {
                case LitGrade::SOLEMNITY:
                    if ($calendar->inSolemnities($date)) {
                        $hasCoincidence = true;
                    }
                    break;
                case LitGrade::FEAST:
                case LitGrade::FEAST_LORD:
                    if ($calendar->inFeasts($date) || $calendar->inFeastsLord($date)) {
                        $hasCoincidence = true;
                    }
                    break;
                case LitGrade::MEMORIAL:
                    if ($calendar->inMemorials($date)) {
                        $hasCoincidence = true;
                    }
                    break;
            }
            return $hasCoincidence;
        }

        return false;
    }
}
