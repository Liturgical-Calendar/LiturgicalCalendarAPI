<?php

namespace LiturgicalCalendar\Api\Models;

use LiturgicalCalendar\Api\DateTime;

/**
 * Represents an action for a conditional rule
 * @phpstan-import-type ConditionalRuleActionObject from \LiturgicalCalendar\Api\Models\ConditionalRule
 * @phpstan-import-type ConditionalRuleActionArray from \LiturgicalCalendar\Api\Models\ConditionalRule
 */
final class ConditionalRuleAction extends AbstractJsonSrcData
{
    public readonly ?string $move;
    public readonly ?string $move_to;

    private function __construct(?string $move = null, ?string $moveTo = null)
    {
        $this->move    = $move;
        $this->move_to = $moveTo;
    }

    /**
     * @param ConditionalRuleActionObject $data
     * @return static
     */
    protected static function fromObjectInternal(\stdClass $data): static
    {
        if (!property_exists($data, 'move') && !property_exists($data, 'move_to')) {
            throw new \InvalidArgumentException('ConditionalRuleAction must have either `move` or `move_to` properties');
        }

        if (property_exists($data, 'move') && property_exists($data, 'move_to')) {
            throw new \InvalidArgumentException('ConditionalRuleAction cannot have both `move` and `move_to` properties at the same time');
        }

        $move   = null;
        $moveTo = null;

        if (property_exists($data, 'move')) {
            if (!is_string($data->move)) {
                throw new \InvalidArgumentException('`move` property must have a value of type string or null, received ' . gettype($data->move));
            }
            $move = $data->move;
        }

        if (property_exists($data, 'move_to')) {
            if (!is_string($data->move_to)) {
                throw new \InvalidArgumentException('`move_to` property must have a value of type string or null, received ' . gettype($data->move_to));
            }
            $moveTo = $data->move_to;
        }

        return new static($move, $moveTo);
    }

    /**
     * @param ConditionalRuleActionArray $data
     * @return static
     */
    protected static function fromArrayInternal(array $data): static
    {
        if (!array_key_exists('move', $data) && !array_key_exists('move_to', $data)) {
            throw new \InvalidArgumentException('ConditionalRuleAction must have either `move` or `move_to` properties');
        }

        if (array_key_exists('move', $data) && array_key_exists('move_to', $data)) {
            throw new \InvalidArgumentException('ConditionalRuleAction cannot have both `move` and `move_to` properties at the same time');
        }

        $move   = null;
        $moveTo = null;

        if (array_key_exists('move', $data)) {
            if (!is_string($data['move'])) {
                throw new \InvalidArgumentException('`move` property must have a value of type string or null, received ' . gettype($data['move']));
            }
            $move = $data['move'];
        }

        if (array_key_exists('move_to', $data)) {
            if (!is_string($data['move_to'])) {
                throw new \InvalidArgumentException('`move_to` property must have a value of type string or null, received ' . gettype($data['move_to']));
            }
            $moveTo = $data['move_to'];
        }

        return new static($move, $moveTo);
    }

    public function apply(DateTime $date): DateTime
    {
        $newDate = clone $date;

        if ($this->move !== null) {
            // Handle DateInterval format (P1D, P7D, etc.)
            if (preg_match('/^P\d+D$/', $this->move)) {
                $newDate->add(new \DateInterval($this->move));
            }
            // Handle negative intervals
            elseif (preg_match('/^-P\d+D$/', $this->move)) {
                $interval = substr($this->move, 1); // Remove the minus sign
                $newDate->sub(new \DateInterval($interval));
            } else {
                throw new \ValueError('Invalid move interval "' . $this->move . '"');
            }
        } elseif ($this->move_to !== null) {
            // Handle relative date strings like "next monday", "previous friday"
            $newDate->modify($this->move_to);
        }

        return $newDate;
    }
}
