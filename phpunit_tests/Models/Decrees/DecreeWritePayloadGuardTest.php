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
}
