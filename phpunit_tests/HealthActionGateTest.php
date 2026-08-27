<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests;

use LiturgicalCalendar\Api\Enum\ProtocolErrorCode;
use LiturgicalCalendar\Api\Health;
use LiturgicalCalendar\Api\Models\Auth\TestTarget;
use LiturgicalCalendar\Api\Models\Auth\WsCaller;
use LiturgicalCalendar\Api\Models\ValidationsPath\CheckableInventory;
use LiturgicalCalendar\Api\Services\TestRunPolicy;
use LiturgicalCalendar\Tests\Support\HealthQueueIsolationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ratchet\ConnectionInterface;

/**
 * The action gate — #894.
 *
 * The gate is asked in two places and the split is the design, not an accident:
 *
 * **Early**, beside `$protocolViolation`, comes the coarse question. It depends only on the caller
 * and not on the message, so it can be settled before the message is understood — which it has to be,
 * because the run-token block below it installs a token and can rebuild the checkable inventory, and
 * a caller about to be refused must be able to do neither.
 * {@see testARefusedMessageDoesNotRebuildTheInventory()} is what proves that placement is
 * load-bearing rather than decorative; move the check below that block and it is the test that fails.
 *
 * **Late**, after schema validation, comes the target-scoped question. A target read from an
 * unvalidated message is a guess, and a permission decision must not rest on one. The coarse policy
 * answers it the same way it answered above, so
 * {@see testTheTargetScopedGateCanRefuseAMessageTheCoarseGateAllowed()} drives it with a stub policy
 * — otherwise the seam would be unexercised code, which is the same thing as absent code.
 */
#[CoversClass(Health::class)]
final class HealthActionGateTest extends TestCase
{
    use HealthQueueIsolationTrait;

    private ?string $appEnvBackup = null;

    protected function setUp(): void
    {
        parent::setUp();
        // Keeps handleHttpResponse() out of its development-only debug-logging branch, as
        // HealthFulfilHandlerThrowTest does.
        $this->appEnvBackup = isset($_ENV['APP_ENV']) && is_string($_ENV['APP_ENV']) ? $_ENV['APP_ENV'] : null;
        $_ENV['APP_ENV']    = 'test';
    }

    protected function tearDown(): void
    {
        if (null === $this->appEnvBackup) {
            unset($_ENV['APP_ENV']);
        } else {
            $_ENV['APP_ENV'] = $this->appEnvBackup;
        }
        parent::tearDown();
    }

    /**
     * A Ratchet connection that records what it was sent. `resourceId` is a dynamic public property
     * Ratchet assigns and is not part of `ConnectionInterface`, so this is a stub rather than a mock.
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

    /**
     * A Health with the identity a handshake would have settled, without running the handshake.
     *
     * @return array{0: Health, 1: ConnectionInterface}
     */
    private function serverFor(WsCaller $caller, ?TestRunPolicy $policy = null): array
    {
        $health = $this->newHealth(null, $policy);
        $conn   = self::createStubConnection(1);

        ( new \ReflectionProperty(Health::class, 'callers') )->setValue($health, [1 => $caller]);

        return [$health, $conn];
    }

    private function validateCalendarMessage(string $requestId = 'req-1'): string
    {
        return json_encode([
            'action'         => 'validateCalendar',
            'calendar'       => ['kind' => 'rite', 'rite' => 'roman'],
            'year'           => 2024,
            'responseFormat' => 'JSON',
            'requestId'      => $requestId,
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * @return list<\stdClass>
     */
    private function refusals(ConnectionInterface $conn): array
    {
        /** @var object{sent: list<string>} $conn */
        $frames = [];
        foreach ($conn->sent as $raw) {
            $frame = json_decode($raw);
            if ($frame instanceof \stdClass && 'protocolError' === ( $frame->type ?? null )) {
                $frames[] = $frame;
            }
        }

        return $frames;
    }

    private function deliver(Health $health, ConnectionInterface $conn, string $message): void
    {
        ob_start();
        $health->onMessage($conn, $message);
        ob_end_clean();
    }

    public function testAnonymousCallerIsRefusedWithNotAuthenticated(): void
    {
        [$health, $conn] = $this->serverFor(WsCaller::anonymous());

        $this->deliver($health, $conn, $this->validateCalendarMessage());

        $refusals = $this->refusals($conn);
        $this->assertCount(1, $refusals);
        $this->assertSame(ProtocolErrorCode::NOT_AUTHENTICATED->value, $refusals[0]->errorCode);
        $this->assertSame('req-1', $refusals[0]->requestId ?? null, 'the refusal must be correlated to the request');
    }

    public function testAuthenticatedWithoutRoleIsRefusedWithInsufficientRole(): void
    {
        [$health, $conn] = $this->serverFor(WsCaller::authenticated('u', ['developer']));

        $this->deliver($health, $conn, $this->validateCalendarMessage());

        $refusals = $this->refusals($conn);
        $this->assertCount(1, $refusals);
        $this->assertSame(
            ProtocolErrorCode::INSUFFICIENT_ROLE->value,
            $refusals[0]->errorCode,
            'a logged-in caller must not be told to log in'
        );
    }

    public function testARefusedMessageDoesNotInstallItsRunToken(): void
    {
        [$health, $conn] = $this->serverFor(WsCaller::anonymous());

        $message           = json_decode($this->validateCalendarMessage());
        $message->runToken = 'run-1';
        $this->deliver($health, $conn, json_encode($message, JSON_THROW_ON_ERROR));

        $tokens = ( new \ReflectionProperty(Health::class, 'runTokens') )->getValue($health);
        $this->assertSame([], $tokens, 'an unauthorized message must not install a run token');
    }

    /**
     * The reason the coarse check sits above the run-token block rather than below it.
     */
    public function testARefusedMessageDoesNotRebuildTheInventory(): void
    {
        [$health, $conn] = $this->serverFor(WsCaller::anonymous());

        // Memoize the inventory. `reset()` nulls `$items`, so a non-null `$items` afterwards is
        // exactly the statement that no reset happened.
        CheckableInventory::byId('an-id-that-matches-nothing-but-builds-the-index');
        $items = new \ReflectionProperty(CheckableInventory::class, 'items');
        $this->assertNotNull($items->getValue(), 'precondition: the inventory is memoized');

        $message           = json_decode($this->validateCalendarMessage());
        $message->runToken = 'run-2';
        $this->deliver($health, $conn, json_encode($message, JSON_THROW_ON_ERROR));

        $this->assertNotNull(
            $items->getValue(),
            'a refused message must not reset the checkable inventory — the coarse check has to sit above the run-token block'
        );
    }

    public function testAPermittedCallerIsNotRefused(): void
    {
        [$health, $conn] = $this->serverFor(WsCaller::authenticated('u', ['admin']));

        $this->deliver($health, $conn, $this->validateCalendarMessage());

        $this->assertSame([], $this->refusals($conn));
    }

    public function testATestEditorIsNotRefused(): void
    {
        [$health, $conn] = $this->serverFor(WsCaller::authenticated('u', ['test_editor']));

        $this->deliver($health, $conn, $this->validateCalendarMessage());

        $this->assertSame([], $this->refusals($conn));
    }

    /**
     * Keeps the fine-grained seam alive. A policy that says yes to the caller and no to the target
     * must be able to stop the message, or the second call site is dead code.
     */
    public function testTheTargetScopedGateCanRefuseAMessageTheCoarseGateAllowed(): void
    {
        $policy = new class extends TestRunPolicy {
            public function mayRun(WsCaller $caller, ?TestTarget $target = null): bool
            {
                // Coarse question: yes. Target-scoped question: no.
                return null === $target;
            }
        };

        [$health, $conn] = $this->serverFor(WsCaller::authenticated('u', ['admin']), $policy);

        $this->deliver($health, $conn, $this->validateCalendarMessage());

        $refusals = $this->refusals($conn);
        $this->assertCount(1, $refusals);
        $this->assertSame(ProtocolErrorCode::INSUFFICIENT_ROLE->value, $refusals[0]->errorCode);
        $this->assertSame('req-1', $refusals[0]->requestId ?? null);
    }

    /**
     * A connection that never opened has no entry in the caller map. It must read as anonymous, not
     * as permitted — the whole change fails open otherwise.
     */
    public function testAConnectionWithNoRecordedCallerIsAnonymous(): void
    {
        $health = $this->newHealth();
        $conn   = self::createStubConnection(99);

        $this->deliver($health, $conn, $this->validateCalendarMessage());

        $refusals = $this->refusals($conn);
        $this->assertCount(1, $refusals);
        $this->assertSame(ProtocolErrorCode::NOT_AUTHENTICATED->value, $refusals[0]->errorCode);
    }
}
