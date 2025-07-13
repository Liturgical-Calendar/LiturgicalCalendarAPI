<?php

namespace LiturgicalCalendar\Api\Params;

/**
 * Interface for classes that handle parameters.
 *
 * This interface defines a method for setting parameters in classes that implement it.
 * It is used to ensure that classes can accept and process parameters in a consistent way.
 */
interface ParamsInterface
{
    /**
     * Sets the parameters for the class.
     *
     * This method takes an associative array of parameters and sets them in the class implementing the method.
     * The keys of the array should corresponde to properties of the class.
     *
     * @param array<string,mixed> $params An associative array of parameter keys to values.
     */
    public function setParams(array $params = []): void;
}
