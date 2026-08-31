<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers;

use LiturgicalCalendar\Api\Enum\JsonData;
use LiturgicalCalendar\Api\Enum\RomanMissal;
use LiturgicalCalendar\Api\Handlers\MissalsHandler;
use LiturgicalCalendar\Api\Http\Exception\ConflictException;
use LiturgicalCalendar\Api\Http\Exception\NotFoundException;
use LiturgicalCalendar\Api\Http\Exception\ValidationException;
use LiturgicalCalendar\Api\Http\Logs\LoggerFactory;
use LiturgicalCalendar\Api\Router;
use LiturgicalCalendar\Tests\Support\ShadowProjectRootTrait;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * `PUT` / `PATCH` / `DELETE /missals/{missal_id}/{event_key}` — issue #943.
 *
 * Every write lands in a throwaway shadow of `jsondata/` under `sys_get_temp_dir()`, never in the
 * working tree: `Router::$apiFilePath` is the single seam every `JsonData::…->path()` resolves
 * against, so repointing it moves the handler AND this class's own assertions together (#921,
 * #935). The whole `jsondata/` tree is re-copied before each test, so no test can see another's
 * writes.
 *
 * The guards are exercised by CONSTRUCTING the failing case, not by inferring their existence from
 * a suite that passes. Each one asserts two things: that the request is refused, and that the
 * source tree is byte-for-byte unchanged afterwards — a guard that throws after having already
 * written half the fan-out would pass the first assertion on its own.
 */
#[CoversClass(MissalsHandler::class)]
final class MissalsHandlerWriteTest extends AbstractHandlerTestCase
{
    use ShadowProjectRootTrait;

    /** Shadow of the project root; `Router::$apiFilePath` points here for the class's lifetime. */
    private static string $fixtureRoot = '';

    /** Untouched copy of the shipped `jsondata/`, re-applied before every test. */
    private static string $pristineJsonData = '';

    public static function setUpBeforeClass(): void
    {
        // Pins Router::$apiFilePath to the real project root, and skips the whole class when JWT
        // config is absent — before anything below has allocated state.
        parent::setUpBeforeClass();

        $realRoot = Router::$apiFilePath;

        // Pin the audit logger to the REAL logs/ folder while the root still points there.
        // LoggerFactory memoises the resolved folder and the 'audit' channel for the whole
        // process; letting the handler resolve it later, under the fixture root, would leave
        // every later test class in this process logging into a directory we delete.
        $realLogs = $realRoot . 'logs';
        if (!is_dir($realLogs)) {
            mkdir($realLogs, 0755, true);
        }
        LoggerFactory::create('audit', $realLogs, 90, false, true, false);

        self::$fixtureRoot      = self::createShadowProjectRoot($realRoot, 'litcal-missalwrite-fixture');
        self::$pristineJsonData = self::$fixtureRoot . DIRECTORY_SEPARATOR . 'pristine';
        self::copyTree(self::$fixtureRoot . DIRECTORY_SEPARATOR . 'jsondata', self::$pristineJsonData);

        Router::$apiFilePath = self::$fixtureRoot . DIRECTORY_SEPARATOR;
    }

    public static function tearDownAfterClass(): void
    {
        if ('' !== self::$fixtureRoot) {
            self::removeTree(self::$fixtureRoot);
            self::$fixtureRoot      = '';
            self::$pristineJsonData = '';
        }
        MissalsHandler::$missalsIndex = null;
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();
        // Both source and destination live under sys_get_temp_dir(), so this delete-then-copy can
        // only ever destroy a copy.
        self::removeTree(self::$fixtureRoot . DIRECTORY_SEPARATOR . 'jsondata');
        self::copyTree(self::$pristineJsonData, self::$fixtureRoot . DIRECTORY_SEPARATOR . 'jsondata');
        // The index is a process-global static built from disk; a stale one would answer for the
        // previous test's tree.
        MissalsHandler::$missalsIndex   = null;
        MissalsHandler::$availableLangs = [];
    }

    // ---------------------------------------------------------------------------------------
    // helpers
    // ---------------------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed> the decoded response body
     */
    private function write(string $method, string $missalId, string $eventKey, ?array $payload = null): array
    {
        MissalsHandler::$missalsIndex = null;
        $handler                      = new MissalsHandler([$missalId, $eventKey]);
        $handler->setAllowedRequestMethods([
            \LiturgicalCalendar\Api\Http\Enum\RequestMethod::PUT,
            \LiturgicalCalendar\Api\Http\Enum\RequestMethod::PATCH,
            \LiturgicalCalendar\Api\Http\Enum\RequestMethod::DELETE,
        ]);
        $request = $this->requestFor($method, "/missals/{$missalId}/{$eventKey}", [], $payload);

        $this->lastResponse = $handler->handle($this->withOidcUser($request, 'editor-1'));

        return $this->decodeJsonBody($this->lastResponse);
    }

    private ?\Psr\Http\Message\ResponseInterface $lastResponse = null;

    /** @return list<array<string, mixed>> */
    private function structureRows(string $missalId): array
    {
        $file = RomanMissal::getSanctoraleFileName($missalId);
        self::assertIsString($file);
        /** @var list<array<string, mixed>> $rows */
        $rows = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);

        return $rows;
    }

    /** @return array<string, mixed> */
    private function sidecar(string $file): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /**
     * Every SOURCE file in the fixture's jsondata tree, ascending.
     *
     * The zero-byte `.lock` files are excluded. They are advisory flocks created beside the
     * aggregate they serialize, gitignored, and never carry content — `fopen(..., 'c')` creates
     * one before the handler has decided whether the request is admissible at all. Counting them
     * would make every refusal look like a write.
     *
     * @return list<string>
     */
    private static function jsonDataFiles(): array
    {
        $root  = self::$fixtureRoot . DIRECTORY_SEPARATOR . 'jsondata';
        $found = [];
        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && 'lock' !== $file->getExtension()) {
                $found[] = $file->getPathname();
            }
        }
        sort($found);

        return $found;
    }

    /**
     * A content fingerprint of the whole source tree, so a refusal can be shown to have written
     * nothing at all rather than merely to have thrown.
     *
     * @return array<string, string>
     */
    private static function sourceTreeFingerprint(): array
    {
        $fingerprint = [];
        foreach (self::jsonDataFiles() as $file) {
            $fingerprint[$file] = (string) md5_file($file);
        }

        return $fingerprint;
    }

    /** An `event_key` declared by `$missalId` but by no other missal, discovered from the corpus. */
    private function keyDeclaredOnlyBy(string $missalId): string
    {
        $others = [];
        foreach (RomanMissal::getMissalIds() as $id) {
            if ($id === $missalId) {
                continue;
            }
            $file = RomanMissal::getSanctoraleFileName($id);
            if (false === $file || false === file_exists($file)) {
                continue;
            }
            foreach ($this->structureRows($id) as $row) {
                $others[$row['event_key']] = true;
            }
        }
        foreach ($this->structureRows($missalId) as $row) {
            if (false === isset($others[$row['event_key']])) {
                return (string) $row['event_key'];
            }
        }
        self::fail("No event_key is declared only by {$missalId}; the fixture's premise no longer holds.");
    }

    // ---------------------------------------------------------------------------------------
    // create: the fan-out
    // ---------------------------------------------------------------------------------------

    /**
     * The invariant the issue calls out: creating an entry must add the key to EVERY locale file,
     * including the ones with nothing to say, writing an empty value rather than omitting the key.
     *
     * Asserted per file, not by counting: a fan-out that reached thirteen of fourteen locales
     * would still change the count of every file it touched.
     */
    public function testCreateFansTheKeyIntoEveryI18nLocaleFileIncludingTheEmptyOnes(): void
    {
        $body = $this->write('PUT', RomanMissal::EDITIO_TYPICA_1970, 'StTestFanOut', [
            'month'    => 6,
            'day'      => 17,
            'grade'    => 2,
            'common'   => ['Pastors'],
            'calendar' => 'GENERAL ROMAN',
            'color'    => ['white'],
            'i18n'     => ['en' => 'Saint Test of the Fan Out'],
        ]);

        self::assertSame(201, $this->lastResponse?->getStatusCode());
        self::assertSame('applied', $body['disposition'] ?? null);

        $i18nFolder = RomanMissal::getSanctoraleI18nFilePath(RomanMissal::EDITIO_TYPICA_1970);
        self::assertIsString($i18nFolder);
        $files = glob(rtrim($i18nFolder, '/') . '/*.json') ?: [];
        self::assertCount(14, $files, 'the 1970 missal is the fourteen-locale case this invariant was written for');

        foreach ($files as $file) {
            $locale = basename($file, '.json');
            $names  = $this->sidecar($file);
            self::assertArrayHasKey(
                'StTestFanOut',
                $names,
                "{$locale}.json must carry the new key even though it has no translation for it"
            );
            self::assertSame(
                $locale === 'en' ? 'Saint Test of the Fan Out' : '',
                $names['StTestFanOut'],
                "{$locale}.json"
            );
        }
    }

    /**
     * The same fan-out for the readings, in the tier that carries them. `EDITIO_TYPICA_1970` has
     * no `lectionary/` folder of its own, so its readings live in the rite-wide `sanctorum` corpus
     * — the same tier `GET /lectionary/{rite}/sanctorale` reports for it.
     */
    public function testCreateFansTheKeyIntoEveryReadingsLocaleFileOfTheRiteTier(): void
    {
        $body = $this->write('PUT', RomanMissal::EDITIO_TYPICA_1970, 'StTestFanOut', [
            'month'    => 6,
            'day'      => 17,
            'grade'    => 2,
            'common'   => ['Pastors'],
            'calendar' => 'GENERAL ROMAN',
            'color'    => ['white'],
            'readings' => [
                'en' => [
                    'first_reading'      => 'Acts 1:1-5',
                    'responsorial_psalm' => 'Psalm 1',
                    'gospel_acclamation' => 'John 1:1',
                    'gospel'             => 'Luke 1:1-4',
                ],
            ],
        ]);

        self::assertSame('rite', $body['readings_tier'] ?? null);

        $files = glob(JsonData::LECTIONARY_SAINTS_FOLDER->path() . '/*.json') ?: [];
        self::assertCount(6, $files);

        foreach ($files as $file) {
            $locale   = basename($file, '.json');
            $readings = $this->sidecar($file);
            self::assertArrayHasKey('StTestFanOut', $readings, "sanctorum/{$locale}.json");
            self::assertSame(
                $locale === 'en'
                    ? ['first_reading' => 'Acts 1:1-5', 'responsorial_psalm' => 'Psalm 1', 'gospel_acclamation' => 'John 1:1', 'gospel' => 'Luke 1:1-4']
                    : ['first_reading' => '', 'responsorial_psalm' => '', 'gospel_acclamation' => '', 'gospel' => ''],
                $readings['StTestFanOut'],
                "sanctorum/{$locale}.json"
            );
        }
    }

    /** A national edition's readings go to its OWN lectionary folder, both locales of it. */
    public function testCreateOnANationalEditionWritesItsOwnLectionaryTier(): void
    {
        $body = $this->write('PUT', RomanMissal::USA_EDITION_2011, 'StTestAmerican', [
            'month'         => 7,
            'day'           => 22,
            'grade'         => 3,
            'grade_display' => null,
            'common'        => ['Proper'],
            'calendar'      => 'US',
            'color'         => ['white'],
        ]);

        self::assertSame('missal', $body['readings_tier'] ?? null);

        $folder = RomanMissal::getLectionaryFilePath(RomanMissal::USA_EDITION_2011);
        self::assertIsString($folder);
        $files = glob(rtrim($folder, '/') . '/*.json') ?: [];
        self::assertCount(2, $files);
        foreach ($files as $file) {
            $readings = $this->sidecar($file);
            self::assertArrayHasKey('StTestAmerican', $readings, basename($file));
            self::assertSame(
                ['first_reading' => '', 'responsorial_psalm' => '', 'gospel_acclamation' => '', 'gospel' => ''],
                $readings['StTestAmerican']
            );
        }

        // The rite-level corpus is not this missal's tier and must be untouched.
        $sanctorum = $this->sidecar(JsonData::LECTIONARY_SAINTS_FOLDER->path() . '/en.json');
        self::assertArrayNotHasKey('StTestAmerican', $sanctorum);
    }

    public function testCreateAppendsTheStructureRowInTheCorpusPropertyOrder(): void
    {
        $this->write('PUT', RomanMissal::USA_EDITION_2011, 'StTestAmerican', [
            'grade_display' => null,
            'color'         => ['white'],
            'calendar'      => 'US',
            'common'        => ['Proper'],
            'grade'         => 3,
            'day'           => 22,
            'month'         => 7,
        ]);

        $rows = $this->structureRows(RomanMissal::USA_EDITION_2011);
        $new  = end($rows);
        self::assertIsArray($new);
        self::assertSame('StTestAmerican', $new['event_key']);
        self::assertSame(
            ['month', 'day', 'event_key', 'grade', 'grade_display', 'common', 'calendar', 'color'],
            array_keys($new),
            'the row is rebuilt in the order the corpus already uses, whatever order the payload arrived in'
        );
    }

    // ---------------------------------------------------------------------------------------
    // the event_key identity rule (scripts/lint-missals.php invariant 2, from #939)
    // ---------------------------------------------------------------------------------------

    public function testCreateIsRefusedWhenAnotherMissalDeclaresTheKeyOnADifferentDate(): void
    {
        $shared = $this->keyDeclaredOnlyBy(RomanMissal::EDITIO_TYPICA_1970);
        $row    = null;
        foreach ($this->structureRows(RomanMissal::EDITIO_TYPICA_1970) as $candidate) {
            if ($candidate['event_key'] === $shared) {
                $row = $candidate;
                break;
            }
        }
        self::assertIsArray($row);

        $before = self::sourceTreeFingerprint();

        try {
            $this->write('PUT', RomanMissal::USA_EDITION_2011, $shared, [
                // Deliberately a different day from the one the 1970 missal declares.
                'month'         => (int) $row['month'],
                'day'           => (int) $row['day'] === 28 ? 27 : (int) $row['day'] + 1,
                'grade'         => 3,
                'grade_display' => null,
                'common'        => ['Proper'],
                'calendar'      => 'US',
                'color'         => ['white'],
            ]);
            self::fail('a key that would denote a second saint must be refused');
        } catch (ConflictException $e) {
            self::assertStringContainsString('cannot denote two saints', $e->getMessage());
            // Named by missal id, which is the vocabulary the API speaks; `lint-missals`
            // names the same missal by its folder, which is the vocabulary a file tree speaks.
            self::assertStringContainsString(RomanMissal::EDITIO_TYPICA_1970, $e->getMessage());
            self::assertStringContainsString($shared, $e->getMessage());
        }

        self::assertSame($before, self::sourceTreeFingerprint(), 'a refused create must write nothing at all');
    }

    /**
     * The other half of the rule, and the reason it is not "a key may appear only once": a missal
     * is a delta layer, and re-declaring a key on the SAME date to give it a different grade for a
     * national calendar is normal and correct — `StPeterClaver` is declared by three missals.
     */
    public function testCreateIsAllowedWhenAnotherMissalDeclaresTheKeyOnTheSameDate(): void
    {
        $shared = $this->keyDeclaredOnlyBy(RomanMissal::EDITIO_TYPICA_1970);
        $row    = null;
        foreach ($this->structureRows(RomanMissal::EDITIO_TYPICA_1970) as $candidate) {
            if ($candidate['event_key'] === $shared) {
                $row = $candidate;
                break;
            }
        }
        self::assertIsArray($row);

        $this->write('PUT', RomanMissal::USA_EDITION_2011, $shared, [
            'month'         => (int) $row['month'],
            'day'           => (int) $row['day'],
            'grade'         => 4,
            'grade_display' => null,
            'common'        => ['Proper'],
            'calendar'      => 'US',
            'color'         => ['white'],
        ]);

        self::assertSame(201, $this->lastResponse?->getStatusCode());
        self::assertContains(
            $shared,
            array_column($this->structureRows(RomanMissal::USA_EDITION_2011), 'event_key')
        );
    }

    /** Moving an existing entry's date can break the agreement just as easily as adding one. */
    public function testPatchIsRefusedWhenItMovesASharedKeyOffTheAgreedDate(): void
    {
        $shared = null;
        $us     = array_column($this->structureRows(RomanMissal::USA_EDITION_2011), null, 'event_key');
        foreach ($this->structureRows(RomanMissal::EDITIO_TYPICA_TERTIA_2002) as $row) {
            if (isset($us[$row['event_key']]) && $us[$row['event_key']]['month'] === $row['month']) {
                $shared = (string) $row['event_key'];
                break;
            }
        }
        self::assertIsString($shared, 'the corpus must contain a key shared by 2002 and US_2011 (StPeterClaver)');

        $before = self::sourceTreeFingerprint();

        $this->expectException(ConflictException::class);
        try {
            $this->write('PATCH', RomanMissal::USA_EDITION_2011, $shared, ['day' => 1, 'month' => 12]);
        } finally {
            self::assertSame($before, self::sourceTreeFingerprint(), 'a refused patch must write nothing at all');
        }
    }

    // ---------------------------------------------------------------------------------------
    // the event_key immutability guard (DecreesHandler FINDING 3)
    // ---------------------------------------------------------------------------------------

    public function testPatchRefusesAPayloadEventKeyThatDisagreesWithTheUrl(): void
    {
        $existing = (string) $this->structureRows(RomanMissal::USA_EDITION_2011)[0]['event_key'];
        $before   = self::sourceTreeFingerprint();

        try {
            $this->write('PATCH', RomanMissal::USA_EDITION_2011, $existing, [
                'event_key' => $existing . 'Renamed',
                'grade'     => 4,
            ]);
            self::fail('renaming an event_key through an edit must be refused, not applied');
        } catch (ValidationException $e) {
            self::assertStringContainsString('is not allowed', $e->getMessage());
            self::assertStringContainsString('DELETE the entry', $e->getMessage());
        }

        self::assertSame($before, self::sourceTreeFingerprint(), 'a refused rename must orphan nothing');
    }

    public function testPatchAcceptsAPayloadEventKeyThatMatchesTheUrl(): void
    {
        $existing = (string) $this->structureRows(RomanMissal::USA_EDITION_2011)[0]['event_key'];

        $this->write('PATCH', RomanMissal::USA_EDITION_2011, $existing, [
            'event_key' => $existing,
            'grade'     => 4,
        ]);

        $rows = array_column($this->structureRows(RomanMissal::USA_EDITION_2011), null, 'event_key');
        self::assertSame(4, $rows[$existing]['grade']);
    }

    /** A PATCH that cannot find its target must not become a create under a nearly-right key. */
    public function testPatchOfAnUnknownKeyIsNotFoundRatherThanACreate(): void
    {
        $before = self::sourceTreeFingerprint();

        try {
            $this->write('PATCH', RomanMissal::USA_EDITION_2011, 'StNoSuchSaint', ['grade' => 3]);
            self::fail('a PATCH of an unknown key must not create it');
        } catch (NotFoundException $e) {
            self::assertStringContainsString('use PUT to create it', $e->getMessage());
        }

        self::assertSame($before, self::sourceTreeFingerprint());
    }

    public function testPutOnAKeyTheMissalAlreadyDeclaresIsAConflict(): void
    {
        $existing = (string) $this->structureRows(RomanMissal::USA_EDITION_2011)[0]['event_key'];
        $before   = self::sourceTreeFingerprint();

        try {
            $this->write('PUT', RomanMissal::USA_EDITION_2011, $existing, [
                'month'         => 1,
                'day'           => 4,
                'grade'         => 3,
                'grade_display' => null,
                'common'        => ['Proper'],
                'calendar'      => 'US',
                'color'         => ['white'],
            ]);
            self::fail('PUT must not silently replace an existing entry');
        } catch (ConflictException $e) {
            self::assertStringContainsString('Use PATCH', $e->getMessage());
        }

        self::assertSame($before, self::sourceTreeFingerprint());
    }

    // ---------------------------------------------------------------------------------------
    // delete: garbage collection
    // ---------------------------------------------------------------------------------------

    public function testDeleteGarbageCollectsTheKeyFromEveryI18nAndLectionarySidecar(): void
    {
        $key = $this->keyDeclaredOnlyBy(RomanMissal::USA_EDITION_2011);

        $body = $this->write('DELETE', RomanMissal::USA_EDITION_2011, $key);
        self::assertSame(200, $this->lastResponse?->getStatusCode());
        self::assertFalse($body['readings_retained'] ?? null);

        self::assertNotContains($key, array_column($this->structureRows(RomanMissal::USA_EDITION_2011), 'event_key'));

        $i18nFolder = RomanMissal::getSanctoraleI18nFilePath(RomanMissal::USA_EDITION_2011);
        self::assertIsString($i18nFolder);
        foreach (glob(rtrim($i18nFolder, '/') . '/*.json') ?: [] as $file) {
            self::assertArrayNotHasKey($key, $this->sidecar($file), 'i18n/' . basename($file));
        }

        $lectFolder = RomanMissal::getLectionaryFilePath(RomanMissal::USA_EDITION_2011);
        self::assertIsString($lectFolder);
        $lectFiles = glob(rtrim($lectFolder, '/') . '/*.json') ?: [];
        self::assertCount(2, $lectFiles);
        foreach ($lectFiles as $file) {
            self::assertArrayNotHasKey($key, $this->sidecar($file), 'lectionary/' . basename($file));
        }
    }

    /** The rite-tier variant: a key nothing else declares loses its rite-level readings too. */
    public function testDeleteGarbageCollectsRiteLevelReadingsForAKeyNothingElseDeclares(): void
    {
        $key = $this->keyDeclaredOnlyBy(RomanMissal::EDITIO_TYPICA_1970);
        self::assertArrayHasKey($key, $this->sidecar(JsonData::LECTIONARY_SAINTS_FOLDER->path() . '/en.json'));

        $body = $this->write('DELETE', RomanMissal::EDITIO_TYPICA_1970, $key);
        self::assertFalse($body['readings_retained'] ?? null);

        foreach (glob(JsonData::LECTIONARY_SAINTS_FOLDER->path() . '/*.json') ?: [] as $file) {
            self::assertArrayNotHasKey($key, $this->sidecar($file), 'sanctorum/' . basename($file));
        }
    }

    /**
     * …and the case that makes the check necessary: the rite-level corpus is SHARED. Dropping
     * `StPeterClaver` from the 2002 editio typica must leave his readings where IT_1983 and
     * US_2011 still need them.
     */
    public function testDeleteRetainsRiteLevelReadingsForAKeyAnotherMissalStillDeclares(): void
    {
        $us     = array_column($this->structureRows(RomanMissal::USA_EDITION_2011), null, 'event_key');
        $shared = null;
        foreach ($this->structureRows(RomanMissal::EDITIO_TYPICA_TERTIA_2002) as $row) {
            if (isset($us[$row['event_key']])) {
                $shared = (string) $row['event_key'];
                break;
            }
        }
        self::assertIsString($shared);

        $sanctorumBefore = $this->sidecar(JsonData::LECTIONARY_SAINTS_FOLDER->path() . '/en.json');
        self::assertArrayHasKey($shared, $sanctorumBefore);

        $body = $this->write('DELETE', RomanMissal::EDITIO_TYPICA_TERTIA_2002, $shared);
        self::assertTrue($body['readings_retained'] ?? null);

        $sanctorumAfter = $this->sidecar(JsonData::LECTIONARY_SAINTS_FOLDER->path() . '/en.json');
        self::assertArrayHasKey($shared, $sanctorumAfter, 'another missal still declares this key');
        self::assertSame($sanctorumBefore[$shared], $sanctorumAfter[$shared]);

        // The 2002 missal's OWN name sidecars are still collected: they belong to it alone.
        $i18nFolder = RomanMissal::getSanctoraleI18nFilePath(RomanMissal::EDITIO_TYPICA_TERTIA_2002);
        self::assertIsString($i18nFolder);
        foreach (glob(rtrim($i18nFolder, '/') . '/*.json') ?: [] as $file) {
            self::assertArrayNotHasKey($shared, $this->sidecar($file), 'i18n/' . basename($file));
        }
    }

    public function testDeleteOfAnUnknownKeyIsNotFound(): void
    {
        $before = self::sourceTreeFingerprint();
        try {
            $this->write('DELETE', RomanMissal::USA_EDITION_2011, 'StNoSuchSaint');
            self::fail('deleting an entry that does not exist must be a 404');
        } catch (NotFoundException) {
            self::assertSame($before, self::sourceTreeFingerprint());
        }
    }

    // ---------------------------------------------------------------------------------------
    // payload and target validation
    // ---------------------------------------------------------------------------------------

    public function testAnEntryMayNotBeFiledUnderACalendarItsMissalNeverApplies(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/belong to the `US` calendar/');
        $this->write('PUT', RomanMissal::USA_EDITION_2011, 'StTestAmerican', [
            'month'    => 7,
            'day'      => 22,
            'grade'    => 3,
            'common'   => ['Proper'],
            'calendar' => 'GENERAL ROMAN',
            'color'    => ['white'],
        ]);
    }

    public function testCreateRequiresTheWholeEntry(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/missing: day, grade, common, calendar, color/');
        $this->write('PUT', RomanMissal::USA_EDITION_2011, 'StTestAmerican', ['month' => 7]);
    }

    public function testAPayloadThatFailsTheWriteSchemaIsRefused(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/failed schema validation/');
        $this->write('PUT', RomanMissal::USA_EDITION_2011, 'StTestAmerican', [
            'month'    => 13,
            'day'      => 22,
            'grade'    => 3,
            'common'   => ['Proper'],
            'calendar' => 'US',
            'color'    => ['white'],
        ]);
    }

    public function testAPatchCarryingNothingButTheKeyIsRefusedRatherThanRecordedAsAChange(): void
    {
        $existing = (string) $this->structureRows(RomanMissal::USA_EDITION_2011)[0]['event_key'];
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/at least one property to change/');
        $this->write('PATCH', RomanMissal::USA_EDITION_2011, $existing, ['event_key' => $existing]);
    }

    /**
     * Adding a locale to a missal changes the locale set `/missals` advertises for it, so it is a
     * separate act and is refused here rather than performed as a side effect. It is also what
     * makes every written path derive from a filename that already exists.
     */
    public function testASidecarLocaleTheMissalHasNoFileForIsRefused(): void
    {
        $before = self::sourceTreeFingerprint();
        try {
            $this->write('PUT', RomanMissal::USA_EDITION_2011, 'StTestAmerican', [
                'month'    => 7,
                'day'      => 22,
                'grade'    => 3,
                'common'   => ['Proper'],
                'calendar' => 'US',
                'color'    => ['white'],
                'i18n'     => ['sw_KE' => 'Mtakatifu Test'],
            ]);
            self::fail('a locale with no file must not be created as a side effect');
        } catch (ValidationException $e) {
            self::assertStringContainsString('sw_KE', $e->getMessage());
            self::assertStringContainsString('en_US', $e->getMessage());
        }
        self::assertSame($before, self::sourceTreeFingerprint());
    }

    public function testAMissalWithNoSanctoraleDataCannotBeWrittenTo(): void
    {
        $this->expectException(NotFoundException::class);
        $this->write('PUT', RomanMissal::CANADA_EDITION_2011, 'StTestCanadian', [
            'month'    => 7,
            'day'      => 22,
            'grade'    => 3,
            'common'   => ['Proper'],
            'calendar' => 'CA',
            'color'    => ['white'],
        ]);
    }

    public function testTheAggregatedTranslationsSubResourceIsNotAWriteTarget(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/addresses one ENTRY/');
        $this->write('PUT', RomanMissal::USA_EDITION_2011, 'i18n', ['month' => 7]);
    }

    // ---------------------------------------------------------------------------------------
    // formatting
    // ---------------------------------------------------------------------------------------

    /**
     * A single-entry write must not reformat the file around it. The handler encodes with plain
     * pretty-printing rather than through `JsonFormatter`, which collapses `["white"]` onto one
     * line — a formatter mismatch would turn every one-row change into a whole-file diff and make
     * a change request unreviewable.
     */
    public function testEncodingAnUntouchedSanctoraleFileReproducesItByteForByte(): void
    {
        $checked = 0;
        foreach (RomanMissal::getMissalIds() as $missalId) {
            $file = RomanMissal::getSanctoraleFileName($missalId);
            if (false === $file || false === file_exists($file)) {
                continue;
            }
            $raw = (string) file_get_contents($file);
            self::assertSame(
                $raw,
                json_encode(json_decode($raw, false, 512, JSON_THROW_ON_ERROR), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL,
                $missalId . ' would be reformatted by a write that changes nothing in it'
            );
            ++$checked;
        }
        self::assertSame(5, $checked);

        foreach (glob(JsonData::LECTIONARY_SAINTS_FOLDER->path() . '/*.json') ?: [] as $file) {
            $raw = (string) file_get_contents($file);
            self::assertSame(
                $raw,
                json_encode(json_decode($raw, true, 512, JSON_THROW_ON_ERROR), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL,
                'sanctorum/' . basename($file)
            );
        }
    }

    /** A create must leave every row that was already there exactly as it was. */
    public function testCreateLeavesEveryExistingRowUntouched(): void
    {
        $before = $this->structureRows(RomanMissal::USA_EDITION_2011);

        $this->write('PUT', RomanMissal::USA_EDITION_2011, 'StTestAmerican', [
            'month'         => 7,
            'day'           => 22,
            'grade'         => 3,
            'grade_display' => null,
            'common'        => ['Proper'],
            'calendar'      => 'US',
            'color'         => ['white'],
        ]);

        $after = $this->structureRows(RomanMissal::USA_EDITION_2011);
        self::assertCount(count($before) + 1, $after);
        self::assertSame($before, array_slice($after, 0, count($before)));
    }
}
