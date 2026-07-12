<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Models\Decrees;

use LiturgicalCalendar\Api\Http\Exception\ValidationException;
use LiturgicalCalendar\Api\Models\Decrees\DecreeWritePayloadGuard;
use PHPUnit\Framework\TestCase;

final class DecreeWritePayloadGuardTest extends TestCase
{
    /** @param array<string,mixed> $overrides */
    private static function payload(string $action, ?string $property = null, array $overrides = []): \stdClass
    {
        $p                   = new \stdClass();
        $p->metadata         = new \stdClass();
        $p->metadata->action = $action;
        if ($property !== null) {
            $p->metadata->property = $property;
        }
        foreach ($overrides as $k => $v) {
            $p->{$k} = $v;
        }
        return $p;
    }

    public function testCreateNewOnPutRequiresI18nAndReadings(): void
    {
        $this->expectException(ValidationException::class);
        DecreeWritePayloadGuard::assertSidecars(self::payload('createNew'), 'en', true);
    }

    public function testCreateNewOnPutWithSidecarsIncludingAcceptLocalePasses(): void
    {
        $p = self::payload('createNew', null, [
            'i18n'     => (object) ['en' => 'Saint Test'],
            'readings' => (object) ['en' => (object) ['first_reading' => 'Gen 1:1']],
        ]);
        DecreeWritePayloadGuard::assertSidecars($p, 'en', true);
        $this->addToAssertionCount(1);
    }

    public function testI18nMissingAcceptLanguageBaseLocaleFails(): void
    {
        $p = self::payload('createNew', null, [
            'i18n'     => (object) ['it' => 'San Test'],
            'readings' => (object) ['en' => (object) ['first_reading' => 'Gen 1:1']],
        ]);
        $this->expectException(ValidationException::class);
        DecreeWritePayloadGuard::assertSidecars($p, 'en', true);
    }

    public function testSetPropertyGradeRejectsI18n(): void
    {
        $p = self::payload('setProperty', 'grade', ['i18n' => (object) ['en' => 'X']]);
        $this->expectException(ValidationException::class);
        DecreeWritePayloadGuard::assertSidecars($p, 'en', false);
    }

    public function testSetPropertyGradeWithoutSidecarsPasses(): void
    {
        DecreeWritePayloadGuard::assertSidecars(self::payload('setProperty', 'grade'), 'en', false);
        $this->addToAssertionCount(1);
    }

    public function testReadingsRejectedOnPutForMakeDoctor(): void
    {
        $p = self::payload('makeDoctor', null, [
            'i18n'     => (object) ['en' => 'Saint Test, Doctor'],
            'readings' => (object) ['en' => (object) ['first_reading' => 'Gen 1:1']],
        ]);
        $this->expectException(ValidationException::class);
        DecreeWritePayloadGuard::assertSidecars($p, 'en', true);
    }

    public function testReadingsOptionalOnPatchForMakeDoctor(): void
    {
        $p = self::payload('makeDoctor', null, [
            'i18n'     => (object) ['en' => 'Saint Test, Doctor'],
            'readings' => (object) ['en' => (object) ['first_reading' => 'Gen 1:1']],
        ]);
        DecreeWritePayloadGuard::assertSidecars($p, 'en', false);
        $this->addToAssertionCount(1);
    }

    public function testSetPropertyNameRequiresI18nOnPatchToo(): void
    {
        $this->expectException(ValidationException::class);
        DecreeWritePayloadGuard::assertSidecars(self::payload('setProperty', 'name'), 'en', false);
    }

    // FINDING 4: invalid locale keys in i18n/readings must be rejected; 'la' must pass.

    public function testI18nInvalidLocaleKeyIsRejected(): void
    {
        $p = self::payload('createNew', null, [
            'i18n'     => (object) ['en' => 'Saint Test', 'zz' => 'Bad Locale'],
            'readings' => (object) ['en' => (object) ['first_reading' => 'Gen 1:1']],
        ]);
        $this->expectException(ValidationException::class);
        DecreeWritePayloadGuard::assertSidecars($p, 'en', true);
    }

    public function testReadingsInvalidLocaleKeyIsRejected(): void
    {
        $p = self::payload('createNew', null, [
            'i18n'     => (object) ['en' => 'Saint Test'],
            'readings' => (object) ['en' => (object) ['first_reading' => 'Gen 1:1'], 'zz' => (object) ['first_reading' => 'Gen 1:1']],
        ]);
        $this->expectException(ValidationException::class);
        DecreeWritePayloadGuard::assertSidecars($p, 'en', true);
    }

    public function testLatinLocaleKeyIsAccepted(): void
    {
        $p = self::payload('createNew', null, [
            'i18n'     => (object) ['en' => 'Saint Test', 'la' => 'Sanctus Test'],
            'readings' => (object) ['en' => (object) ['first_reading' => 'Gen 1:1'], 'la' => (object) ['first_reading' => 'Gen 1:1']],
        ]);
        DecreeWritePayloadGuard::assertSidecars($p, 'en', true);
        $this->addToAssertionCount(1);
    }

    /**
     * When the payload has no `metadata` property, the guard must construct an empty stdClass
     * for metadata (line 28 of DecreeWritePayloadGuard), treat the action as null (non-nameBearing),
     * and pass without throwing when no i18n is present.
     */
    public function testPayloadWithoutMetadataPropertyPassesWhenNoI18n(): void
    {
        // Payload has no 'metadata' key at all — guard must not crash (triggers line 28).
        $p = new \stdClass();
        DecreeWritePayloadGuard::assertSidecars($p, 'en', false);
        $this->addToAssertionCount(1);
    }

    /**
     * When the payload has a non-stdClass `metadata` value (e.g. null or a scalar),
     * the guard must replace it with an empty stdClass and proceed without throwing.
     */
    public function testPayloadWithNullMetadataPassesWhenNoI18n(): void
    {
        $p           = new \stdClass();
        $p->metadata = null; // present but not stdClass → triggers line 28
        DecreeWritePayloadGuard::assertSidecars($p, 'en', false);
        $this->addToAssertionCount(1);
    }

    /**
     * createNew with i18n present AND accepted locale, but readings absent on PUT:
     * the guard must throw because readings are required for createNew on create.
     * This specifically covers lines 62-64 (the readings-absent branch) rather than
     * the i18n-absent branch covered by testCreateNewOnPutRequiresI18nAndReadings.
     */
    public function testCreateNewWithI18nButMissingReadingsOnPutIsRejected(): void
    {
        $p = self::payload('createNew', null, [
            'i18n' => (object) ['en' => 'Saint Test'],
            // no readings
        ]);
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/require a `readings` object when creating/');
        DecreeWritePayloadGuard::assertSidecars($p, 'en', true);
    }

    /**
     * setProperty(grade) on PATCH with readings present: readings are optional for every
     * action on PATCH (isCreate=false), so this must pass.
     */
    public function testSetPropertyGradeWithReadingsOnPatchPasses(): void
    {
        $p = self::payload('setProperty', 'grade', [
            'readings' => (object) ['en' => (object) ['first_reading' => 'Gen 1:1']],
        ]);
        DecreeWritePayloadGuard::assertSidecars($p, 'en', false);
        $this->addToAssertionCount(1);
    }

    /**
     * Non-nameBearing action (setProperty/grade) with a `readings` object on PUT:
     * the guard must reject it (lines 66-70).
     */
    public function testSetPropertyGradeWithReadingsOnPutIsRejected(): void
    {
        $p = self::payload('setProperty', 'grade', [
            'readings' => (object) ['en' => (object) ['first_reading' => 'Gen 1:1']],
        ]);
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/do not accept a `readings` object on creation/');
        DecreeWritePayloadGuard::assertSidecars($p, 'en', true);
    }

    /**
     * setProperty with a non-string property value: nameBearing is false, so i18n is rejected.
     * Also exercises the $propertyStr interpolation branch in the elseif ($hasI18n) block.
     */
    public function testSetPropertyWithNonStringPropertyAndI18nIsRejected(): void
    {
        // Build payload manually so property is not null but an int (non-string)
        $p                     = new \stdClass();
        $p->metadata           = new \stdClass();
        $p->metadata->action   = 'setProperty';
        $p->metadata->property = 42; // not a string → nameBearing=false, but hasI18n=true
        $p->i18n               = (object) ['en' => 'X'];
        $this->expectException(ValidationException::class);
        DecreeWritePayloadGuard::assertSidecars($p, 'en', false);
    }
}
