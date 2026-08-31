<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers;

use LiturgicalCalendar\Api\Enum\JsonData;
use LiturgicalCalendar\Api\Enum\LitSchema;
use LiturgicalCalendar\Api\Enum\RomanMissal;
use LiturgicalCalendar\Api\Handlers\MissalsHandler;
use LiturgicalCalendar\Api\Http\Exception\NotFoundException;
use LiturgicalCalendar\Api\Http\Exception\ValidationException;
use LiturgicalCalendar\Api\Router;
use PHPUnit\Framework\Attributes\CoversClass;
use Swaggest\JsonSchema\Schema;

/**
 * `GET /missals/{missal_id}/i18n` — issue #941 — plus the response-schema half of the same issue:
 * `openapi.json` documented `GET /missals/{missal_id}` with the *metadata* shape of a `/missals`
 * index row, which is not what that route emits.
 *
 * The translated/empty/missing three-way split is checked against every missal in the index, with
 * the expectation computed by reading the i18n files straight off disk — an oracle independent of
 * the handler — rather than by naming an `event_key` that a parallel rename could invalidate.
 *
 * One of the three states has no instance in the committed corpus: today every locale file of every
 * missal carries an entry for every row, so `missing` is empty everywhere. It is exercised anyway,
 * against a temporary source tree assembled by
 * {@see self::testAKeyAbsentFromALocaleIsReportedMissingNotEmpty()} — an untested branch of a
 * three-way split is a coin toss, and this one is the whole reason the split exists.
 */
#[CoversClass(MissalsHandler::class)]
final class MissalsHandlerI18nTest extends AbstractHandlerTestCase
{
    private ?string $tempRoot     = null;
    private ?string $savedApiFile = null;

    protected function setUp(): void
    {
        parent::setUp();
        MissalsHandler::$missalsIndex = null;
    }

    protected function tearDown(): void
    {
        if (null !== $this->savedApiFile) {
            Router::$apiFilePath = $this->savedApiFile;
            $this->savedApiFile  = null;
        }
        if (null !== $this->tempRoot) {
            self::removeTree($this->tempRoot);
            $this->tempRoot = null;
        }
        MissalsHandler::$missalsIndex = null;
        parent::tearDown();
    }

    /**
     * @return array<string,mixed>
     */
    private function i18nFor(string $missalId): array
    {
        MissalsHandler::$missalsIndex = null;
        $response                     = ( new MissalsHandler([$missalId, 'i18n']) )->handle(
            $this->requestFor('GET', '/missals/' . $missalId . '/i18n')
        );
        self::assertSame(200, $response->getStatusCode());
        return $this->decodeJsonBody($response);
    }

    /**
     * The missal ids the index actually serves, discovered through the index route rather than
     * from `RomanMissal`, whose enumeration includes editions with no data on disk.
     *
     * @return string[]
     */
    private function indexedMissalIds(): array
    {
        MissalsHandler::$missalsIndex = null;
        $response                     = ( new MissalsHandler() )->handle($this->requestFor('GET', '/missals'));
        self::assertSame(200, $response->getStatusCode());
        $body = $this->decodeJsonBody($response);
        /** @var string[] $ids */
        $ids = array_column($body['litcal_missals'], 'missal_id');
        self::assertNotEmpty($ids);
        return $ids;
    }

    /**
     * One missal's i18n files, decoded off disk.
     *
     * @return array<string,array<string,string>>
     */
    private static function i18nFilesOnDisk(string $missalId): array
    {
        $folder = RomanMissal::getSanctoraleI18nFilePath($missalId);
        self::assertIsString($folder);
        $files = glob(rtrim($folder, '/') . '/*.json');
        self::assertIsArray($files);
        sort($files);

        $decoded = [];
        foreach ($files as $file) {
            $raw = file_get_contents($file);
            self::assertIsString($raw);
            /** @var array<string,string> $map */
            $map                               = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            $decoded[basename($file, '.json')] = $map;
        }
        return $decoded;
    }

    // -------------------------------------------------------------------- shape

    public function testEveryLocaleIsReturnedSideBySide(): void
    {
        foreach ($this->indexedMissalIds() as $missalId) {
            $body = $this->i18nFor($missalId);

            self::assertSame($missalId, $body['missal_id']);
            $onDisk = self::i18nFilesOnDisk($missalId);

            self::assertSame(
                array_keys($onDisk),
                $body['locales'],
                "{$missalId} must report every locale its i18n folder declares"
            );
            self::assertSame(array_keys($onDisk), array_keys($body['i18n']));
            foreach ($onDisk as $locale => $map) {
                self::assertSame($map, $body['i18n'][$locale], "{$missalId}/{$locale} must be returned verbatim");
            }
            self::assertNotEmpty($body['event_keys']);
            self::assertSame($body['event_keys'], array_keys($body['coverage']));
        }
    }

    public function testUnknownMissalIsNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        ( new MissalsHandler(['NOT_A_REAL_MISSAL', 'i18n']) )
            ->handle($this->requestFor('GET', '/missals/NOT_A_REAL_MISSAL/i18n'));
    }

    public function testAnUnknownSubResourceIsStillAValidationError(): void
    {
        // Only `i18n` is a sub-resource of a missal; anything else stays the two-path-params error
        // the route answered before this one existed.
        $this->expectException(ValidationException::class);
        ( new MissalsHandler(['EDITIO_TYPICA_2002', 'lectionary']) )
            ->handle($this->requestFor('GET', '/missals/EDITIO_TYPICA_2002/lectionary'));
    }

    // -------------------------------------------------- translated vs. empty vs. missing

    public function testCoverageMatchesTheLocaleFilesForEveryMissal(): void
    {
        $sawTranslated = false;
        $sawEmpty      = false;

        foreach ($this->indexedMissalIds() as $missalId) {
            $body   = $this->i18nFor($missalId);
            $onDisk = self::i18nFilesOnDisk($missalId);

            foreach ($body['event_keys'] as $eventKey) {
                $translated = [];
                $empty      = [];
                $missing    = [];
                foreach ($onDisk as $locale => $map) {
                    if (false === array_key_exists($eventKey, $map)) {
                        $missing[] = $locale;
                    } elseif ($map[$eventKey] === '') {
                        $empty[] = $locale;
                    } else {
                        $translated[] = $locale;
                    }
                }

                $coverage = $body['coverage'][$eventKey];
                self::assertSame($translated, $coverage['translated'], "{$missalId}/{$eventKey} translated");
                self::assertSame($empty, $coverage['empty'], "{$missalId}/{$eventKey} empty");
                self::assertSame($missing, $coverage['missing'], "{$missalId}/{$eventKey} missing");

                $sawTranslated = $sawTranslated || [] !== $translated;
                $sawEmpty      = $sawEmpty || [] !== $empty;
            }
        }

        self::assertTrue($sawTranslated, 'no translated name anywhere in the corpus — the oracle cannot be right');
        self::assertTrue(
            $sawEmpty,
            'no empty-string translation anywhere in the corpus, so the translated/empty distinction '
            . 'was not actually exercised by this run'
        );
    }

    public function testAnEmptyTranslationIsReportedEmptyAndReturnedVerbatimRatherThanDropped(): void
    {
        // Find a real event_key that some locales translate and others carry as the empty string —
        // exactly the "translated in twelve locales, empty in two" case a single-locale request
        // cannot show. The one-request-per-locale workaround this route replaces could not tell
        // that apart from a locale with no entry at all.
        foreach ($this->indexedMissalIds() as $missalId) {
            $body = $this->i18nFor($missalId);
            foreach ($body['coverage'] as $eventKey => $coverage) {
                if ([] === $coverage['translated'] || [] === $coverage['empty']) {
                    continue;
                }

                foreach ($coverage['empty'] as $locale) {
                    self::assertArrayHasKey(
                        $eventKey,
                        $body['i18n'][$locale],
                        "{$missalId}/{$locale} carries {$eventKey} as an empty string, so the key must still be present"
                    );
                    self::assertSame('', $body['i18n'][$locale][$eventKey]);
                    self::assertNotContains($locale, $coverage['missing']);
                }
                foreach ($coverage['translated'] as $locale) {
                    self::assertNotSame('', $body['i18n'][$locale][$eventKey]);
                }
                return;
            }
        }

        self::fail('no event_key is translated in some locales and empty in others; the distinction was not exercised');
    }

    public function testAKeyAbsentFromALocaleIsReportedMissingNotEmpty(): void
    {
        // Every committed locale file carries an entry for every row, so the third state has no
        // instance to point at. Build one: a throwaway source tree holding a copy of one real
        // missal plus one extra locale file that deliberately omits some of its keys. Nothing
        // under jsondata/ is touched — `Router::$apiFilePath` is the prefix every `JsonData` path
        // is built from, so re-pointing it moves the whole read.
        $missalId    = 'EDITIO_TYPICA_2008';
        $missalDir   = 'propriumdesanctis_2008';
        $sourceDir   = JsonData::MISSALS_FOLDER->path() . '/' . $missalDir;
        $extraLocale = 'ca';

        self::assertDirectoryExists($sourceDir);
        self::assertFileDoesNotExist(
            $sourceDir . '/i18n/' . $extraLocale . '.json',
            "this test assumes '{$extraLocale}' is not a real locale of {$missalId}"
        );

        $this->tempRoot = self::makeTempDir();
        $target         = $this->tempRoot . '/jsondata/sourcedata/rite/roman/missals/' . $missalDir;
        self::copyTree($sourceDir, $target);

        $rows = json_decode((string) file_get_contents($target . '/' . $missalDir . '.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($rows);
        self::assertGreaterThanOrEqual(2, count($rows), 'need at least two rows to have one present and one absent');
        /** @var array<int,array<string,mixed>> $rows */
        $eventKeys  = array_map(static fn (array $row): string => (string) $row['event_key'], $rows);
        $presentKey = $eventKeys[0];
        $absentKeys = array_slice($eventKeys, 1);

        file_put_contents(
            $target . '/i18n/' . $extraLocale . '.json',
            json_encode([$presentKey => 'Un nom en català'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)
        );

        $this->savedApiFile  = Router::$apiFilePath;
        Router::$apiFilePath = $this->tempRoot . DIRECTORY_SEPARATOR;

        $body = $this->i18nFor($missalId);

        // Put the real tree back before asserting: every `JsonData` path hangs off this prefix,
        // the schema folder included, so leaving it redirected would send Schema::import() looking
        // for LitCalMissalTranslationsPath.json inside the throwaway tree.
        Router::$apiFilePath = $this->savedApiFile;
        $this->savedApiFile  = null;

        self::assertContains($extraLocale, $body['locales']);

        // The key the extra locale does carry: translated, and neither empty nor missing.
        self::assertContains($extraLocale, $body['coverage'][$presentKey]['translated']);
        self::assertNotContains($extraLocale, $body['coverage'][$presentKey]['missing']);
        self::assertNotContains($extraLocale, $body['coverage'][$presentKey]['empty']);

        // The keys it does not: missing — NOT empty — and absent from the returned map, which is
        // what a client reads to tell "no entry in this locale" from "entry not written yet".
        foreach ($absentKeys as $absentKey) {
            self::assertContains(
                $extraLocale,
                $body['coverage'][$absentKey]['missing'],
                "{$absentKey} has no entry in {$extraLocale}, so it must be reported missing"
            );
            self::assertNotContains($extraLocale, $body['coverage'][$absentKey]['empty']);
            self::assertNotContains($extraLocale, $body['coverage'][$absentKey]['translated']);
            self::assertArrayNotHasKey($absentKey, $body['i18n'][$extraLocale]);
        }

        // …while the locales that carry those same keys as empty strings are still reported empty,
        // so the two states are told apart within a single response.
        $sawEmptyBeside = false;
        foreach ($absentKeys as $absentKey) {
            if ([] !== $body['coverage'][$absentKey]['empty']) {
                $sawEmptyBeside = true;
                $locale         = $body['coverage'][$absentKey]['empty'][0];
                self::assertSame('', $body['i18n'][$locale][$absentKey]);
            }
        }
        self::assertTrue($sawEmptyBeside, 'expected the same response to carry an empty entry alongside a missing one');

        Schema::import(LitSchema::MISSAL_TRANSLATIONS->path())->in(json_decode((string) json_encode($body), flags: JSON_THROW_ON_ERROR));
        $this->addToAssertionCount(1);
    }

    // -------------------------------------------------------------- response schemas

    public function testI18nResponsesValidateAgainstTheMissalTranslationsSchema(): void
    {
        foreach ($this->indexedMissalIds() as $missalId) {
            MissalsHandler::$missalsIndex = null;
            $response                     = ( new MissalsHandler([$missalId, 'i18n']) )->handle(
                $this->requestFor('GET', '/missals/' . $missalId . '/i18n')
            );
            self::assertSame(200, $response->getStatusCode());
            $decoded = json_decode((string) $response->getBody(), flags: JSON_THROW_ON_ERROR);
            Schema::import(LitSchema::MISSAL_TRANSLATIONS->path())->in($decoded);
            $this->addToAssertionCount(1);
        }
    }

    /**
     * The other half of #941: `GET /missals/{missal_id}` returns an array of sanctorale rows, and
     * openapi.json now says so. Validating the real response against the schema that replaced the
     * metadata `$ref` is what keeps the documented shape and the emitted one from drifting again.
     */
    public function testSingleMissalResponseValidatesAgainstTheSanctoraleSchema(): void
    {
        foreach ($this->indexedMissalIds() as $missalId) {
            MissalsHandler::$missalsIndex = null;
            $response                     = ( new MissalsHandler([$missalId]) )->handle(
                $this->requestFor('GET', '/missals/' . $missalId, ['Accept-Language' => 'en'])
            );
            self::assertSame(200, $response->getStatusCode());
            $decoded = json_decode((string) $response->getBody(), flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($decoded, "{$missalId} must answer with an array of sanctorale rows, not a metadata object");
            Schema::import(LitSchema::MISSAL_SANCTORALE->path())->in($decoded);
            $this->addToAssertionCount(1);
        }
    }

    // ------------------------------------------------------------------- helpers

    private static function makeTempDir(): string
    {
        $base = sys_get_temp_dir() . '/litcal-missal-i18n-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($base, 0o777, true));
        return $base;
    }

    private static function copyTree(string $from, string $to): void
    {
        self::assertTrue(is_dir($from));
        if (false === is_dir($to)) {
            self::assertTrue(mkdir($to, 0o777, true));
        }
        $entries = scandir($from);
        self::assertIsArray($entries);
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $src = $from . DIRECTORY_SEPARATOR . $entry;
            $dst = $to . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($src)) {
                self::copyTree($src, $dst);
            } else {
                self::assertTrue(copy($src, $dst));
            }
        }
    }

    private static function removeTree(string $path): void
    {
        if (false === is_dir($path)) {
            return;
        }
        $entries = scandir($path);
        if (false === $entries) {
            return;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $path . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($child)) {
                self::removeTree($child);
            } else {
                unlink($child);
            }
        }
        rmdir($path);
    }
}
