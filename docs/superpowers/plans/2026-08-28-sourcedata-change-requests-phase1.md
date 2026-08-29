# Source-data change requests — Phase 1 (API) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or
> superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for
> tracking.

**Goal:** Give source-data writes a seam with two implementations — today's disk writes, and a reviewable
change request in Postgres with an approval gate and RBAC-scoped listing and history — selected per
deployment, so a self-hosted instance without Postgres and OpenFGA keeps behaving exactly as it does now.

**Architecture:** A write handler builds its payload exactly as it does today — schema validation and OpenFGA
authorization unchanged — then stages each file it would write and commits once, through a `SourceDataWriter`
interface. `DiskSourceDataWriter` performs today's `file_put_contents()`/`unlink()`;
`ChangeRequestSourceDataWriter` records a **batch** of proposed files and touches no files at all. One API
write request produces one batch (a calendar plus its i18n files travel together), so they are approved or
rejected as a unit. A submitter who holds the `admin` relation on the resource is auto-approved at submit
time. Editors list their own batches; resource admins list batches for resources they administer; global
admins list everything. No GitHub integration in this phase: approved batches simply sit as `approved`,
waiting for the Phase 2 publisher.

The handlers never branch on mode. Selection happens once, in one place, gated on `SOURCEDATA_CHANGE_REQUESTS`
plus the presence of Postgres and OpenFGA.

**Tech Stack:** PHP 8.4, PDO/PostgreSQL, Doctrine Migrations, PHPUnit 12, OpenFGA via `OpenFgaClient`,
PSR-7/15 handlers.

**Spec:** `docs/superpowers/specs/2026-08-28-sourcedata-change-requests-design.md`

**Tracked by:** [#902](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/issues/902)

## Global Constraints

- PHP >= 8.4. PSR-12 via `phpcs.xml`; 4-space indent, short array syntax `[]`, single quotes unless
  interpolating.
- PHPStan level 10 must pass: `composer analyse`.
- Never use `--no-verify` when committing. Pre-commit hooks run linting and must pass.
- Branch: `feature/sourcedata-change-requests`, already created from `development`. PRs target `development`.
- Test commands: `composer test` (full), `vendor/bin/phpunit phpunit_tests/Path/ToTest.php` (single file).
- `SOURCEDATA_CHANGE_REQUESTS` defaults to `false`. Queue mode additionally requires
  `Connection::isConfigured()` **and** `OpenFgaClient::isConfigured()`; the flag alone must never enable it,
  because `ChangeRequestReview::administers()` fails closed and queue mode without OpenFGA would accept edits
  nobody could approve.
- Repository tests need Postgres credentials in `DB_HOST`/`DB_NAME`/`DB_USER`/`DB_PASSWORD`; without them
  `RepositoryTestCase` skips the class. CI supplies them via `.env.local`.
- Status columns are `VARCHAR` + `CHECK` constraints, **not** PostgreSQL `ENUM` types. This matches
  `access_requests` and, unlike `openfga_outbox`'s enums, lets Phase 2 and Phase 3 add publication states
  without an `ALTER TYPE` inside a transactional migration.
- Every new class carries a `#[CoversClass]` attribute on its test.

## Scope note

This plan covers the **API repository only**. The frontend admin interface described in the spec is a separate
plan in `LiturgicalCalendarFrontend`: different repo, different PR stream, and it consumes endpoints that must
exist first. Write it after Task 14 lands.

## Design refinement discovered while planning

The spec treats a change request as one file. In practice, creating a diocesan calendar writes the calendar
**and** N i18n files (`RegionalDataHandler::writeI18nFiles()`), and an admin must not be able to approve the
calendar while rejecting its translations. This plan therefore groups rows by a `batch_id`, and **approval and
rejection operate on the batch, never on a single row**.

Save-equals-submit is implemented as _replace_: submitting a batch deletes the submitter's existing
`submitted` rows for the same resource inside the same transaction, then inserts the new batch. This gives
"one pending proposal per resource per editor" without upsert gymnastics.

## File Structure

**Create:**

| Path                                                        | Responsibility                                              |
| ----------------------------------------------------------- | ----------------------------------------------------------- |
| `src/Migrations/Version20260828120000.php`                  | `sourcedata_change_requests` table                          |
| `src/Enum/ChangeOperation.php`                              | `create` / `update` / `delete`                              |
| `src/Enum/ChangeReviewStatus.php`                           | `submitted` / `approved` / `rejected` / `withdrawn`         |
| `src/Enum/ChangePublicationStatus.php`                      | `none` / `queued` / `open` / `merged` / `closed`            |
| `src/Services/ChangeResource.php`                           | Identifies the resource an edit targets; maps it to OpenFGA |
| `src/Services/SourceData/SourceDataWriter.php`              | The seam: `stage()` then `commit()`                         |
| `src/Services/SourceData/DiskSourceDataWriter.php`          | Today's disk behaviour, extracted from the handlers         |
| `src/Services/SourceData/ChangeRequestSourceDataWriter.php` | Records a reviewable batch; touches no files                |
| `src/Services/SourceData/SourceDataWriteMode.php`           | Which writer this deployment uses, and why                  |
| `src/Handlers/Concerns/WritesSourceData.php`                | Handler-side staging and commit, mode-agnostic              |
| `src/Repositories/SourceDataChangeRequestRepository.php`    | All persistence for change requests                         |
| `src/Services/ChangeRequestReview.php`                      | Auto-approval decision and admin-scoped filtering           |
| `src/Handlers/Auth/ChangeRequestHandler.php`                | `GET /auth/change-requests`, withdraw                       |
| `src/Handlers/Admin/ChangeRequestAdminHandler.php`          | `GET /admin/change-requests`, approve, reject               |

**Modify:**

| Path                                                   | Change                                               |
| ------------------------------------------------------ | ---------------------------------------------------- |
| `phpunit_tests/Repositories/RepositoryTestCase.php:34` | Add the new table to `TABLES`                        |
| `src/Handlers/RegionalDataHandler.php`                 | 7 writes + 3 `unlink()` become stage + commit        |
| `src/Handlers/DecreesHandler.php`                      | 4 writes become stage + commit                       |
| `src/Handlers/TestsHandler.php:438,265`                | 1 write + 1 `unlink()` become stage + commit         |
| `src/Health.php`                                       | Report the source-data write mode                    |
| `.env.example`                                         | Document `SOURCEDATA_CHANGE_REQUESTS`                |
| `src/Router.php`                                       | Register the two new routes                          |
| `jsondata/schemas/openapi.json`                        | Document the new endpoints and the `disposition` key |

**Test:**

| Path                                                                   |
| ---------------------------------------------------------------------- |
| `phpunit_tests/Services/ChangeResourceTest.php`                        |
| `phpunit_tests/Repositories/SourceDataChangeRequestRepositoryTest.php` |
| `phpunit_tests/Services/ChangeRequestReviewTest.php`                   |
| `phpunit_tests/Services/SourceData/SourceDataWriteModeTest.php`        |
| `phpunit_tests/Services/SourceData/DiskSourceDataWriterTest.php`       |
| `phpunit_tests/Handlers/RegionalDataChangeRequestTest.php`             |
| `phpunit_tests/Handlers/DecreesChangeRequestTest.php`                  |
| `phpunit_tests/Handlers/TestsChangeRequestTest.php`                    |
| `phpunit_tests/Handlers/ChangeRequestAdminHandlerTest.php`             |

---

### Task 1: Migration and status enums

**Files:**

- Create: `src/Migrations/Version20260828120000.php`
- Create: `src/Enum/ChangeOperation.php`
- Create: `src/Enum/ChangeReviewStatus.php`
- Create: `src/Enum/ChangePublicationStatus.php`
- Modify: `phpunit_tests/Repositories/RepositoryTestCase.php:34`
- Test: `phpunit_tests/Repositories/SourceDataChangeRequestSchemaTest.php`

**Interfaces:**

- Consumes: nothing.
- Produces: table `sourcedata_change_requests`; `ChangeOperation`, `ChangeReviewStatus`,
  `ChangePublicationStatus` backed string enums whose `->value` matches the CHECK constraint literals.

- [ ] **Step 1: Write the three enums**

`src/Enum/ChangeOperation.php`:

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Enum;

/**
 * What a proposed change does to a single file under source data.
 *
 * `DELETE` replaces the `unlink()` calls the write handlers previously made
 * directly; the file is removed from the repository when the pull request
 * merges, never from the deployed tree.
 */
enum ChangeOperation: string
{
    case CREATE = 'create';
    case UPDATE = 'update';
    case DELETE = 'delete';
}
```

`src/Enum/ChangeReviewStatus.php`:

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Enum;

/**
 * Where a change request sits in OUR review workflow.
 *
 * Deliberately separate from {@see ChangePublicationStatus}, which tracks
 * GitHub's view of the same change. Flattening the two would make
 * "approved but the push failed" indistinguishable from "approved, awaiting
 * review on the pull request".
 */
enum ChangeReviewStatus: string
{
    case SUBMITTED = 'submitted';
    case APPROVED  = 'approved';
    case REJECTED  = 'rejected';
    case WITHDRAWN = 'withdrawn';
}
```

`src/Enum/ChangePublicationStatus.php`:

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Enum;

/**
 * Where a change request sits on GitHub.
 *
 * Phase 1 only ever writes `NONE`; the publisher (Phase 2) and merge polling
 * (Phase 3) drive the rest. The values exist now so the column and its CHECK
 * constraint do not need a migration later.
 */
enum ChangePublicationStatus: string
{
    case NONE   = 'none';
    case QUEUED = 'queued';
    case OPEN   = 'open';
    case MERGED = 'merged';
    case CLOSED = 'closed';
}
```

- [ ] **Step 2: Write the migration**

`src/Migrations/Version20260828120000.php`:

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Migrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Source-data change requests.
 *
 * One row per proposed file change. Rows sharing a `batch_id` were submitted by
 * one API write request and are reviewed together: a diocesan calendar and its
 * i18n files must not be approvable separately.
 *
 * Statuses are VARCHAR + CHECK rather than PostgreSQL ENUM types so that later
 * phases can add publication states without an ALTER TYPE inside a transactional
 * migration. This matches `access_requests`; `openfga_outbox` uses real enums and
 * is the exception, not the pattern.
 */
final class Version20260828120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create sourcedata_change_requests table (source-data change request workflow, phase 1)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !( $this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform ),
            'This migration targets PostgreSQL only.'
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE sourcedata_change_requests (
                id                          UUID         PRIMARY KEY DEFAULT gen_random_uuid(),
                batch_id                    UUID         NOT NULL,
                resource_type               VARCHAR(64)  NOT NULL,
                resource_id                 VARCHAR(255) NOT NULL,
                path                        TEXT         NOT NULL,
                operation                   VARCHAR(16)  NOT NULL,
                content                     TEXT         NULL,
                base_sha                    VARCHAR(64)  NULL,
                submitted_by_sub            VARCHAR(255) NOT NULL,
                submitted_by_name           VARCHAR(255) NULL,
                submitted_by_email          VARCHAR(255) NULL,
                submitted_by_email_verified BOOLEAN      NOT NULL DEFAULT FALSE,
                review_status               VARCHAR(20)  NOT NULL DEFAULT 'submitted',
                publication_status          VARCHAR(20)  NOT NULL DEFAULT 'none',
                approved_by_sub             VARCHAR(255) NULL,
                approved_at                 TIMESTAMPTZ  NULL,
                rejected_reason             TEXT         NULL,
                pr_number                   INTEGER      NULL,
                branch                      TEXT         NULL,
                commit_sha                  VARCHAR(64)  NULL,
                merge_commit_sha            VARCHAR(64)  NULL,
                metadata                    JSONB        NOT NULL DEFAULT '{}'::jsonb,
                created_at                  TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                updated_at                  TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                CONSTRAINT chk_scr_operation CHECK (operation IN ('create', 'update', 'delete')),
                CONSTRAINT chk_scr_review_status CHECK (review_status IN ('submitted', 'approved', 'rejected', 'withdrawn')),
                CONSTRAINT chk_scr_publication_status CHECK (publication_status IN ('none', 'queued', 'open', 'merged', 'closed')),
                CONSTRAINT chk_scr_delete_has_no_content CHECK (operation <> 'delete' OR content IS NULL),
                CONSTRAINT chk_scr_write_has_content CHECK (operation = 'delete' OR content IS NOT NULL)
            )
        SQL);

        $this->addSql('CREATE INDEX idx_scr_review_status ON sourcedata_change_requests (review_status, created_at)');
        $this->addSql('CREATE INDEX idx_scr_submitter ON sourcedata_change_requests (submitted_by_sub, created_at DESC)');
        $this->addSql('CREATE INDEX idx_scr_resource ON sourcedata_change_requests (resource_id, review_status)');
        $this->addSql('CREATE INDEX idx_scr_batch ON sourcedata_change_requests (batch_id)');

        // Save-equals-submit: one pending proposal per (path, submitter). The repository
        // deletes prior submitted rows for the resource before inserting a new batch, so
        // this is a defence-in-depth net against races and direct inserts, exactly as
        // idx_access_requests_unique_pending_user_role is for access_requests.
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX idx_scr_unique_pending_path_submitter
            ON sourcedata_change_requests (path, submitted_by_sub)
            WHERE review_status = 'submitted'
        SQL);

        $this->addSql("COMMENT ON TABLE sourcedata_change_requests IS 'Proposed edits to jsondata/sourcedata, reviewed here and published to GitHub as pull requests'");
        $this->addSql("COMMENT ON COLUMN sourcedata_change_requests.batch_id IS 'Rows submitted by one API write request; approved and rejected together'");
        $this->addSql("COMMENT ON COLUMN sourcedata_change_requests.path IS 'Repository-relative path, e.g. jsondata/sourcedata/rite/roman/calendars/nation/USA.json'");
        $this->addSql("COMMENT ON COLUMN sourcedata_change_requests.submitted_by_email_verified IS 'Only a verified email may be used as the git commit author email'");
        $this->addSql("COMMENT ON COLUMN sourcedata_change_requests.publication_status IS 'GitHub-side state; phase 1 only ever writes none'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS sourcedata_change_requests');
    }
}
```

- [ ] **Step 3: Add the table to the repository test base**

In `phpunit_tests/Repositories/RepositoryTestCase.php:34`, change:

```php
    protected const TABLES = ['api_keys', 'applications', 'access_requests', 'audit_log', 'openfga_outbox'];
```

to:

```php
    protected const TABLES = ['api_keys', 'applications', 'access_requests', 'audit_log', 'openfga_outbox', 'sourcedata_change_requests'];
```

- [ ] **Step 4: Write the failing schema test**

`phpunit_tests/Repositories/SourceDataChangeRequestSchemaTest.php`:

```php
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
```

- [ ] **Step 5: Run the test to verify it fails**

Run: `vendor/bin/phpunit phpunit_tests/Repositories/SourceDataChangeRequestSchemaTest.php`

Expected: FAIL — `relation "sourcedata_change_requests" does not exist`. If instead the whole class is
SKIPPED, your Postgres credentials are missing; set `DB_HOST`/`DB_NAME`/`DB_USER`/`DB_PASSWORD` before
continuing, because every repository task in this plan depends on them.

- [ ] **Step 6: Apply the migration**

Run: `vendor/bin/doctrine-migrations migrate --no-interaction`

Expected: `Version20260828120000` applied.

- [ ] **Step 7: Run the test to verify it passes**

Run: `vendor/bin/phpunit phpunit_tests/Repositories/SourceDataChangeRequestSchemaTest.php`

Expected: PASS, 5 tests.

- [ ] **Step 8: Lint, analyse, commit**

```bash
composer parallel-lint && composer lint:fix && composer analyse
git add src/Enum/ChangeOperation.php src/Enum/ChangeReviewStatus.php src/Enum/ChangePublicationStatus.php \
        src/Migrations/Version20260828120000.php \
        phpunit_tests/Repositories/RepositoryTestCase.php \
        phpunit_tests/Repositories/SourceDataChangeRequestSchemaTest.php
git commit -m "feat(data): add sourcedata_change_requests table and status enums"
```

---

### Task 2: `ChangeResource` — what an edit targets

**Files:**

- Create: `src/Services/ChangeResource.php`
- Test: `phpunit_tests/Services/ChangeResourceTest.php`

**Interfaces:**

- Consumes: `LiturgicalCalendar\Api\Enum\Rite`, `LiturgicalCalendar\Api\Services\RiteScopedObjectId`.
- Produces: `ChangeResource` with `public readonly string $type`, `public readonly string $id`; named
  constructors `nationalCalendar(Rite, string): self`, `diocesanCalendar(Rite, string): self`,
  `widerRegion(string): self`, `decrees(): self`, `test(Rite, string, string): self`; and methods
  `fgaPermission(): array{object_type: string, object_id: string, relation: string}` and `branch(): string`.
  Tasks 3, 6, 7, 8, 9, 10, 11, 13 all use these exact names.

**Why this exists:** `ResourceAdminService::filterByAdminAccess()` filters rows by a `permissions` key shaped
`{object_type, object_id, relation}`. Giving each change request batch a one-element permissions array built
by `fgaPermission()` lets Task 6 reuse that service **without modifying it**. `branch()` is unused in Phase 1
and exists so Phase 2's publisher does not have to re-derive naming.

- [ ] **Step 1: Write the failing test**

`phpunit_tests/Services/ChangeResourceTest.php`:

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services;

use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Services\ChangeResource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChangeResource::class)]
final class ChangeResourceTest extends TestCase
{
    public function testNationalCalendarUsesABareCalendarId(): void
    {
        $resource = ChangeResource::nationalCalendar(Rite::ROMAN, 'USA');

        self::assertSame('national_calendar', $resource->type);
        self::assertSame('USA', $resource->id);
    }

    public function testDiocesanCalendarUsesABareCalendarId(): void
    {
        $resource = ChangeResource::diocesanCalendar(Rite::AMBROSIAN, 'lugano_ch');

        self::assertSame('diocesan_calendar', $resource->type);
        self::assertSame('lugano_ch', $resource->id);
    }

    public function testDecreesIsTheGeneralRomanCalendarDecreesObject(): void
    {
        $resource = ChangeResource::decrees();

        self::assertSame('general_roman_calendar', $resource->type);
        self::assertSame('decrees', $resource->id);
    }

    public function testTestScopeIdsAreRiteQualified(): void
    {
        $resource = ChangeResource::test(Rite::AMBROSIAN, 'diocesan_calendar_test', 'lugano_ch');

        self::assertSame('diocesan_calendar_test', $resource->type);
        self::assertSame('ambrosian/lugano_ch', $resource->id);
    }

    public function testFgaPermissionAsksForTheAdminRelation(): void
    {
        $resource = ChangeResource::nationalCalendar(Rite::ROMAN, 'IT');

        self::assertSame(
            ['object_type' => 'national_calendar', 'object_id' => 'IT', 'relation' => 'admin'],
            $resource->fgaPermission()
        );
    }

    public function testBranchNameIsStablePerResource(): void
    {
        $resource = ChangeResource::diocesanCalendar(Rite::ROMAN, 'romamo_it');

        self::assertSame('litcal-data/diocesan_calendar/romamo_it', $resource->branch());
        self::assertSame($resource->branch(), ChangeResource::diocesanCalendar(Rite::ROMAN, 'romamo_it')->branch());
    }

    public function testWiderRegionRejectsAnEmptyId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ChangeResource::widerRegion('');
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit phpunit_tests/Services/ChangeResourceTest.php`

Expected: FAIL — `Class "LiturgicalCalendar\Api\Services\ChangeResource" not found`.

- [ ] **Step 3: Write the implementation**

`src/Services/ChangeResource.php`:

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services;

use LiturgicalCalendar\Api\Enum\Rite;

/**
 * Identifies the resource a source-data change targets.
 *
 * Object types match AccessRequestRepository::VALID_OBJECT_TYPES, so the
 * permission tuple produced by {@see fgaPermission()} can be handed straight to
 * ResourceAdminService without translation.
 *
 * Calendar types carry a bare calendar id; the scoped test types carry a
 * rite-qualified `<rite>/<calendarId>` id, because a bare calendar id does not
 * identify a calendar across rites. This mirrors the validation rules already
 * enforced by AccessRequestRepository::validateObjectId().
 */
final readonly class ChangeResource
{
    /** Test object types whose ids must be rite-qualified. */
    private const RITE_QUALIFIED_TEST_TYPES = [
        'national_calendar_test',
        'diocesan_calendar_test',
    ];

    private function __construct(
        public string $type,
        public string $id
    ) {
    }

    public static function nationalCalendar(Rite $rite, string $nation): self
    {
        return new self('national_calendar', self::requireNonEmpty($nation, 'nation'));
    }

    public static function diocesanCalendar(Rite $rite, string $diocese): self
    {
        return new self('diocesan_calendar', self::requireNonEmpty($diocese, 'diocese'));
    }

    public static function widerRegion(string $region): self
    {
        return new self('wider_region', self::requireNonEmpty($region, 'wider region'));
    }

    /**
     * The decrees corpus is a fixed object id on the general_roman_calendar type —
     * see AccessRequestRepository::GRC_OBJECT_IDS.
     */
    public static function decrees(): self
    {
        return new self('general_roman_calendar', 'decrees');
    }

    /**
     * @param string $objectType One of AccessRequestRepository::VALID_OBJECT_TYPES ending in `_test`.
     * @param string $calendarId The calendar the test is scoped to, unqualified.
     */
    public static function test(Rite $rite, string $objectType, string $calendarId): self
    {
        $calendarId = self::requireNonEmpty($calendarId, 'calendar id');

        $id = in_array($objectType, self::RITE_QUALIFIED_TEST_TYPES, true)
            ? RiteScopedObjectId::qualify($rite, $calendarId)
            : $calendarId;

        return new self($objectType, $id);
    }

    /**
     * The OpenFGA tuple asserting that a caller administers this resource.
     *
     * @return array{object_type: string, object_id: string, relation: string}
     */
    public function fgaPermission(): array
    {
        return [
            'object_type' => $this->type,
            'object_id'   => $this->id,
            'relation'    => 'admin',
        ];
    }

    /**
     * The git branch carrying this resource's rolling pull request.
     *
     * Unused in phase 1. Defined here so the publisher does not re-derive naming
     * from a different place and drift.
     */
    public function branch(): string
    {
        return sprintf('litcal-data/%s/%s', $this->type, $this->id);
    }

    private static function requireNonEmpty(string $value, string $label): string
    {
        if ($value === '') {
            throw new \InvalidArgumentException(sprintf('A change resource requires a non-empty %s', $label));
        }

        return $value;
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit phpunit_tests/Services/ChangeResourceTest.php`

Expected: PASS, 7 tests.

- [ ] **Step 5: Lint, analyse, commit**

```bash
composer parallel-lint && composer lint:fix && composer analyse
git add src/Services/ChangeResource.php phpunit_tests/Services/ChangeResourceTest.php
git commit -m "feat(data): add ChangeResource to identify what a source-data edit targets"
```

---

### Task 3: Repository — submit a batch, read it back

**Files:**

- Create: `src/Repositories/SourceDataChangeRequestRepository.php`
- Test: `phpunit_tests/Repositories/SourceDataChangeRequestRepositoryTest.php`

**Interfaces:**

- Consumes: `ChangeResource` (Task 2), `ChangeOperation`/`ChangeReviewStatus`/`ChangePublicationStatus` (Task
  1), `LiturgicalCalendar\Api\Database\Connection`.
- Produces:

```php
public function __construct(?PDO $db = null);

/**
 * @param list<array{path: string, operation: ChangeOperation, content: ?string}> $files
 * @param array<string, mixed> $metadata
 * @return string The batch id.
 */
public function submitBatch(
    ChangeResource $resource,
    array $files,
    string $submittedBySub,
    ?string $submittedByName,
    ?string $submittedByEmail,
    bool $submittedByEmailVerified,
    array $metadata = []
): string;

public function getById(string $id): ?array;

/** Rows ordered by `path`, each with the synthetic `permissions` key attached. */
public function getBatch(string $batchId): array;
```

Tasks 4, 5, 8–13 depend on these exact names.

**Note on `permissions`:** `getBatch()` and every listing method attach a synthetic `permissions` key holding
`[$resource->fgaPermission()]`. That is the shape `ResourceAdminService::filterByAdminAccess()` reads, and
attaching it here is what lets Task 6 reuse that service unmodified.

- [ ] **Step 1: Write the failing test**

`phpunit_tests/Repositories/SourceDataChangeRequestRepositoryTest.php`:

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Repositories;

use LiturgicalCalendar\Api\Enum\ChangeOperation;
use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Repositories\SourceDataChangeRequestRepository;
use LiturgicalCalendar\Api\Services\ChangeResource;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(SourceDataChangeRequestRepository::class)]
final class SourceDataChangeRequestRepositoryTest extends RepositoryTestCase
{
    private SourceDataChangeRequestRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new SourceDataChangeRequestRepository(self::$pdo);
    }

    /** @return list<array{path: string, operation: ChangeOperation, content: ?string}> */
    private function calendarWithTranslations(): array
    {
        return [
            [
                'path'      => 'jsondata/sourcedata/rite/roman/calendars/nation/USA.json',
                'operation' => ChangeOperation::CREATE,
                'content'   => '{"litcal":[]}',
            ],
            [
                'path'      => 'jsondata/sourcedata/rite/roman/calendars/nation/USA/i18n/en.json',
                'operation' => ChangeOperation::CREATE,
                'content'   => '{"key":"value"}',
            ],
        ];
    }

    private function submitUsa(string $sub = 'user-1'): string
    {
        return $this->repo->submitBatch(
            ChangeResource::nationalCalendar(Rite::ROMAN, 'USA'),
            $this->calendarWithTranslations(),
            $sub,
            'Alice',
            'alice@example.test',
            true,
            ['authorized_by' => 'admin']
        );
    }

    public function testSubmitBatchPersistsEveryFileUnderOneBatchId(): void
    {
        $batchId = $this->submitUsa();

        $rows = $this->repo->getBatch($batchId);
        self::assertCount(2, $rows);

        foreach ($rows as $row) {
            self::assertSame($batchId, $row['batch_id']);
            self::assertSame('national_calendar', $row['resource_type']);
            self::assertSame('USA', $row['resource_id']);
            self::assertSame('submitted', $row['review_status']);
            self::assertSame('none', $row['publication_status']);
            self::assertSame('user-1', $row['submitted_by_sub']);
            self::assertSame('Alice', $row['submitted_by_name']);
            self::assertSame('alice@example.test', $row['submitted_by_email']);
            self::assertTrue($row['submitted_by_email_verified']);
        }

        // Ordered by path, so the calendar precedes its i18n directory.
        self::assertSame('jsondata/sourcedata/rite/roman/calendars/nation/USA.json', $rows[0]['path']);
    }

    public function testGetBatchAttachesTheAdminPermissionTupleForFiltering(): void
    {
        $rows = $this->repo->getBatch($this->submitUsa());

        self::assertSame(
            [['object_type' => 'national_calendar', 'object_id' => 'USA', 'relation' => 'admin']],
            $rows[0]['permissions']
        );
    }

    public function testMetadataRoundTripsAsAnArray(): void
    {
        $rows = $this->repo->getBatch($this->submitUsa());

        self::assertSame(['authorized_by' => 'admin'], $rows[0]['metadata']);
    }

    public function testResubmittingReplacesTheSubmittersPendingRowsForThatResource(): void
    {
        $first = $this->submitUsa();

        $second = $this->repo->submitBatch(
            ChangeResource::nationalCalendar(Rite::ROMAN, 'USA'),
            [
                [
                    'path'      => 'jsondata/sourcedata/rite/roman/calendars/nation/USA.json',
                    'operation' => ChangeOperation::UPDATE,
                    'content'   => '{"litcal":[{"event_key":"NewFeast"}]}',
                ],
            ],
            'user-1',
            'Alice',
            'alice@example.test',
            true
        );

        self::assertNotSame($first, $second);
        self::assertSame([], $this->repo->getBatch($first), 'the superseded batch should be gone');

        $rows = $this->repo->getBatch($second);
        self::assertCount(1, $rows);
        self::assertSame('update', $rows[0]['operation']);
    }

    public function testAnotherSubmittersPendingRowsAreNotReplaced(): void
    {
        $mine = $this->submitUsa('user-1');
        $theirs = $this->repo->submitBatch(
            ChangeResource::nationalCalendar(Rite::ROMAN, 'USA'),
            [
                [
                    'path'      => 'jsondata/sourcedata/rite/roman/calendars/nation/USA/i18n/it.json',
                    'operation' => ChangeOperation::CREATE,
                    'content'   => '{"key":"valore"}',
                ],
            ],
            'user-2',
            'Bob',
            null,
            false
        );

        self::assertCount(2, $this->repo->getBatch($mine));
        self::assertCount(1, $this->repo->getBatch($theirs));
    }

    public function testDeleteOperationsCarryNoContent(): void
    {
        $batchId = $this->repo->submitBatch(
            ChangeResource::diocesanCalendar(Rite::ROMAN, 'romamo_it'),
            [
                [
                    'path'      => 'jsondata/sourcedata/rite/roman/calendars/diocese/romamo_it.json',
                    'operation' => ChangeOperation::DELETE,
                    'content'   => null,
                ],
            ],
            'user-1',
            'Alice',
            'alice@example.test',
            true
        );

        $rows = $this->repo->getBatch($batchId);
        self::assertSame('delete', $rows[0]['operation']);
        self::assertNull($rows[0]['content']);
    }

    public function testSubmitBatchRejectsAnEmptyFileList(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('at least one file');

        $this->repo->submitBatch(
            ChangeResource::nationalCalendar(Rite::ROMAN, 'USA'),
            [],
            'user-1',
            'Alice',
            'alice@example.test',
            true
        );
    }

    public function testGetByIdReturnsNullForAnUnknownId(): void
    {
        self::assertNull($this->repo->getById('00000000-0000-0000-0000-000000000000'));
    }

    public function testGetBatchReturnsAnEmptyArrayForAnUnknownBatch(): void
    {
        self::assertSame([], $this->repo->getBatch('00000000-0000-0000-0000-000000000000'));
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit phpunit_tests/Repositories/SourceDataChangeRequestRepositoryTest.php`

Expected: FAIL — `Class "LiturgicalCalendar\Api\Repositories\SourceDataChangeRequestRepository" not found`.

- [ ] **Step 3: Write the implementation**

`src/Repositories/SourceDataChangeRequestRepository.php`:

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Repositories;

use LiturgicalCalendar\Api\Database\Connection;
use LiturgicalCalendar\Api\Enum\ChangeOperation;
use LiturgicalCalendar\Api\Enum\ChangePublicationStatus;
use LiturgicalCalendar\Api\Enum\ChangeReviewStatus;
use LiturgicalCalendar\Api\Services\ChangeResource;
use PDO;

/**
 * Persistence for proposed edits to source data.
 *
 * A single API write request produces one BATCH: creating a diocesan calendar
 * writes the calendar and its i18n files, and those must be reviewed together.
 * Approval and rejection therefore act on a batch id, never on a row id.
 *
 * Save-equals-submit is implemented as replace: submitting deletes the
 * submitter's existing `submitted` rows for the same resource in the same
 * transaction. Other submitters' pending rows are untouched.
 */
class SourceDataChangeRequestRepository
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Connection::getInstance();
    }

    /**
     * Submit a batch of proposed file changes, superseding the submitter's own
     * pending proposal for this resource.
     *
     * @param list<array{path: string, operation: ChangeOperation, content: ?string}> $files
     * @param array<string, mixed> $metadata
     * @return string The batch id.
     */
    public function submitBatch(
        ChangeResource $resource,
        array $files,
        string $submittedBySub,
        ?string $submittedByName,
        ?string $submittedByEmail,
        bool $submittedByEmailVerified,
        array $metadata = []
    ): string {
        if ($files === []) {
            throw new \InvalidArgumentException('A change request batch must contain at least one file');
        }

        $batchId = $this->newBatchId();

        $this->db->beginTransaction();
        try {
            $supersede = $this->db->prepare(
                'DELETE FROM sourcedata_change_requests
                  WHERE resource_type = :resource_type
                    AND resource_id = :resource_id
                    AND submitted_by_sub = :sub
                    AND review_status = :submitted'
            );
            $supersede->execute([
                'resource_type' => $resource->type,
                'resource_id'   => $resource->id,
                'sub'           => $submittedBySub,
                'submitted'     => ChangeReviewStatus::SUBMITTED->value,
            ]);

            $insert = $this->db->prepare(
                'INSERT INTO sourcedata_change_requests
                    (batch_id, resource_type, resource_id, path, operation, content,
                     submitted_by_sub, submitted_by_name, submitted_by_email, submitted_by_email_verified,
                     review_status, publication_status, metadata)
                 VALUES
                    (:batch_id, :resource_type, :resource_id, :path, :operation, :content,
                     :sub, :name, :email, :email_verified,
                     :review_status, :publication_status, :metadata)'
            );

            foreach ($files as $file) {
                $insert->execute([
                    'batch_id'           => $batchId,
                    'resource_type'      => $resource->type,
                    'resource_id'        => $resource->id,
                    'path'               => $file['path'],
                    'operation'          => $file['operation']->value,
                    'content'            => $file['content'],
                    'sub'                => $submittedBySub,
                    'name'               => $submittedByName,
                    'email'              => $submittedByEmail,
                    'email_verified'     => $submittedByEmailVerified ? 'true' : 'false',
                    'review_status'      => ChangeReviewStatus::SUBMITTED->value,
                    'publication_status' => ChangePublicationStatus::NONE->value,
                    'metadata'           => json_encode($metadata) ?: '{}',
                ]);
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return $batchId;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getById(string $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM sourcedata_change_requests WHERE id = :id');
        $stmt->execute(['id' => $id]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $this->hydrate($row) : null;
    }

    /**
     * Every row in a batch, ordered by path so a calendar precedes its i18n files.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getBatch(string $batchId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM sourcedata_change_requests WHERE batch_id = :batch_id ORDER BY path ASC'
        );
        $stmt->execute(['batch_id' => $batchId]);

        return array_map(
            fn (array $row): array => $this->hydrate($row),
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    /**
     * Decode the JSONB and boolean columns, and attach the synthetic `permissions`
     * key that ResourceAdminService::filterByAdminAccess() reads.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    protected function hydrate(array $row): array
    {
        $metadata = is_string($row['metadata'] ?? null)
            ? json_decode($row['metadata'], true)
            : [];

        $row['metadata']                    = is_array($metadata) ? $metadata : [];
        $row['submitted_by_email_verified'] = self::toBool($row['submitted_by_email_verified'] ?? false);
        $row['permissions']                 = [
            [
                'object_type' => (string) $row['resource_type'],
                'object_id'   => (string) $row['resource_id'],
                'relation'    => 'admin',
            ],
        ];

        return $row;
    }

    /**
     * PDO's pgsql driver returns booleans as the strings 't'/'f' (or '1'/'0'
     * depending on the build), so normalise rather than casting.
     */
    private static function toBool(mixed $value): bool
    {
        return in_array($value, [true, 't', 'true', '1', 1], true);
    }

    private function newBatchId(): string
    {
        $stmt = $this->db->query('SELECT gen_random_uuid()');
        if ($stmt === false) {
            throw new \RuntimeException('Failed to generate a change request batch id');
        }

        return (string) $stmt->fetchColumn();
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit phpunit_tests/Repositories/SourceDataChangeRequestRepositoryTest.php`

Expected: PASS, 9 tests.

- [ ] **Step 5: Lint, analyse, commit**

```bash
composer parallel-lint && composer lint:fix && composer analyse
git add src/Repositories/SourceDataChangeRequestRepository.php \
        phpunit_tests/Repositories/SourceDataChangeRequestRepositoryTest.php
git commit -m "feat(data): persist source-data change requests as reviewable batches"
```

---

### Task 4: Repository — approve, reject, withdraw

**Files:**

- Modify: `src/Repositories/SourceDataChangeRequestRepository.php`
- Modify: `phpunit_tests/Repositories/SourceDataChangeRequestRepositoryTest.php`

**Interfaces:**

- Consumes: everything from Task 3.
- Produces:
  - `approveBatch(string $batchId, string $approvedBySub): int` — rows affected
  - `rejectBatch(string $batchId, string $rejectedBySub, ?string $reason = null): int`
  - `withdrawBatch(string $batchId, string $submittedBySub): int`

All three transition only rows still in `submitted`, so a second call is a no-op returning `0` rather than
resurrecting a decided batch. Task 12 and Task 13 call these.

- [ ] **Step 1: Write the failing tests**

Append to `phpunit_tests/Repositories/SourceDataChangeRequestRepositoryTest.php`:

```php
    public function testApproveBatchStampsTheApproverOnEveryRow(): void
    {
        $batchId = $this->submitUsa();

        self::assertSame(2, $this->repo->approveBatch($batchId, 'admin-1'));

        foreach ($this->repo->getBatch($batchId) as $row) {
            self::assertSame('approved', $row['review_status']);
            self::assertSame('admin-1', $row['approved_by_sub']);
            self::assertNotNull($row['approved_at']);
        }
    }

    public function testSelfApprovalIsRecordedAsSuch(): void
    {
        $batchId = $this->submitUsa('admin-1');
        $this->repo->approveBatch($batchId, 'admin-1');

        $row = $this->repo->getBatch($batchId)[0];
        self::assertSame($row['submitted_by_sub'], $row['approved_by_sub']);
    }

    public function testRejectBatchRecordsTheReason(): void
    {
        $batchId = $this->submitUsa();

        self::assertSame(2, $this->repo->rejectBatch($batchId, 'admin-1', 'Wrong feast rank'));

        foreach ($this->repo->getBatch($batchId) as $row) {
            self::assertSame('rejected', $row['review_status']);
            self::assertSame('admin-1', $row['approved_by_sub']);
            self::assertSame('Wrong feast rank', $row['rejected_reason']);
        }
    }

    public function testWithdrawBatchIsScopedToItsOwnSubmitter(): void
    {
        $batchId = $this->submitUsa('user-1');

        self::assertSame(0, $this->repo->withdrawBatch($batchId, 'user-2'), 'another user must not withdraw it');
        self::assertSame(2, $this->repo->withdrawBatch($batchId, 'user-1'));

        self::assertSame('withdrawn', $this->repo->getBatch($batchId)[0]['review_status']);
    }

    public function testADecidedBatchCannotBeDecidedAgain(): void
    {
        $batchId = $this->submitUsa();
        $this->repo->approveBatch($batchId, 'admin-1');

        self::assertSame(0, $this->repo->rejectBatch($batchId, 'admin-2', 'too late'));
        self::assertSame(0, $this->repo->approveBatch($batchId, 'admin-2'));

        $row = $this->repo->getBatch($batchId)[0];
        self::assertSame('approved', $row['review_status']);
        self::assertSame('admin-1', $row['approved_by_sub']);
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit phpunit_tests/Repositories/SourceDataChangeRequestRepositoryTest.php`

Expected: FAIL — `Call to undefined method ...::approveBatch()`.

- [ ] **Step 3: Write the implementation**

Add to `src/Repositories/SourceDataChangeRequestRepository.php`, after `getBatch()`:

```php
    /**
     * Approve every still-submitted row in the batch.
     *
     * @return int Rows transitioned. Zero means the batch was already decided.
     */
    public function approveBatch(string $batchId, string $approvedBySub): int
    {
        return $this->decideBatch($batchId, ChangeReviewStatus::APPROVED, $approvedBySub, null);
    }

    /**
     * @return int Rows transitioned. Zero means the batch was already decided.
     */
    public function rejectBatch(string $batchId, string $rejectedBySub, ?string $reason = null): int
    {
        return $this->decideBatch($batchId, ChangeReviewStatus::REJECTED, $rejectedBySub, $reason);
    }

    /**
     * Withdraw a batch. Only its own submitter may do this, which is enforced in
     * SQL rather than by the caller so a handler bug cannot widen it.
     *
     * @return int Rows transitioned. Zero means it was not theirs, or already decided.
     */
    public function withdrawBatch(string $batchId, string $submittedBySub): int
    {
        $stmt = $this->db->prepare(
            'UPDATE sourcedata_change_requests
                SET review_status = :withdrawn,
                    updated_at = NOW()
              WHERE batch_id = :batch_id
                AND submitted_by_sub = :sub
                AND review_status = :submitted'
        );
        $stmt->execute([
            'withdrawn' => ChangeReviewStatus::WITHDRAWN->value,
            'batch_id'  => $batchId,
            'sub'       => $submittedBySub,
            'submitted' => ChangeReviewStatus::SUBMITTED->value,
        ]);

        return $stmt->rowCount();
    }

    /**
     * The `review_status = 'submitted'` predicate is what makes a decision
     * single-shot: re-deciding an approved batch matches no rows.
     */
    private function decideBatch(
        string $batchId,
        ChangeReviewStatus $status,
        string $deciderSub,
        ?string $reason
    ): int {
        $stmt = $this->db->prepare(
            'UPDATE sourcedata_change_requests
                SET review_status = :status,
                    approved_by_sub = :decider,
                    approved_at = NOW(),
                    rejected_reason = :reason,
                    updated_at = NOW()
              WHERE batch_id = :batch_id
                AND review_status = :submitted'
        );
        $stmt->execute([
            'status'    => $status->value,
            'decider'   => $deciderSub,
            'reason'    => $reason,
            'batch_id'  => $batchId,
            'submitted' => ChangeReviewStatus::SUBMITTED->value,
        ]);

        return $stmt->rowCount();
    }
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/phpunit phpunit_tests/Repositories/SourceDataChangeRequestRepositoryTest.php`

Expected: PASS, 14 tests.

- [ ] **Step 5: Lint, analyse, commit**

```bash
composer parallel-lint && composer lint:fix && composer analyse
git add src/Repositories/SourceDataChangeRequestRepository.php \
        phpunit_tests/Repositories/SourceDataChangeRequestRepositoryTest.php
git commit -m "feat(data): add single-shot approve, reject and withdraw transitions"
```

---

### Task 5: Repository — listing and counts

**Files:**

- Modify: `src/Repositories/SourceDataChangeRequestRepository.php`
- Modify: `phpunit_tests/Repositories/SourceDataChangeRequestRepositoryTest.php`

**Interfaces:**

- Consumes: everything from Tasks 3 and 4.
- Produces:
  - `listBySubmitter(string $sub, ?ChangeReviewStatus $status = null, int $limit = 50, int $offset = 0): array`
  - `listAll(?ChangeReviewStatus $status = null, int $limit = 50, int $offset = 0): array`
  - `countBySubmitter(string $sub, ?ChangeReviewStatus $status = null): int`
  - `countAll(?ChangeReviewStatus $status = null): int`

Both list methods return **one entry per batch**, not per row, because a batch is the review unit. Each entry
carries `batch_id`, `resource_type`, `resource_id`, `review_status`, `publication_status`, `submitted_by_*`,
`approved_by_sub`, `created_at`, a `file_count`, a `paths` list, and the synthetic `permissions` key. Tasks 12
and 13 render these directly.

- [ ] **Step 1: Write the failing tests**

Append to `phpunit_tests/Repositories/SourceDataChangeRequestRepositoryTest.php`:

```php
    public function testListBySubmitterReturnsOneEntryPerBatch(): void
    {
        $this->submitUsa('user-1');
        $this->repo->submitBatch(
            ChangeResource::widerRegion('Americas'),
            [
                [
                    'path'      => 'jsondata/sourcedata/rite/roman/calendars/widerregion/Americas.json',
                    'operation' => ChangeOperation::UPDATE,
                    'content'   => '{"litcal":[]}',
                ],
            ],
            'user-1',
            'Alice',
            'alice@example.test',
            true
        );

        $batches = $this->repo->listBySubmitter('user-1');

        self::assertCount(2, $batches);
        // Newest first: the Americas batch (1 file) precedes the USA batch (2 files).
        self::assertSame([1, 2], [$batches[0]['file_count'], $batches[1]['file_count']]);
    }

    public function testListBySubmitterExcludesOtherSubmitters(): void
    {
        $this->submitUsa('user-1');
        $this->submitUsa('user-2');

        $batches = $this->repo->listBySubmitter('user-1');

        self::assertCount(1, $batches);
        self::assertSame('user-1', $batches[0]['submitted_by_sub']);
    }

    public function testListBatchesCarryTheirPathsAndPermissions(): void
    {
        $this->submitUsa('user-1');

        $batch = $this->repo->listBySubmitter('user-1')[0];

        self::assertSame(2, $batch['file_count']);
        self::assertContains('jsondata/sourcedata/rite/roman/calendars/nation/USA.json', $batch['paths']);
        self::assertSame(
            [['object_type' => 'national_calendar', 'object_id' => 'USA', 'relation' => 'admin']],
            $batch['permissions']
        );
    }

    public function testListAllCanFilterByReviewStatus(): void
    {
        $approved = $this->submitUsa('user-1');
        $this->repo->approveBatch($approved, 'admin-1');
        $this->submitUsa('user-2');

        self::assertCount(2, $this->repo->listAll());
        self::assertCount(1, $this->repo->listAll(ChangeReviewStatus::APPROVED));
        self::assertCount(1, $this->repo->listAll(ChangeReviewStatus::SUBMITTED));
    }

    public function testCountsMatchTheListings(): void
    {
        $this->submitUsa('user-1');
        $this->submitUsa('user-2');

        self::assertSame(2, $this->repo->countAll());
        self::assertSame(2, $this->repo->countAll(ChangeReviewStatus::SUBMITTED));
        self::assertSame(1, $this->repo->countBySubmitter('user-1'));
    }

    public function testListingIsNewestFirst(): void
    {
        $older = $this->submitUsa('user-1');
        self::$pdo->exec("UPDATE sourcedata_change_requests SET created_at = NOW() - INTERVAL '1 day'");
        $newer = $this->repo->submitBatch(
            ChangeResource::widerRegion('Europe'),
            [
                [
                    'path'      => 'jsondata/sourcedata/rite/roman/calendars/widerregion/Europe.json',
                    'operation' => ChangeOperation::UPDATE,
                    'content'   => '{"litcal":[]}',
                ],
            ],
            'user-1',
            'Alice',
            'alice@example.test',
            true
        );

        $batches = $this->repo->listBySubmitter('user-1');

        self::assertSame($newer, $batches[0]['batch_id']);
        self::assertSame($older, $batches[1]['batch_id']);
    }
```

Add the import at the top of the file:

```php
use LiturgicalCalendar\Api\Enum\ChangeReviewStatus;
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit phpunit_tests/Repositories/SourceDataChangeRequestRepositoryTest.php`

Expected: FAIL — `Call to undefined method ...::listBySubmitter()`.

- [ ] **Step 3: Write the implementation**

Add to `src/Repositories/SourceDataChangeRequestRepository.php`:

```php
    /**
     * @return array<int, array<string, mixed>> One entry per batch, newest first.
     */
    public function listBySubmitter(
        string $sub,
        ?ChangeReviewStatus $status = null,
        int $limit = 50,
        int $offset = 0
    ): array {
        return $this->listBatches('submitted_by_sub = :sub', ['sub' => $sub], $status, $limit, $offset);
    }

    /**
     * @return array<int, array<string, mixed>> One entry per batch, newest first.
     */
    public function listAll(?ChangeReviewStatus $status = null, int $limit = 50, int $offset = 0): array
    {
        return $this->listBatches('TRUE', [], $status, $limit, $offset);
    }

    public function countBySubmitter(string $sub, ?ChangeReviewStatus $status = null): int
    {
        return $this->countBatches('submitted_by_sub = :sub', ['sub' => $sub], $status);
    }

    public function countAll(?ChangeReviewStatus $status = null): int
    {
        return $this->countBatches('TRUE', [], $status);
    }

    /**
     * Collapse rows into batches.
     *
     * Every row in a batch shares its resource, submitter and statuses — they are
     * written together and transitioned together — so MIN() over those columns is
     * exact, not an approximation.
     *
     * @param array<string, string> $params
     * @return array<int, array<string, mixed>>
     */
    private function listBatches(
        string $predicate,
        array $params,
        ?ChangeReviewStatus $status,
        int $limit,
        int $offset
    ): array {
        if ($status !== null) {
            $predicate .= ' AND review_status = :review_status';
            $params['review_status'] = $status->value;
        }

        $sql = 'SELECT batch_id,
                       MIN(resource_type)       AS resource_type,
                       MIN(resource_id)         AS resource_id,
                       MIN(review_status)       AS review_status,
                       MIN(publication_status)  AS publication_status,
                       MIN(submitted_by_sub)    AS submitted_by_sub,
                       MIN(submitted_by_name)   AS submitted_by_name,
                       MIN(submitted_by_email)  AS submitted_by_email,
                       MIN(approved_by_sub)     AS approved_by_sub,
                       MIN(created_at)          AS created_at,
                       MAX(updated_at)          AS updated_at,
                       COUNT(*)                 AS file_count,
                       ARRAY_AGG(path ORDER BY path) AS paths
                  FROM sourcedata_change_requests
                 WHERE ' . $predicate . '
              GROUP BY batch_id
              ORDER BY MIN(created_at) DESC
                 LIMIT :limit OFFSET :offset';

        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return array_map(
            fn (array $row): array => $this->hydrateBatch($row),
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    /**
     * @param array<string, string> $params
     */
    private function countBatches(string $predicate, array $params, ?ChangeReviewStatus $status): int
    {
        if ($status !== null) {
            $predicate .= ' AND review_status = :review_status';
            $params['review_status'] = $status->value;
        }

        $stmt = $this->db->prepare(
            'SELECT COUNT(DISTINCT batch_id) FROM sourcedata_change_requests WHERE ' . $predicate
        );
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function hydrateBatch(array $row): array
    {
        $row['file_count']  = (int) $row['file_count'];
        $row['paths']       = self::parsePgArray((string) $row['paths']);
        $row['permissions'] = [
            [
                'object_type' => (string) $row['resource_type'],
                'object_id'   => (string) $row['resource_id'],
                'relation'    => 'admin',
            ],
        ];

        return $row;
    }

    /**
     * PDO's pgsql driver hands back ARRAY_AGG as the literal `{a,b}` text form,
     * with double quotes around any element containing a comma or brace. Paths
     * contain neither, but parse defensively rather than assuming.
     *
     * @return list<string>
     */
    private static function parsePgArray(string $literal): array
    {
        $inner = trim($literal, '{}');
        if ($inner === '') {
            return [];
        }

        return array_map(
            static fn (string $element): string => trim($element, '"'),
            str_getcsv($inner, ',', '"', '\\')
        );
    }
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/phpunit phpunit_tests/Repositories/SourceDataChangeRequestRepositoryTest.php`

Expected: PASS, 20 tests.

- [ ] **Step 5: Lint, analyse, commit**

```bash
composer parallel-lint && composer lint:fix && composer analyse
git add src/Repositories/SourceDataChangeRequestRepository.php \
        phpunit_tests/Repositories/SourceDataChangeRequestRepositoryTest.php
git commit -m "feat(data): list and count change requests by batch"
```

---

### Task 6: `ChangeRequestReview` — who may approve, and who sees what

**Files:**

- Create: `src/Services/ChangeRequestReview.php`
- Test: `phpunit_tests/Services/ChangeRequestReviewTest.php`

**Interfaces:**

- Consumes: `ChangeResource` (Task 2), `ResourceAdminService`, `OpenFgaClient`.
- Produces:
  - `__construct(ResourceAdminService $resourceAdmin)`
  - `administers(ChangeResource $resource, string $sub): bool` — Task 8 uses this to auto-approve.
  - `filterForAdmin(array $batches, string $adminSub): array` — Task 13 uses this.

`ResourceAdminService` is `final`, so it is injected rather than mocked; tests build a real one over a
`MockHandler`-backed `OpenFgaClient`, exactly as `ResourceAdminServiceTest::serviceWith()` does.

**Fail-closed:** when OpenFGA is unreachable, `administers()` returns `false`. The change is still recorded as
`submitted` — it just is not auto-approved. Never the other way round.

- [ ] **Step 1: Write the failing test**

`phpunit_tests/Services/ChangeRequestReviewTest.php`:

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Services\ChangeRequestReview;
use LiturgicalCalendar\Api\Services\ChangeResource;
use LiturgicalCalendar\Api\Services\OpenFgaClient;
use LiturgicalCalendar\Api\Services\ResourceAdminService;
use LiturgicalCalendar\Tests\Support\CollectingLogger;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChangeRequestReview::class)]
final class ChangeRequestReviewTest extends TestCase
{
    /** @param array<int, GuzzleResponse> $responses */
    private function reviewWith(array $responses): ChangeRequestReview
    {
        $guzzle = new GuzzleClient(['handler' => HandlerStack::create(new MockHandler($responses))]);
        $psr17  = new Psr17Factory();
        $client = new OpenFgaClient(
            apiUrl: 'http://openfga.test',
            storeId: 'test-store',
            modelId: 'test-model',
            httpClient: $guzzle,
            requestFactory: $psr17,
            streamFactory: $psr17,
            apiToken: 'test-token'
        );

        return new ChangeRequestReview(new ResourceAdminService($client, new CollectingLogger()));
    }

    private static function allowed(bool $allowed): GuzzleResponse
    {
        return new GuzzleResponse(200, [], json_encode(['allowed' => $allowed]));
    }

    public function testAdministersIsTrueWhenOpenFgaAllowsTheAdminRelation(): void
    {
        $review = $this->reviewWith([self::allowed(true)]);

        self::assertTrue($review->administers(ChangeResource::nationalCalendar(Rite::ROMAN, 'USA'), 'admin-1'));
    }

    public function testAdministersIsFalseWhenOpenFgaDenies(): void
    {
        $review = $this->reviewWith([self::allowed(false)]);

        self::assertFalse($review->administers(ChangeResource::nationalCalendar(Rite::ROMAN, 'USA'), 'editor-1'));
    }

    public function testAdministersFailsClosedWhenOpenFgaIsUnreachable(): void
    {
        $review = $this->reviewWith([new GuzzleResponse(500, [], 'boom')]);

        self::assertFalse($review->administers(ChangeResource::nationalCalendar(Rite::ROMAN, 'USA'), 'admin-1'));
    }

    public function testFilterForAdminKeepsOnlyAdministeredBatches(): void
    {
        $review = $this->reviewWith([self::allowed(true), self::allowed(false)]);

        $batches = [
            ['batch_id' => 'b1', 'permissions' => [['object_type' => 'national_calendar', 'object_id' => 'USA', 'relation' => 'admin']]],
            ['batch_id' => 'b2', 'permissions' => [['object_type' => 'national_calendar', 'object_id' => 'ITALY', 'relation' => 'admin']]],
        ];

        $kept = $review->filterForAdmin($batches, 'admin-1');

        self::assertCount(1, $kept);
        self::assertSame('b1', $kept[0]['batch_id']);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit phpunit_tests/Services/ChangeRequestReviewTest.php`

Expected: FAIL — `Class "LiturgicalCalendar\Api\Services\ChangeRequestReview" not found`.

- [ ] **Step 3: Write the implementation**

`src/Services/ChangeRequestReview.php`:

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services;

/**
 * The two authorization questions the change request workflow asks.
 *
 * Both delegate to ResourceAdminService, which already caches and budgets its
 * OpenFGA fan-out. This class exists so the auto-approval rule lives in exactly
 * one place: a submitter who administers the resource is approved at submit
 * time, and GitHub pull request review is the second pair of eyes.
 */
final readonly class ChangeRequestReview
{
    public function __construct(private ResourceAdminService $resourceAdmin)
    {
    }

    /**
     * Whether $sub holds the `admin` relation on $resource.
     *
     * Fails closed: an unreachable OpenFGA yields false, so the change is
     * recorded as `submitted` and waits for a human. It must never yield true.
     */
    public function administers(ChangeResource $resource, string $sub): bool
    {
        /** @var array<string, bool> $cache */
        $cache = [];

        try {
            return $this->resourceAdmin->administersAllResources(
                [$resource->fgaPermission()],
                'user:' . $sub,
                $cache
            );
        } catch (\RuntimeException) {
            return false;
        }
    }

    /**
     * Keep only the batches whose resource $adminSub administers.
     *
     * Each batch carries the synthetic `permissions` key that
     * SourceDataChangeRequestRepository attaches, which is exactly the shape
     * filterByAdminAccess() reads — so no translation happens here.
     *
     * @param array<int, array<string, mixed>> $batches
     * @return array<int, array<string, mixed>>
     */
    public function filterForAdmin(array $batches, string $adminSub): array
    {
        return $this->resourceAdmin->filterByAdminAccess($batches, $adminSub);
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit phpunit_tests/Services/ChangeRequestReviewTest.php`

Expected: PASS, 4 tests.

- [ ] **Step 5: Lint, analyse, commit**

```bash
composer parallel-lint && composer lint:fix && composer analyse
git add src/Services/ChangeRequestReview.php phpunit_tests/Services/ChangeRequestReviewTest.php
git commit -m "feat(data): decide auto-approval and admin visibility for change requests"
```

---

### Task 7: `SourceDataWriter` seam, disk implementation, and mode selection

**Files:**

- Create: `src/Services/SourceData/SourceDataWriter.php`
- Create: `src/Services/SourceData/DiskSourceDataWriter.php`
- Create: `src/Services/SourceData/SourceDataWriteMode.php`
- Modify: `src/Health.php` — add the write-mode check
- Modify: `.env.example` — document `SOURCEDATA_CHANGE_REQUESTS`
- Test: `phpunit_tests/Services/SourceData/DiskSourceDataWriterTest.php`
- Test: `phpunit_tests/Services/SourceData/SourceDataWriteModeTest.php`

**Interfaces:**

- Consumes: `ChangeOperation` (Task 1), `ChangeResource` (Task 2).
- Produces:

```php
interface SourceDataWriter
{
    /** Record a file this request would write. $content is null only for DELETE. */
    public function stage(string $absolutePath, ChangeOperation $operation, ?string $content): void;

    /**
     * Apply everything staged so far and describe what happened.
     *
     * @return array<string, mixed> Always carries a `disposition` key.
     */
    public function commit(ChangeResource $resource): array;
}
```

Task 8 supplies the second implementation; tasks 9, 10 and 11 call these through the trait.

**Why a seam rather than a branch:** the API is self-hostable. A diocese running it without Zitadel, OpenFGA
and Postgres must keep working exactly as it does today — `Router.php:775` already branches on
`Connection::isConfigured()`, and `AuthorizationMiddleware` gates `/data` writes on JWT roles rather than
OpenFGA, so a Postgres-less authoring deployment is a supported shape. One interface with two implementations
keeps that guarantee in one place instead of ten mode-aware call sites, and keeps today's disk behaviour under
test rather than deleting it.

- [ ] **Step 1: Write the failing mode-selection test**

`phpunit_tests/Services/SourceData/SourceDataWriteModeTest.php`:

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services\SourceData;

use LiturgicalCalendar\Api\Services\SourceData\SourceDataWriteMode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SourceDataWriteMode::class)]
final class SourceDataWriteModeTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $originalEnv = [];

    protected function setUp(): void
    {
        foreach (['SOURCEDATA_CHANGE_REQUESTS', 'DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASSWORD', 'OPENFGA_API_URL', 'OPENFGA_STORE_ID', 'OPENFGA_MODEL_ID'] as $key) {
            $this->originalEnv[$key] = $_ENV[$key] ?? false;
            unset($_ENV[$key]);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->originalEnv as $key => $value) {
            if ($value === false) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $value;
            }
        }
    }

    private function withFullStack(): void
    {
        $_ENV['DB_HOST']          = 'localhost';
        $_ENV['DB_NAME']          = 'litcal';
        $_ENV['DB_USER']          = 'litcal';
        $_ENV['DB_PASSWORD']      = 'secret';
        $_ENV['OPENFGA_API_URL']  = 'http://openfga.test';
        $_ENV['OPENFGA_STORE_ID'] = 'store';
        $_ENV['OPENFGA_MODEL_ID'] = 'model';
    }

    public function testDefaultsToDiskWhenTheFlagIsAbsent(): void
    {
        $this->withFullStack();

        self::assertFalse(SourceDataWriteMode::changeRequestsEnabled());
    }

    public function testIsDiskWhenTheFlagIsExplicitlyFalse(): void
    {
        $this->withFullStack();
        $_ENV['SOURCEDATA_CHANGE_REQUESTS'] = 'false';

        self::assertFalse(SourceDataWriteMode::changeRequestsEnabled());
    }

    public function testIsQueueWhenTheFlagIsTrueAndTheStackIsPresent(): void
    {
        $this->withFullStack();
        $_ENV['SOURCEDATA_CHANGE_REQUESTS'] = 'true';

        self::assertTrue(SourceDataWriteMode::changeRequestsEnabled());
    }

    public function testFallsBackToDiskWhenTheFlagIsTrueButPostgresIsMissing(): void
    {
        $_ENV['SOURCEDATA_CHANGE_REQUESTS'] = 'true';
        $_ENV['OPENFGA_API_URL']            = 'http://openfga.test';
        $_ENV['OPENFGA_STORE_ID']           = 'store';
        $_ENV['OPENFGA_MODEL_ID']           = 'model';

        self::assertFalse(SourceDataWriteMode::changeRequestsEnabled());
    }

    public function testFallsBackToDiskWhenTheFlagIsTrueButOpenFgaIsMissing(): void
    {
        $_ENV['SOURCEDATA_CHANGE_REQUESTS'] = 'true';
        $_ENV['DB_HOST']                    = 'localhost';
        $_ENV['DB_NAME']                    = 'litcal';
        $_ENV['DB_USER']                    = 'litcal';
        $_ENV['DB_PASSWORD']                = 'secret';

        // Queue mode without OpenFGA would accept edits nobody could ever approve,
        // because ChangeRequestReview::administers() fails closed.
        self::assertFalse(SourceDataWriteMode::changeRequestsEnabled());
    }

    public function testAMisconfiguredFlagIsReportedSoItCanBeLogged(): void
    {
        $_ENV['SOURCEDATA_CHANGE_REQUESTS'] = 'true';

        self::assertTrue(SourceDataWriteMode::isMisconfigured());
        self::assertFalse(SourceDataWriteMode::changeRequestsEnabled());
    }

    public function testDiskModeWithNoStackIsNotMisconfigured(): void
    {
        self::assertFalse(SourceDataWriteMode::isMisconfigured());
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit phpunit_tests/Services/SourceData/SourceDataWriteModeTest.php`

Expected: FAIL — `Class "…\SourceDataWriteMode" not found`.

- [ ] **Step 3: Write the interface and the mode selector**

`src/Services/SourceData/SourceDataWriter.php`:

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services\SourceData;

use LiturgicalCalendar\Api\Enum\ChangeOperation;
use LiturgicalCalendar\Api\Services\ChangeResource;

/**
 * How a write handler puts source data somewhere.
 *
 * Two implementations exist: {@see DiskSourceDataWriter} writes files, which is
 * what a self-hosted deployment without Postgres and OpenFGA does and always
 * has; {@see ChangeRequestSourceDataWriter} records a reviewable proposal and
 * touches no files.
 *
 * Handlers stage each file and commit once per request, and never know which
 * implementation is behind the interface.
 */
interface SourceDataWriter
{
    /**
     * Record a file this request would write.
     *
     * @param string  $absolutePath The on-disk path the handler targets.
     * @param ?string $content      Null only for ChangeOperation::DELETE.
     */
    public function stage(string $absolutePath, ChangeOperation $operation, ?string $content): void;

    /**
     * Apply everything staged so far, and describe what happened.
     *
     * @return array<string, mixed> Always carries a `disposition` key: `applied`
     *                              when the files are now on disk, `submitted` or
     *                              `approved` when a proposal was recorded.
     */
    public function commit(ChangeResource $resource): array;
}
```

`src/Services/SourceData/SourceDataWriteMode.php`:

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services\SourceData;

use LiturgicalCalendar\Api\Database\Connection;
use LiturgicalCalendar\Api\Services\OpenFgaClient;

/**
 * Whether this deployment records source-data edits as change requests, or
 * writes them straight to disk the way it always has.
 *
 * Opt-in and fail-safe. The flag alone is not enough: queue mode needs Postgres
 * to store proposals and OpenFGA to decide who may approve them. A flag set
 * without that stack behind it falls back to disk and reports itself as
 * misconfigured, rather than accepting edits nobody could ever approve.
 */
final class SourceDataWriteMode
{
    public const FLAG = 'SOURCEDATA_CHANGE_REQUESTS';

    /**
     * True when edits should become change requests rather than files.
     */
    public static function changeRequestsEnabled(): bool
    {
        return self::flagSet() && self::stackAvailable();
    }

    /**
     * True when the operator asked for queue mode but the stack cannot support it.
     *
     * Callers log this and Health surfaces it; the request itself still succeeds
     * in disk mode.
     */
    public static function isMisconfigured(): bool
    {
        return self::flagSet() && !self::stackAvailable();
    }

    /**
     * True when this deployment is writing to disk despite having everything
     * queue mode needs — almost always a forgotten flag on a host that rsyncs
     * `--delete` from git, where the next deploy silently reverts the edit.
     */
    public static function isUnexpectedlyWritingToDisk(): bool
    {
        return !self::flagSet() && self::stackAvailable();
    }

    private static function flagSet(): bool
    {
        return 'true' === strtolower(trim((string) ( $_ENV[self::FLAG] ?? 'false' )));
    }

    private static function stackAvailable(): bool
    {
        return Connection::isConfigured() && OpenFgaClient::isConfigured();
    }
}
```

- [ ] **Step 4: Run the mode test to verify it passes**

Run: `vendor/bin/phpunit phpunit_tests/Services/SourceData/SourceDataWriteModeTest.php`

Expected: PASS, 7 tests.

- [ ] **Step 5: Write the failing disk-writer test**

`phpunit_tests/Services/SourceData/DiskSourceDataWriterTest.php`:

```php
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

        $result = $writer->commit(ChangeResource::nationalCalendar(Rite::ROMAN, 'USA'));

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
        $writer->commit(ChangeResource::nationalCalendar(Rite::ROMAN, 'USA'));

        self::assertFileDoesNotExist($this->tmp . '/obsolete.json');
    }

    public function testDeletingAnAbsentFileIsNotAnError(): void
    {
        $writer = new DiskSourceDataWriter();
        $writer->stage($this->tmp . '/never-existed.json', ChangeOperation::DELETE, null);

        $result = $writer->commit(ChangeResource::nationalCalendar(Rite::ROMAN, 'USA'));

        self::assertSame('applied', $result['disposition']);
    }

    public function testAnUnwritablePathRaisesServiceUnavailable(): void
    {
        $writer = new DiskSourceDataWriter();
        $writer->stage($this->tmp . '/no-such-dir/calendar.json', ChangeOperation::CREATE, '{}');

        $this->expectException(ServiceUnavailableException::class);
        $writer->commit(ChangeResource::nationalCalendar(Rite::ROMAN, 'USA'));
    }

    public function testCommitClearsTheStagingArea(): void
    {
        $writer = new DiskSourceDataWriter();
        $writer->stage($this->tmp . '/calendar.json', ChangeOperation::CREATE, '{"first":true}');
        $writer->commit(ChangeResource::nationalCalendar(Rite::ROMAN, 'USA'));

        unlink($this->tmp . '/calendar.json');

        $writer->stage($this->tmp . '/other.json', ChangeOperation::CREATE, '{}');
        $writer->commit(ChangeResource::nationalCalendar(Rite::ROMAN, 'USA'));

        self::assertFileDoesNotExist($this->tmp . '/calendar.json');
        self::assertFileExists($this->tmp . '/other.json');
    }
}
```

- [ ] **Step 6: Run it to verify it fails**

Run: `vendor/bin/phpunit phpunit_tests/Services/SourceData/DiskSourceDataWriterTest.php`

Expected: FAIL — `Class "…\DiskSourceDataWriter" not found`.

- [ ] **Step 7: Write the disk implementation**

`src/Services/SourceData/DiskSourceDataWriter.php`:

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services\SourceData;

use LiturgicalCalendar\Api\Enum\ChangeOperation;
use LiturgicalCalendar\Api\Http\Exception\ServiceUnavailableException;
use LiturgicalCalendar\Api\Services\ChangeResource;

/**
 * Writes source data to disk — the behaviour every deployment had before change
 * requests existed, and the behaviour a self-hosted instance without Postgres
 * and OpenFGA keeps.
 *
 * The logic here is lifted from RegionalDataHandler, DecreesHandler and
 * TestsHandler rather than rewritten: same `file_put_contents()`, same
 * `unlink()`, same ServiceUnavailableException on failure.
 *
 * Staging is deferred rather than immediate so that both implementations share
 * one contract, and so a request that fails validation half way through has not
 * already half-written the calendar.
 */
final class DiskSourceDataWriter implements SourceDataWriter
{
    /** @var list<array{path: string, operation: ChangeOperation, content: ?string}> */
    private array $staged = [];

    public function stage(string $absolutePath, ChangeOperation $operation, ?string $content): void
    {
        $this->staged[] = [
            'path'      => $absolutePath,
            'operation' => $operation,
            'content'   => $content,
        ];
    }

    public function commit(ChangeResource $resource): array
    {
        $staged       = $this->staged;
        $this->staged = [];

        foreach ($staged as $file) {
            if ($file['operation'] === ChangeOperation::DELETE) {
                $this->removeFile($file['path']);
                continue;
            }

            $this->writeFile($file['path'], (string) $file['content']);
        }

        return ['disposition' => 'applied'];
    }

    private function writeFile(string $path, string $content): void
    {
        if (false === file_put_contents($path, $content)) {
            throw new ServiceUnavailableException('Failed to write to file ' . $path);
        }
    }

    /**
     * A missing file is not an error: the previous handler code reached `unlink()`
     * only after its own existence checks, and re-deleting is idempotent from the
     * caller's point of view.
     */
    private function removeFile(string $path): void
    {
        if (false === file_exists($path)) {
            return;
        }

        if (false === unlink($path)) {
            throw new ServiceUnavailableException('Failed to delete file ' . $path);
        }
    }
}
```

- [ ] **Step 8: Run the disk-writer test to verify it passes**

Run: `vendor/bin/phpunit phpunit_tests/Services/SourceData/DiskSourceDataWriterTest.php`

Expected: PASS, 6 tests.

- [ ] **Step 9: Add the Health check**

In `src/Health.php`, add a check reporting the source-data write mode:

- queue mode → OK, message `source data writes are recorded as change requests`
- disk mode with no stack present → OK, message
  `source data writes go to disk (no change request stack configured)`
- disk mode with the full stack present (`SourceDataWriteMode::isUnexpectedlyWritingToDisk()`) → **WARNING**,
  message
  `source data writes go to disk, but this deployment has Postgres and OpenFGA configured; if it deploys by rsync --delete from git, edits will be reverted on the next deploy`
- `SourceDataWriteMode::isMisconfigured()` → **WARNING**, message
  `SOURCEDATA_CHANGE_REQUESTS is set but Postgres or OpenFGA is not configured; falling back to disk writes`

Follow the shape of the existing checks in that file; do not invent a new reporting mechanism.

- [ ] **Step 10: Document the flag**

In `.env.example`, beside the other feature settings, add:

```dotenv
# Record source-data edits as reviewable change requests instead of writing them
# straight to disk. Requires Postgres (DB_*) and OpenFGA (OPENFGA_*); without both,
# this falls back to disk writes and Health reports the misconfiguration.
# Leave false for a self-hosted instance that has neither.
SOURCEDATA_CHANGE_REQUESTS=false
```

- [ ] **Step 11: Run the full suite**

Run: `composer test`

Expected: PASS.

- [ ] **Step 12: Lint, analyse, commit**

```bash
composer parallel-lint && composer lint:fix && composer analyse
git add src/Services/SourceData/ src/Health.php .env.example phpunit_tests/Services/SourceData/
git commit -m "feat(data): add the source-data writer seam and its disk implementation"
```

---

### Task 8: Change-request writer, `WritesSourceData` trait, and RegionalDataHandler create paths

**Files:**

- Create: `src/Services/SourceData/ChangeRequestSourceDataWriter.php`
- Create: `src/Handlers/Concerns/WritesSourceData.php`
- Modify: `src/Handlers/RegionalDataHandler.php` — class properties, `handle()`, `createDiocesanCalendar()`
  (line 330), `createNationalCalendar()` (line 437), `createWiderRegionCalendar()` (line 571),
  `writeI18nFiles()` (line 1208)
- Test: `phpunit_tests/Handlers/RegionalDataChangeRequestTest.php`

**Interfaces:**

- Consumes: `SourceDataWriter`, `SourceDataWriteMode` (Task 7); `SourceDataChangeRequestRepository` (Tasks
  3–5); `ChangeRequestReview` (Task 6); `ChangeResource` (Task 2); `ChangeOperation` (Task 1).
- Produces `ChangeRequestSourceDataWriter implements SourceDataWriter`, and on the trait:
  - `captureSubmitter(ServerRequestInterface $request): void` — reads the `oidc_user` attribute
  - `stageFile(string $absolutePath, ChangeOperation $operation, ?string $content): void` — delegates
  - `commitStagedFiles(ChangeResource $resource): array` — delegates, returns the response body
  - `sourceDataWriter(): SourceDataWriter` — mode-selected, memoised per request

Tasks 9, 10 and 11 use these exact names.

**The handlers never branch on mode.** `sourceDataWriter()` returns `DiskSourceDataWriter` or
`ChangeRequestSourceDataWriter` per `SourceDataWriteMode::changeRequestsEnabled()`, and every write site calls
the same two methods either way.

**Response shape** returned by `commitStagedFiles()` — the `disposition` key is always present, and disk
mode's body is otherwise byte-identical to today's, so an existing deployment sees no contract change:

```jsonc
// disk mode; the handler merges its existing resource body alongside this
{ "disposition": "applied" }

// queue mode
{
  "disposition": "submitted",
  "change_request": {
    "batch_id": "…",
    "review_status": "submitted",
    "auto_approved": false,
    "resource": { "type": "national_calendar", "id": "USA" },
    "paths": ["jsondata/sourcedata/…/USA.json"]
  }
}
```

- [ ] **Step 1: Write the failing test**

`phpunit_tests/Handlers/RegionalDataChangeRequestTest.php`:

```php
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
    }

    public function testAnAdministratorSubmissionIsAutoApproved(): void
    {
        $this->host->setAdministers(true);
        $this->host->stageFile('/app/jsondata/sourcedata/rite/roman/calendars/nation/USA.json', ChangeOperation::CREATE, '{}');

        $body = $this->host->commitStagedFiles(ChangeResource::nationalCalendar(Rite::ROMAN, 'USA'));

        self::assertSame('approved', $body['change_request']['review_status']);
        self::assertTrue($body['change_request']['auto_approved']);

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
}
```

Create the host beside it, `phpunit_tests/Handlers/ChangeRequestTraitHost.php`. It wraps a
`ChangeRequestSourceDataWriter` and keeps the same small surface the tests in this task and in tasks 10 and 11
use:

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use LiturgicalCalendar\Api\Enum\ChangeOperation;
use LiturgicalCalendar\Api\Repositories\SourceDataChangeRequestRepository;
use LiturgicalCalendar\Api\Services\ChangeRequestReview;
use LiturgicalCalendar\Api\Services\ChangeResource;
use LiturgicalCalendar\Api\Services\OpenFgaClient;
use LiturgicalCalendar\Api\Services\ResourceAdminService;
use LiturgicalCalendar\Api\Services\SourceData\ChangeRequestSourceDataWriter;
use LiturgicalCalendar\Tests\Support\CollectingLogger;
use Nyholm\Psr7\Factory\Psr17Factory;

/**
 * Drives ChangeRequestSourceDataWriter with the queue-mode collaborators a handler
 * would give it.
 *
 * ChangeRequestReview and ResourceAdminService are both final, so auto-approval is
 * steered by a queued OpenFGA response rather than by stubbing a class — the same
 * seam ResourceAdminServiceTest uses. The writer is rebuilt on demand so a test can
 * change the answer between calls.
 */
final class ChangeRequestTraitHost
{
    /** @var array<string, mixed> */
    private array $oidcUser = [];

    private bool $administers = false;

    private ?ChangeRequestSourceDataWriter $writer = null;

    public function __construct(private readonly SourceDataChangeRequestRepository $repository)
    {
    }

    /** @param array<string, mixed> $user */
    public function setSubmitter(array $user): void
    {
        $this->oidcUser = $user;
        $this->writer   = null;
    }

    public function setAdministers(bool $administers): void
    {
        $this->administers = $administers;
        $this->writer      = null;
    }

    public function stageFile(string $absolutePath, ChangeOperation $operation, ?string $content): void
    {
        $this->writer()->stage($absolutePath, $operation, $content);
    }

    /** @return array<string, mixed> */
    public function commitStagedFiles(ChangeResource $resource): array
    {
        $result       = $this->writer()->commit($resource);
        $this->writer = null;

        return $result;
    }

    private function writer(): ChangeRequestSourceDataWriter
    {
        return $this->writer ??= new ChangeRequestSourceDataWriter(
            $this->repository,
            new ChangeRequestReview(new ResourceAdminService($this->fgaAnswering($this->administers), new CollectingLogger())),
            $this->oidcUser,
            '/app/'
        );
    }

    private function fgaAnswering(bool $allowed): OpenFgaClient
    {
        $responses = [new GuzzleResponse(200, [], json_encode(['allowed' => $allowed]))];
        $guzzle    = new GuzzleClient(['handler' => HandlerStack::create(new MockHandler($responses))]);
        $psr17     = new Psr17Factory();

        return new OpenFgaClient(
            apiUrl: 'http://openfga.test',
            storeId: 'test-store',
            modelId: 'test-model',
            httpClient: $guzzle,
            requestFactory: $psr17,
            streamFactory: $psr17,
            apiToken: 'test-token'
        );
    }
}
```

Every `commitStagedFiles()` assertion in this task also gains a `disposition` check:
`self::assertSame('submitted', $body['disposition']);` for a non-administrator, `'approved'` for an
administrator.

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit phpunit_tests/Handlers/RegionalDataChangeRequestTest.php`

Expected: FAIL — `Trait "LiturgicalCalendar\Api\Handlers\Concerns\WritesSourceData" not found`.

- [ ] **Step 3: Write the change-request writer**

`src/Services/SourceData/ChangeRequestSourceDataWriter.php`:

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services\SourceData;

use LiturgicalCalendar\Api\Enum\ChangeOperation;
use LiturgicalCalendar\Api\Repositories\SourceDataChangeRequestRepository;
use LiturgicalCalendar\Api\Router;
use LiturgicalCalendar\Api\Services\ChangeRequestReview;
use LiturgicalCalendar\Api\Services\ChangeResource;

/**
 * Records a reviewable proposal instead of writing files.
 *
 * Staging is separate from committing because one API request can produce
 * several files — a calendar plus its i18n catalogues — that must be approved or
 * rejected together, so they become one batch.
 */
final class ChangeRequestSourceDataWriter implements SourceDataWriter
{
    /** @var list<array{path: string, operation: ChangeOperation, content: ?string}> */
    private array $staged = [];

    /**
     * @param array<string, mixed> $oidcUser The authenticated identity, from the
     *                                       request's `oidc_user` attribute.
     */
    public function __construct(
        private readonly SourceDataChangeRequestRepository $repository,
        private readonly ChangeRequestReview $review,
        private readonly array $oidcUser,
        private readonly ?string $projectRoot = null
    ) {
    }

    public function stage(string $absolutePath, ChangeOperation $operation, ?string $content): void
    {
        $this->staged[] = [
            'path'      => $this->repoRelativePath($absolutePath),
            'operation' => $operation,
            'content'   => $content,
        ];
    }

    public function commit(ChangeResource $resource): array
    {
        if ($this->staged === []) {
            throw new \LogicException('commit() called with no staged files');
        }

        $sub = $this->submitterSub();

        // An unverified email must never become a git commit author email: anyone
        // able to set an address in Zitadel could otherwise forge authorship of a
        // third party in a public repository.
        $emailVerified = true === ( $this->oidcUser['email_verified'] ?? false );
        $email         = $emailVerified && is_string($this->oidcUser['email'] ?? null)
            ? $this->oidcUser['email']
            : null;
        $name = is_string($this->oidcUser['name'] ?? null) ? $this->oidcUser['name'] : null;

        $batchId = $this->repository->submitBatch(
            $resource,
            $this->staged,
            $sub,
            $name,
            $email,
            $emailVerified,
            ['authorizing_relation' => 'admin']
        );

        $paths        = array_map(static fn (array $file): string => $file['path'], $this->staged);
        $this->staged = [];

        $autoApproved = $this->review->administers($resource, $sub);
        if ($autoApproved) {
            $this->repository->approveBatch($batchId, $sub);
        }

        return [
            'disposition'    => $autoApproved ? 'approved' : 'submitted',
            'change_request' => [
                'batch_id'      => $batchId,
                'review_status' => $autoApproved ? 'approved' : 'submitted',
                'auto_approved' => $autoApproved,
                'resource'      => [
                    'type' => $resource->type,
                    'id'   => $resource->id,
                ],
                'paths'         => $paths,
            ],
        ];
    }

    /**
     * Strip the deployment root, so a path is stored the way GitHub addresses it
     * and is stable across `api/dev` and `api/vN`.
     */
    private function repoRelativePath(string $absolutePath): string
    {
        $root = $this->projectRoot ?? Router::$apiFilePath;

        return str_starts_with($absolutePath, $root)
            ? substr($absolutePath, strlen($root))
            : ltrim($absolutePath, '/');
    }

    private function submitterSub(): string
    {
        $sub = $this->oidcUser['sub'] ?? null;
        if (!is_string($sub) || $sub === '') {
            throw new \LogicException('A change request cannot be submitted without an authenticated subject');
        }

        return $sub;
    }
}
```

- [ ] **Step 3b: Write the trait**

`src/Handlers/Concerns/WritesSourceData.php`:

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Handlers\Concerns;

use LiturgicalCalendar\Api\Enum\ChangeOperation;
use LiturgicalCalendar\Api\Repositories\SourceDataChangeRequestRepository;
use LiturgicalCalendar\Api\Services\ChangeRequestReview;
use LiturgicalCalendar\Api\Services\ChangeResource;
use LiturgicalCalendar\Api\Services\ResourceAdminService;
use LiturgicalCalendar\Api\Services\SourceData\ChangeRequestSourceDataWriter;
use LiturgicalCalendar\Api\Services\SourceData\DiskSourceDataWriter;
use LiturgicalCalendar\Api\Services\SourceData\SourceDataWriteMode;
use LiturgicalCalendar\Api\Services\SourceData\SourceDataWriter;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Gives a write handler one way to put source data somewhere, whichever mode the
 * deployment is in.
 *
 * The handler stages each file it would write and commits once per request. It
 * never asks which writer is behind the interface — that is the whole point:
 * a self-hosted instance without Postgres and OpenFGA keeps writing files, and
 * the same handler code records proposals where the stack is present.
 */
trait WritesSourceData
{
    private ?SourceDataWriter $sourceDataWriter = null;

    /** @var array<string, mixed>|null */
    private ?array $submitterOidcUser = null;

    /**
     * Capture the authenticated identity for the duration of the request, the same
     * way handle() already captures the client IP for audit logging.
     */
    protected function captureSubmitter(ServerRequestInterface $request): void
    {
        $oidcUser                = $request->getAttribute('oidc_user');
        $this->submitterOidcUser = is_array($oidcUser) ? $oidcUser : null;
    }

    protected function stageFile(string $absolutePath, ChangeOperation $operation, ?string $content): void
    {
        $this->sourceDataWriter()->stage($absolutePath, $operation, $content);
    }

    /**
     * @return array<string, mixed> Always carries a `disposition` key.
     */
    protected function commitStagedFiles(ChangeResource $resource): array
    {
        return $this->sourceDataWriter()->commit($resource);
    }

    /**
     * Memoised per request, so every staged file in one request lands in one
     * writer — and therefore, in queue mode, in one batch.
     */
    protected function sourceDataWriter(): SourceDataWriter
    {
        return $this->sourceDataWriter ??= SourceDataWriteMode::changeRequestsEnabled()
            ? new ChangeRequestSourceDataWriter(
                new SourceDataChangeRequestRepository(),
                new ChangeRequestReview(new ResourceAdminService($this->getFgaClient())),
                $this->submitterOidcUser ?? []
            )
            : new DiskSourceDataWriter();
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit phpunit_tests/Handlers/RegionalDataChangeRequestTest.php`

Expected: PASS, 6 tests.

- [ ] **Step 5: Wire the RegionalDataHandler create paths**

In `src/Handlers/RegionalDataHandler.php`, add to the `use` list beside `ClientIpTrait`:

```php
    use WritesSourceData;
```

`RegionalDataHandler` already uses `ResolvesOutboxTooling`, which supplies `getFgaClient()`; no further wiring
is needed for `changeRequestReview()`.

In `handle()`, immediately after the existing `$this->clientIp = ...` line (around line 1714), add:

```php
        // Capture the authenticated identity for change request authorship, the same
        // way the client IP is captured just above for audit logging.
        $this->captureSubmitter($request);
```

In `createDiocesanCalendar()`, replace the `file_put_contents(...)` block at line 391:

```php
        // Use raw payload for json_encode to preserve schema-compliant structure
        $calendarData = JsonFormatter::encode($rawPayload);
        $this->stageFile($diocesanCalendarFile, ChangeOperation::CREATE, $calendarData . PHP_EOL);
        $changeRequest = $this->commitStagedFiles(ChangeResource::diocesanCalendar($this->rite, $diocese_id));
```

and return `$changeRequest` through `encodeResponseBody()` instead of the previously-returned resource body,
keeping the existing `StatusCode` argument.

Apply the same two-line substitution in `createNationalCalendar()` (line 477, resource
`ChangeResource::nationalCalendar($this->rite, $nation)`) and `createWiderRegionCalendar()` (line 612,
resource `ChangeResource::widerRegion($widerRegionName)`).

In `writeI18nFiles()` (line 1208), replace its `file_put_contents(...)` at line 1219 with:

```php
            $this->stageFile($i18nFile, ChangeOperation::CREATE, $i18nContent . PHP_EOL);
```

and keep returning the locale list it already returns. Because `writeI18nFiles()` is called **before** the
calendar file is staged, both land in the same batch — which is the point.

Add the imports at the top of the file:

```php
use LiturgicalCalendar\Api\Enum\ChangeOperation;
use LiturgicalCalendar\Api\Handlers\Concerns\WritesSourceData;
use LiturgicalCalendar\Api\Services\ChangeResource;
```

- [ ] **Step 6: Verify no create path writes to disk**

Run: `grep -n "file_put_contents" src/Handlers/RegionalDataHandler.php`

Expected: lines 391, 477 and 612 are gone; only the update and delete paths (Task 9) remain.

- [ ] **Step 7: Run the full suite**

Run: `composer test`

Expected: PASS. Existing `RegionalDataHandler` tests that asserted a file appeared on disk will fail; update
them to assert a change request batch exists instead. Do not delete them.

- [ ] **Step 8: Lint, analyse, commit**

```bash
composer parallel-lint && composer lint:fix && composer analyse
git add src/Handlers/Concerns/WritesSourceData.php src/Handlers/RegionalDataHandler.php \
        phpunit_tests/Handlers/RegionalDataChangeRequestTest.php \
        phpunit_tests/Handlers/ChangeRequestTraitHost.php
git commit -m "feat(data): route regional calendar creation into change requests"
```

---

### Task 9: RegionalDataHandler update and delete paths

**Files:**

- Modify: `src/Handlers/RegionalDataHandler.php` — `updateNationalCalendar()` (line 680),
  `updateWiderRegionCalendar()` (line 773), `updateDiocesanCalendar()` (line 863), `deleteCalendar()` (line
  1087), `updateI18nFiles()` (line 1252)
- Modify: `phpunit_tests/Handlers/RegionalDataChangeRequestTest.php`

**Interfaces:**

- Consumes: `stageFile()`, `commitStagedFiles()` from Task 8.
- Produces: no new public surface.

- [ ] **Step 1: Write the failing tests**

Append to `phpunit_tests/Handlers/RegionalDataChangeRequestTest.php`:

```php
    public function testADeletionStagesNoContent(): void
    {
        $this->host->stageFile(
            '/app/jsondata/sourcedata/rite/roman/calendars/diocese/romamo_it.json',
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
        $this->host->stageFile('/app/jsondata/sourcedata/rite/roman/calendars/diocese/romamo_it.json', ChangeOperation::DELETE, null);
        $this->host->stageFile('/app/jsondata/sourcedata/rite/roman/calendars/diocese/romamo_it/i18n/it.json', ChangeOperation::DELETE, null);

        $body = $this->host->commitStagedFiles(ChangeResource::diocesanCalendar(Rite::ROMAN, 'romamo_it'));

        $repo = new SourceDataChangeRequestRepository(self::$pdo);
        self::assertCount(2, $repo->getBatch($body['change_request']['batch_id']));
    }

    public function testAnUpdateAndADeleteCanShareABatch(): void
    {
        $this->host->stageFile('/app/jsondata/sourcedata/rite/roman/calendars/nation/USA.json', ChangeOperation::UPDATE, '{"litcal":[]}');
        $this->host->stageFile('/app/jsondata/sourcedata/rite/roman/calendars/nation/USA/i18n/fr.json', ChangeOperation::DELETE, null);

        $body = $this->host->commitStagedFiles(ChangeResource::nationalCalendar(Rite::ROMAN, 'USA'));

        $repo       = new SourceDataChangeRequestRepository(self::$pdo);
        $operations = array_column($repo->getBatch($body['change_request']['batch_id']), 'operation');

        sort($operations);
        self::assertSame(['delete', 'update'], $operations);
    }
```

- [ ] **Step 2: Run the tests to verify they pass against the Task 8 trait**

Run: `vendor/bin/phpunit phpunit_tests/Handlers/RegionalDataChangeRequestTest.php`

Expected: PASS, 9 tests. These characterise behaviour the trait already supports; they exist to pin the delete
and mixed-operation contract before the handler paths start relying on it.

- [ ] **Step 3: Wire the three update paths**

In `updateNationalCalendar()` (line 680), replace the `file_put_contents(...)` block at line 730:

```php
        $this->stageFile($nationalCalendarFile, ChangeOperation::UPDATE, $calendarData . PHP_EOL);
        $changeRequest = $this->commitStagedFiles(ChangeResource::nationalCalendar($this->rite, $nation));
```

In `updateWiderRegionCalendar()` (line 773), at line 823:

```php
        $this->stageFile($widerRegionFile, ChangeOperation::UPDATE, $calendarData . PHP_EOL);
        $changeRequest = $this->commitStagedFiles(ChangeResource::widerRegion($widerRegionName));
```

In `updateDiocesanCalendar()` (line 863), at line 915:

```php
        $this->stageFile($diocesanCalendarFile, ChangeOperation::UPDATE, $calendarData . PHP_EOL);
        $changeRequest = $this->commitStagedFiles(ChangeResource::diocesanCalendar($this->rite, $diocese_id));
```

Each returns `$changeRequest` through `encodeResponseBody()` in place of the resource body.

- [ ] **Step 4: Wire `updateI18nFiles()`**

In `updateI18nFiles()` (line 1252), replace the `file_put_contents(...)` at line 1282 with:

```php
            $this->stageFile($i18nFile, ChangeOperation::UPDATE, $i18nContent . PHP_EOL);
```

and the `unlink($jsonFile)` at line 1302 with:

```php
                $this->stageFile($jsonFile, ChangeOperation::DELETE, null);
```

A locale removed from the payload becomes a delete row in the same batch as the calendar update, which is how
a translation is retired without ever removing a file from the deployed tree.

- [ ] **Step 5: Wire `deleteCalendar()`**

In `deleteCalendar()` (line 1087), replace the `unlink($calendarDataFile)` at line 1108 with:

```php
        $this->stageFile($calendarDataFile, ChangeOperation::DELETE, null);
```

and the `unlink($file)` inside the i18n loop at line 1124 with:

```php
                $this->stageFile($file, ChangeOperation::DELETE, null);
```

After the loop, submit the batch with the resource matching the tier being deleted, and return that body
through `encodeResponseBody()`.

- [ ] **Step 6: Verify RegionalDataHandler no longer touches disk directly**

Run: `grep -nE "file_put_contents|unlink\(" src/Handlers/RegionalDataHandler.php`

Expected: no matches. Disk writes have not disappeared — they now live in `DiskSourceDataWriter`, which is
exactly why a self-hosted deployment is unaffected.

- [ ] **Step 7: Run the full suite**

Run: `composer test`

Expected: PASS, with existing on-disk assertions updated to change request assertions.

- [ ] **Step 8: Lint, analyse, commit**

```bash
composer parallel-lint && composer lint:fix && composer analyse
git add src/Handlers/RegionalDataHandler.php phpunit_tests/Handlers/RegionalDataChangeRequestTest.php
git commit -m "feat(data): route regional calendar updates and deletions into change requests"
```

---

### Task 10: DecreesHandler

**Files:**

- Modify: `src/Handlers/DecreesHandler.php` — `saveDecreesDatabase()` (line 533), `distributeI18n()` (line
  568), `distributeReadings()` (line 590), `removeKeyFromLocaleFiles()` (line 737)
- Test: `phpunit_tests/Handlers/DecreesChangeRequestTest.php`

**Interfaces:**

- Consumes: `stageFile()`, `commitStagedFiles()`, `captureSubmitter()` from Task 8;
  `ChangeResource::decrees()` from Task 2.
- Produces: no new public surface.

**Resource:** every decree edit targets one resource — `ChangeResource::decrees()`, which is
`general_roman_calendar` / `decrees`, an id already enumerated in `AccessRequestRepository::GRC_OBJECT_IDS`.
So a decree edit, its i18n distribution and its readings distribution all land in one batch keyed to that
single resource.

**Note on the four writers:** `saveDecreesDatabase()`, `distributeI18n()`, `distributeReadings()` and
`removeKeyFromLocaleFiles()` all return `void` and are called from the PUT/PATCH/DELETE paths. Converting them
to stage rather than write means the calling path must submit the batch once, after the last of them runs. Do
not submit inside these four methods.

- [ ] **Step 1: Write the failing test**

`phpunit_tests/Handlers/DecreesChangeRequestTest.php`:

```php
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
```

- [ ] **Step 2: Run the test to verify it passes**

Run: `vendor/bin/phpunit phpunit_tests/Handlers/DecreesChangeRequestTest.php`

Expected: PASS, 3 tests. They pin the decrees resource contract before the handler adopts it.

- [ ] **Step 3: Wire the handler**

Add to the `use` list inside `DecreesHandler`:

```php
    use WritesSourceData;
```

and the imports:

```php
use LiturgicalCalendar\Api\Enum\ChangeOperation;
use LiturgicalCalendar\Api\Handlers\Concerns\WritesSourceData;
use LiturgicalCalendar\Api\Services\ChangeResource;
```

If `DecreesHandler` does not already use `ResolvesFgaClient` or `ResolvesOutboxTooling`, add:

```php
    use ResolvesFgaClient;
```

so `changeRequestReview()` can resolve its client.

In `handle()`, after the response is initialised and before dispatching by method, add:

```php
        $this->captureSubmitter($request);
```

Replace the four write sites:

- `saveDecreesDatabase()` line 536:

```php
        $this->stageFile($path, ChangeOperation::UPDATE, JsonFormatter::encode(array_values($decrees)) . PHP_EOL);
```

- `distributeI18n()` line 582:

```php
            $this->stageFile($file, ChangeOperation::UPDATE, JsonFormatter::encode($arr) . PHP_EOL);
```

- `distributeReadings()` line 602:

```php
            $this->stageFile($file, ChangeOperation::UPDATE, JsonFormatter::encode($arr) . PHP_EOL);
```

- `removeKeyFromLocaleFiles()` line 748:

```php
                $this->stageFile($file, ChangeOperation::UPDATE, JsonFormatter::encode($arr) . PHP_EOL);
```

Each of the four loses its `false === ...` error branch, because staging cannot fail on I/O. Delete those
branches rather than leaving them unreachable.

- [ ] **Step 4: Submit once per request in the PUT, PATCH and DELETE paths**

In each of the three request paths, after the last call to the staging methods above, add:

```php
        $changeRequest = $this->commitStagedFiles(ChangeResource::decrees());
```

and return `$changeRequest` through `encodeResponseBody()` in place of the decree body.

- [ ] **Step 5: Verify DecreesHandler no longer touches disk directly**

Run: `grep -nE "file_put_contents|unlink\(" src/Handlers/DecreesHandler.php`

Expected: only the line 385 comment mentioning `file_put_contents` remains. Update that comment — it describes
a `LOCK_EX` nesting hazard that no longer exists — rather than leaving it to mislead.

- [ ] **Step 6: Run the full suite**

Run: `composer test`

Expected: PASS, with existing decree write tests updated to assert change requests.

- [ ] **Step 7: Lint, analyse, commit**

```bash
composer parallel-lint && composer lint:fix && composer analyse
git add src/Handlers/DecreesHandler.php phpunit_tests/Handlers/DecreesChangeRequestTest.php
git commit -m "feat(decrees): route decree edits into change requests"
```

---

### Task 11: TestsHandler

**Files:**

- Modify: `src/Handlers/TestsHandler.php` — `handleDeleteRequest()` (line 247, `unlink()` at 265),
  `writeTestToDisk()` (line 435, write at 438)
- Test: `phpunit_tests/Handlers/TestsChangeRequestTest.php`

**Interfaces:**

- Consumes: `stageFile()`, `commitStagedFiles()`, `captureSubmitter()` from Task 8; `ChangeResource::test()`
  from Task 2.
- Produces: no new public surface.

**Resource:** test definitions live under `jsondata/tests`, **not** `jsondata/sourcedata`. The `path` column
is repository-relative so this costs nothing, but the resource type must come from the handler's existing
scope resolution (`TestScopeResolver`), which is the authority on which of `national_calendar_test`,
`diocesan_calendar_test`, `general_roman_calendar_test` or `rite_calendar_test` a given test belongs to. Do
not guess it from the path.

- [ ] **Step 1: Write the failing test**

`phpunit_tests/Handlers/TestsChangeRequestTest.php`:

```php
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
}
```

- [ ] **Step 2: Run the test to verify it passes**

Run: `vendor/bin/phpunit phpunit_tests/Handlers/TestsChangeRequestTest.php`

Expected: PASS, 3 tests.

- [ ] **Step 3: Wire the handler**

Add `use WritesSourceData;` to the class, the same three imports as Task 10, and
`$this->captureSubmitter($request);` in `handle()`.

Replace `writeTestToDisk()` line 438:

```php
        $this->stageFile($testFilePath, ChangeOperation::CREATE, $jsonEncodedTest);
```

The method's `$bytesWritten` check and its failure branch go with it. Rename the method to
`stageTestDefinition()` so the name stops claiming a disk write, and update its one call site.

Replace `handleDeleteRequest()` line 265:

```php
        $this->stageFile($this->testFilePath($testName), ChangeOperation::DELETE, null);
```

The path-traversal guard immediately above it stays — it is still what keeps an unsafe name out of the `path`
column.

In both request paths, after staging, submit once:

```php
        $changeRequest = $this->commitStagedFiles($this->changeResourceForTest($testName));
```

Add the private helper that maps the handler's already-resolved test scope onto a `ChangeResource`:

```php
    /**
     * Map the test's resolved scope onto the resource whose administrators review it.
     *
     * TestScopeResolver is the authority here: a bare calendar id does not identify a
     * calendar across rites, which is why the scoped types carry rite-qualified ids.
     */
    private function changeResourceForTest(string $testName): ChangeResource
    {
        $scope = $this->resolveTestScope($testName);

        return ChangeResource::test($scope->rite, $scope->objectType, $scope->calendarId);
    }
```

Adapt the property names to whatever `TestScopeResolver` actually returns — read
`src/Services/TestScopeResolver.php` before writing this method, and do not assume the shape above.

- [ ] **Step 4: Verify TestsHandler no longer touches disk directly**

Run: `grep -nE "file_put_contents|unlink\(" src/Handlers/TestsHandler.php`

Expected: only the line 259 comment remains.

- [ ] **Step 5: Run the full suite**

Run: `composer test`

Expected: PASS.

- [ ] **Step 6: Lint, analyse, commit**

```bash
composer parallel-lint && composer lint:fix && composer analyse
git add src/Handlers/TestsHandler.php phpunit_tests/Handlers/TestsChangeRequestTest.php
git commit -m "feat(tests): route test definition edits into change requests"
```

---

### Task 12: `GET /auth/change-requests` — an editor's own view

**Files:**

- Create: `src/Handlers/Auth/ChangeRequestHandler.php`
- Modify: `src/Router.php` — the `case 'auth':` block, beside the existing `admin-scopes` branch (around
  line 515)
- Test: `phpunit_tests/Handlers/AuthChangeRequestHandlerTest.php`

**Interfaces:**

- Consumes: `SourceDataChangeRequestRepository::listBySubmitter()`, `countBySubmitter()`, `withdrawBatch()`
  (Tasks 3–5).
- Produces: `GET /auth/change-requests?status=&limit=&offset=` and
  `POST /auth/change-requests/{batchId}/withdraw`.

Scoping is entirely server-side: the handler passes the caller's own `sub` and never accepts a submitter
parameter, so there is no way to widen the query from the client.

- [ ] **Step 1: Write the failing test**

`phpunit_tests/Handlers/AuthChangeRequestHandlerTest.php`:

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers;

use LiturgicalCalendar\Api\Enum\ChangeOperation;
use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Handlers\Auth\ChangeRequestHandler;
use LiturgicalCalendar\Api\Repositories\SourceDataChangeRequestRepository;
use LiturgicalCalendar\Api\Services\ChangeResource;
use LiturgicalCalendar\Tests\Repositories\RepositoryTestCase;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(ChangeRequestHandler::class)]
final class AuthChangeRequestHandlerTest extends RepositoryTestCase
{
    private SourceDataChangeRequestRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new SourceDataChangeRequestRepository(self::$pdo);
    }

    private function submitFor(string $sub, string $nation): string
    {
        return $this->repo->submitBatch(
            ChangeResource::nationalCalendar(Rite::ROMAN, $nation),
            [
                [
                    'path'      => 'jsondata/sourcedata/rite/roman/calendars/nation/' . $nation . '.json',
                    'operation' => ChangeOperation::UPDATE,
                    'content'   => '{"litcal":[]}',
                ],
            ],
            $sub,
            'Alice',
            'alice@example.test',
            true
        );
    }

    private function request(string $method, string $path, string $sub): ServerRequest
    {
        return ( new ServerRequest($method, $path) )
            ->withAttribute('oidc_user', ['sub' => $sub, 'email' => 'alice@example.test', 'email_verified' => true])
            ->withHeader('Accept', 'application/json');
    }

    public function testListReturnsOnlyTheCallersOwnBatches(): void
    {
        $mine = $this->submitFor('user-1', 'USA');
        $this->submitFor('user-2', 'ITALY');

        $handler  = new ChangeRequestHandler([], $this->repo);
        $response = $handler->handle($this->request('GET', '/auth/change-requests', 'user-1'));

        $body = json_decode((string) $response->getBody(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(1, $body['change_requests']);
        self::assertSame($mine, $body['change_requests'][0]['batch_id']);
        self::assertSame(1, $body['total']);
    }

    public function testAnUnauthenticatedCallerIsRejected(): void
    {
        $handler = new ChangeRequestHandler([], $this->repo);
        $request = ( new ServerRequest('GET', '/auth/change-requests') )->withHeader('Accept', 'application/json');

        $response = $handler->handle($request);

        self::assertSame(401, $response->getStatusCode());
    }

    public function testWithdrawingOwnBatchSucceeds(): void
    {
        $batchId = $this->submitFor('user-1', 'USA');

        $handler  = new ChangeRequestHandler(['change-requests', $batchId, 'withdraw'], $this->repo);
        $response = $handler->handle($this->request('POST', '/auth/change-requests/' . $batchId . '/withdraw', 'user-1'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('withdrawn', $this->repo->getBatch($batchId)[0]['review_status']);
    }

    public function testWithdrawingSomeoneElsesBatchIsNotFound(): void
    {
        $batchId = $this->submitFor('user-2', 'ITALY');

        $handler  = new ChangeRequestHandler(['change-requests', $batchId, 'withdraw'], $this->repo);
        $response = $handler->handle($this->request('POST', '/auth/change-requests/' . $batchId . '/withdraw', 'user-1'));

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('submitted', $this->repo->getBatch($batchId)[0]['review_status']);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit phpunit_tests/Handlers/AuthChangeRequestHandlerTest.php`

Expected: FAIL — `Class "LiturgicalCalendar\Api\Handlers\Auth\ChangeRequestHandler" not found`.

- [ ] **Step 3: Write the handler**

Model it on `src/Handlers/Auth/AccessRequestHandler.php`: same `AbstractHandler` base, same
`OffsetPaginationTrait`, same `validateAcceptHeader()` / `encodeResponseBody()` flow, same
`UnauthorizedException` when `oidc_user` is absent. The repository is constructor-injectable as the second
parameter so the test above can pass a test-database instance.

Behaviour:

- `GET` — `listBySubmitter($sub, $status, $limit, $offset)` plus `countBySubmitter($sub, $status)`; respond
  `{"change_requests": [...], "total": N, "limit": L, "offset": O}`.
- `POST /{batchId}/withdraw` — `withdrawBatch($batchId, $sub)`; `0` rows means either not theirs or already
  decided, and both answer **404**, never 403. A 403 would confirm the batch exists to a caller who may not
  see it.
- An unrecognised `status` query value is a `ValidationException`, not a silent full listing.

- [ ] **Step 4: Register the route**

In `src/Router.php`, inside `case 'auth':`, beside the existing `admin-scopes` branch, add:

```php
                    } elseif ($authRoute === 'change-requests') {
                        // GET  /auth/change-requests                    - The caller's own change requests
                        // POST /auth/change-requests/{batchId}/withdraw - Withdraw one of them
                        $this->handler = new ChangeRequestHandler($requestPathParts);
```

and import `LiturgicalCalendar\Api\Handlers\Auth\ChangeRequestHandler`.

`auth` is already in both `$skipAuthRoutes` (line 773) and the OIDC list (line 792), so the `oidc_user`
attribute is populated before the handler runs. No middleware change is needed.

- [ ] **Step 5: Run the test to verify it passes**

Run: `vendor/bin/phpunit phpunit_tests/Handlers/AuthChangeRequestHandlerTest.php`

Expected: PASS, 4 tests.

- [ ] **Step 6: Lint, analyse, commit**

```bash
composer parallel-lint && composer lint:fix && composer analyse
git add src/Handlers/Auth/ChangeRequestHandler.php src/Router.php \
        phpunit_tests/Handlers/AuthChangeRequestHandlerTest.php
git commit -m "feat(auth): let editors list and withdraw their own change requests"
```

---

### Task 13: `GET /admin/change-requests` — review queue and history

**Files:**

- Create: `src/Handlers/Admin/ChangeRequestAdminHandler.php`
- Modify: `src/Router.php` — the `case 'admin':` block, beside `access-requests` (around line 541)
- Test: `phpunit_tests/Handlers/ChangeRequestAdminHandlerTest.php`

**Interfaces:**

- Consumes: `SourceDataChangeRequestRepository::listAll()`, `countAll()`, `approveBatch()`, `rejectBatch()`,
  `getBatch()` (Tasks 3–5); `ChangeRequestReview::filterForAdmin()` (Task 6); `AuditLogRepository::log()`.
- Produces: `GET /admin/change-requests?status=&limit=&offset=`,
  `POST /admin/change-requests/{batchId}/approve`, `POST /admin/change-requests/{batchId}/reject`.

- [ ] **Step 1: Write the failing test**

`phpunit_tests/Handlers/ChangeRequestAdminHandlerTest.php`:

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use LiturgicalCalendar\Api\Enum\ChangeOperation;
use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Handlers\Admin\ChangeRequestAdminHandler;
use LiturgicalCalendar\Api\Repositories\SourceDataChangeRequestRepository;
use LiturgicalCalendar\Api\Services\ChangeResource;
use LiturgicalCalendar\Api\Services\OpenFgaClient;
use LiturgicalCalendar\Tests\Repositories\RepositoryTestCase;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(ChangeRequestAdminHandler::class)]
final class ChangeRequestAdminHandlerTest extends RepositoryTestCase
{
    private SourceDataChangeRequestRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new SourceDataChangeRequestRepository(self::$pdo);
    }

    /** @param array<int, GuzzleResponse> $fgaResponses */
    private function handler(array $pathParts, array $fgaResponses): ChangeRequestAdminHandler
    {
        $guzzle = new GuzzleClient(['handler' => HandlerStack::create(new MockHandler($fgaResponses))]);
        $psr17  = new Psr17Factory();
        $client = new OpenFgaClient(
            apiUrl: 'http://openfga.test',
            storeId: 'test-store',
            modelId: 'test-model',
            httpClient: $guzzle,
            requestFactory: $psr17,
            streamFactory: $psr17,
            apiToken: 'test-token'
        );

        return new ChangeRequestAdminHandler($pathParts, $this->repo, $client);
    }

    private static function allowed(bool $allowed): GuzzleResponse
    {
        return new GuzzleResponse(200, [], json_encode(['allowed' => $allowed]));
    }

    private function submitFor(string $sub, string $nation): string
    {
        return $this->repo->submitBatch(
            ChangeResource::nationalCalendar(Rite::ROMAN, $nation),
            [
                [
                    'path'      => 'jsondata/sourcedata/rite/roman/calendars/nation/' . $nation . '.json',
                    'operation' => ChangeOperation::UPDATE,
                    'content'   => '{"litcal":[]}',
                ],
            ],
            $sub,
            'Alice',
            'alice@example.test',
            true
        );
    }

    private function request(string $method, string $path, string $sub): ServerRequest
    {
        return ( new ServerRequest($method, $path) )
            ->withAttribute('oidc_user', ['sub' => $sub, 'roles' => ['calendar_editor']])
            ->withHeader('Accept', 'application/json');
    }

    public function testAResourceAdminSeesOnlyTheirOwnResources(): void
    {
        $usa = $this->submitFor('user-1', 'USA');
        $this->submitFor('user-2', 'ITALY');

        // Two batches probed; the admin administers only the first.
        $handler  = $this->handler([], [self::allowed(true), self::allowed(false)]);
        $response = $handler->handle($this->request('GET', '/admin/change-requests', 'admin-1'));

        $body = json_decode((string) $response->getBody(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(1, $body['change_requests']);
        self::assertSame($usa, $body['change_requests'][0]['batch_id']);
    }

    public function testApprovingABatchStampsTheAdmin(): void
    {
        $batchId = $this->submitFor('user-1', 'USA');

        $handler  = $this->handler(['change-requests', $batchId, 'approve'], [self::allowed(true)]);
        $response = $handler->handle($this->request('POST', '/admin/change-requests/' . $batchId . '/approve', 'admin-1'));

        self::assertSame(200, $response->getStatusCode());

        $row = $this->repo->getBatch($batchId)[0];
        self::assertSame('approved', $row['review_status']);
        self::assertSame('admin-1', $row['approved_by_sub']);
    }

    public function testApprovingAResourceTheCallerDoesNotAdministerIsForbidden(): void
    {
        $batchId = $this->submitFor('user-1', 'USA');

        $handler  = $this->handler(['change-requests', $batchId, 'approve'], [self::allowed(false)]);
        $response = $handler->handle($this->request('POST', '/admin/change-requests/' . $batchId . '/approve', 'admin-2'));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('submitted', $this->repo->getBatch($batchId)[0]['review_status']);
    }

    public function testRejectingRecordsTheReason(): void
    {
        $batchId = $this->submitFor('user-1', 'USA');

        $handler = $this->handler(['change-requests', $batchId, 'reject'], [self::allowed(true)]);
        $request = $this->request('POST', '/admin/change-requests/' . $batchId . '/reject', 'admin-1')
            ->withHeader('Content-Type', 'application/json');
        $request->getBody()->write(json_encode(['reason' => 'Wrong feast rank']));
        $request->getBody()->rewind();

        $response = $handler->handle($request);

        self::assertSame(200, $response->getStatusCode());

        $row = $this->repo->getBatch($batchId)[0];
        self::assertSame('rejected', $row['review_status']);
        self::assertSame('Wrong feast rank', $row['rejected_reason']);
    }

    public function testApprovingAnAlreadyDecidedBatchConflicts(): void
    {
        $batchId = $this->submitFor('user-1', 'USA');
        $this->repo->approveBatch($batchId, 'admin-1');

        $handler  = $this->handler(['change-requests', $batchId, 'approve'], [self::allowed(true)]);
        $response = $handler->handle($this->request('POST', '/admin/change-requests/' . $batchId . '/approve', 'admin-2'));

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('admin-1', $this->repo->getBatch($batchId)[0]['approved_by_sub']);
    }

    public function testAnUnknownBatchIsNotFound(): void
    {
        $handler  = $this->handler(['change-requests', '00000000-0000-0000-0000-000000000000', 'approve'], []);
        $response = $handler->handle($this->request('POST', '/admin/change-requests/x/approve', 'admin-1'));

        self::assertSame(404, $response->getStatusCode());
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit phpunit_tests/Handlers/ChangeRequestAdminHandlerTest.php`

Expected: FAIL — `Class "LiturgicalCalendar\Api\Handlers\Admin\ChangeRequestAdminHandler" not found`.

- [ ] **Step 3: Write the handler**

Model it closely on `src/Handlers/Admin/AccessRequestAdminHandler.php`: `AbstractHandler`,
`OffsetPaginationTrait`, `ResolvesFgaClient`, a nullable constructor-injected repository and `OpenFgaClient`
for the test seam.

Behaviour:

- `GET` — `listAll($status, $limit, $offset)`, then `ChangeRequestReview::filterForAdmin($batches, $sub)`. A
  caller who administers everything skips the filter, exactly as `AccessRequestAdminHandler` does via
  `administersAllResources`. Respond `{"change_requests": [...], "total": N, "limit": L, "offset": O}`.
- `POST /{batchId}/approve` — load the batch; **404** when empty; **403** when
  `ChangeRequestReview::administers()` is false for its resource; then `approveBatch()`. A return of `0` rows
  means someone decided it first, which is **409**.
- `POST /{batchId}/reject` — same guards, then `rejectBatch()` with the optional `reason` from the JSON body.
- Every approve and reject calls
  `AuditLogRepository::log($sub, 'change_request.approve', $resourceType, $resourceId, [...])`.

**Ordering matters:** check existence, then authorization, then the transition. Reversing the first two would
let a caller distinguish "no such batch" from "exists but not yours".

- [ ] **Step 4: Register the route**

In `src/Router.php`, inside `case 'admin':`, beside the `access-requests` branch, add:

```php
                    } elseif ($adminRoute === 'change-requests') {
                        // GET  /admin/change-requests                   - Review queue and history
                        // POST /admin/change-requests/{batchId}/approve - Approve a batch
                        // POST /admin/change-requests/{batchId}/reject  - Reject a batch
                        $this->handler = new ChangeRequestAdminHandler($requestPathParts);
```

and import `LiturgicalCalendar\Api\Handlers\Admin\ChangeRequestAdminHandler`.

- [ ] **Step 5: Run the test to verify it passes**

Run: `vendor/bin/phpunit phpunit_tests/Handlers/ChangeRequestAdminHandlerTest.php`

Expected: PASS, 6 tests.

- [ ] **Step 6: Run the full suite**

Run: `composer test`

Expected: PASS.

- [ ] **Step 7: Lint, analyse, commit**

```bash
composer parallel-lint && composer lint:fix && composer analyse
git add src/Handlers/Admin/ChangeRequestAdminHandler.php src/Router.php \
        phpunit_tests/Handlers/ChangeRequestAdminHandlerTest.php
git commit -m "feat(admin): review queue and history for source-data change requests"
```

---

### Task 14: OpenAPI and documentation

**Files:**

- Modify: `jsondata/schemas/openapi.json`
- Modify: `CHANGELOG.md`
- Create: `docs/ops/change-request-runbook.md`

**Interfaces:**

- Consumes: the endpoints from Tasks 12 and 13, and the changed write responses from Tasks 8–11.
- Produces: no code surface.

**Every write endpoint's response schema gained a `disposition` discriminator**, and in queue mode returns a
change request instead of the resource. Because disk mode's body is otherwise byte-identical to today's, this
is **not** a breaking change for an existing deployment — but a client that assumes the resource is always
returned will be wrong on any deployment that enables queue mode, and will be wrong silently.

- [ ] **Step 1: Document the two new endpoints**

Add paths for `/auth/change-requests`, `/auth/change-requests/{batchId}/withdraw`, `/admin/change-requests`,
`/admin/change-requests/{batchId}/approve` and `/admin/change-requests/{batchId}/reject`, with a shared
`ChangeRequestBatch` schema component carrying `batch_id`, `resource`, `review_status`, `publication_status`,
`submitted_by_name`, `approved_by_sub`, `file_count`, `paths`, `created_at`.

- [ ] **Step 2: Update every write endpoint response**

For each of `/data/*` (PUT, PATCH, POST, DELETE), `/decrees/*` and `/tests/*`, model the response as a `oneOf`
over the two shapes from Task 8, discriminated on `disposition`:

- `applied` — the existing resource schema, plus the `disposition` property. Unchanged otherwise.
- `submitted` / `approved` — the `ChangeRequestBatch` response.

Add a description sentence explaining that a deployment with `SOURCEDATA_CHANGE_REQUESTS` enabled returns the
proposal, which reaches the calendar only once its pull request merges, and that a deployment without it
writes the resource directly as it always has.

- [ ] **Step 3: Lint the schema**

Run: `composer lint:openapi`

Expected: no errors.

- [ ] **Step 4: Write the runbook**

`docs/ops/change-request-runbook.md`, following the shape of `docs/ops/openfga-outbox-runbook.md`: how to
inspect the queue in SQL, how to approve out of band, what `review_status` and `publication_status` mean and
why they are separate, the explicit note that Phase 1 leaves approved batches sitting with
`publication_status = 'none'` until the Phase 2 publisher exists, and **how to tell which write mode a
deployment is in** — the `Health` check, the flag, and what the fallback does when the flag is set without
Postgres or OpenFGA behind it.

- [ ] **Step 5: Update the changelog**

Add an entry under the unreleased heading recording the new `disposition` key on write responses, the
`SOURCEDATA_CHANGE_REQUESTS` flag, and that deployments which do not set it are unaffected.

- [ ] **Step 6: Lint markdown and commit**

```bash
composer lint:md:fix && composer lint:md
git add jsondata/schemas/openapi.json CHANGELOG.md docs/ops/change-request-runbook.md
git commit -m "docs(data): document the change request endpoints and the write response break"
```

---

## Done when

- [ ] `grep -rnE "file_put_contents|unlink\(" src/Handlers/` returns nothing under the three write handlers.
- [ ] `composer test` passes.
- [ ] `composer analyse` passes at level 10.
- [ ] `composer lint` and `composer lint:md` pass.
- [ ] With `SOURCEDATA_CHANGE_REQUESTS=true` plus Postgres and OpenFGA, a write request creates a change
      request batch and leaves `jsondata/sourcedata` byte-identical — verify with
      `git status --porcelain jsondata/` after exercising a `PUT /data/nation/USA`.
- [ ] With the flag unset, the same request writes the file exactly as it does today, and the response body is
      unchanged apart from `"disposition": "applied"`.
- [ ] With the flag set but Postgres absent, the request still succeeds in disk mode and `Health` reports the
      misconfiguration.

## Deliberately not in phase 1

Two items from the spec's error-handling table have **no task here**, on purpose:

- **`base_sha` population and the rebase check.** The column exists in Task 1's migration, but nothing writes
  it. Its only source is GitHub's blob sha, which arrives with the Phase 2 publisher; populating it now would
  mean inventing a value. The approval gate therefore cannot yet detect that the base moved underneath a
  submitted change.
- **Re-validating the payload against its JSON schema at approval time.** Phase 1 validates at submit, as the
  handlers already do. Re-validation at the gate matters when time passes between submit and approve, which
  only becomes consequential once approval leads somewhere — Phase 2.

Both are listed in the spec's error-handling table and must be implemented before the publisher opens its
first pull request. Neither is a Phase 1 omission to be quietly forgotten.

## Follow-on work

- **Frontend plan** — the admin interface, in `LiturgicalCalendarFrontend`. Write it once Task 14 lands.
- **Phase 2** — the GitHub App and `SourceDataPublishProcessor`.
- **Phase 3** — merge polling and notifications.
- **Phase 4** — preview.
