<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests;

use LiturgicalCalendar\Api\Health;
use LiturgicalCalendar\Api\Models\ValidationsPath\CheckableInventory;
use LiturgicalCalendar\Api\Models\ValidationsPath\CheckableItem;
use LiturgicalCalendar\Api\Router;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ratchet\ConnectionInterface;

/**
 * `validateSource` — asking for a check by the id the server published.
 *
 * A client used to have to name a check with a hyphenated slug (`proprium-de-tempore`,
 * `national-calendar-IT`) that the server recovered with eight anchored `preg_match` arms, each
 * one a second copy of a naming convention written down elsewhere. `GET /validations` already
 * publishes a stable opaque id for every checkable artifact, so `validateSource` takes that id and
 * resolves it with a single {@see CheckableInventory::byId()} lookup.
 *
 * The legacy `executeValidation` shape is untouched and its arms stay reachable; this is purely
 * additive. See #806 section C.
 */
#[CoversClass(Health::class)]
final class HealthValidateSourceTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        // Health resolves relative source paths against Router::$apiFilePath, and the inventory
        // builds every path from it. Drop the memo so the index is built against these paths
        // rather than against whatever an earlier test class in this process left behind.
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
     * stub convention already used by HealthCancelRunTest and HealthFolderStepResultTest rather
     * than a PHPUnit mock, which would trigger a dynamic-property deprecation.
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
     * @param array<string, mixed> $payload
     */
    private static function send(Health $health, ConnectionInterface $conn, array $payload): void
    {
        $health->onMessage($conn, (string) json_encode($payload));
    }

    private static function retrieveSchemaForCategory(string $category, string $dataPath): ?string
    {
        $method = new \ReflectionMethod(Health::class, 'retrieveSchemaForCategory');
        /** @var string|null $result */
        $result = $method->invoke(null, $category, $dataPath);

        return $result;
    }

    // ---------------------------------------------------------------- the id IS the slug's address

    /**
     * The point of the action: `temporale:roman` names exactly what `proprium-de-tempore` named.
     *
     * @return array<string, array{string, string}>
     */
    public static function equivalentAddressProvider(): array
    {
        return [
            'temporale'         => ['temporale:roman', 'proprium-de-tempore'],
            'national calendar' => ['nation:roman:IT', 'national-calendar-IT']
        ];
    }

    /**
     * A `validateSource` naming an id runs the very same check the legacy slug ran.
     *
     * The schema is the load-bearing assertion, and it is taken from the *frame* rather than from
     * the inventory, so this compares two resolutions of the same artifact — the id's, through
     * `byId()`, and the slug's, through `retrieveSchemaForCategory()`'s anchored patterns — rather
     * than comparing one resolution with itself.
     *
     * The three frames matter too: a client sizes the phase as exactly three per check, so an
     * accepted-but-silent target would wedge the run just as surely as a rejected one.
     *
     * @param string $id   the inventory id a v2 client sends
     * @param string $slug the `validate` slug a v1 client sends for the same artifact
     */
    #[DataProvider('equivalentAddressProvider')]
    public function testAKnownIdRunsTheSameCheckAsItsLegacySlug(string $id, string $slug): void
    {
        $item = CheckableInventory::byId($id);
        self::assertInstanceOf(CheckableItem::class, $item, "the inventory has no entry for {$id}");

        $health = new Health();
        $conn   = self::createStubConnection();
        self::send($health, $conn, ['action' => 'validateSource', 'target' => ['id' => $id]]);

        self::assertCount(3, $conn->sent, "{$id} did not produce the three frames a check is made of");

        $frames = array_map(
            /** @return \stdClass */
            static fn (string $raw): object => (object) json_decode($raw),
            $conn->sent
        );

        self::assertSame(
            [".{$item->label}.file-exists", ".{$item->label}.json-valid", ".{$item->label}.schema-valid"],
            array_map(static fn (\stdClass $f): mixed => $f->classes, $frames),
            'the frames are addressed by the item label, in step order'
        );

        foreach ($frames as $frame) {
            self::assertSame('success', $frame->type, "{$id} did not pass its own check: {$frame->text}");
        }

        $legacySchema = self::retrieveSchemaForCategory('sourceDataCheck', $slug);
        self::assertIsString($legacySchema, "the legacy slug {$slug} resolves to no schema at all");
        self::assertStringContainsString(
            $legacySchema,
            (string) $frames[2]->text,
            "{$id} was validated against a different schema than {$slug} resolves to"
        );
    }

    // ---------------------------------------------------------------- rejections

    public function testAnUnknownIdIsRejectedAndNothingIsChecked(): void
    {
        $health = new Health();
        $conn   = self::createStubConnection();

        self::send($health, $conn, ['action' => 'validateSource', 'target' => ['id' => 'nation:roman:ZZ']]);

        self::assertCount(1, $conn->sent, 'an unresolvable target is answered once and not checked');
        $frame = json_decode($conn->sent[0]);
        self::assertSame('echobot', $frame->type, 'rejections reuse the echobot shape: since UnitTestInterface#46 an unknown type is painted as a failed check');
        self::assertSame('Unknown validation target: nation:roman:ZZ', $frame->text);
    }

    public function testATargetThatIsNotAnObjectIsRejectedAsMalformed(): void
    {
        $health = new Health();
        $conn   = self::createStubConnection();

        self::send($health, $conn, ['action' => 'validateSource', 'target' => 'temporale:roman']);

        self::assertCount(1, $conn->sent);
        $frame = json_decode($conn->sent[0]);
        self::assertSame('echobot', $frame->type);
        self::assertSame('validateSource requires a target object with an id.', $frame->text);
    }

    public function testATargetIdThatIsNotAStringIsRejected(): void
    {
        $health = new Health();
        $conn   = self::createStubConnection();

        self::send($health, $conn, ['action' => 'validateSource', 'target' => ['id' => 42]]);

        self::assertCount(1, $conn->sent);
        $frame = json_decode($conn->sent[0]);
        self::assertSame('echobot', $frame->type);
        self::assertSame('validateSource target id must be a string.', $frame->text);
    }

    /**
     * A missing `target` never reaches the handler: `ACTION_PROPERTIES` declares it required, so
     * `validateMessageProperties()` turns the message away on the generic protocol-error path.
     */
    public function testAMessageWithNoTargetIsRejectedByPropertyValidation(): void
    {
        $health = new Health();
        $conn   = self::createStubConnection();

        self::send($health, $conn, ['action' => 'validateSource']);

        self::assertCount(1, $conn->sent);
        $frame = json_decode($conn->sent[0]);
        self::assertSame('echobot', $frame->type);
        self::assertSame('Invalid message properties', $frame->errorMsg);
    }

    // ---------------------------------------------------------------- the per-run inventory reset

    /**
     * Whether the memoized index is currently populated.
     *
     * There is no accessor for this and there should not be — the memo is an implementation
     * detail. Where it is dropped is not: a v2 message resolves *solely* through `byId()`, so a
     * memo that outlives a `/data` write makes the new calendar unaddressable, and that is a
     * property of `onMessage()`, not of the inventory.
     */
    private static function inventoryIsMemoized(): bool
    {
        return null !== ( new \ReflectionProperty(CheckableInventory::class, 'items') )->getValue();
    }

    /** @param array<int, string> $tokens */
    private static function setRunTokens(Health $health, array $tokens): void
    {
        ( new \ReflectionProperty(Health::class, 'runTokens') )->setValue($health, $tokens);
    }

    /** @return array<int, string> */
    private static function getRunTokens(Health $health): array
    {
        /** @var array<int, string> */
        return ( new \ReflectionProperty(Health::class, 'runTokens') )->getValue($health);
    }

    /**
     * A run beginning is the one safe moment to rebuild the index.
     *
     * The memo is per-*process*, which is a single request under PHP-FPM but the entire server
     * lifetime in Health's long-running ReactPHP process, so without this a calendar created
     * through `/data` would stay invisible to the WebSocket until someone restarted it. A
     * write-path invalidation hook cannot close the gap: `/data` writes happen in the HTTP process,
     * which never runs this code.
     */
    public function testANewRunTokenDropsTheMemoizedInventory(): void
    {
        $health = new Health();
        $conn   = self::createStubConnection();

        CheckableInventory::all();
        self::assertTrue(self::inventoryIsMemoized(), 'precondition: the index is built');

        self::send($health, $conn, ['action' => 'validateCalendar', 'runToken' => 'run-a']);

        self::assertFalse(self::inventoryIsMemoized(), 'a run beginning rebuilds the index');
        self::assertSame([1 => 'run-a'], self::getRunTokens($health));
    }

    /**
     * Once per run, not once per message.
     *
     * A run sends one message per checked item — dozens of them — and rebuilding reads and parses
     * every calendar source file, so resetting on each would trade an unbounded staleness window
     * for an unbounded cost. Resetting on the token *change* bounds staleness to a single run and
     * pays for it once.
     */
    public function testAContinuingRunKeepsTheMemoizedInventory(): void
    {
        $health = new Health();
        $conn   = self::createStubConnection();

        self::setRunTokens($health, [1 => 'run-a']);
        CheckableInventory::all();
        self::assertTrue(self::inventoryIsMemoized(), 'precondition: the index is built');

        self::send($health, $conn, ['action' => 'validateSource', 'target' => ['id' => 'temporale:roman'], 'runToken' => 'run-a']);

        self::assertTrue(self::inventoryIsMemoized(), 'a second message of the same run must not rebuild the index');
    }

    /**
     * `cancelRun` is exempt from the ambient run-token store — it names the run it wants abandoned,
     * not the run this connection is on — and the reset lives inside that same exemption, so it
     * must stay exempt from the reset too. A cancel is the end of a run, not the beginning of one;
     * rebuilding the index there would pay the full cost for a client that has stopped asking.
     */
    public function testCancelRunNeitherStoresATokenNorRebuildsTheInventory(): void
    {
        $health = new Health();
        $conn   = self::createStubConnection();

        CheckableInventory::all();
        self::assertTrue(self::inventoryIsMemoized(), 'precondition: the index is built');

        self::send($health, $conn, ['action' => 'cancelRun', 'runToken' => 'run-a']);

        self::assertTrue(self::inventoryIsMemoized(), 'a cancel is not a run beginning');
        self::assertSame([], self::getRunTokens($health), 'cancelRun stays exempt from the ambient token store');
    }

    // ---------------------------------------------------------------- round trip

    /**
     * Everything `/validations` publishes can be sent back.
     *
     * The published set and the addressable set have to be the same set: an id a client can read
     * out of the catalogue but cannot use as an address would be worse than not publishing it at
     * all, because the client has no way to tell the two cases apart. Driven from
     * `CheckableInventory::all()` rather than a hand-written list, so a newly enumerated *kind* of
     * source data is covered the day it appears rather than the day someone remembers to add it
     * here.
     */
    public function testEveryPublishedIdResolvesBackToACheckableItem(): void
    {
        $all = CheckableInventory::all();
        self::assertNotEmpty($all, 'the inventory published nothing to round-trip');

        foreach ($all as $published) {
            $resolved = CheckableInventory::byId($published->id);
            self::assertInstanceOf(CheckableItem::class, $resolved, "published id {$published->id} does not resolve");
            self::assertSame($published->id, $resolved->id);
            self::assertNotSame('', $resolved->schema->path(), "published id {$published->id} resolves to no schema");
            self::assertContains($resolved->kind, ['file', 'folder'], "published id {$published->id} has no usable kind");
        }
    }
}
