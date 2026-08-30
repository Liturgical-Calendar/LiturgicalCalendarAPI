<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Repositories;

use LiturgicalCalendar\Api\Enum\ChangeOperation;
use LiturgicalCalendar\Api\Enum\ChangePublicationStatus;
use LiturgicalCalendar\Api\Enum\ChangeReviewStatus;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(ChangeOperation::class)]
#[CoversClass(ChangeReviewStatus::class)]
#[CoversClass(ChangePublicationStatus::class)]
final class SourceDataChangeRequestSchemaTest extends RepositoryTestCase
{
    public function testTableExists(): void
    {
        $stmt = self::$pdo->query(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_name = 'sourcedata_change_requests'"
        );
        self::assertSame(1, (int) $stmt->fetchColumn());
    }

    public function testEveryEnumValueSatisfiesItsCheckConstraint(): void
    {
        foreach (ChangeReviewStatus::cases() as $reviewStatus) {
            $id = $this->insertRow(ChangeOperation::UPDATE, $reviewStatus, ChangePublicationStatus::NONE);
            self::assertNotSame('', $id, 'review_status ' . $reviewStatus->value . ' was rejected by its CHECK constraint');
            self::$pdo->exec('DELETE FROM sourcedata_change_requests');
        }

        foreach (ChangePublicationStatus::cases() as $publicationStatus) {
            $id = $this->insertRow(ChangeOperation::UPDATE, ChangeReviewStatus::APPROVED, $publicationStatus);
            self::assertNotSame('', $id, 'publication_status ' . $publicationStatus->value . ' was rejected by its CHECK constraint');
            self::$pdo->exec('DELETE FROM sourcedata_change_requests');
        }
    }

    public function testDeleteOperationMayNotCarryContent(): void
    {
        $this->expectException(\PDOException::class);
        $this->insertRow(ChangeOperation::DELETE, ChangeReviewStatus::SUBMITTED, ChangePublicationStatus::NONE, 'some content');
    }

    public function testWriteOperationMustCarryContent(): void
    {
        $this->expectException(\PDOException::class);
        $this->insertRow(ChangeOperation::CREATE, ChangeReviewStatus::SUBMITTED, ChangePublicationStatus::NONE, null);
    }

    public function testOnlyOneSubmittedRowPerPathAndSubmitter(): void
    {
        $this->insertRow(ChangeOperation::UPDATE, ChangeReviewStatus::SUBMITTED, ChangePublicationStatus::NONE);

        $this->expectException(\PDOException::class);
        $this->insertRow(ChangeOperation::UPDATE, ChangeReviewStatus::SUBMITTED, ChangePublicationStatus::NONE);
    }

    public function testPublishClaimTokenAndSettledAtColumnsExist(): void
    {
        $stmt = self::$pdo->query(
            "SELECT column_name, data_type, is_nullable
               FROM information_schema.columns
              WHERE table_name = 'sourcedata_change_requests'
                AND column_name IN ('publish_claim_token', 'publication_settled_at')
              ORDER BY column_name"
        );
        self::assertNotFalse($stmt);
        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        self::assertCount(2, $rows, 'Both phase-3 columns must exist');
        self::assertSame('publication_settled_at', $rows[0]['column_name']);
        self::assertSame('timestamp with time zone', $rows[0]['data_type']);
        self::assertSame('YES', $rows[0]['is_nullable']);
        self::assertSame('publish_claim_token', $rows[1]['column_name']);
        self::assertSame('uuid', $rows[1]['data_type']);
        self::assertSame('YES', $rows[1]['is_nullable']);
    }

    public function testPhase3IndexesExist(): void
    {
        $stmt = self::$pdo->query(
            "SELECT indexname FROM pg_indexes
              WHERE tablename = 'sourcedata_change_requests'
                AND indexname IN ('idx_scr_open_pr', 'idx_scr_settled_for_submitter')
              ORDER BY indexname"
        );
        self::assertNotFalse($stmt);
        self::assertSame(
            ['idx_scr_open_pr', 'idx_scr_settled_for_submitter'],
            $stmt->fetchAll(\PDO::FETCH_COLUMN)
        );
    }

    private function insertRow(
        ChangeOperation $operation,
        ChangeReviewStatus $reviewStatus,
        ChangePublicationStatus $publicationStatus,
        ?string $content = 'body'
    ): string {
        $stmt = self::$pdo->prepare(
            'INSERT INTO sourcedata_change_requests
                (batch_id, resource_type, resource_id, path, operation, content, submitted_by_sub, review_status, publication_status)
             VALUES
                (gen_random_uuid(), :resource_type, :resource_id, :path, :operation, :content, :sub, :review_status, :publication_status)
             RETURNING id'
        );
        $stmt->execute([
            'resource_type'      => 'national_calendar',
            'resource_id'        => 'USA',
            'path'               => 'jsondata/sourcedata/rite/roman/calendars/nation/USA.json',
            'operation'          => $operation->value,
            'content'            => $content,
            'sub'                => 'user-1',
            'review_status'      => $reviewStatus->value,
            'publication_status' => $publicationStatus->value,
        ]);

        return (string) $stmt->fetchColumn();
    }
}
