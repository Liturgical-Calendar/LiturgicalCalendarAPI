<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services\SourceData;

use LiturgicalCalendar\Api\ApcuCache;
use LiturgicalCalendar\Api\ApcuShimStore;
use LiturgicalCalendar\Api\Enum\ChangeOperation;
use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Http\Exception\ServiceUnavailableException;
use LiturgicalCalendar\Api\Services\ChangeResource;
use LiturgicalCalendar\Api\Services\SourceData\DiskSourceDataWriter;
use LiturgicalCalendar\Api\Utilities;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DiskSourceDataWriter::class)]
final class DiskSourceDataWriterTest extends TestCase
{
    private string $tmp;

    /**
     * The memoised {@see ApcuCache::isUsable()} answer as this test found it, so
     * tearDown() can put the process back exactly as it was — see the identical
     * pattern (and its rationale) in ApcuCacheDetectionTest::setUp()/tearDown().
     */
    private ?bool $apcuUsableBefore = null;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/litcal-disk-writer-' . bin2hex(random_bytes(6));
        mkdir($this->tmp . '/nested', 0o755, true);

        // Load the in-memory APCu stand-in and force ApcuCache::isUsable() to answer
        // true for the two cache-invalidation tests below, regardless of whether the
        // real ext-apcu is installed or CLI-enabled on the host running this suite.
        // See phpunit_tests/Support/ApcuShim.php and ApcuCacheDetectionTest for why
        // this is the established way to exercise APCu-dependent code deterministically.
        require_once dirname(__DIR__, 2) . '/Support/ApcuShim.php';
        $usable                 = ( new \ReflectionProperty(ApcuCache::class, 'usable') )->getValue();
        $this->apcuUsableBefore = is_bool($usable) ? $usable : null;
        ( new \ReflectionProperty(ApcuCache::class, 'usable') )->setValue(null, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmp . '/{,*/}*', GLOB_BRACE) ?: [] as $path) {
            is_dir($path) ? @rmdir($path) : @unlink($path);
        }
        @rmdir($this->tmp);

        ( new \ReflectionProperty(ApcuCache::class, 'usable') )->setValue(null, $this->apcuUsableBefore);
    }

    /**
     * Fail loudly, rather than silently passing for the wrong reason, if this
     * process bound `ApcuCache`'s unqualified `apcu_*` calls to a real backend
     * before the shim above was loaded — see
     * ApcuCacheDetectionTest::assertShimIsTheBoundBackend() for the full
     * explanation of why that binding is permanent for the life of the process.
     */
    private static function assertApcuShimIsBound(): void
    {
        $sentinel = 'litcal_disk_writer_apcu_binding_' . uniqid();
        ApcuShimStore::store($sentinel, 'bound', 10);

        try {
            $value = ApcuCache::fetch($sentinel, $found);
            self::assertTrue(
                $found && 'bound' === $value,
                'ApcuCache is not reaching phpunit_tests/Support/ApcuShim.php in this process — its '
                . 'apcu_* call sites were bound to different functions before the shim was required, '
                . 'so this test cannot observe DiskSourceDataWriter\'s cache invalidation.'
            );
        } finally {
            ApcuShimStore::delete($sentinel);
        }
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

    /**
     * Without this, a handler that reads a file back through
     * Utilities::jsonFileToArray() after writing it through this class would be
     * served the pre-write contents from APCu for up to 300 seconds — see
     * Utilities::jsonFileToArray()'s cache TTL and DiskSourceDataWriter's class
     * docblock for why this must live in the writer, not in each handler.
     */
    public function testCommitInvalidatesTheApcuCacheAfterWritingAFile(): void
    {
        self::assertApcuShimIsBound();

        $file = $this->tmp . '/calendar.json';
        file_put_contents($file, json_encode(['version' => 1], JSON_THROW_ON_ERROR));

        // Prime the cache exactly the way a handler's own read would.
        self::assertSame(1, Utilities::jsonFileToArray($file)['version']);

        $writer = new DiskSourceDataWriter();
        $writer->stage($file, ChangeOperation::UPDATE, json_encode(['version' => 2], JSON_THROW_ON_ERROR));
        $writer->commit(ChangeResource::nationalCalendar(Rite::ROMAN, 'US'));

        self::assertSame(
            2,
            Utilities::jsonFileToArray($file)['version'],
            'commit() must invalidate the APCu cache entry for a file it writes, or a caller reading '
            . 'it back through Utilities keeps seeing the pre-write contents'
        );
    }

    /**
     * The deletion side of the same requirement: a cache entry populated by a read
     * that happened before the delete must not go on being served once the file
     * itself is gone.
     */
    public function testCommitInvalidatesTheApcuCacheAfterDeletingAFile(): void
    {
        self::assertApcuShimIsBound();

        $file = $this->tmp . '/obsolete.json';
        file_put_contents($file, json_encode(['version' => 1], JSON_THROW_ON_ERROR));

        // Prime the cache before the file is removed.
        Utilities::jsonFileToArray($file);

        $writer = new DiskSourceDataWriter();
        $writer->stage($file, ChangeOperation::DELETE, null);
        $writer->commit(ChangeResource::nationalCalendar(Rite::ROMAN, 'US'));

        self::assertFalse(
            ApcuShimStore::exists('jsoncache_array_' . md5($file)),
            'commit() must invalidate the APCu cache entry for a file it deletes, or a caller reading '
            . 'the now-missing file through Utilities keeps seeing its last contents'
        );
    }
}
