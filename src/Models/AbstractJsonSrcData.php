<?php

namespace LiturgicalCalendar\Api\Models;

abstract class AbstractJsonSrcData
{
    private bool $locked = false;

    /**
     * Magic method for setting the value of a property.
     *
     * If the object is locked, attempting to set any property will throw a LogicException.
     *
     * @param string $name  The name of the property.
     * @param mixed $value The value to be set to the property.
     *
     * @throws \LogicException If the object is locked and a property modification is attempted.
     */
    public function __set(string $name, mixed $value): void
    {
        if ($this->locked) {
            throw new \LogicException(__METHOD__ . ' (' . get_class($this) . ") Cannot modify locked object property '$name'.");
        }
        $this->$name = $value;
    }

    /**
     * Creates an instance of a class that implements AbstractJsonSrcData from an array.
     *
     * The array should have the properties of the class as keys and the values should be the
     * values for the properties.
     *
     * The object is locked after creation.
     *
     * @param array<string,mixed> $data The array containing the properties of the class.
     * @return static The newly created instance.
     */
    public static function fromArray(array $data): static
    {
        if (reset($data) instanceof \stdClass) {
            throw new \InvalidArgumentException('Please use fromObject instead.');
        }
        $obj = static::fromArrayInternal($data);
        $obj->lock();
        return $obj;
    }

    /**
     * Creates an instance of a class that implements AbstractJsonSrcData from an array.
     *
     * @param array<string,mixed> $data
     */
    abstract protected static function fromArrayInternal(array $data): static;

    /**
     * Creates an instance of a class that implements AbstractJsonSrcData from a stdClass object.
     *
     * The object should have the properties of the class as properties and the values should be the
     * values for the properties.
     *
     * The object is locked after creation.
     *
     * @param \stdClass $data The stdClass object containing the properties of the class.
     * @return static The newly created instance.
     */
    public static function fromObject(\stdClass $data): static
    {
        $obj = static::fromObjectInternal($data);
        $obj->lock();
        return $obj;
    }

    /**
     * Creates an instance of a class that implements AbstractJsonSrcData from a stdClass object.
     *
     * @param \stdClass $data
     * @return static
     */
    abstract protected static function fromObjectInternal(\stdClass $data): static;

    /**
     * Locks the object, preventing any further modifications to its properties.
     *
     * Once locked, any attempt to set a property will result in a LogicException.
     * TODO: if this is never needed by a child class, make it private
     */
    protected function lock(): void
    {
        $this->locked = true;
    }

    /**
     * Unlocks the object, allowing modifications to its properties again.
     *
     * Note that this should generally only be used internally by the class
     * or its subclasses, as it can be used to bypass the immutability
     * guarantee.
     * TODO: if this is never needed by a child class, make it private
     */
    protected function unlock(): void
    {
        $this->locked = false;
    }

    /**
     * Checks if all required properties are present in a stdClass object.
     *
     * @param \stdClass $data The stdClass object to check.
     * @param string[] $required_properties The list of required property names.
     *
     * @throws \ValueError if any of the required properties is missing from the stdClass object.
     */
    protected static function validateRequiredProps(\stdClass $data, array $required_properties): void
    {
        $current_properties = array_keys(get_object_vars($data));
        $missing_properties = array_diff($required_properties, $current_properties);

        if (!empty($missing_properties)) {
            throw new \ValueError(sprintf(
                'The following properties are missing: %s. Found properties: %s, but required properties are: %s.',
                implode(', ', $missing_properties),
                implode(', ', $current_properties),
                implode(', ', $required_properties)
            ));
        }
    }

    /**
     * Validates that all required keys are present in an associative array.
     *
     * @param array<string,mixed> $data The associative array to validate.
     * @param string[] $required_keys The keys that must be present in the array.
     *
     * @throws \ValueError if any of the required keys is missing from the array.
     */
    protected static function validateRequiredKeys(array $data, array $required_keys): void
    {
        $current_keys = array_keys($data);
        $missing_keys = array_diff($required_keys, $current_keys);

        if (!empty($missing_keys)) {
            throw new \ValueError(sprintf(
                'The following keys are missing: %s. Found keys: %s, but required keys are: %s.',
                implode(', ', $missing_keys),
                implode(', ', $current_keys),
                implode(', ', $required_keys)
            ));
        }
    }
}
