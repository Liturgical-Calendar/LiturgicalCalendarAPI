<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests;

use LiturgicalCalendar\Api\Enum\JsonData;
use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Health;
use LiturgicalCalendar\Api\Models\Metadata\MetadataCalendars;
use LiturgicalCalendar\Api\Router;
use PHPUnit\Framework\Attributes\CoversClass;
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
            self::captureSourceFileRead('milano_it')
        );
    }

    public function testRomanDiocesanSourceFileStaysUnderTheRomanTree(): void
    {
        $expected = strtr(JsonData::DIOCESAN_CALENDAR_FILE->path(), [
            '{nation}'       => 'NL',
            '{diocese}'      => 'rotter_nl',
            '{diocese_name}' => 'Rotterdam'
        ]);

        $output = self::captureSourceFileRead('rotter_nl');

        self::assertStringContainsString('Reading data from file ' . $expected, $output);
        self::assertStringNotContainsString(JsonData::AMBROSIAN_DIOCESAN_CALENDARS_FOLDER->value, $output);
    }

    /**
     * The server derives the path from the diocese id and its rite; whatever the client put in
     * `sourceFile` is not what gets read.
     */
    public function testTheClientSuppliedSourceFileIsNotWhatIsRead(): void
    {
        $output = self::captureSourceFileRead('milano_it');

        self::assertStringNotContainsString('client/supplied/nonsense.json', $output);
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
        $expected = strtr(JsonData::AMBROSIAN_DIOCESAN_CALENDAR_I18N_FOLDER->path(), [
            '{nation}'  => 'ZZ',
            '{diocese}' => 'nowher_zz'
        ]);

        $conn = self::runI18nFolderCheck([self::diocese('nowher_zz', 'Nowhere', 'ZZ', Rite::AMBROSIAN)], 'nowher_zz');

        self::assertCount(3, $conn->sent);
        foreach (self::decode($conn) as $frame) {
            self::assertStringContainsString($expected, (string) $frame->text);
        }
    }

    public function testARomanDioceseDerivesItsI18nFolderFromTheRomanTemplate(): void
    {
        $expected = strtr(JsonData::DIOCESAN_CALENDAR_I18N_FOLDER->path(), [
            '{nation}'  => 'ZZ',
            '{diocese}' => 'nowher_zz'
        ]);

        $conn = self::runI18nFolderCheck([self::diocese('nowher_zz', 'Nowhere', 'ZZ', Rite::ROMAN)], 'nowher_zz');

        self::assertCount(3, $conn->sent);
        foreach (self::decode($conn) as $frame) {
            self::assertStringContainsString($expected, (string) $frame->text);
            self::assertStringNotContainsString(JsonData::AMBROSIAN_DIOCESAN_CALENDARS_FOLDER->value, (string) $frame->text);
        }
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Run a diocesan `sourceFile` check and return everything `executeValidation()` echoed. The
     * path is echoed before the read is handed to a promise that is never resolved, so this
     * needs neither the event loop nor the file to exist.
     */
    private static function captureSourceFileRead(string $dioceseId): string
    {
        $conn   = self::createStubConnection(1);
        $health = self::healthWithRunToken($conn);

        ob_start();
        try {
            self::withMetadata(
                [
                    self::diocese('milano_it', 'Arcidiocesi di Milano', 'IT', Rite::AMBROSIAN),
                    self::diocese('rotter_nl', 'Rotterdam', 'NL', Rite::ROMAN),
                ],
                static function () use ($health, $conn, $dioceseId): void {
                    $method = new \ReflectionMethod(Health::class, 'executeValidation');
                    $method->invoke($health, (object) [
                        'action'     => 'executeValidation',
                        'category'   => 'sourceDataCheck',
                        'validate'   => "diocesan-calendar-$dioceseId",
                        'sourceFile' => 'client/supplied/nonsense.json',
                    ], $conn);
                }
            );
        } finally {
            $output = (string) ob_get_clean();
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
