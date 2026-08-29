<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services\SourceData;

use LiturgicalCalendar\Api\Enum\ChangeOperation;
use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Http\Exception\ServiceUnavailableException;
use LiturgicalCalendar\Api\Services\ChangeResource;
use LiturgicalCalendar\Api\Services\SourceData\DiskSourceDataWriter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DiskSourceDataWriter::class)]
final class DiskSourceDataWriterTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/litcal-disk-writer-' . bin2hex(random_bytes(6));
        mkdir($this->tmp . '/nested', 0o755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmp . '/{,*/}*', GLOB_BRACE) ?: [] as $path) {
            is_dir($path) ? @rmdir($path) : @unlink($path);
        }
        @rmdir($this->tmp);
    }

    public function testCommitWritesEveryStagedFile(): void
    {
        $writer = new DiskSourceDataWriter();
        $writer->stage($this->tmp . '/calendar.json', ChangeOperation::CREATE, '{"litcal":[]}');
        $writer->stage($this->tmp . '/nested/en.json', ChangeOperation::CREATE, '{"key":"value"}');

        $result = $writer->commit(ChangeResource::nationalCalendar(Rite::ROMAN, 'US'));

        self::assertSame('applied', $result['disposition']);
        self::assertSame('{"litcal":[]}', file_get_contents($this->tmp . '/calendar.json'));
        self::assertSame('{"key":"value"}', file_get_contents($this->tmp . '/nested/en.json'));
    }

    public function testNothingIsWrittenBeforeCommit(): void
    {
        $writer = new DiskSourceDataWriter();
        $writer->stage($this->tmp . '/calendar.json', ChangeOperation::CREATE, '{}');

        self::assertFileDoesNotExist($this->tmp . '/calendar.json');
    }

    public function testCommitRemovesStagedDeletions(): void
    {
        file_put_contents($this->tmp . '/obsolete.json', '{}');

        $writer = new DiskSourceDataWriter();
        $writer->stage($this->tmp . '/obsolete.json', ChangeOperation::DELETE, null);
        $writer->commit(ChangeResource::nationalCalendar(Rite::ROMAN, 'US'));

        self::assertFileDoesNotExist($this->tmp . '/obsolete.json');
    }

    public function testDeletingAnAbsentFileIsNotAnError(): void
    {
        $writer = new DiskSourceDataWriter();
        $writer->stage($this->tmp . '/never-existed.json', ChangeOperation::DELETE, null);

        $result = $writer->commit(ChangeResource::nationalCalendar(Rite::ROMAN, 'US'));

        self::assertSame('applied', $result['disposition']);
    }

    public function testAnUnwritablePathRaisesServiceUnavailable(): void
    {
        $writer = new DiskSourceDataWriter();
        $writer->stage($this->tmp . '/no-such-dir/calendar.json', ChangeOperation::CREATE, '{}');

        $this->expectException(ServiceUnavailableException::class);
        $writer->commit(ChangeResource::nationalCalendar(Rite::ROMAN, 'US'));
    }

    public function testCommitClearsTheStagingArea(): void
    {
        $writer = new DiskSourceDataWriter();
        $writer->stage($this->tmp . '/calendar.json', ChangeOperation::CREATE, '{"first":true}');
        $writer->commit(ChangeResource::nationalCalendar(Rite::ROMAN, 'US'));

        unlink($this->tmp . '/calendar.json');

        $writer->stage($this->tmp . '/other.json', ChangeOperation::CREATE, '{}');
        $writer->commit(ChangeResource::nationalCalendar(Rite::ROMAN, 'US'));

        self::assertFileDoesNotExist($this->tmp . '/calendar.json');
        self::assertFileExists($this->tmp . '/other.json');
    }
}
