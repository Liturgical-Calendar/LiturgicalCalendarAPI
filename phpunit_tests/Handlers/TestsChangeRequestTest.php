<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers;

use LiturgicalCalendar\Api\Enum\ChangeOperation;
use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Repositories\SourceDataChangeRequestRepository;
use LiturgicalCalendar\Api\Services\ChangeResource;
use LiturgicalCalendar\Tests\Repositories\RepositoryTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(ChangeResource::class)]
final class TestsChangeRequestTest extends RepositoryTestCase
{
    private ChangeRequestTraitHost $host;

    protected function setUp(): void
    {
        parent::setUp();
        $this->host = new ChangeRequestTraitHost(new SourceDataChangeRequestRepository(self::$pdo));
        $this->host->setSubmitter([
            'sub'            => 'test-editor-1',
            'name'           => 'Alice',
            'email'          => 'alice@example.test',
            'email_verified' => true,
        ]);
    }

    public function testATestDefinitionPathIsStoredOutsideSourcedata(): void
    {
        $this->host->stageFile(
            '/app/jsondata/tests/roman/StIgnatiusOfLoyolaTest.json',
            ChangeOperation::CREATE,
            '{"name":"StIgnatiusOfLoyolaTest"}'
        );

        $body = $this->host->commitStagedFiles(
            ChangeResource::test(Rite::ROMAN, 'general_roman_calendar_test', 'general_roman_calendar')
        );

        self::assertSame(['jsondata/tests/roman/StIgnatiusOfLoyolaTest.json'], $body['change_request']['paths']);
    }

    public function testAScopedTestResourceIdIsRiteQualified(): void
    {
        $this->host->stageFile('/app/jsondata/tests/ambrosian/LuganoTest.json', ChangeOperation::CREATE, '{}');

        $body = $this->host->commitStagedFiles(
            ChangeResource::test(Rite::AMBROSIAN, 'diocesan_calendar_test', 'lugano_ch')
        );

        self::assertSame('diocesan_calendar_test', $body['change_request']['resource']['type']);
        self::assertSame('ambrosian/lugano_ch', $body['change_request']['resource']['id']);
    }

    public function testDeletingATestStagesADeleteWithNoContent(): void
    {
        $this->host->stageFile('/app/jsondata/tests/roman/ObsoleteTest.json', ChangeOperation::DELETE, null);

        $body = $this->host->commitStagedFiles(
            ChangeResource::test(Rite::ROMAN, 'general_roman_calendar_test', 'general_roman_calendar')
        );

        $repo = new SourceDataChangeRequestRepository(self::$pdo);
        $row  = $repo->getBatch($body['change_request']['batch_id'])[0];

        self::assertSame('delete', $row['operation']);
        self::assertNull($row['content']);
    }

    /**
     * The analogue of RegionalDataChangeRequestTest::testDeletingACalendarFlagsTheBatchAsAResourceDeletion().
     * Trait-level only: see TestsHandlerTest for whether TestsHandler's own DELETE path is proven to
     * pass this flag at the handler level.
     */
    public function testDeletingATestDefinitionFlagsTheBatchAsAResourceDeletion(): void
    {
        $this->host->stageFile('/app/jsondata/tests/roman/MyTest.json', ChangeOperation::DELETE, null);

        $body = $this->host->commitStagedFiles(
            ChangeResource::test(Rite::ROMAN, 'general_roman_calendar_test', 'general_roman_calendar'),
            deletesResource: true
        );

        $repo = new SourceDataChangeRequestRepository(self::$pdo);
        foreach ($repo->getBatch($body['change_request']['batch_id']) as $row) {
            self::assertTrue($row['metadata']['deletes_resource'] ?? false);
        }
    }
}
