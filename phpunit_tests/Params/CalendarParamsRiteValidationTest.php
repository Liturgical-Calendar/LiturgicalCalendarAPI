<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Params;

use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Http\Exception\ValidationException;
use LiturgicalCalendar\Api\Params\CalendarParams;
use PHPUnit\Framework\TestCase;

final class CalendarParamsRiteValidationTest extends TestCase
{
    /** Build a CalendarParams with the given fields set, bypassing the metadata-loading constructor. */
    private function params(Rite $rite, ?string $national, ?string $diocesan, int $year): CalendarParams
    {
        $p                   = ( new \ReflectionClass(CalendarParams::class) )->newInstanceWithoutConstructor();
        $p->Rite             = $rite;
        $p->NationalCalendar = $national;
        $p->DiocesanCalendar = $diocesan;
        $p->Year             = $year;
        return $p;
    }

    public function testRomanAcceptsEverything(): void
    {
        $this->params(Rite::ROMAN, 'US', null, 1970)->validateRiteCompatibility();
        $this->params(Rite::ROMAN, null, 'romamo_it', 1970)->validateRiteCompatibility();
        $this->addToAssertionCount(1); // no exception thrown
    }

    public function testAmbrosianComuneBaseIsValid(): void
    {
        $this->params(Rite::AMBROSIAN, null, null, 2025)->validateRiteCompatibility();
        $this->addToAssertionCount(1);
    }

    public function testAmbrosianWhitelistedDioceseIsValid(): void
    {
        $this->params(Rite::AMBROSIAN, null, 'milano_it', 2025)->validateRiteCompatibility();
        $this->addToAssertionCount(1);
    }

    public function testAmbrosianRejectsNationalCalendar(): void
    {
        $this->expectException(ValidationException::class);
        $this->params(Rite::AMBROSIAN, 'US', null, 2025)->validateRiteCompatibility();
    }

    public function testAmbrosianRejectsNonWhitelistedDiocese(): void
    {
        $this->expectException(ValidationException::class);
        $this->params(Rite::AMBROSIAN, null, 'romamo_it', 2025)->validateRiteCompatibility();
    }

    public function testAmbrosianRejectsYearBelow1976(): void
    {
        $this->expectException(ValidationException::class);
        $this->params(Rite::AMBROSIAN, null, null, 1975)->validateRiteCompatibility();
    }
}
