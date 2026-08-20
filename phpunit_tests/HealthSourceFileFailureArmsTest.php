<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests;

use LiturgicalCalendar\Api\Health;
use LiturgicalCalendar\Api\Models\ValidationsPath\CheckableInventory;
use LiturgicalCalendar\Api\Models\ValidationsPath\CheckableItem;
use LiturgicalCalendar\Api\Router;
use LiturgicalCalendar\Tests\Support\HealthQueueIsolationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ratchet\ConnectionInterface;

/**
 * The ways a source-file check used to say something untrue about what it found.
 *
 * These arms share one read path and one failure mode of the same kind — a check reporting an answer
 * that is not the answer — which is why they are pinned together:
 *
 * - **#822.** A file that is *not there* was reported `file-exists = success`. `react/filesystem`'s
 *   Fallback adapter — always the one in play, since neither `ext-eio` nor `ext-uv` is a dependency
 *   of this project — resolves a missing file with zero bytes instead of rejecting, so the read's
 *   rejection handler, and therefore {@see Health::handleValidationDataError()}, was unreachable
 *   from any message. The success path ran over an empty string and the two steps after `exists`
 *   failed for a reason that was not the real one. A green box above two red ones reads as "the file
 *   is there but its contents are broken", which sends whoever is debugging to the wrong file.
 * The sibling arm, #821 — a file that *is* there but does not decode emitting two frames where every
 * other arm emits three — is fixed separately and pinned here too.
 *
 * The arms are driven **through a message**, not by invoking the private emitters. That is the
 * whole point of these two: the emitters were already correct and already tested — what was wrong
 * was which of them a real request reached. A test that called the right one directly would have
 * passed against both bugs. {@see HealthValidationDataErrorTest} covers the emitter itself, and
 * says so.
 *
 * The frame count is asserted against the inventory's own published `steps` rather than a literal
 * `3`, because that array *is* the contract — it is what a client sizes the phase with, and the
 * number the emission has to agree with. The two sides are independent: `steps` is a static list on
 * {@see CheckableInventory}, the frames are composed in {@see Health}.
 */
#[CoversClass(Health::class)]
final class HealthSourceFileFailureArmsTest extends TestCase
{
    use HealthQueueIsolationTrait;

    /**
     * A path under the project root that is deliberately not a file.
     *
     * Relative, so `runValidationSteps()` resolves it against `Router::$apiFilePath` the way a real
     * source path is resolved — the absolute-path branch is a different one, and #822 was reported
     * against ordinary source data.
     */
    private const ABSENT_SOURCE = 'jsondata/sourcedata/this-source-file-does-not-exist.json';

    public static function setUpBeforeClass(): void
    {
        // The inventory builds every path from Router::$apiFilePath, and the published `steps` this
        // class asserts against are read out of it. Drop whatever an earlier class in this process
        // left memoized.
        Router::getApiPaths();
        CheckableInventory::reset();
    }

    public static function tearDownAfterClass(): void
    {
        CheckableInventory::reset();
    }

    /**
     * A minimal Ratchet connection that records every outbound frame. `resourceId` is a dynamic
     * public property Ratchet assigns and is not part of `ConnectionInterface`, so this mirrors the
     * stub convention used across the Health suite rather than a PHPUnit mock, which would trigger a
     * dynamic-property deprecation.
     */
    private static function createStubConnection(int $resourceId = 1)
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
     * Run one v1 `executeValidation` against a client-supplied `sourceFile` and return the frames.
     *
     * The v1 shape is used because it is the one that takes a path: a v2 `validateSource` names an
     * inventory id, and every id in the inventory points at a file that is really there — reaching
     * these two arms from v2 would mean building a poisoned source tree to make a published id
     * unreadable. The code under test is the same either way: both actions converge on
     * `runValidationSteps()`'s file branch, and the read path below it is shared.
     *
     * @return list<\stdClass>
     */
    private function framesForSourceFile(string $sourceFile): array
    {
        $conn   = self::createStubConnection();
        $health = $this->newHealth();

        // Health narrates each read to stdout; the suite's output stays about failures.
        ob_start();
        $health->onMessage($conn, (string) json_encode([
            'action'     => 'executeValidation',
            'category'   => 'sourceDataCheck',
            'validate'   => 'proprium-de-tempore',
            'sourceFile' => $sourceFile
        ]));
        ob_end_clean();

        return array_map(static fn (string $raw): \stdClass => json_decode($raw), $conn->sent);
    }

    /**
     * How many frames a source-file check has published that it will emit.
     *
     * Read from a real inventory item rather than written as `3`, so that publishing a fourth step
     * without emitting it fails here instead of shipping.
     */
    private static function publishedStepCount(): int
    {
        $item = CheckableInventory::byId('temporale:roman');
        self::assertInstanceOf(CheckableItem::class, $item, 'the inventory has no temporale:roman entry to read steps from');
        self::assertNotEmpty($item->steps, 'a source-file check that publishes no steps cannot be sized by any client');

        return count($item->steps);
    }

    // ---------------------------------------------------------------- #822: the file is not there

    /**
     * The step named after detecting absence must be the one that detects it.
     *
     * This is the assertion the bug could not survive and the suite did not have: before the fix
     * `exists` came back `success` for a file that was never on disk.
     */
    public function testAMissingSourceFileFailsTheExistsStepInsteadOfReportingItPresent(): void
    {
        $frames = $this->framesForSourceFile(self::ABSENT_SOURCE);

        self::assertNotEmpty($frames, 'a missing source file produced no answer at all');
        self::assertSame('.proprium-de-tempore.file-exists', $frames[0]->classes, 'precondition: the first frame is the existence step');
        self::assertSame('error', $frames[0]->type, 'a file that is not on disk must not be reported as existing');
        self::assertSame('fail', $frames[0]->status);
        self::assertStringContainsString(
            self::ABSENT_SOURCE,
            (string) $frames[0]->text,
            'the failure has to name the path it looked for, or it cannot be acted on'
        );
    }

    /**
     * An absent file fails every step, not only the first — the same one-frame-per-published-step
     * contract as the decode arm, from the other side.
     */
    public function testAMissingSourceFileReportsEveryPublishedStepAsFailed(): void
    {
        $frames = $this->framesForSourceFile(self::ABSENT_SOURCE);

        self::assertCount(
            self::publishedStepCount(),
            $frames,
            'a missing file emitted a different number of frames than a source check publishes steps'
        );
        self::assertSame(
            ['exists', 'parses', 'validates'],
            array_map(static fn (\stdClass $f): mixed => $f->step, $frames)
        );
        foreach ($frames as $frame) {
            self::assertSame('fail', $frame->status, "a file that could not be read cannot have passed a step: {$frame->classes}");
        }
    }

    /**
     * The absent-file arm now reaches {@see Health::handleValidationDataError()}, which is what
     * makes the fix a fix: the emitter was always right, and nothing routed to it.
     *
     * Pinned by the text that emitter alone produces. Asserting only "three failed frames" would
     * also pass if the decode arm had somehow handled it, which is a different bug with the same
     * shape of output.
     */
    public function testTheMissingFileArmIsRoutedThroughTheUnreadableHandler(): void
    {
        $frames = $this->framesForSourceFile(self::ABSENT_SOURCE);

        self::assertStringStartsWith(
            'Data file ' . self::ABSENT_SOURCE . ' is not readable:',
            (string) $frames[0]->text,
            'the missing file did not reach handleValidationDataError()'
        );
        self::assertStringContainsString(
            'no such file',
            (string) $frames[0]->text,
            'the diagnosis has to distinguish absence from an unreadable file that is there'
        );
    }
}
