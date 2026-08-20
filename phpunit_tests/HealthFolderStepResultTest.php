<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests;

use LiturgicalCalendar\Api\Enum\JsonData;
use LiturgicalCalendar\Api\Health;
use LiturgicalCalendar\Api\Router;
use LiturgicalCalendar\Tests\Support\HealthQueueIsolationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ratchet\ConnectionInterface;

/**
 * `Health::sendFolderStepResult()` — one frame per step of a `sourceFolder` check.
 *
 * A folder check is a statement about the folder, so each of its three steps must produce
 * exactly one frame whether it passes or fails. Previously a failure emitted one frame *per
 * failing file*, which made the frame count depend on how many files happened to be broken.
 *
 * That is unpredictable for any client: both UnitTestInterface runners size a phase as
 * `checks.length * 3` and complete it by counting frames, so a folder containing two bad files
 * over-delivered and inflated the counters — the recorded "success counter (162) past the
 * rendered-card total (159)" incident — and with a strict equality check would have hung the
 * phase outright (UnitTestInterface#43).
 */
#[CoversClass(Health::class)]
final class HealthFolderStepResultTest extends TestCase
{
    use HealthQueueIsolationTrait;

    /**
     * A minimal Ratchet connection that records every outbound frame. `resourceId` is a dynamic
     * public property Ratchet assigns and is not part of `ConnectionInterface`, so this mirrors
     * the stub convention already used by HealthHelpersTest rather than a PHPUnit mock, which
     * would trigger a dynamic-property deprecation.
     */
    private static function createStubConnection(int $resourceId)
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

    /**
     * @param list<string> $errors
     * @return list<\stdClass> the decoded frames the helper emitted
     */
    private function invoke(array $errors): array
    {
        $conn = self::createStubConnection(1);

        $method = new \ReflectionMethod(Health::class, 'sendFolderStepResult');
        $method->invoke(
            $this->newHealth(),
            $conn,
            'national-calendar-IT-i18n.json-valid',
            $errors,
            'all good',
            'not all good',
            'run-token-1'
        );

        return array_map(static fn(string $raw): \stdClass => json_decode($raw), $conn->sent);
    }

    public function testAPassingStepEmitsExactlyOneSuccessFrame(): void
    {
        $frames = $this->invoke([]);

        self::assertCount(1, $frames);
        self::assertSame('success', $frames[0]->type);
        self::assertSame('all good', $frames[0]->text);
        self::assertSame('.national-calendar-IT-i18n.json-valid', $frames[0]->classes);
    }

    public function testOneFailingFileEmitsExactlyOneErrorFrame(): void
    {
        $frames = $this->invoke(['it.json: Syntax error']);

        self::assertCount(1, $frames);
        self::assertSame('error', $frames[0]->type);
        self::assertStringContainsString('it.json: Syntax error', $frames[0]->text);
    }

    /**
     * The regression that motivated this: N broken files used to mean N frames.
     */
    public function testManyFailingFilesStillEmitExactlyOneErrorFrame(): void
    {
        $frames = $this->invoke(['it.json: Syntax error', 'en.json: Syntax error', 'fr.json: Control character error']);

        self::assertCount(1, $frames, 'a folder step must report once, however many files are broken');
        self::assertSame('error', $frames[0]->type);
    }

    public function testTheErrorFrameNamesEveryOffendingFile(): void
    {
        $frames = $this->invoke(['it.json: Syntax error', 'en.json: Syntax error']);

        // Aggregating must not lose the detail that the per-file frames used to carry.
        self::assertStringContainsString('it.json', $frames[0]->text);
        self::assertStringContainsString('en.json', $frames[0]->text);
        self::assertStringContainsString('2 problem(s)', $frames[0]->text);
    }

    public function testTheFrameCarriesTheRunTokenForCorrelation(): void
    {
        $frames = $this->invoke([]);

        self::assertSame('run-token-1', $frames[0]->runToken);
    }

    /**
     * The three-frames-per-check contract has to hold on the *short* paths too.
     *
     * A folder that does not exist used to emit a single `file-exists` error and return, which
     * under-delivers by two frames. That is the same wedge as over-delivering, approached from
     * the other side: a client counting toward `checks.length * 3` never reaches its target.
     */
    public function testAMissingFolderStillReportsAllThreeSteps(): void
    {
        // Driven through executeValidation() rather than the helper: the fix is in the `glob()`
        // guard, and a test that called sendFolderStepResult() three times itself would pass
        // whether or not that guard had been fixed. The path is synchronous — the guard returns
        // before any promise is created — so no event loop is needed.
        Router::getApiPaths();

        $conn   = self::createStubConnection(3);
        $health = $this->newHealth();

        $tokens = new \ReflectionProperty(Health::class, 'runTokens');
        $tokens->setValue($health, [$conn->resourceId => 'run-token-1']);

        $validate = 'tests-NoSuchTest-i18n';
        $method   = new \ReflectionMethod(Health::class, 'executeValidation');
        $method->invoke($health, (object) [
            'action'       => 'executeValidation',
            'category'     => 'sourceDataCheck',
            'validate'     => $validate,
            'sourceFolder' => 'jsondata/definitely-not-a-real-folder',
        ], $conn);

        $frames = array_map(static fn(string $raw): \stdClass => json_decode($raw), $conn->sent);

        self::assertCount(3, $frames, 'a missing folder must still report every step');
        self::assertSame(
            [".$validate.file-exists", ".$validate.json-valid", ".$validate.schema-valid"],
            array_map(static fn(\stdClass $f): string => $f->classes, $frames)
        );
        foreach ($frames as $frame) {
            self::assertSame('error', $frame->type);
            self::assertSame('run-token-1', $frame->runToken);
        }
    }

    /**
     * The frames quote the folder the *client* named, while the server reads the folder it
     * derived from the `validate` slug. The two are not the same string: for the reconstructed
     * i18n slugs a client sends a bare id and the server rebuilds the real path from it, which is
     * why `runValidationSteps()` takes the caller's folder string as a parameter instead of
     * quoting the path it reads.
     *
     * Nothing pinned this before, so a refactor could have silently swapped one for the other in
     * six message texts and every assertion would still have passed.
     */
    public function testTheFramesQuoteTheFolderTheClientNamedNotTheOneTheServerReads(): void
    {
        Router::getApiPaths();

        $derived = strtr(JsonData::NATIONAL_CALENDAR_I18N_FOLDER->path(), ['{nation}' => 'ZZ']);

        $conn   = self::createStubConnection(4);
        $health = $this->newHealth();

        $tokens = new \ReflectionProperty(Health::class, 'runTokens');
        $tokens->setValue($health, [$conn->resourceId => 'run-token-1']);

        $method = new \ReflectionMethod(Health::class, 'executeValidation');
        $method->invoke($health, (object) [
            'action'       => 'executeValidation',
            'category'     => 'sourceDataCheck',
            'validate'     => 'national-calendar-ZZ-i18n',
            'sourceFolder' => 'client/supplied/nonsense',
        ], $conn);

        $frames = array_map(static fn(string $raw): \stdClass => json_decode($raw), $conn->sent);

        self::assertCount(3, $frames);
        foreach ($frames as $frame) {
            self::assertStringContainsString(
                'Data folder client/supplied/nonsense could not be checked',
                (string) $frame->text,
                'the frames must quote the folder the client named'
            );
            self::assertStringContainsString(
                $derived,
                (string) $frame->text,
                'and must report on the folder the server derived from the slug and actually read'
            );
        }
    }
}
