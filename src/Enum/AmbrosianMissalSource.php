<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Enum;

use LiturgicalCalendar\Api\Http\Exception\ValidationException;
use LiturgicalCalendar\Api\Models\MissalsPath\MissalMetadata;

/**
 * {@see MissalSource} over {@see AmbrosianMissal}.
 *
 * Every Ambrosian edition currently declared is a typical edition of the Ambrosian rite, and none
 * ships a lectionary yet — `/lectionary/ambrosian/sanctorale` reports that absence honestly, and
 * nothing here invents readings. The lectionary lookup is nonetheless declared PER EDITION on
 * {@see AmbrosianMissal}, because for this rite it varies by edition (#957).
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

    /**
     * Declared per edition on {@see AmbrosianMissal::$lectionaryPath}, not hard-coded here: the Ambrosian
     * lectionary genuinely varies by edition (the renewed Lezionario is from 2008, between the 1976 and 2024
     * editions), which is exactly the case the per-missal seam on {@see MissalSource} exists for. Both
     * editions map to `false` today, so behaviour is unchanged; the id is still validated first, so an
     * unknown id fails the same way it does everywhere else on this interface.
     */
    public function getLectionaryFilePath(string $missalId): string|false
    {
        return AmbrosianMissal::getLectionaryFilePath($missalId);
    }

    /**
     * No Ambrosian rite-wide sanctorale lectionary corpus exists on disk (#957) — do NOT fall back
     * to the Roman one, which is what {@see \LiturgicalCalendar\Api\Handlers\MissalsHandler} used
     * to do before this method existed.
     */
    public function riteLectionaryFolder(): false
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
        return AmbrosianMissal::isEditioTypica($missalId);
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

    public function editioTypicaFallbackLocale(): string
    {
        return AmbrosianMissal::PRIMARY_LOCALE;
    }

    /** @return array<string, MissalMetadata> */
    public function produceMetadata(): array
    {
        /** @var array<string, MissalMetadata> $metadata */
        $metadata = AmbrosianMissal::produceMetadata();
        return $metadata;
    }
}
