<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Params;

use LiturgicalCalendar\Api\Enum\LitLocale;
use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Http\Exception\ValidationException;
use LiturgicalCalendar\Api\Params\CalendarParams;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CalendarParamsRiteValidationTest extends TestCase
{
    /**
     * Build a CalendarParams with the given fields set, bypassing the metadata-loading constructor.
     *
     * `$locale` defaults to Latin because it is valid under every rite (it is the
     * Ambrosian *editio typica* as much as it is the Roman default), so the tests that
     * aren't about locale don't have to care. The constructor is bypassed here, so every
     * property `validateRiteCompatibility()` reads has to be assigned explicitly —
     * including `$Locale`, which is a typed property and would otherwise raise
     * "must not be accessed before initialization" rather than any assertion failure.
     */
    private function params(Rite $rite, ?string $national, ?string $diocesan, int $year, string $locale = LitLocale::LATIN): CalendarParams
    {
        $p                   = ( new \ReflectionClass(CalendarParams::class) )->newInstanceWithoutConstructor();
        $p->Rite             = $rite;
        $p->NationalCalendar = $national;
        $p->DiocesanCalendar = $diocesan;
        $p->Year             = $year;
        $p->Locale           = $locale;
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

    public function testAmbrosianDioceseDoesNotTripUpOnItsOwn(): void
    {
        // validateRiteCompatibility() no longer enforces diocese/rite matching
        // itself (that's rite-scoped in CalendarParams::validateDiocesanCalendarParam(),
        // against each diocese's declared `rite` metadata, exercised in
        // CalendarParamsTest). This reflection-based harness bypasses that
        // method entirely, so validateRiteCompatibility() must accept any
        // DiocesanCalendar value here, whitelisted or not.
        $this->params(Rite::AMBROSIAN, null, 'milano_it', 2025)->validateRiteCompatibility();
        $this->params(Rite::AMBROSIAN, null, 'romamo_it', 2025)->validateRiteCompatibility();
        $this->addToAssertionCount(2);
    }

    public function testAmbrosianRejectsNationalCalendar(): void
    {
        $this->expectException(ValidationException::class);
        $this->params(Rite::AMBROSIAN, 'US', null, 2025)->validateRiteCompatibility();
    }

    public function testAmbrosianRejectsYearBelow1976(): void
    {
        $this->expectException(ValidationException::class);
        $this->params(Rite::AMBROSIAN, null, null, 1975)->validateRiteCompatibility();
    }

    /**
     * Issue #761. The Ambrosian rite's liturgical books exist only in Italian and Latin,
     * which is what `/calendars` declares; the endpoint must enforce it rather than
     * serving Italian source data under a locale label it never applied.
     *
     * @return array<string,array{0:string}>
     */
    public static function localesTheAmbrosianRiteHasNoBooksFor(): array
    {
        return [
            'Dutch'   => ['nl_NL'],
            'English' => ['en_US'],
            'French'  => ['fr_FR'],
            'German'  => ['de_DE'],
        ];
    }

    #[DataProvider('localesTheAmbrosianRiteHasNoBooksFor')]
    public function testAmbrosianRejectsLocaleItHasNoLiturgicalBooksFor(string $locale): void
    {
        $this->expectException(ValidationException::class);
        $this->params(Rite::AMBROSIAN, null, null, 2025, $locale)->validateRiteCompatibility();
    }

    /**
     * The two official Ambrosian liturgical languages, in both the bare and
     * region-qualified shapes a request can produce. Matching is on primary language, so
     * `it_CH` — the Diocese of Lugano's Ticinese Italian, the one non-Italian civil
     * jurisdiction with Ambrosian parishes — is Italian and must be accepted.
     *
     * @return array<string,array{0:string}>
     */
    public static function localesTheAmbrosianRiteHasBooksFor(): array
    {
        return [
            'Latin'            => ['la'],
            'Latin (Vatican)'  => ['la_VA'],
            'Italian'          => ['it'],
            'Italian (Italy)'  => ['it_IT'],
            'Italian (Ticino)' => ['it_CH'],
        ];
    }

    #[DataProvider('localesTheAmbrosianRiteHasBooksFor')]
    public function testAmbrosianAcceptsItsOwnLiturgicalLanguages(string $locale): void
    {
        $this->params(Rite::AMBROSIAN, null, null, 2025, $locale)->validateRiteCompatibility();
        $this->addToAssertionCount(1);
    }

    /**
     * The restriction is rite-scoped: the Roman rite is translated into every locale the
     * API ships and must keep accepting all of them.
     */
    #[DataProvider('localesTheAmbrosianRiteHasNoBooksFor')]
    public function testRomanRiteAcceptsEveryLocale(string $locale): void
    {
        $this->params(Rite::ROMAN, null, null, 2025, $locale)->validateRiteCompatibility();
        $this->addToAssertionCount(1);
    }

    /**
     * The 400 has to be actionable: a client that asked for the wrong language needs to
     * be told which ones exist, the way every other invalid-parameter response does.
     */
    public function testAmbrosianLocaleRejectionNamesTheSupportedSet(): void
    {
        try {
            $this->params(Rite::AMBROSIAN, null, null, 2025, 'nl_NL')->validateRiteCompatibility();
            self::fail('Expected a ValidationException for an unsupported Ambrosian locale');
        } catch (ValidationException $e) {
            self::assertStringContainsString('nl_NL', $e->getMessage(), 'names the rejected value');
            self::assertStringContainsString('locale', $e->getMessage(), 'names the offending parameter');
            self::assertStringContainsString('it', $e->getMessage(), 'names the supported set');
            self::assertStringContainsString('la', $e->getMessage(), 'names the supported set');
        }
    }
}
