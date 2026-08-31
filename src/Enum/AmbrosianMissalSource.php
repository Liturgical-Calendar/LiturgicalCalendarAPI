<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Enum;

use LiturgicalCalendar\Api\Http\Exception\ValidationException;

/**
 * {@see MissalSource} over {@see AmbrosianMissal}.
 *
 * Every Ambrosian edition currently declared is a typical edition of the Ambrosian rite, and none
 * ships a lectionary — `/lectionary/ambrosian/sanctorale` reports that absence honestly, and this
 * change does not invent readings.
 */
final class AmbrosianMissalSource implements MissalSource
{
    public function rite(): Rite
    {
        return Rite::AMBROSIAN;
    }

    /** @return string[] */
    public function getMissalIds(): array
    {
        return AmbrosianMissal::getMissalIds();
    }

    public function isValid(string $missalId): bool
    {
        return AmbrosianMissal::isValid($missalId);
    }

    public function getName(string $missalId): string
    {
        return AmbrosianMissal::getName($missalId);
    }

    public function getSanctoraleFileName(string $missalId): string|false
    {
        return AmbrosianMissal::getSanctoraleFileName($missalId);
    }

    public function getSanctoraleI18nFilePath(string $missalId): string|false
    {
        return AmbrosianMissal::getSanctoraleI18nFilePath($missalId);
    }

    public function getLectionaryFilePath(string $missalId): string|false
    {
        return false;
    }

    /** @return array{since_year:int,until_year?:int} */
    public function getYearLimits(string $missalId): array
    {
        return AmbrosianMissal::getYearLimits($missalId);
    }

    public function isEditioTypica(string $missalId): bool
    {
        return AmbrosianMissal::isValid($missalId);
    }

    public function regionFor(string $missalId): string
    {
        if (false === AmbrosianMissal::isValid($missalId)) {
            throw new ValidationException('Invalid missal_id: ' . $missalId);
        }

        return AmbrosianMissal::REGION;
    }

    public function calendarLabelFor(string $missalId): string
    {
        return $this->regionFor($missalId);
    }
}
