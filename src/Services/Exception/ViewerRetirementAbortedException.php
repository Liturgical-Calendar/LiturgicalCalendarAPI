<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services\Exception;

use LiturgicalCalendar\Api\Services\ViewerRetirementSweep;

/**
 * {@see ViewerRetirementSweep::apply()} stopped before the end of its plan.
 *
 * Carries what was done so far, with every planned role revocation not carried out (those not reached included), so
 * the caller can still report them: once a person's viewer tuples are gone, no later run can find them again.
 *
 * @phpstan-import-type ApplyResult from ViewerRetirementSweep
 */
final class ViewerRetirementAbortedException extends \RuntimeException
{
    /**
     * @param ApplyResult $partial
     */
    public function __construct(public readonly array $partial, \Throwable $previous)
    {
        parent::__construct('The sweep stopped early: ' . $previous->getMessage(), 0, $previous);
    }
}
