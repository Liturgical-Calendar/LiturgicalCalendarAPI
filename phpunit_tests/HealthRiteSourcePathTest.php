<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests;

use LiturgicalCalendar\Api\Enum\JsonData;
use LiturgicalCalendar\Api\Enum\LitSchema;
use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Health;
use LiturgicalCalendar\Api\Models\Metadata\MetadataCalendars;
use LiturgicalCalendar\Api\Router;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ratchet\ConnectionInterface;

/**
 * Coverage for the rite partitioning of the diocesan source paths `executeValidation()` derives
 * (issue #812).
 *
 * The diocesan tier is the only one that exists under more than one rite: an Ambrosian diocese
 * keeps its calendar file and its i18n folder under `sourcedata/rite/ambrosian/...`, reached
 * through `JsonData::diocesanCalendarFileFor()` / `diocesanCalendarI18nFolderFor()`. `Health`
 * used the bare Roman constants, so validating any of the four Ambrosian dioceses looked for
 * data in a tree that does not contain it.
 *
 * Both branches are driven through `executeValidation()` rather than by re-deriving the path in
 * the test, because a test that built the path itself would pass whether or not the production
 * call site had been fixed. Neither branch needs an event loop: the file branch echoes the path
 * it is about to read before handing off to the (never-run) promise, and the folder branch
 * short-circuits synchronously when the folder is missing.
 */
#[CoversClass(Health::class)]
final class HealthRiteSourcePathTest extends TestCase
{
    protected function setUp(): void
    {
        Router::getApiPaths();
    }

    // ---------------------------------------------------------------- source file

    public function testAmbrosianDiocesanSourceFileResolvesUnderTheAmbrosianTree(): void
    {
        $expected = strtr(JsonData::AMBROSIAN_DIOCESAN_CALENDAR_FILE->path(), [
            '{nation}'       => 'IT',
            '{diocese}'      => 'milano_it',
            '{diocese_name}' => 'Arcidiocesi di Milano'
        ]);

        self::assertStringContainsString(
            'Reading data from file ' . $expected,
            self::captureSourceFileRead('diocesan-calendar-milano_it')
        );
    }

    public function testRomanDiocesanSourceFileStaysUnderTheRomanTree(): void
    {
        $expected = strtr(JsonData::DIOCESAN_CALENDAR_FILE->path(), [
            '{nation}'       => 'NL',
            '{diocese}'      => 'rotter_nl',
            '{diocese_name}' => 'Rotterdam'
        ]);

        $output = self::captureSourceFileRead('diocesan-calendar-rotter_nl');

        self::assertStringContainsString('Reading data from file ' . $expected, $output);
        self::assertStringNotContainsString(JsonData::AMBROSIAN_DIOCESAN_CALENDARS_FOLDER->value, $output);
    }

    /**
     * The server derives the path from the diocese id and its rite; whatever the client put in
     * `sourceFile` is not what gets read.
     */
    public function testTheClientSuppliedSourceFileIsNotWhatIsRead(): void
    {
        $output = self::captureSourceFileRead('diocesan-calendar-milano_it');

        self::assertStringNotContainsString('client/supplied/nonsense.json', $output);
    }

    // ------------------------------------------------- slugs the trailing block cannot match

    /**
     * The `sourceFile` branch's own slug pattern was `([A-Z][a-z]+)`, which matches `Europe` but
     * neither `IT` nor `milano_it` — so its national and diocesan arms never ran and `$dataPath`
     * kept whatever the client sent. For the *canonical* id shapes that went unnoticed, because
     * the block at the tail of `executeValidation()` re-derives the path for `[A-Z]{2}` nations
     * and `[a-z]{6}_[a-z]{2}` dioceses anyway.
     *
     * These two exercise ids that the trailing block's stricter patterns cannot match, which is
     * the only window in which the widened pattern is observable. They also show why the widened
     * pattern and the rite-aware diocesan constant had to land together: the moment the pattern
     * admits a diocesan id, that arm starts executing, and with the bare Roman constant it would
     * have sent every Ambrosian diocese to a Roman path.
     */
    public function testANationalSlugTheTrailingBlockCannotMatchIsDerivedServerSide(): void
    {
        $expected = strtr(JsonData::NATIONAL_CALENDAR_FILE->path(), ['{nation}' => 'USA']);

        $output = self::captureSourceFileRead('national-calendar-USA');

        self::assertStringContainsString('Reading data from file ' . $expected, $output);
        self::assertStringNotContainsString('client/supplied/nonsense.json', $output);
    }

    public function testADiocesanSlugTheTrailingBlockCannotMatchKeepsItsRite(): void
    {
        $ambrosian = strtr(JsonData::AMBROSIAN_DIOCESAN_CALENDAR_FILE->path(), [
            '{nation}'       => 'ZZ',
            '{diocese}'      => 'nowhere_zz',
            '{diocese_name}' => 'Nowhere'
        ]);
        $roman     = strtr(JsonData::DIOCESAN_CALENDAR_FILE->path(), [
            '{nation}'       => 'ZZ',
            '{diocese}'      => 'nowhere_zz',
            '{diocese_name}' => 'Nowhere'
        ]);

        $output = self::captureSourceFileRead(
            'diocesan-calendar-nowhere_zz',
            [self::diocese('nowhere_zz', 'Nowhere', 'ZZ', Rite::AMBROSIAN)]
        );

        self::assertStringContainsString('Reading data from file ' . $ambrosian, $output);
        self::assertStringNotContainsString($roman, $output);
    }

    // ------------------------------------------------- the two slug grammars must agree

    /**
     * One identifier, described twice in one file: `retrieveSchemaForCategory()` matches the
     * `validate` slug to pick a schema, and `executeValidation()` matches the same slug to derive
     * the source path. They have to agree, and nothing but this test makes them.
     *
     * Widening `executeValidation()`'s pattern to `[A-Za-z_]+` left the schema side on
     * `[A-Z]{2}` / `[a-z]{6}_[a-z]{2}`, so an id outside the conventional shapes would have had
     * its path derived and its schema resolve to null — the "Unable to detect schema" failure
     * again, from the other direction. Every id in jsondata today satisfies both forms, so the
     * canonical rows below pass either way; the off-convention rows are what pins the grammars
     * together.
     *
     * @return array<string, array{0: string, 1: LitSchema, 2: list<\stdClass>|null}>
     */
    public static function slugGrammarProvider(): array
    {
        return [
            'national, canonical'      => ['national-calendar-IT', LitSchema::NATIONAL, null],
            'national, off-convention' => ['national-calendar-USA', LitSchema::NATIONAL, null],
            'diocesan, canonical'      => ['diocesan-calendar-milano_it', LitSchema::DIOCESAN, null],
            'diocesan, off-convention' => [
                'diocesan-calendar-nowhere_zz',
                LitSchema::DIOCESAN,
                [self::diocese('nowhere_zz', 'Nowhere', 'ZZ', Rite::AMBROSIAN)]
            ],
        ];
    }

    /**
     * @param list<\stdClass>|null $dioceses
     */
    #[DataProvider('slugGrammarProvider')]
    public function testTheSchemaAndPathGrammarsAcceptTheSameSlug(string $slug, LitSchema $expected, ?array $dioceses): void
    {
        $method = new \ReflectionMethod(Health::class, 'retrieveSchemaForCategory');
        self::assertSame(
            $expected->path(),
            $method->invoke(null, 'sourceDataCheck', $slug),
            "the schema side must accept the slug the path side accepts: $slug"
        );

        // Reaching a derived path at all is the proof that `executeValidation()`'s own pattern
        // matched: had it not, $dataPath would still be the client's `sourceFile`.
        $output = self::captureSourceFileRead($slug, $dioceses);
        self::assertStringContainsString('Reading data from file ', $output);
        self::assertStringNotContainsString(
            'client/supplied/nonsense.json',
            $output,
            "the path side must accept the slug the schema side accepts: $slug"
        );
    }

    // ---------------------------------------------------------------- i18n folder

    /**
     * The direct regression guard, on the real fixture: the Ambrosian i18n folder for `milano_it`
     * exists and its files validate, so a correctly derived path yields three *success* frames.
     * With the Roman constant the derived folder does not exist and the missing-folder short
     * circuit fired three error frames instead.
     */
    public function testAmbrosianDiocesanI18nFolderIsFoundAndValidatedOnDisk(): void
    {
        $conn = self::runI18nFolderCheck([self::diocese('milano_it', 'Arcidiocesi di Milano', 'IT', Rite::AMBROSIAN)], 'milano_it');

        self::assertCount(3, $conn->sent);
        foreach (self::decode($conn) as $frame) {
            self::assertSame('success', $frame->type, 'the Ambrosian i18n folder exists; the check must not report it missing');
        }
    }

    /**
     * The same guard from the Roman side, so the rite branch cannot be "fixed" by pointing
     * everything at the Ambrosian tree.
     */
    public function testRomanDiocesanI18nFolderIsFoundAndValidatedOnDisk(): void
    {
        $conn = self::runI18nFolderCheck([self::diocese('rotter_nl', 'Rotterdam', 'NL', Rite::ROMAN)], 'rotter_nl');

        self::assertCount(3, $conn->sent);
        foreach (self::decode($conn) as $frame) {
            self::assertSame('success', $frame->type);
        }
    }

    /**
     * The exact-path assertion. A diocese that is absent from *both* trees takes the
     * missing-folder short circuit, whose message quotes the path that was derived — the only
     * synchronous window onto it.
     */
    public function testAnAmbrosianDioceseDerivesItsI18nFolderFromTheAmbrosianTemplate(): void
    {
        $expectedAbsolute = strtr(JsonData::AMBROSIAN_DIOCESAN_CALENDAR_I18N_FOLDER->path(), [
            '{nation}'  => 'ZZ',
            '{diocese}' => 'nowher_zz'
        ]);
        // #827: the frame quotes the derived folder project-relative, never with the server's
        // absolute filesystem root.
        $expected = substr($expectedAbsolute, strlen(Router::$apiFilePath));

        $conn = self::runI18nFolderCheck([self::diocese('nowher_zz', 'Nowhere', 'ZZ', Rite::AMBROSIAN)], 'nowher_zz');

        self::assertCount(3, $conn->sent);
        foreach (self::decode($conn) as $frame) {
            self::assertStringContainsString($expected, (string) $frame->text);
            self::assertStringNotContainsString(rtrim(Router::$apiFilePath, '/'), (string) $frame->text);
        }
    }

    public function testARomanDioceseDerivesItsI18nFolderFromTheRomanTemplate(): void
    {
        $expectedAbsolute = strtr(JsonData::DIOCESAN_CALENDAR_I18N_FOLDER->path(), [
            '{nation}'  => 'ZZ',
            '{diocese}' => 'nowher_zz'
        ]);
        // #827: the frame quotes the derived folder project-relative, never with the server's
        // absolute filesystem root.
        $expected = substr($expectedAbsolute, strlen(Router::$apiFilePath));

        $conn = self::runI18nFolderCheck([self::diocese('nowher_zz', 'Nowhere', 'ZZ', Rite::ROMAN)], 'nowher_zz');

        self::assertCount(3, $conn->sent);
        foreach (self::decode($conn) as $frame) {
            self::assertStringContainsString($expected, (string) $frame->text);
            self::assertStringNotContainsString(JsonData::AMBROSIAN_DIOCESAN_CALENDARS_FOLDER->value, (string) $frame->text);
            self::assertStringNotContainsString(rtrim(Router::$apiFilePath, '/'), (string) $frame->text);
        }
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Run a `sourceFile` check and return everything `executeValidation()` echoed. The path is
     * echoed before the read is handed off, so this needs neither the file to exist nor the
     * validation to succeed.
     *
     * @param list<\stdClass>|null $dioceses metadata stand-in, or null for the two real fixtures
     */
    private static function captureSourceFileRead(string $validateSlug, ?array $dioceses = null): string
    {
        $conn   = self::createStubConnection(1);
        $health = self::healthWithRunToken($conn);

        // Several of these slugs name files that deliberately do not exist — that is the point of
        // them. Since #822 the read stats before it reads, so absence no longer reaches
        // react/filesystem's fallback adapter and the PHP warnings it emitted unconditionally are
        // gone; the suppressor is kept because what is under assertion here is the path Health
        // echoed, not whether the read succeeded, and a warning from some other cause would be an
        // equally irrelevant failure.
        set_error_handler(static fn(): bool => true);

        ob_start();
        try {
            self::withMetadata(
                $dioceses ?? [
                    self::diocese('milano_it', 'Arcidiocesi di Milano', 'IT', Rite::AMBROSIAN),
                    self::diocese('rotter_nl', 'Rotterdam', 'NL', Rite::ROMAN),
                ],
                static function () use ($health, $conn, $validateSlug): void {
                    $method = new \ReflectionMethod(Health::class, 'executeValidation');
                    $method->invoke($health, (object) [
                        'action'     => 'executeValidation',
                        'category'   => 'sourceDataCheck',
                        'validate'   => $validateSlug,
                        'sourceFile' => 'client/supplied/nonsense.json',
                    ], $conn);
                }
            );
        } finally {
            $output = (string) ob_get_clean();
            restore_error_handler();
        }

        return $output;
    }

    /**
     * @param list<\stdClass> $dioceses
     */
    private static function runI18nFolderCheck(array $dioceses, string $dioceseId): ConnectionInterface
    {
        $conn   = self::createStubConnection(2);
        $health = self::healthWithRunToken($conn);

        ob_start();
        try {
            self::withMetadata($dioceses, static function () use ($health, $conn, $dioceseId): void {
                $method = new \ReflectionMethod(Health::class, 'executeValidation');
                $method->invoke($health, (object) [
                    'action'       => 'executeValidation',
                    'category'     => 'sourceDataCheck',
                    'validate'     => "diocesan-calendar-$dioceseId-i18n",
                    'sourceFolder' => 'client/supplied/nonsense',
                ], $conn);
            });
        } finally {
            ob_end_clean();
        }

        return $conn;
    }

    /**
     * @return list<\stdClass>
     */
    private static function decode(ConnectionInterface $conn): array
    {
        /** @var object{sent: list<string>} $conn */
        return array_map(static fn(string $raw): \stdClass => json_decode($raw), $conn->sent);
    }

    private static function healthWithRunToken(ConnectionInterface $conn): Health
    {
        $health = new Health();
        /** @var object{resourceId: int} $conn */
        ( new \ReflectionProperty(Health::class, 'runTokens') )->setValue($health, [$conn->resourceId => 'run-token-1']);

        return $health;
    }

    /**
     * A minimal Ratchet connection recording every outbound frame, following the stub convention
     * of HealthFolderStepResultTest rather than a PHPUnit mock (which would trip a
     * dynamic-property deprecation on `resourceId`).
     */
    private static function createStubConnection(int $resourceId): ConnectionInterface
    {
        return new class ($resourceId) implements ConnectionInterface {
            /** @var list<string> */
            public array $sent = [];

            public function __construct(public int $resourceId)
            {
            }

            public function send($data)
            {
                $this->sent[] = (string) $data;

                return $this;
            }

            public function close()
            {
            }
        };
    }

    private static function diocese(string $calendarId, string $name, string $nation, Rite $rite): \stdClass
    {
        return (object) [
            'calendar_id' => $calendarId,
            'diocese'     => $name,
            'nation'      => $nation,
            'locales'     => ['it_IT'],
            'timezone'    => 'Europe/Rome',
            'rite'        => $rite->value,
        ];
    }

    /**
     * Populate `Health::$metadata` for the duration of $fn, then restore. The property is a typed
     * static with no default, so an initially-uninitialised one is left holding an *empty*
     * MetadataCalendars rather than this fixture — see HealthRiteRequestPathTest, which uses the
     * same approach for the same reason.
     *
     * @param list<\stdClass> $dioceses
     */
    private static function withMetadata(array $dioceses, callable $fn): void
    {
        $property = new \ReflectionProperty(Health::class, 'metadata');
        $wasSet   = $property->isInitialized();
        $previous = $wasSet ? $property->getValue() : null;

        $property->setValue(null, self::metadata($dioceses));

        try {
            $fn();
        } finally {
            if ($wasSet && $previous instanceof MetadataCalendars) {
                $property->setValue(null, $previous);
            } else {
                $property->setValue(null, self::metadata([]));
            }
        }
    }

    /**
     * @param list<\stdClass> $dioceses
     */
    private static function metadata(array $dioceses): MetadataCalendars
    {
        return MetadataCalendars::fromObject((object) [
            'national_calendars'       => [],
            'national_calendars_keys'  => [],
            'diocesan_calendars'       => $dioceses,
            'diocesan_calendars_keys'  => array_map(static fn(\stdClass $d): string => (string) $d->calendar_id, $dioceses),
            'diocesan_groups'          => [],
            'wider_regions'            => [],
            'wider_regions_keys'       => [],
            'ambrosian_calendars'      => [],
            'ambrosian_calendars_keys' => [],
            'locales'                  => ['en'],
        ]);
    }
}
