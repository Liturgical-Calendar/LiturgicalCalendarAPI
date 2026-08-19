<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests;

use LiturgicalCalendar\Api\Health;
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
            new Health(),
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
}
