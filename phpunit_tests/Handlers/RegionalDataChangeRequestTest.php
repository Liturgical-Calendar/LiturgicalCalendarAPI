<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers;

use LiturgicalCalendar\Api\Enum\ChangeOperation;
use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Repositories\SourceDataChangeRequestRepository;
use LiturgicalCalendar\Api\Services\ChangeResource;
use LiturgicalCalendar\Tests\Repositories\RepositoryTestCase;
use PHPUnit\Framework\Attributes\CoversTrait;

/**
 * The trait is exercised through a minimal host class rather than through
 * RegionalDataHandler itself: constructing that handler needs the full PSR-7
 * request pipeline, and what needs proving here is the staging and submission
 * contract every write path relies on.
 */
#[CoversTrait(\LiturgicalCalendar\Api\Handlers\Concerns\WritesSourceData::class)]
final class RegionalDataChangeRequestTest extends RepositoryTestCase
{
    private ChangeRequestTraitHost $host;

    protected function setUp(): void
    {
        parent::setUp();
        $this->host = new ChangeRequestTraitHost(
            new SourceDataChangeRequestRepository(self::$pdo)
        );
        $this->host->setSubmitter([
            'sub'            => 'user-1',
            'name'           => 'Alice',
            'email'          => 'alice@example.test',
            'email_verified' => true,
        ]);
    }

    public function testStagedFilesBecomeOneBatch(): void
    {
        $this->host->stageFile('/app/jsondata/sourcedata/rite/roman/calendars/nation/USA.json', ChangeOperation::CREATE, '{"litcal":[]}');
        $this->host->stageFile('/app/jsondata/sourcedata/rite/roman/calendars/nation/USA/i18n/en.json', ChangeOperation::CREATE, '{}');

        $body = $this->host->commitStagedFiles(ChangeResource::nationalCalendar(Rite::ROMAN, 'USA'));

        self::assertArrayHasKey('change_request', $body);
        self::assertCount(2, $body['change_request']['paths']);
        self::assertSame('national_calendar', $body['change_request']['resource']['type']);
    }

    public function testPathsAreStoredRepositoryRelative(): void
    {
        $this->host->stageFile('/app/jsondata/sourcedata/rite/roman/calendars/nation/USA.json', ChangeOperation::CREATE, '{}');

        $body = $this->host->commitStagedFiles(ChangeResource::nationalCalendar(Rite::ROMAN, 'USA'));

        self::assertSame(
            ['jsondata/sourcedata/rite/roman/calendars/nation/USA.json'],
            $body['change_request']['paths']
        );
    }

    public function testANonAdministratorSubmissionStaysSubmitted(): void
    {
        $this->host->setAdministers(false);
        $this->host->stageFile('/app/jsondata/sourcedata/rite/roman/calendars/nation/USA.json', ChangeOperation::CREATE, '{}');

        $body = $this->host->commitStagedFiles(ChangeResource::nationalCalendar(Rite::ROMAN, 'USA'));

        self::assertSame('submitted', $body['change_request']['review_status']);
        self::assertFalse($body['change_request']['auto_approved']);
        self::assertSame('submitted', $body['disposition']);
    }

    public function testAnAdministratorSubmissionIsAutoApproved(): void
    {
        $this->host->setAdministers(true);
        $this->host->stageFile('/app/jsondata/sourcedata/rite/roman/calendars/nation/USA.json', ChangeOperation::CREATE, '{}');

        $body = $this->host->commitStagedFiles(ChangeResource::nationalCalendar(Rite::ROMAN, 'USA'));

        self::assertSame('approved', $body['change_request']['review_status']);
        self::assertTrue($body['change_request']['auto_approved']);
        self::assertSame('approved', $body['disposition']);

        $repo = new SourceDataChangeRequestRepository(self::$pdo);
        $row  = $repo->getBatch($body['change_request']['batch_id'])[0];
        self::assertSame('user-1', $row['approved_by_sub']);
    }

    public function testAnUnverifiedEmailIsNotUsedAsTheGitAuthorEmail(): void
    {
        $this->host->setSubmitter([
            'sub'            => 'user-2',
            'name'           => 'Bob',
            'email'          => 'bob@example.test',
            'email_verified' => false,
        ]);
        $this->host->stageFile('/app/jsondata/sourcedata/rite/roman/calendars/nation/USA.json', ChangeOperation::CREATE, '{}');

        $body = $this->host->commitStagedFiles(ChangeResource::nationalCalendar(Rite::ROMAN, 'USA'));

        $repo = new SourceDataChangeRequestRepository(self::$pdo);
        $row  = $repo->getBatch($body['change_request']['batch_id'])[0];

        self::assertFalse($row['submitted_by_email_verified']);
        self::assertNull($row['submitted_by_email']);
    }

    public function testSubmittingWithNothingStagedIsRejected(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('no staged files');

        $this->host->commitStagedFiles(ChangeResource::nationalCalendar(Rite::ROMAN, 'USA'));
    }

    public function testADeletionStagesNoContent(): void
    {
        $this->host->stageFile(
            '/app/jsondata/sourcedata/rite/roman/calendars/dioceses/IT/romamo_it/Diocesi di Roma.json',
            ChangeOperation::DELETE,
            null
        );

        $body = $this->host->commitStagedFiles(ChangeResource::diocesanCalendar(Rite::ROMAN, 'romamo_it'));

        $repo = new SourceDataChangeRequestRepository(self::$pdo);
        $row  = $repo->getBatch($body['change_request']['batch_id'])[0];

        self::assertSame('delete', $row['operation']);
        self::assertNull($row['content']);
    }

    public function testADeletionOfACalendarAndItsTranslationsIsOneBatch(): void
    {
        $this->host->stageFile(
            '/app/jsondata/sourcedata/rite/roman/calendars/dioceses/IT/romamo_it/Diocesi di Roma.json',
            ChangeOperation::DELETE,
            null
        );
        $this->host->stageFile(
            '/app/jsondata/sourcedata/rite/roman/calendars/dioceses/IT/romamo_it/i18n/it_IT.json',
            ChangeOperation::DELETE,
            null
        );

        $body = $this->host->commitStagedFiles(ChangeResource::diocesanCalendar(Rite::ROMAN, 'romamo_it'));

        $repo = new SourceDataChangeRequestRepository(self::$pdo);
        self::assertCount(2, $repo->getBatch($body['change_request']['batch_id']));
    }

    public function testAnUpdateAndADeleteCanShareABatch(): void
    {
        $this->host->stageFile(
            '/app/jsondata/sourcedata/rite/roman/calendars/nations/US/US.json',
            ChangeOperation::UPDATE,
            '{"litcal":[]}'
        );
        $this->host->stageFile(
            '/app/jsondata/sourcedata/rite/roman/calendars/nations/US/i18n/fr_FR.json',
            ChangeOperation::DELETE,
            null
        );

        $body = $this->host->commitStagedFiles(ChangeResource::nationalCalendar(Rite::ROMAN, 'US'));

        $repo       = new SourceDataChangeRequestRepository(self::$pdo);
        $operations = array_column($repo->getBatch($body['change_request']['batch_id']), 'operation');

        sort($operations);
        self::assertSame(['delete', 'update'], $operations);
    }
}
