<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Enum;

/**
 * {@see MissalSource} over {@see RomanMissal}. Delegation only: the statics remain the single
 * definition of Roman missal identity, and every existing static call site keeps working.
 */
final class RomanMissalSource implements MissalSource
{
    public function rite(): Rite
    {
        return Rite::ROMAN;
    }

    /** @return string[] */
    public function getMissalIds(): array
    {
        return RomanMissal::getMissalIds();
    }

    public function isValid(string $missalId): bool
    {
        return RomanMissal::isValid($missalId);
    }

    public function getName(string $missalId): string
    {
        return RomanMissal::getName($missalId);
    }

    public function getSanctoraleFileName(string $missalId): string|false
    {
        return RomanMissal::getSanctoraleFileName($missalId);
    }

    public function getSanctoraleI18nFilePath(string $missalId): string|false
    {
        return RomanMissal::getSanctoraleI18nFilePath($missalId);
    }

    public function getLectionaryFilePath(string $missalId): string|false
    {
        return RomanMissal::getLectionaryFilePath($missalId);
    }

    /** @return array{since_year:int,until_year?:int} */
    public function getYearLimits(string $missalId): array
    {
        return RomanMissal::getYearLimits($missalId);
    }

    public function isEditioTypica(string $missalId): bool
    {
        return RomanMissal::isEditioTypica($missalId);
    }

    public function regionFor(string $missalId): string
    {
        return RomanMissal::regionFor($missalId);
    }

    public function calendarLabelFor(string $missalId): string
    {
        return $this->isEditioTypica($missalId) ? 'GENERAL ROMAN' : $this->regionFor($missalId);
    }
}
