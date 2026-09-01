<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers;

use LiturgicalCalendar\Api\Database\Connection;
use LiturgicalCalendar\Api\Enum\LitSchema;
use LiturgicalCalendar\Api\Handlers\Admin\LocalesAdminHandler;
use LiturgicalCalendar\Api\Http\Exception\ConflictException;
use LiturgicalCalendar\Api\Repositories\SourceDataChangeRequestRepository;
use LiturgicalCalendar\Api\Router;
use LiturgicalCalendar\Api\Services\ChangeResource;
use LiturgicalCalendar\Api\Services\SourceData\ChangeRequestSchemaValidator;
use LiturgicalCalendar\Api\Services\SourceData\ChangeRequestSourceDataWriter;
use LiturgicalCalendar\Api\Services\SourceData\SourceDataSchemaResolver;
use LiturgicalCalendar\Api\Services\SourceData\SourceDataWriteMode;
use LiturgicalCalendar\Api\Services\SupportedLocales;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * `POST /admin/locales/{locale}/promote` with change requests enabled.
 *
 * Three things only this mode can prove, and all three were the point of routing curation
 * through the writer seam rather than writing the file directly:
 *
 * 1. A promotion becomes a reviewable proposal against
 *    `rite_calendar:roman/supported_locales`, and touches no file.
 * 2. `supportedLocales.json` is an AGGREGATE — one file holding the whole official set — so a
 *    second promotion must accumulate onto the submitter's first, unpublished one. Rebuilding
 *    from disk here would silently drop it, which is the defect that once lost a decree behind
 *    a `201`.
 * 3. The batch's content is claimed by a schema, so the #918 approval-time re-validation gate
 *    actually checks it. Before this branch there was no `SupportedLocales.json` at all, and
 *    `SourceDataSchemaResolver::forPath()` answered null — which
 *    {@see ChangeRequestSchemaValidator} reads as "not validated", not "invalid", so a
 *    malformed promotion would have sailed through approval.
 *
 * Auto-approval is deliberately steered OFF by pointing OpenFGA at a store that does not
 * exist, exactly as `RegionalDataQueueModeTest` does: `ChangeRequestReview::administers()`
 * fails closed, so every submission here stays `submitted` and the assertions read a stable
 * disposition.
 */
#[CoversClass(LocalesAdminHandler::class)]
#[CoversClass(ChangeRequestSourceDataWriter::class)]
#[CoversClass(SourceDataSchemaResolver::class)]
#[CoversClass(ChangeRequestSchemaValidator::class)]
#[CoversClass(ChangeResource::class)]
final class LocalesAdminQueueModeTest extends AbstractHandlerTestCase
{
    protected static bool $requiresDatabase = true;

    /** @var array<string, string|false> */
    private array $originalEnv = [];

    private string $savedApiFilePath = '';

    private string $root = '';

    private string $committedResourceDigest = '';

    protected function setUp(): void
    {
        parent::setUp();

        Connection::getInstance()->exec('TRUNCATE TABLE sourcedata_change_requests RESTART IDENTITY CASCADE');

        $this->savedApiFilePath        = Router::$apiFilePath;
        $this->committedResourceDigest = (string) md5_file(self::committedResourcePath());

        foreach ([SourceDataWriteMode::FLAG, 'OPENFGA_API_URL', 'OPENFGA_STORE_ID', 'OPENFGA_MODEL_ID'] as $key) {
            $this->originalEnv[$key] = $_ENV[$key] ?? false;
        }

        $_ENV[SourceDataWriteMode::FLAG] = 'true';
        $_ENV['OPENFGA_API_URL']         = 'http://localhost:8083';
        $_ENV['OPENFGA_STORE_ID']        = 'no-such-store-locale-curation-test';
        $_ENV['OPENFGA_MODEL_ID']        = 'no-such-model-locale-curation-test';

        // Two official locales withheld, so both are ready by construction and there is a
        // second promotion to accumulate onto the first.
        $this->root = $this->seedResource(array_slice(self::committedOfficial(), 2));

        Router::$apiFilePath = $this->root;
        SupportedLocales::reset();
    }

    protected function tearDown(): void
    {
        Router::$apiFilePath = $this->savedApiFilePath;

        foreach ($this->originalEnv as $key => $value) {
            if (false === $value) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $value;
            }
        }
        $this->originalEnv = [];

        if ($this->root !== '') {
            self::removeTree($this->root);
            $this->root = '';
        }

        SupportedLocales::reset();

        self::assertSame(
            $this->committedResourceDigest,
            (string) md5_file(self::committedResourcePath()),
            'a test wrote to the committed jsondata/supportedLocales.json instead of its throwaway copy'
        );

        parent::tearDown();
    }

    public function testAPromotionBecomesAChangeRequestAndWritesNoFile(): void
    {
        $before  = (string) file_get_contents($this->resourcePath());
        $promote = self::committedOfficial()[0];

        $body = $this->promote($promote);

        self::assertSame('submitted', $body['disposition']);
        self::assertSame('rite_calendar', $body['change_request']['resource']['type']);
        self::assertSame('roman/supported_locales', $body['change_request']['resource']['id']);
        self::assertSame(['jsondata/supportedLocales.json'], $body['change_request']['paths']);
        self::assertSame($before, (string) file_get_contents($this->resourcePath()), 'queue mode must touch no file');
    }

    /**
     * The reason `readCuratedResource()` goes through `unpublishedSourceContent()` rather than
     * reading the file: in queue mode the first promotion never reached disk, so a second one
     * rebuilt from disk would propose a list missing the first locale.
     */
    public function testASecondPromotionAccumulatesOntoTheFirstUnpublishedOne(): void
    {
        [$first, $second] = array_slice(self::committedOfficial(), 0, 2);

        $this->promote($first);
        $body = $this->promote($second);

        self::assertContains($first, $body['official'], 'the first, still unpublished, promotion was dropped');
        self::assertContains($second, $body['official']);
    }

    /**
     * The corollary: a locale already promoted in flight is already official as far as the
     * next request is concerned, so promoting it twice is a conflict rather than a duplicate
     * entry in the list.
     */
    public function testPromotingTheSameLocaleTwiceConflictsOnTheUnpublishedList(): void
    {
        $locale = self::committedOfficial()[0];
        $this->promote($locale);

        $this->expectException(ConflictException::class);
        $this->expectExceptionMessage('already officially supported');

        $this->promote($locale);
    }

    /**
     * The #918 gate, end to end: the row a promotion queues is claimed by
     * `SupportedLocales.json`, and its content passes.
     */
    public function testTheQueuedRowIsClaimedByTheSupportedLocalesSchemaAndValidates(): void
    {
        $body = $this->promote(self::committedOfficial()[0]);
        $rows = ( new SourceDataChangeRequestRepository(self::$pdo) )->getBatch($body['change_request']['batch_id']);

        self::assertCount(1, $rows);
        self::assertSame(
            LitSchema::SUPPORTED_LOCALES,
            SourceDataSchemaResolver::forPath((string) $rows[0]['path']),
            'an unclaimed path is treated as "not validated", so a malformed promotion would approve unchecked'
        );
        self::assertSame([], ( new ChangeRequestSchemaValidator() )->violations($rows));
    }

    /**
     * The other half of the same gate: content that would be accepted while nothing claimed
     * the path is now a named violation. `official` may not be empty — an empty list is
     * indistinguishable from an unreadable resource, and the API would silently fall back to
     * its built-in five.
     */
    public function testAnEmptyOfficialListIsAViolationOfTheSupportedLocalesSchema(): void
    {
        $violations = ( new ChangeRequestSchemaValidator() )->violations([
            [
                'path'    => 'jsondata/supportedLocales.json',
                'content' => '{"general_roman_calendar":{"official":[]}}',
            ],
        ]);

        self::assertCount(1, $violations);
        self::assertSame('SupportedLocales.json', $violations[0]['schema']);
    }

    // ---------------------------------------------------------------- machinery

    /** @return array<string, mixed> */
    private function promote(string $locale): array
    {
        return $this->decodeJsonBody(
            ( new LocalesAdminHandler(['locales', $locale, 'promote']) )->handle(
                $this->requestFor('POST', '/admin/locales/' . $locale . '/promote')
                    ->withAttribute('oidc_user', ['sub' => 'admin-1', 'roles' => ['admin']])
            )
        );
    }

    private function resourcePath(): string
    {
        return $this->root . 'jsondata/supportedLocales.json';
    }

    /**
     * @param list<string> $official
     */
    private function seedResource(array $official): string
    {
        $repo = dirname(__DIR__, 2) . '/';
        $root = sys_get_temp_dir() . '/litcal-locale-queue-' . bin2hex(random_bytes(6)) . '/';
        mkdir($root . 'jsondata', 0o755, true);

        symlink($repo . 'i18n', $root . 'i18n');
        symlink($repo . 'jsondata/sourcedata', $root . 'jsondata/sourcedata');
        symlink($repo . 'jsondata/schemas', $root . 'jsondata/schemas');

        file_put_contents(
            $root . 'jsondata/supportedLocales.json',
            json_encode(
                ['general_roman_calendar' => ['official' => $official]],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            ) . "\n"
        );

        return $root;
    }

    private static function committedResourcePath(): string
    {
        return dirname(__DIR__, 2) . '/jsondata/supportedLocales.json';
    }

    /** @return list<string> */
    private static function committedOfficial(): array
    {
        /** @var array{general_roman_calendar: array{official: list<string>}} $decoded */
        $decoded = json_decode((string) file_get_contents(self::committedResourcePath()), true, 512, JSON_THROW_ON_ERROR);

        return $decoded['general_roman_calendar']['official'];
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $it = new \RecursiveIteratorIterator(
            // No FOLLOW_SYMLINKS: the tree is symlinks into the repository.
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($it as $item) {
            /** @var \SplFileInfo $item */
            if ($item->isLink() || !$item->isDir()) {
                unlink($item->getPathname());
                continue;
            }
            rmdir($item->getPathname());
        }

        rmdir($dir);
    }
}
