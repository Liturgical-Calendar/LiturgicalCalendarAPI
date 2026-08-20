<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests;

use LiturgicalCalendar\Api\Health;
use LiturgicalCalendar\Tests\Support\HealthQueueIsolationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ratchet\ConnectionInterface;

/**
 * `Health::handleValidationDataError()` — the three frames an unreadable source emits.
 *
 * These three texts are long, and #806 moved every one of them from an inline `$message->text`
 * assignment into a `sendStepResult()` call. Nothing in CI reached them at the time: the arm runs
 * only when the read *rejects*, and `react/filesystem`'s Fallback adapter resolves a missing file
 * with zero bytes instead, so every message-driven path landed in `processValidationData()` and
 * reported the file as existing. Identity was originally established by a throwaway driver diffing
 * the frames against the pre-change revision — good evidence, and evidence that stopped existing
 * the moment the driver was deleted. This is that check, kept.
 *
 * #822 has since made the arm reachable from a message: the read stats before it reads, so an absent
 * file rejects and lands here. That is asserted end-to-end in {@see HealthSourceFileFailureArmsTest},
 * which is a different question — *which* emitter a request reaches. This file still owns *what* this
 * emitter says, driving it directly, because a routing change must not be able to silently take the
 * texts with it.
 *
 * The expectations are literals, character for character. A paraphrase of any of the three — even
 * one that reads better — is a change to what a shipped client displays, and must fail here.
 */
#[CoversClass(Health::class)]
final class HealthValidationDataErrorTest extends TestCase
{
    use HealthQueueIsolationTrait;

    /**
     * A minimal Ratchet connection that records every outbound frame. `resourceId` is a dynamic
     * public property Ratchet assigns and is not part of `ConnectionInterface`, so this mirrors the
     * stub convention used across the Health suite rather than a PHPUnit mock, which would trigger
     * a dynamic-property deprecation.
     */
    private static function stubConnection(int $resourceId = 1)
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
     * Drive the handler directly. It is unreachable from a message — that is the whole reason this
     * test exists — and it diagnoses to stdout, which is buffered away so the suite's output stays
     * about failures.
     *
     * @return list<\stdClass> the decoded frames
     */
    private function frames(?\stdClass $target = null): array
    {
        $conn = self::stubConnection(9);

        $validation = (object) [
            'action'     => 'executeValidation',
            'category'   => 'sourceDataCheck',
            'validate'   => 'proprium-de-tempore',
            'sourceFile' => 'jsondata/x.json'
        ];

        ob_start();
        ( new \ReflectionMethod(Health::class, 'handleValidationDataError') )->invokeArgs(
            $this->newHealth(),
            [new \RuntimeException('boom'), $conn, $validation, 'jsondata/x.json', 'run-token-9', $target]
        );
        ob_end_clean();

        return array_map(static fn (string $raw): \stdClass => json_decode($raw), $conn->sent);
    }

    /**
     * All three steps, not just the one that actually failed: a client sizes the phase as three
     * frames per check, so reporting the unreadable file once would leave the phase short — the
     * same wedge that UnitTestInterface#43 describes from the other direction.
     */
    public function testAnUnreadableSourceFailsAllThreeStepsWithTheirExactTexts(): void
    {
        $frames = $this->frames();

        self::assertCount(3, $frames, 'an unreadable source must report every step');

        self::assertSame('error', $frames[0]->type);
        self::assertSame('Data file jsondata/x.json is not readable: boom', $frames[0]->text);
        self::assertSame('.proprium-de-tempore.file-exists', $frames[0]->classes);

        self::assertSame('error', $frames[1]->type);
        self::assertSame('Could not decode the Data file jsondata/x.json as JSON because it is not readable', $frames[1]->text);
        self::assertSame('.proprium-de-tempore.json-valid', $frames[1]->classes);

        self::assertSame('error', $frames[2]->type);
        self::assertSame(
            'Unable to verify schema for dataPath jsondata/x.json and category sourceDataCheck since Data file jsondata/x.json does not exist or is not readable',
            $frames[2]->text
        );
        self::assertSame('.proprium-de-tempore.schema-valid', $frames[2]->classes);
    }

    /**
     * The structured half of the same three frames, so a v2 client is served by this arm too.
     */
    public function testTheThreeFramesCarryTheStructuredFieldsAndTheRunToken(): void
    {
        $frames = $this->frames((object) ['id' => 'temporale:roman']);

        self::assertSame(['exists', 'parses', 'validates'], array_map(static fn (\stdClass $f): mixed => $f->step, $frames));
        foreach ($frames as $frame) {
            self::assertSame('fail', $frame->status);
            self::assertEquals((object) ['id' => 'temporale:roman'], $frame->target, 'the target is an object naming what was checked, not a bare id');
            self::assertSame('run-token-9', $frame->runToken, 'the answers must carry the token the client correlates them by');
            self::assertObjectNotHasProperty('details', $frame, 'nothing structured is known here, so nothing is manufactured');
        }
    }

    /**
     * A v1 `executeValidation` message names no id, and none is fabricated from the class fragment:
     * the derivation only runs id → fragment.
     */
    public function testAV1MessageGetsANullTargetRatherThanAReconstructedOne(): void
    {
        foreach ($this->frames() as $frame) {
            self::assertNull($frame->target);
        }
    }
}
