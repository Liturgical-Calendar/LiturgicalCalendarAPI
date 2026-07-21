<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Models\Calendar\Precedence;

/**
 * Resolves a rite's precedence rules (coincidence, suppression, transfer of
 * liturgical events) against the shared LiturgicalEventCollection carried by
 * the PrecedenceContext. Implementations MUST be re-runnable per year (the
 * calendar handler runs the pipeline twice for LITURGICAL year_type) and MUST
 * NOT hold per-request state between calls.
 */
interface PrecedenceResolver
{
    public function resolve(PrecedenceContext $ctx): void;
}
