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
}
