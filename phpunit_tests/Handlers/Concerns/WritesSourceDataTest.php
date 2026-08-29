<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers\Concerns;

use LiturgicalCalendar\Api\Handlers\Concerns\WritesSourceData;
use LiturgicalCalendar\Api\Services\SourceData\DiskSourceDataWriter;
use LiturgicalCalendar\Tests\Support\CollectingLogger;
use LiturgicalCalendar\Tests\Support\EnvIsolationTrait;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\TestCase;

/**
 * The trait's own behaviour: which writer a deployment gets, what it says out loud
 * when the operator asked for queue mode without the stack to back it, and the
 * disk-mode answers to the two read-your-own-pending-writes questions.
 *
 * The last of those is the branch's backwards-compatibility guarantee in test form:
 * a self-hoster with neither Postgres nor OpenFGA must see `null` and `[]` from
 * these, so every handler that asks falls straight back to the disk read it has
 * always done.
 */
#[CoversTrait(WritesSourceData::class)]
final class WritesSourceDataTest extends TestCase
{
    use EnvIsolationTrait;

    /** @var list<string> Everything SourceDataWriteMode::stackAvailable() consults. */
    private const STACK_ENV_VARS = [
        'DB_HOST',
        'DB_PORT',
        'DB_NAME',
        'DB_USER',
        'DB_PASSWORD',
        'OPENFGA_API_URL',
        'OPENFGA_STORE_ID',
        'OPENFGA_MODEL_ID',
        'OPENFGA_API_TOKEN',
    ];

    /** @var array<string, string|false> */
    private array $savedFlag = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->savedFlag = ['SOURCEDATA_CHANGE_REQUESTS' => $_ENV['SOURCEDATA_CHANGE_REQUESTS'] ?? false];
    }

    protected function tearDown(): void
    {
        foreach ($this->savedFlag as $key => $value) {
            if ($value === false) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $value;
            }
        }
        parent::tearDown();
    }

    /**
     * The misconfiguration branch: the operator set SOURCEDATA_CHANGE_REQUESTS but
     * Postgres and/or OpenFGA are not both reachable, so the request silently falls
     * back to disk. On the very host this feature exists to protect — one that rsyncs
     * `--delete` from git — the next deploy reverts that write with no trace, which is
     * why the fallback must announce itself rather than happening quietly.
     */
    public function testAMisconfiguredDeploymentWarnsAndStillFallsBackToDisk(): void
    {
        $logger = new CollectingLogger();
        $host   = new WritesSourceDataHost();
        $host->setSourceDataWriteLogger($logger);

        $_ENV['SOURCEDATA_CHANGE_REQUESTS'] = 'true';

        $writer = $this->withoutEnv(self::STACK_ENV_VARS, static fn () => $host->callSourceDataWriter());

        self::assertInstanceOf(DiskSourceDataWriter::class, $writer, 'the request must still succeed, on disk');

        $warnings = $logger->recordsAtLevel('warning');
        self::assertCount(1, $warnings);
        self::assertStringContainsString('SOURCEDATA_CHANGE_REQUESTS is set', $warnings[0]['message']);
        self::assertStringContainsString('falling back to writing this change straight to disk', $warnings[0]['message']);
    }

    /**
     * The flag unset is the ordinary self-hosted case, not a misconfiguration: no
     * warning, and the same disk writer.
     */
    public function testAnUnflaggedDeploymentGetsTheDiskWriterWithoutWarning(): void
    {
        $logger = new CollectingLogger();
        $host   = new WritesSourceDataHost();
        $host->setSourceDataWriteLogger($logger);

        unset($_ENV['SOURCEDATA_CHANGE_REQUESTS']);

        $writer = $this->withoutEnv(self::STACK_ENV_VARS, static fn () => $host->callSourceDataWriter());

        self::assertInstanceOf(DiskSourceDataWriter::class, $writer);
        self::assertSame([], $logger->recordsAtLevel('warning'));
    }

    /**
     * The warning is emitted once per request, not once per staged file: the writer is
     * memoised, and a handler asks for it repeatedly (every stage, the commit, and now
     * every aggregate-file read as well).
     */
    public function testTheMisconfigurationWarningIsEmittedOncePerRequestNotPerCall(): void
    {
        $logger = new CollectingLogger();
        $host   = new WritesSourceDataHost();
        $host->setSourceDataWriteLogger($logger);

        $_ENV['SOURCEDATA_CHANGE_REQUESTS'] = 'true';

        $this->withoutEnv(self::STACK_ENV_VARS, static function () use ($host): void {
            $host->callSourceDataWriter();
            $host->callUnpublishedSourceContent('/app/jsondata/sourcedata/rite/roman/decrees/decrees.json');
            $host->callSourceDataWriter();
        });

        self::assertCount(1, $logger->recordsAtLevel('warning'));
    }

    /**
     * Disk mode has no queue, so read-your-own-unpublished-writes is inert there and
     * every caller falls back to the disk read it has always done. This is the
     * backwards-compatibility guarantee for deployments with no Postgres and no OpenFGA.
     */
    public function testDiskModeReportsNothingUnpublished(): void
    {
        $host = new WritesSourceDataHost();
        $host->setSourceDataWriteLogger(new CollectingLogger());

        unset($_ENV['SOURCEDATA_CHANGE_REQUESTS']);

        $this->withoutEnv(self::STACK_ENV_VARS, static function () use ($host): void {
            self::assertNull($host->callUnpublishedSourceContent('/app/jsondata/sourcedata/rite/roman/decrees/decrees.json'));
            self::assertSame([], $host->callUnpublishedSourcePathsUnder('/app/jsondata/sourcedata/rite/roman/decrees/i18n'));
        });
    }
}
