<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers;

use LiturgicalCalendar\Api\Handlers\Admin\LocalesAdminHandler;
use LiturgicalCalendar\Api\Http\Exception\ConflictException;
use LiturgicalCalendar\Api\Http\Exception\ForbiddenException;
use LiturgicalCalendar\Api\Http\Exception\MethodNotAllowedException;
use LiturgicalCalendar\Api\Http\Exception\NotFoundException;
use LiturgicalCalendar\Api\Http\Exception\ServiceUnavailableException;
use LiturgicalCalendar\Api\Http\Exception\UnauthorizedException;
use LiturgicalCalendar\Api\Http\Exception\UnprocessableContentException;
use LiturgicalCalendar\Api\Router;
use LiturgicalCalendar\Api\Services\ChangeResource;
use LiturgicalCalendar\Api\Services\SourceData\DiskSourceDataWriter;
use LiturgicalCalendar\Api\Services\SourceData\SourceDataWriteMode;
use LiturgicalCalendar\Api\Services\SupportedLocales;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `POST /admin/locales/{locale}/promote|demote` in DISK mode — the behaviour a self-hosted
 * deployment without Postgres and OpenFGA gets, and the one every refusal branch is shared with.
 *
 * Every test runs against a throwaway tree whose `jsondata/supportedLocales.json` is a real,
 * writable copy and whose data folders are symlinks into the repository. That is what makes a
 * completed disk write safe to assert: the handler genuinely writes a file, and the file it
 * writes is not the committed resource. Rewriting the committed one would work too, right up
 * until a fatal left it rewritten in the working tree and every later test in the process read
 * a list this class invented — the trap `HealthLocaleReadinessTest` documents.
 *
 * The refusal branches are forced rather than assumed. A normally-configured run exercises
 * none of them: it promotes nothing, is never misconfigured, and never meets an unready locale.
 *
 * @see LocalesAdminQueueModeTest for the same route with change requests enabled.
 */
#[CoversClass(LocalesAdminHandler::class)]
#[CoversClass(DiskSourceDataWriter::class)]
#[CoversClass(SourceDataWriteMode::class)]
#[CoversClass(ChangeResource::class)]
final class LocalesAdminCurationTest extends AbstractHandlerTestCase
{
    /** @var list<string> */
    private array $tempRoots = [];

    /** @var array<string, string|false> */
    private array $originalEnv = [];

    private string $savedApiFilePath = '';

    /** Digest of the COMMITTED resource, so a stray write to it fails this class rather than the next one. */
    private string $committedResourceDigest = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->savedApiFilePath        = Router::$apiFilePath;
        $this->committedResourceDigest = (string) md5_file(self::committedResourcePath());

        foreach ([SourceDataWriteMode::FLAG, 'OPENFGA_API_URL', 'OPENFGA_STORE_ID', 'OPENFGA_MODEL_ID'] as $key) {
            $this->originalEnv[$key] = $_ENV[$key] ?? false;
        }

        // Disk mode by default: the flag decides, and it must not be inherited from .env.local.
        unset($_ENV[SourceDataWriteMode::FLAG]);

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

        foreach ($this->tempRoots as $root) {
            self::removeTree($root);
        }
        $this->tempRoots = [];

        SupportedLocales::reset();

        self::assertSame(
            $this->committedResourceDigest,
            (string) md5_file(self::committedResourcePath()),
            'a test wrote to the committed jsondata/supportedLocales.json instead of its throwaway copy'
        );

        parent::tearDown();
    }

    // ---------------------------------------------------------------- promotion

    /**
     * The happy path, built by seeding the throwaway resource with one official locale
     * REMOVED. The locale is therefore ready by construction — it is officially supported on
     * the committed data, so every probe passes — while being absent from the list this
     * request curates. That beats inventing a synthetic locale's worth of lectionary corpora,
     * and it fails loudly if the readiness probes ever stop agreeing with the committed list.
     */
    public function testPromotingAReadyLocaleAddsItToTheOfficialSetOnDisk(): void
    {
        $victim = self::committedOfficial()[0];
        $rest   = array_values(array_diff(self::committedOfficial(), [$victim]));
        $root   = $this->seedResource($rest, [$victim => 'held back by this test']);

        $body = $this->curate($root, $victim, 'promote');

        self::assertSame('applied', $body['disposition']);
        self::assertArrayNotHasKey('change_request', $body, 'disk mode records no proposal');
        self::assertSame($victim, $body['locale']);
        self::assertSame(self::committedOfficial(), $body['official']);

        $written = self::readResource($root);
        self::assertSame(self::committedOfficial(), $written['general_roman_calendar']['official']);
        self::assertArrayNotHasKey(
            'candidates',
            $written['general_roman_calendar'],
            'a promoted locale is no longer a candidate, and its "why not yet" note is now false'
        );
    }

    /**
     * The official list is re-sorted, so the file keeps its alphabetical order rather than
     * growing an append-ordered tail.
     */
    public function testTheOfficialListStaysSorted(): void
    {
        $official = self::committedOfficial();
        $victim   = $official[count($official) - 1];
        $root     = $this->seedResource(array_values(array_diff($official, [$victim])));

        $body = $this->curate($root, $victim, 'promote');

        $sorted = $body['official'];
        sort($sorted);
        self::assertSame($sorted, $body['official']);
    }

    /**
     * The gate this route exists to enforce, and it is not bypassable: there is no force
     * parameter to pass and no role that skips it. `hr` has a complete lectionary and a
     * gettext catalogue but an unnamed decreed event, which is exactly the gap that took the
     * Croatian calendar down once it was served strictly.
     */
    public function testAnUnreadyLocaleIsRefused(): void
    {
        $root = $this->seedResource(self::committedOfficial());

        try {
            $this->curate($root, 'hr', 'promote');
            self::fail('an unready locale must not be promotable');
        } catch (UnprocessableContentException $e) {
            self::assertStringContainsString('not ready to be promoted', $e->getMessage());
            self::assertStringContainsString('decree_names', $e->getMessage());
        }

        self::assertSame(
            self::committedOfficial(),
            self::readResource($root)['general_roman_calendar']['official'],
            'a refused promotion must leave the resource untouched'
        );
    }

    public function testPromotingAnAlreadyOfficialLocaleIsAConflict(): void
    {
        $root = $this->seedResource(self::committedOfficial());

        $this->expectException(ConflictException::class);
        $this->expectExceptionMessage('already officially supported');

        $this->curate($root, self::committedOfficial()[0], 'promote');
    }

    public function testPromotingALocaleWithNoResourcesIsNotFound(): void
    {
        $root = $this->seedResource(self::committedOfficial());

        $this->expectException(NotFoundException::class);

        $this->curate($root, 'zz', 'promote');
    }

    // ----------------------------------------------------------------- demotion

    /**
     * Demotion is deliberately NOT readiness-gated: it loosens enforcement rather than
     * tightening it, so it cannot turn a working calendar into a 500. Proved by demoting a
     * locale that is fully ready — the readiness probes have no say either way.
     */
    public function testDemotingRemovesTheLocaleWithoutConsultingReadiness(): void
    {
        $official = self::committedOfficial();
        $victim   = $official[0];
        $root     = $this->seedResource($official);

        $body = $this->curate($root, $victim, 'demote');

        self::assertSame('applied', $body['disposition']);
        self::assertSame(array_values(array_diff($official, [$victim])), $body['official']);
        self::assertSame(
            array_values(array_diff($official, [$victim])),
            self::readResource($root)['general_roman_calendar']['official']
        );
    }

    public function testDemotingALocaleThatIsNotOfficialIsAConflict(): void
    {
        $root = $this->seedResource(self::committedOfficial());

        $this->expectException(ConflictException::class);
        $this->expectExceptionMessage('is not officially supported');

        $this->curate($root, 'hr', 'demote');
    }

    /**
     * Emptying the list does not do what it says. `SupportedLocales::official()` substitutes
     * its built-in FALLBACK for an empty or unreadable resource, so a demotion that emptied
     * the file would silently RESTORE the historical five rather than support nothing.
     */
    public function testTheLastOfficialLocaleCannotBeDemoted(): void
    {
        $only = self::committedOfficial()[0];
        $root = $this->seedResource([$only]);

        try {
            $this->curate($root, $only, 'demote');
            self::fail('the last official locale must not be removable');
        } catch (ConflictException $e) {
            self::assertStringContainsString('last officially supported locale', $e->getMessage());
        }

        self::assertSame([$only], self::readResource($root)['general_roman_calendar']['official']);
    }

    // -------------------------------------------------------------- write modes

    /**
     * Queue mode asked for, stack absent. Every other write handler falls back to disk here
     * and logs a warning; this one refuses, because a promotion silently reverted by the next
     * deploy is worse than a promotion that did not happen, and unlike calendar editing there
     * is an understood manual alternative.
     */
    public function testCurationIsRefusedWhenQueueModeIsMisconfigured(): void
    {
        $root = $this->seedResource(self::committedOfficial());
        $this->misconfigureQueueMode();

        try {
            $this->curate($root, 'hr', 'promote');
            self::fail('a misconfigured queue mode must refuse rather than fall back to disk');
        } catch (ServiceUnavailableException $e) {
            self::assertStringContainsString('SOURCEDATA_CHANGE_REQUESTS', $e->getMessage());
        }
    }

    public function testTheListReportsMisconfiguredCurationAsNotWritable(): void
    {
        $root = $this->seedResource(self::committedOfficial());
        $this->misconfigureQueueMode();

        $body = $this->listPayload($root);

        self::assertFalse($body['curation']['writable']);
        self::assertSame('misconfigured', $body['curation']['mode']);
    }

    public function testTheListReportsDiskModeAsWritableWithItsCaveat(): void
    {
        $root = $this->seedResource(self::committedOfficial());

        $body = $this->listPayload($root);

        self::assertTrue($body['curation']['writable']);
        self::assertSame('disk', $body['curation']['mode']);
        self::assertStringContainsString('SOURCEDATA_CHANGE_REQUESTS', $body['curation']['reason']);
    }

    // ------------------------------------------------------------ route surface

    public function testAnUnknownCurationActionIsNotFound(): void
    {
        $root = $this->seedResource(self::committedOfficial());

        $this->expectException(NotFoundException::class);

        $this->curate($root, 'hr', 'annoint');
    }

    public function testCurationWithoutALocaleIsNotFound(): void
    {
        $this->seedResource(self::committedOfficial());

        $this->expectException(NotFoundException::class);

        ( new LocalesAdminHandler(['locales']) )->handle(
            $this->requestFor('POST', '/admin/locales')->withAttribute('oidc_user', self::globalAdmin())
        );
    }

    public function testAnUnauthenticatedCallerCannotCurate(): void
    {
        $this->expectException(UnauthorizedException::class);

        ( new LocalesAdminHandler(['locales', 'hr', 'promote']) )
            ->handle($this->requestFor('POST', '/admin/locales/hr/promote'));
    }

    public function testANonAdminCannotCurate(): void
    {
        $this->expectException(ForbiddenException::class);

        ( new LocalesAdminHandler(['locales', 'hr', 'promote']) )->handle(
            $this->requestFor('POST', '/admin/locales/hr/promote')
                ->withAttribute('oidc_user', ['sub' => 'editor-1', 'roles' => ['calendar_editor']])
        );
    }

    /**
     * The route curates through POST only. `return_type` and REST-shaped verbs are not the
     * house idiom for admin actions — `/admin/access-requests/{id}/approve` and
     * `/admin/change-requests/{batchId}/approve` are both POST — so PUT must be refused
     * rather than quietly aliased.
     */
    public function testPutIsNotAllowed(): void
    {
        $this->expectException(MethodNotAllowedException::class);

        ( new LocalesAdminHandler(['locales', 'hr', 'promote']) )
            ->handle($this->requestFor('PUT', '/admin/locales/hr/promote'));
    }

    // ---------------------------------------------------------------- machinery

    /**
     * Drive `POST /admin/locales/{locale}/{action}` against the tree rooted at `$root`.
     *
     * @return array<string, mixed>
     */
    private function curate(string $root, string $locale, string $action): array
    {
        Router::$apiFilePath = $root;
        SupportedLocales::reset();

        return $this->decodeJsonBody(
            ( new LocalesAdminHandler(['locales', $locale, $action]) )->handle(
                $this->requestFor('POST', sprintf('/admin/locales/%s/%s', $locale, $action))
                    ->withAttribute('oidc_user', self::globalAdmin())
            )
        );
    }

    /** @return array<string, mixed> */
    private function listPayload(string $root): array
    {
        Router::$apiFilePath = $root;
        SupportedLocales::reset();

        return $this->decodeJsonBody(
            ( new LocalesAdminHandler(['locales']) )->handle(
                $this->requestFor('GET', '/admin/locales')->withAttribute('oidc_user', self::globalAdmin())
            )
        );
    }

    /** @return array<string, mixed> */
    private static function globalAdmin(): array
    {
        return ['sub' => 'admin-1', 'roles' => ['admin']];
    }

    /**
     * The flag on, the stack gone. `SourceDataWriteMode::stackAvailable()` needs the OpenFGA
     * triple, so clearing it is enough and no database has to be taken away.
     */
    private function misconfigureQueueMode(): void
    {
        $_ENV[SourceDataWriteMode::FLAG] = 'true';
        unset($_ENV['OPENFGA_API_URL'], $_ENV['OPENFGA_STORE_ID'], $_ENV['OPENFGA_MODEL_ID']);

        self::assertTrue(SourceDataWriteMode::isMisconfigured(), 'the precondition this test forces did not take');
    }

    /**
     * A tree indistinguishable from the repository to every reader the handler has, except
     * that `jsondata/supportedLocales.json` is a real writable copy carrying `$official`.
     *
     * @param list<string>          $official
     * @param array<string, string> $candidates
     * @return string The root, with a trailing separator, as `Router::$apiFilePath` carries it.
     */
    private function seedResource(array $official, array $candidates = []): string
    {
        $repo = dirname(__DIR__, 2) . '/';
        $root = sys_get_temp_dir() . '/litcal-locale-curation-' . bin2hex(random_bytes(6)) . '/';
        mkdir($root . 'jsondata', 0o755, true);
        $this->tempRoots[] = $root;

        symlink($repo . 'i18n', $root . 'i18n');
        symlink($repo . 'jsondata/sourcedata', $root . 'jsondata/sourcedata');
        symlink($repo . 'jsondata/schemas', $root . 'jsondata/schemas');

        $set = ['official' => $official];
        if ($candidates !== []) {
            $set['candidates'] = $candidates;
        }

        file_put_contents(
            $root . 'jsondata/supportedLocales.json',
            json_encode(
                ['general_roman_calendar' => $set],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            ) . "\n"
        );

        return $root;
    }

    /** @return array<string, array<string, mixed>> */
    private static function readResource(string $root): array
    {
        /** @var array<string, array<string, mixed>> $decoded */
        $decoded = json_decode(
            (string) file_get_contents($root . 'jsondata/supportedLocales.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        return $decoded;
    }

    private static function committedResourcePath(): string
    {
        return dirname(__DIR__, 2) . '/jsondata/supportedLocales.json';
    }

    /**
     * The committed official list, read straight off disk rather than through
     * `SupportedLocales::official()`, which these tests keep re-pointing at temp trees.
     *
     * @return list<string>
     */
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
            // No FOLLOW_SYMLINKS: the tree is made of symlinks into the repository, and
            // descending into them would delete the repository's own source data.
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
