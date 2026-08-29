<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers;

use LiturgicalCalendar\Api\Enum\ChangeOperation;
use LiturgicalCalendar\Api\Repositories\SourceDataChangeRequestRepository;
use LiturgicalCalendar\Api\Services\ChangeResource;
use LiturgicalCalendar\Tests\Repositories\RepositoryTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(ChangeResource::class)]
final class DecreesChangeRequestTest extends RepositoryTestCase
{
    private ChangeRequestTraitHost $host;

    protected function setUp(): void
    {
        parent::setUp();
        $this->host = new ChangeRequestTraitHost(new SourceDataChangeRequestRepository(self::$pdo));
        $this->host->setSubmitter([
            'sub'            => 'editor-1',
            'name'           => 'Alice',
            'email'          => 'alice@example.test',
            'email_verified' => true,
        ]);
    }

    public function testDecreesEditsTargetTheGeneralRomanCalendarDecreesResource(): void
    {
        $this->host->stageFile(
            '/app/jsondata/sourcedata/rite/roman/decrees/decrees.json',
            ChangeOperation::UPDATE,
            '[]'
        );

        $body = $this->host->commitStagedFiles(ChangeResource::decrees());

        self::assertSame('general_roman_calendar', $body['change_request']['resource']['type']);
        self::assertSame('decrees', $body['change_request']['resource']['id']);
    }

    public function testDatabaseI18nAndReadingsShareOneBatch(): void
    {
        $this->host->stageFile('/app/jsondata/sourcedata/rite/roman/decrees/decrees.json', ChangeOperation::UPDATE, '[]');
        $this->host->stageFile('/app/jsondata/sourcedata/rite/roman/decrees/i18n/en.json', ChangeOperation::UPDATE, '{}');
        $this->host->stageFile('/app/jsondata/sourcedata/rite/roman/lectionary/readings.json', ChangeOperation::UPDATE, '{}');

        $body = $this->host->commitStagedFiles(ChangeResource::decrees());

        $repo = new SourceDataChangeRequestRepository(self::$pdo);
        self::assertCount(3, $repo->getBatch($body['change_request']['batch_id']));
    }

    public function testRemovingAnEventKeyFromLocaleFilesIsAnUpdateNotADelete(): void
    {
        // The locale file survives; only one key leaves it. So the staged
        // operation is UPDATE with the rewritten body, never DELETE.
        $this->host->stageFile('/app/jsondata/sourcedata/rite/roman/decrees/i18n/it.json', ChangeOperation::UPDATE, '{}');

        $body = $this->host->commitStagedFiles(ChangeResource::decrees());

        $repo = new SourceDataChangeRequestRepository(self::$pdo);
        self::assertSame('update', $repo->getBatch($body['change_request']['batch_id'])[0]['operation']);
    }
}
