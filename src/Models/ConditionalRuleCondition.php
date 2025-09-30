<?php

namespace LiturgicalCalendar\Api\Models;

use LiturgicalCalendar\Api\Enum\LitGrade;

/**
 * Represents a condition for a conditional rule
 */
final class ConditionalRuleCondition
{
    public function __construct(
        public readonly ?array $coincides_with = null,
        public readonly ?string $day_of_week = null
    ) {}

    public static function fromObject(\stdClass $data): self
    {
        $coincidesWith = null;
        $dayOfWeek = null;

        if (property_exists($data, 'coincides_with')) {
            $coincidesWith = [];
            if (property_exists($data->coincides_with, 'lit_grade')) {
                if (is_array($data->coincides_with->lit_grade)) {
                    foreach ($data->coincides_with->lit_grade as $grade) {
                        $coincidesWith['lit_grade'][] = LitGrade::from($grade);
                    }
                } else {
                    $coincidesWith['lit_grade'] = [LitGrade::from($data->coincides_with->lit_grade)];
                }
            }
            if (property_exists($data->coincides_with, 'day_of_week')) {
                $coincidesWith['day_of_week'] = $data->coincides_with->day_of_week;
            }
        }

        if (property_exists($data, 'day_of_week')) {
            $dayOfWeek = $data->day_of_week;
        }

        return new self($coincidesWith, $dayOfWeek);
    }

    public function matches(\DateTime $date, $calendar): bool
    {
        // Check day of week condition
        if ($this->day_of_week !== null) {
            $dayName = strtolower($date->format('l'));
            if ($dayName !== strtolower($this->day_of_week)) {
                return false;
            }
        }

        // Check coincides with condition
        if ($this->coincides_with !== null) {
            if (isset($this->coincides_with['lit_grade'])) {
                $hasCoincidence = false;
                foreach ($this->coincides_with['lit_grade'] as $grade) {
                    switch ($grade) {
                        case LitGrade::SOLEMNITY:
                            if ($calendar->inSolemnities($date)) {
                                $hasCoincidence = true;
                                break 2;
                            }
                            break;
                        case LitGrade::FEAST:
                        case LitGrade::FEAST_LORD:
                            if ($calendar->inFeasts($date) || $calendar->inFeastsLord($date)) {
                                $hasCoincidence = true;
                                break 2;
                            }
                            break;
                        case LitGrade::MEMORIAL:
                            if ($calendar->inMemorials($date)) {
                                $hasCoincidence = true;
                                break 2;
                            }
                            break;
                    }
                }
                if (!$hasCoincidence && isset($this->coincides_with['day_of_week'])) {
                    $dayName = strtolower($date->format('l'));
                    $hasCoincidence = ($dayName === strtolower($this->coincides_with['day_of_week']));
                }
                return $hasCoincidence;
            }
            
            if (isset($this->coincides_with['day_of_week'])) {
                $dayName = strtolower($date->format('l'));
                return ($dayName === strtolower($this->coincides_with['day_of_week']));
            }
        }

        return true;
    }
}
