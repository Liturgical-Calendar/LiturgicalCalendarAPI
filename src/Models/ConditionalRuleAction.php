<?php

namespace LiturgicalCalendar\Api\Models;

/**
 * Represents an action for a conditional rule
 */
final class ConditionalRuleAction
{
    public function __construct(
        public readonly ?string $move = null,
        public readonly ?string $move_to = null
    ) {}

    public static function fromObject(\stdClass $data): self
    {
        $move = property_exists($data, 'move') ? $data->move : null;
        $moveTo = property_exists($data, 'move_to') ? $data->move_to : null;

        return new self($move, $moveTo);
    }

    public function apply(\DateTime $date): \DateTime
    {
        $newDate = clone $date;

        if ($this->move !== null) {
            // Handle DateInterval format (P1D, P7D, etc.)
            if (preg_match('/^P\d+[DWMY]$/', $this->move)) {
                $newDate->add(new \DateInterval($this->move));
            }
            // Handle negative intervals
            elseif (preg_match('/^-P\d+[DWMY]$/', $this->move)) {
                $interval = substr($this->move, 1); // Remove the minus sign
                $newDate->sub(new \DateInterval($interval));
            }
        }

        if ($this->move_to !== null) {
            // Handle relative date strings like "next monday", "previous friday"
            $newDate->modify($this->move_to);
        }

        return $newDate;
    }
}
