<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers;

use LiturgicalCalendar\Api\Database\Connection;
use LiturgicalCalendar\Api\Enum\JsonData;
use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Handlers\TestsHandler;
use LiturgicalCalendar\Api\Repositories\SourceDataChangeRequestRepository;
use LiturgicalCalendar\Api\Services\SourceData\SourceDataWriteMode;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * TestsHandler's DELETE path, driven end to end in queue mode.
 *
 * The handler-level counterpart to
 * TestsChangeRequestTest::testDeletingATestDefinitionFlagsTheBatchAsAResourceDeletion(): that test
 * proves ChangeRequestSourceDataWriter honours `deletesResource` when handed it directly; this one
 * proves TestsHandler::handleDeleteRequest() itself passes `deletesResource: true` through to the
 * writer. Modelled on RegionalDataQueueModeTest, which established this same env-setup pattern for
 * RegionalDataHandler's write paths.
 */
#[CoversClass(TestsHandler::class)]
final class TestsQueueModeTest extends AbstractHandlerTestCase
{
    /** @var array<string, string|false> */
    private array $originalEnv = [];

    /** Absolute path to a temporary test fixture created during a test; cleaned up in tearDown. */
    private ?string $testFixturePath = null;

    protected function setUp(): void
    {
        parent::setUp();

        // AbstractHandlerTestCase does not open a connection of its own here, so this must
        // use the same one the handler writes through or it silently truncates nothing.
        Connection::getInstance()->exec('TRUNCATE TABLE sourcedata_change_requests RESTART IDENTITY CASCADE');

        foreach ([SourceDataWriteMode::FLAG, 'OPENFGA_API_URL', 'OPENFGA_STORE_ID', 'OPENFGA_MODEL_ID'] as $key) {
            $this->originalEnv[$key] = $_ENV[$key] ?? false;
        }

        // A store id that does not exist, so every FGA check fails fast and
        // ChangeRequestReview::administers() returns false. Submissions therefore stay
        // `submitted` rather than being auto-approved, which is what these assertions read.
        $_ENV[SourceDataWriteMode::FLAG] = 'true';
        $_ENV['OPENFGA_API_URL']         = 'http://localhost:8083';
        $_ENV['OPENFGA_STORE_ID']        = 'no-such-store-tests-queue-test';
        $_ENV['OPENFGA_MODEL_ID']        = 'no-such-model-tests-queue-test';
    }

    protected function tearDown(): void
    {
        foreach ($this->originalEnv as $key => $value) {
            if (false === $value) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $value;
            }
        }
        $this->originalEnv = [];

        // Queue mode never touches the filesystem, so the fixture this test wrote is still
        // there — unlike disk mode's DELETE tests, where the handler itself removes it.
        if ($this->testFixturePath !== null && file_exists($this->testFixturePath)) {
            unlink($this->testFixturePath);
        }
        $this->testFixturePath = null;

        parent::tearDown();
    }

    public function testDeletingATestIsQueuedAndFlaggedAsAResourceDeletion(): void
    {
        $testName              = 'QueueDeleteFixture_' . bin2hex(random_bytes(6));
        $testsDir              = JsonData::testsFolderFor(Rite::ROMAN)->path();
        $fixturePath           = $testsDir . DIRECTORY_SEPARATOR . $testName . '.json';
        $this->testFixturePath = $fixturePath;

        file_put_contents($fixturePath, json_encode(
            ['name' => $testName, 'applies_to' => ['national_calendar' => 'US']],
            JSON_THROW_ON_ERROR
        ));

        $handler  = new TestsHandler([$testName], Rite::ROMAN);
        $response = $handler->handle(
            $this->withOidcUser($this->requestFor('DELETE', "/tests/roman/{$testName}"), 'editor-1')
        );

        $body = $this->decodeJsonBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('submitted', $body['disposition'] ?? null);
        self::assertIsArray($body['change_request'] ?? null);
        /** @var array<string,mixed> $changeRequest */
        $changeRequest = $body['change_request'];
        self::assertIsString($changeRequest['batch_id'] ?? null);

        // Queue mode must not have touched the filesystem.
        self::assertFileExists($fixturePath);

        $repo = new SourceDataChangeRequestRepository();
        $rows = $repo->getBatch($changeRequest['batch_id']);
        self::assertNotSame([], $rows, 'the deletion must have been queued');
        foreach ($rows as $row) {
            self::assertTrue(
                $row['metadata']['deletes_resource'] ?? false,
                'every row of a resource-deletion batch must carry the flag'
            );
        }
    }
}
