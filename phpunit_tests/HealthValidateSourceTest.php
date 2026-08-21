<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests;

use LiturgicalCalendar\Api\Enum\JsonData;
use LiturgicalCalendar\Api\Enum\ProtocolErrorCode;
use LiturgicalCalendar\Api\Health;
use LiturgicalCalendar\Api\Models\ValidationsPath\CheckableInventory;
use LiturgicalCalendar\Api\Models\ValidationsPath\CheckableItem;
use LiturgicalCalendar\Api\Router;
use LiturgicalCalendar\Api\Services\WebSocketMessageValidator;
use LiturgicalCalendar\Tests\Support\BrokenInventoryTrait;
use LiturgicalCalendar\Tests\Support\HealthQueueIsolationTrait;
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
#[CoversClass(WebSocketMessageValidator::class)]
final class HealthValidateSourceTest extends TestCase
{
    use BrokenInventoryTrait;
    use HealthQueueIsolationTrait;

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
     * Not every row proves as much as it looks like it does. `retrieveSchemaForCategory()`'s
     * `sourceDataCheck` arm carries a `$legacySlugToId` table that rewrites four legacy slugs —
     * `proprium-de-tempore`, `proprium-de-tempore-i18n`, `memorials-from-decrees` and
     * `memorials-from-decrees-i18n` — into inventory ids *before* calling `byId()`. For those the
     * two sides of the comparison are literally the same lookup, so the row pins the mapping table
     * rather than proving two independent resolutions agree. The `national-calendar-…` rows are the
     * ones that genuinely exercise both paths: the slug goes through the anchored regex arms and
     * the id through `byId()`, and nothing rewrites one into the other. Marked below so a later
     * reader does not over-trust the cheap rows.
     *
     * The folder row matters for a different reason: `kind` selects between two entirely separate
     * branches of `runValidationSteps()`, and without it the folder branch is never reached through
     * this action at all.
     *
     * The third column is the class fragment the frames must be addressed by, written out in full
     * rather than computed. `temporale:roman` is an explicit inventory item and `nation:roman:IT` /
     * `nation:roman:US:i18n` are enumerated per-calendar ones, whose labels (`National calendar: IT`)
     * are the prose that made the shipped fragments unusable — so the two categories the derivation
     * has to cover are both pinned by a literal here.
     *
     * @return array<string, array{string, string, string}>
     */
    public static function equivalentAddressProvider(): array
    {
        return [
            // Same lookup on both sides — $legacySlugToId rewrites the slug into this very id.
            'temporale'              => ['temporale:roman', 'proprium-de-tempore', 'temporale-roman'],
            // Two independent resolutions: regex arm vs byId().
            'national calendar'      => ['nation:roman:IT', 'national-calendar-IT', 'nation-roman-IT'],
            // Likewise independent, and the only row whose kind is 'folder'.
            'national calendar i18n' => ['nation:roman:US:i18n', 'national-calendar-US-i18n', 'nation-roman-US-i18n']
        ];
    }

    /**
     * The published `steps` vocabulary is not the emitted frame-class vocabulary.
     *
     * `CheckableInventory::STEPS` publishes `exists|parses|validates` on the wire, while the frames
     * are addressed `.<fragment>.file-exists`, `.<fragment>.json-valid`, `.<fragment>.schema-valid`.
     * The two describe the same three steps in different words, and the correspondence is written
     * down here — once — and asserted rather than restated as a hardcoded list at each call site. A
     * newly published step with no entry here fails loudly, which is the point: it would also be a
     * step no client could match a frame to.
     *
     * **Deliberately a literal, and deliberately not read from `FrameFamily::CLASS_FOR_STEP`.** The
     * production table now exists, and reading it here would make both sides of the `assertSame()`
     * below derive from one source: a projection changed in `src` would change the expectation in
     * lockstep and the assertion could not fail. That is the exact pattern this branch spent four
     * dead tests learning to avoid, so the one file that polices addressing states its own
     * expectation. The production table is pinned separately, against literals, in
     * {@see HealthFrameProjectionTest}.
     *
     * @var array<string, string>
     */
    private const FRAME_CLASS_FOR_STEP = [
        'exists'    => 'file-exists',
        'parses'    => 'json-valid',
        'validates' => 'schema-valid'
    ];

    /**
     * What a frame's class fragment has to look like for the client to be able to use it at all.
     *
     * `UnitTestInterface/assets/js/testResults.js` feeds the whole `classes` string to
     * `document.querySelectorAll()` after passing it through `slugifySelector()`, which only
     * rewrites tokens matching `\.([a-zA-Z][a-zA-Z0-9_-]*)`. A fragment outside this shape is
     * therefore not merely ugly: one containing a space matches zero cards, and one containing `:`
     * makes `querySelectorAll()` throw a `SyntaxError` and paints nothing at all.
     *
     * Stated here as a literal, independent of anything the server computes. That is the whole
     * point — an earlier version of the expectation below built it out of `$item->label`, the very
     * value under test, and so could never have failed on the fragment being unusable.
     */
    private const USABLE_CLASS_FRAGMENT = '/^[A-Za-z][A-Za-z0-9_-]*$/';

    /**
     * The frame classes an item's *published* steps say it should emit, in order.
     *
     * $fragment is passed in by the caller rather than derived from the item, so that the
     * expectation is stated rather than recomputed.
     *
     * @return list<string>
     */
    private static function expectedFrameClasses(CheckableItem $item, string $fragment): array
    {
        return array_map(
            static function (string $step) use ($fragment): string {
                self::assertArrayHasKey($step, self::FRAME_CLASS_FOR_STEP, "published step '{$step}' has no frame class");

                return ".{$fragment}." . self::FRAME_CLASS_FOR_STEP[$step];
            },
            $item->steps
        );
    }

    /**
     * The class fragment a frame is addressed by: everything between the leading dot and the
     * trailing step.
     *
     * A fragment may not contain a `.` — that is what makes `.<fragment>.<step>` parseable at all —
     * so a frame whose `classes` does not split into exactly three pieces is itself the failure.
     */
    private static function classFragmentOf(\stdClass $frame): string
    {
        $parts = explode('.', (string) $frame->classes);
        self::assertCount(3, $parts, "a frame class must be .<fragment>.<step>, got: {$frame->classes}");
        self::assertSame('', $parts[0], "a frame class must start with a dot, got: {$frame->classes}");
        // Three literals, not the production table: reading `FrameFamily::CLASS_FOR_STEP` on both sides
        // of this comparison would make it a tautology, which is the very anti-pattern this branch is
        // policing. The step token of any frame a client can use is one of these three and nothing else.
        self::assertContains($parts[2], ['file-exists', 'json-valid', 'schema-valid'], "unknown step in frame class: {$frame->classes}");

        return $parts[1];
    }

    /**
     * A `validateSource` naming an id runs the very same check the legacy slug ran.
     *
     * The schema is the load-bearing assertion, and it is taken from the *frame* rather than from
     * the inventory, so this compares two resolutions of the same artifact — the id's, through
     * `byId()`, and the slug's, through `retrieveSchemaForCategory()`'s anchored patterns — rather
     * than comparing one resolution with itself.
     *
     * The frame expectations are derived from the item's own published `steps` rather than
     * hardcoded, because that is the contract a client sizes the phase with: it reads `steps` from
     * `/validations` and waits for exactly that many frames per check. Publishing one count and
     * emitting another wedges the run just as surely as emitting none.
     *
     * The frames are addressed by a fragment **derived from the id**, not by the item's label. The
     * label is human prose (`National calendar: IT`) and the shipped code used it verbatim, which
     * made the client's `querySelectorAll()` throw on the ~60 per-calendar items and match nothing
     * on the rest — so the new addressing path painted no card at all. The expectation is written
     * out in the provider and cross-checked against {@see self::USABLE_CLASS_FRAGMENT}: the literal
     * pins the exact derivation, and the pattern says what any fragment must satisfy, so a change of
     * shape fails the first and a change to an equally-broken shape fails the second.
     *
     * @param string $id       the inventory id a v2 client sends
     * @param string $slug     the `validate` slug a v1 client sends for the same artifact
     * @param string $fragment the CSS class fragment the frames must be addressed by
     */
    #[DataProvider('equivalentAddressProvider')]
    public function testAKnownIdRunsTheSameCheckAsItsLegacySlug(string $id, string $slug, string $fragment): void
    {
        $item = CheckableInventory::byId($id);
        self::assertInstanceOf(CheckableItem::class, $item, "the inventory has no entry for {$id}");

        $health = $this->newHealth();
        $conn   = self::createStubConnection();
        self::send($health, $conn, ['action' => 'validateSource', 'target' => ['id' => $id]]);

        self::assertCount(
            count($item->steps),
            $conn->sent,
            "{$id} publishes " . count($item->steps) . ' steps but emitted ' . count($conn->sent) . ' frames'
        );

        $frames = array_map(
            /** @return \stdClass */
            static fn (string $raw): object => (object) json_decode($raw),
            $conn->sent
        );

        self::assertSame(
            self::expectedFrameClasses($item, $fragment),
            array_map(static fn (\stdClass $f): mixed => $f->classes, $frames),
            'the frames are addressed by the fragment derived from the id, one per published step, in step order'
        );

        foreach ($frames as $frame) {
            self::assertMatchesRegularExpression(
                self::USABLE_CLASS_FRAGMENT,
                self::classFragmentOf($frame),
                "the frame class for {$id} is not a selector a client can use: {$frame->classes}"
            );
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
        $health = $this->newHealth();
        $conn   = self::createStubConnection();

        self::send($health, $conn, ['action' => 'validateSource', 'target' => ['id' => 'nation:roman:ZZ']]);

        self::assertCount(1, $conn->sent, 'an unresolvable target is answered once and not checked');
        $frame = json_decode($conn->sent[0]);
        self::assertSame('protocolError', $frame->type, 'rejections are a typed protocolError: since UnitTestInterface#46 an unrecognised type is painted as a failed check');
        self::assertSame(ProtocolErrorCode::UNKNOWN_TARGET_ID->value, $frame->errorCode);
        self::assertSame('Unknown validation target: nation:roman:ZZ', $frame->text);
    }

    /**
     * `validateSource.target` is typed as `{id: string}` on the schema, so
     * `WebSocketMessageValidator` now refuses a non-object `target` before `validateSource()`'s own
     * "requires a target object with an id" check ever runs, with `humanize()`'s deterministic
     * wording rather than that curated sentence. This test's job is confirming the schema catches
     * it pre-dispatch, not pinning that wording — see `HealthProtocolValidationTest` for coverage
     * of the wording itself. Only the error code is asserted here for that reason.
     */
    public function testATargetThatIsNotAnObjectIsRejectedAsMalformed(): void
    {
        $health = $this->newHealth();
        $conn   = self::createStubConnection();

        self::send($health, $conn, ['action' => 'validateSource', 'target' => 'temporale:roman']);

        self::assertCount(1, $conn->sent);
        $frame = json_decode($conn->sent[0]);
        self::assertSame('protocolError', $frame->type);
        self::assertSame(ProtocolErrorCode::INVALID_MESSAGE->value, $frame->errorCode);
    }

    /**
     * Same reasoning as {@see testATargetThatIsNotAnObjectIsRejectedAsMalformed()}: `target.id` is
     * typed as `string` on the schema, so a non-string id is refused before `validateSource()`'s own
     * "target id must be a string" check runs.
     */
    public function testATargetIdThatIsNotAStringIsRejected(): void
    {
        $health = $this->newHealth();
        $conn   = self::createStubConnection();

        self::send($health, $conn, ['action' => 'validateSource', 'target' => ['id' => 42]]);

        self::assertCount(1, $conn->sent);
        $frame = json_decode($conn->sent[0]);
        self::assertSame('protocolError', $frame->type);
        self::assertSame(ProtocolErrorCode::INVALID_MESSAGE->value, $frame->errorCode);
    }

    /**
     * A missing `target` never reaches the handler: the schema's `validateSource` definition
     * declares it required, so `WebSocketMessageValidator::validate()` turns the message away on
     * the generic protocol-error path.
     */
    public function testAMessageWithNoTargetIsRejectedByPropertyValidation(): void
    {
        $health = $this->newHealth();
        $conn   = self::createStubConnection();

        self::send($health, $conn, ['action' => 'validateSource']);

        self::assertCount(1, $conn->sent);
        $frame = json_decode($conn->sent[0]);
        self::assertSame('protocolError', $frame->type);
        self::assertSame(ProtocolErrorCode::INVALID_MESSAGE->value, $frame->errorCode);
    }

    // ---------------------------------------------------------------- retired properties

    /**
     * The four properties an inventory id replaces outright.
     *
     * `executeValidation` spread a check's address across a schema-resolution strategy (`category`),
     * a slug (`validate`) and a path (`sourceFile`/`sourceFolder`). An id is the whole address: the
     * server minted it, published it, and resolves all of that from it. A message carrying both is a
     * half-migrated client naming its check twice and being read once — it works today, because
     * `target` is what gets read, and it breaks the day the slug arms are removed.
     *
     * Each row's payload is a *valid* `validateSource` message with one retired property added, and
     * the added value is a *legitimate* legacy value taken from the same enums `executeValidation`
     * resolves against — so what fails is the retired property itself, not a bad value in it.
     *
     * @return array<string, array{0: string, 1: mixed}>
     */
    public static function retiredPropertyProvider(): array
    {
        return [
            'category'     => ['category', 'sourceDataCheck'],
            'validate'     => ['validate', 'proprium-de-tempore'],
            'sourceFile'   => ['sourceFile', JsonData::TEMPORALE_FILE->value],
            'sourceFolder' => ['sourceFolder', JsonData::TEMPORALE_FOLDER->value],
        ];
    }

    #[DataProvider('retiredPropertyProvider')]
    public function testALegacyAddressingPropertyAlongsideATargetIsRejected(string $property, mixed $value): void
    {
        $health = $this->newHealth();
        $conn   = self::createStubConnection();

        self::send($health, $conn, [
            'action'  => 'validateSource',
            'target'  => ['id' => 'temporale:roman'],
            $property => $value
        ]);

        self::assertCount(1, $conn->sent, 'a half-migrated message is answered once and not checked');
        $frame = json_decode($conn->sent[0]);
        self::assertSame('protocolError', $frame->type);
        self::assertSame(ProtocolErrorCode::RETIRED_PROPERTY->value, $frame->errorCode);
        self::assertSame(
            "{$property} is not part of a validateSource message: target.id replaces it.",
            $frame->text,
            'the rejection must name the property and its replacement, so a migrating client is told what to do'
        );
    }

    /**
     * The rule must not reach the legacy action it is named after. `executeValidation` *requires*
     * these properties; rejecting them there would delete the v1 surface rather than guard the v2
     * one, and the slug arms stay reachable until clients have moved over (UnitTestInterface#42).
     *
     * The `classes` values are asserted literally, and that is the point of the second half of this
     * test rather than an incidental extra: splitting the class fragment out of `$label` inside
     * `runValidationSteps()` is the one change on this branch that could have moved a v1 frame, and
     * the file branch of that method is reached from nowhere else in the in-process suite. A v1
     * caller passes no fragment, the fragment defaults to the label, and the frames must come out
     * addressed by the slug exactly as they always were.
     */
    public function testExecuteValidationStillAcceptsTheVeryPropertiesValidateSourceRetires(): void
    {
        $health = $this->newHealth();
        $conn   = self::createStubConnection();

        self::send($health, $conn, [
            'action'     => 'executeValidation',
            'category'   => 'sourceDataCheck',
            'validate'   => 'proprium-de-tempore',
            'sourceFile' => JsonData::TEMPORALE_FILE->value
        ]);

        self::assertNotEmpty($conn->sent, 'the legacy action stopped being usable');
        $frames = [];
        foreach ($conn->sent as $raw) {
            $frame = json_decode($raw);
            self::assertNotSame('protocolError', $frame->type, "the legacy slug shape was refused: {$frame->text}");
            $frames[] = $frame;
        }

        self::assertSame(
            ['.proprium-de-tempore.file-exists', '.proprium-de-tempore.json-valid', '.proprium-de-tempore.schema-valid'],
            array_map(static fn (\stdClass $f): mixed => $f->classes, $frames),
            'a v1 message must still be addressed by its own slug, unchanged'
        );
    }

    /**
     * `runToken` is shared and current, not retired: it correlates responses on every action,
     * including this one, and a rule that swept it up would break run correlation everywhere at
     * once. Asserted through the frames rather than merely by absence of a rejection — the token
     * has to come back stamped on the answers, which is what a client matches them with.
     */
    public function testARunTokenIsNotARetiredProperty(): void
    {
        $health = $this->newHealth();
        $conn   = self::createStubConnection();

        self::send($health, $conn, [
            'action'   => 'validateSource',
            'target'   => ['id' => 'temporale:roman'],
            'runToken' => 'run-a'
        ]);

        self::assertNotEmpty($conn->sent, 'a runToken was mistaken for a retired property');
        foreach ($conn->sent as $raw) {
            $frame = json_decode($raw);
            self::assertNotSame('protocolError', $frame->type, "the message was refused: {$frame->text}");
            self::assertSame('run-a', $frame->runToken, 'the answers must carry the token the client correlates them by');
        }
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
        $health = $this->newHealth();
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
     *
     * Identity, not populated-ness, is what is asserted here, and the distinction is the whole
     * test. `validateSource` resolves through `byId()`, which calls `all()`, which re-memoizes on
     * the spot — so under the very mutation this test exists to catch (an unconditional reset) the
     * memo would be nulled and immediately rebuilt, and any "is it populated?" check would still
     * be green. A rebuild mints fresh `CheckableItem` instances, so holding one from before the
     * message and asserting it is the *same object* afterwards is a claim only a surviving memo
     * can satisfy.
     */
    public function testAContinuingRunKeepsTheMemoizedInventory(): void
    {
        $health = $this->newHealth();
        $conn   = self::createStubConnection();

        self::setRunTokens($health, [1 => 'run-a']);
        $before = CheckableInventory::all();
        self::assertNotEmpty($before, 'precondition: the index is built');

        self::send($health, $conn, ['action' => 'validateSource', 'target' => ['id' => 'temporale:roman'], 'runToken' => 'run-a']);

        self::assertSame(
            $before[0],
            CheckableInventory::all()[0],
            'a second message of the same run rebuilt the index instead of reusing it'
        );
    }

    /**
     * `cancelRun` is exempt from the ambient run-token store — it names the run it wants abandoned,
     * not the run this connection is on — and the reset lives inside that same exemption, so it
     * must stay exempt from the reset too. A cancel is the end of a run, not the beginning of one;
     * rebuilding the index there would pay the full cost for a client that has stopped asking.
     */
    public function testCancelRunNeitherStoresATokenNorRebuildsTheInventory(): void
    {
        $health = $this->newHealth();
        $conn   = self::createStubConnection();

        CheckableInventory::all();
        self::assertTrue(self::inventoryIsMemoized(), 'precondition: the index is built');

        self::send($health, $conn, ['action' => 'cancelRun', 'runToken' => 'run-a']);

        self::assertTrue(self::inventoryIsMemoized(), 'a cancel is not a run beginning');
        self::assertSame([], self::getRunTokens($health), 'cancelRun stays exempt from the ambient token store');
    }

    // ---------------------------------------------------------------- a broken inventory is contained

    /**
     * One malformed calendar file must not cost a client its connection, nor its static targets.
     *
     * `byId()` reads and JSON-parses every national and diocesan calendar file, so one unparseable
     * file makes it throw — and a `Throwable` escaping `onMessage()` is caught by Ratchet's
     * `IoServer`, which closes the connection: an entire run lost to one bad file, by the tool whose
     * job is to find bad files. `validateSource()` therefore retries against the inventory's static
     * half, which builds its paths from the `RomanMissal` and `JsonData` registries and never reads
     * a calendar file, so it cannot have been broken by one.
     *
     * Both directions are asserted, because either alone would pass against the wrong thing. The
     * static id must still reach the execution phase — a retry that resolved nothing would look
     * identical to no retry at all from the outside. The per-calendar id must NOT, because a
     * "fallback" that resolved it would have to have read the very data that just failed.
     */
    public function testABrokenInventoryStillLetsStaticTargetsBeChecked(): void
    {
        $realRoot = Router::$apiFilePath;

        // Not a static closure: the Health built inside it has to be tracked by
        // HealthQueueIsolationTrait, whose newHealth() is an instance method.
        self::withBrokenInventory(function () use ($realRoot): void {
            // The fixture tree holds one malformed calendar and nothing else, so give it the two
            // things a temporale check reads — the temporale file and the schemas it validates
            // against — and the check can succeed or fail on its own merits rather than on the
            // fixture's poverty. Nothing here touches the malformed calendar, so the inventory
            // stays broken; the guard in withBrokenInventory() already proved it.
            self::copyIntoFixture($realRoot, JsonData::TEMPORALE_FILE->value);
            self::copyIntoFixture($realRoot, JsonData::SCHEMAS_FOLDER->value);

            $health = $this->newHealth();

            $staticTarget = self::createStubConnection(1);
            self::send($health, $staticTarget, ['action' => 'validateSource', 'target' => ['id' => 'temporale:roman']]);

            $item = CheckableInventory::staticById('temporale:roman');
            self::assertInstanceOf(CheckableItem::class, $item, 'precondition: the temporale is in the static half');

            self::assertCount(
                count($item->steps),
                $staticTarget->sent,
                'a static target stopped being checkable because an unrelated calendar file was malformed'
            );

            $frames = array_map(
                /** @return \stdClass */
                static fn (string $raw): object => (object) json_decode($raw),
                $staticTarget->sent
            );
            self::assertSame(
                self::expectedFrameClasses($item, 'temporale-roman'),
                array_map(static fn (\stdClass $f): mixed => $f->classes, $frames),
                'the frames were not addressed to the static item the retry was supposed to resolve'
            );
            foreach ($frames as $frame) {
                self::assertSame('success', $frame->type, "the temporale failed its own check: {$frame->text}");
            }

            $enumeratedTarget = self::createStubConnection(2);
            self::send($health, $enumeratedTarget, ['action' => 'validateSource', 'target' => ['id' => 'nation:roman:IT']]);

            self::assertCount(1, $enumeratedTarget->sent, 'a target that genuinely cannot be resolved must be answered once, not checked');
            $frame = json_decode($enumeratedTarget->sent[0]);
            self::assertSame('protocolError', $frame->type);
            self::assertSame(ProtocolErrorCode::UNKNOWN_TARGET_ID->value, $frame->errorCode);
            // The message says the index could not be built, not that the id is unknown:
            // nation:roman:IT is perfectly well known, and reporting a server-side failure as a
            // client-side one sends the reader hunting a bug that is not there.
            self::assertStringStartsWith(
                'Could not resolve validation target nation:roman:IT: the source data inventory could not be built',
                (string) $frame->text
            );
        });
    }

    /**
     * Copy one repo-relative file or folder from the real source tree into the fixture tree, at the
     * same relative position, so that `Router::$apiFilePath` being repointed still finds it.
     */
    private static function copyIntoFixture(string $realRoot, string $relative): void
    {
        $relative = trim($relative, '/');
        $from     = $realRoot . $relative;
        $to       = Router::$apiFilePath . $relative;

        if (is_dir($from)) {
            self::assertTrue(is_dir($to) || mkdir($to, 0777, true), "could not create fixture folder {$to}");
            $entries = scandir($from);
            self::assertIsArray($entries, "could not read {$from}");
            foreach ($entries as $entry) {
                if ('.' !== $entry && '..' !== $entry) {
                    self::copyIntoFixture($realRoot, $relative . '/' . $entry);
                }
            }
            return;
        }

        $parent = dirname($to);
        self::assertTrue(is_dir($parent) || mkdir($parent, 0777, true), "could not create fixture folder {$parent}");
        self::assertTrue(copy($from, $to), "could not copy {$from} into the fixture tree");
    }

    // ---------------------------------------------------------------- round trip

    /**
     * Everything `/validations` publishes can be sent back — through the surface a client uses.
     *
     * The published set and the addressable set have to be the same set: an id a client can read
     * out of the catalogue but cannot use as an address would be worse than not publishing it at
     * all, because the client has no way to tell the two cases apart.
     *
     * The obvious way to write this — iterate `all()` and look each id up with `byId()` — proves
     * nothing. `byId()` is a linear identity scan over the very array `all()` just returned, so it
     * cannot miss, and the assertions would hold against a `validateSource` that had been deleted
     * outright. What has to be exercised is the *action*: each id goes out as a real
     * `validateSource` message and must come back as a check rather than a rejection. That takes
     * `Health::validateSource()` across all of the published ids instead of the three the happy-path
     * provider covers, and it fails the day an advertised id stops being addressable — which is the
     * guarantee this test is named after.
     *
     * Silence counts as a failure too. A message that resolves but emits nothing leaves the client
     * waiting on frames that will never arrive, which is the same wedge as a rejection, arriving
     * more slowly.
     */
    public function testEveryPublishedIdIsAddressableThroughValidateSource(): void
    {
        $published = CheckableInventory::all();
        self::assertNotEmpty($published, 'the inventory published nothing to round-trip');

        $health = $this->newHealth();

        /** @var array<string, string> $unaddressable */
        $unaddressable = [];

        /**
         * The id that first claimed each lowercased class fragment, so a second claimant is caught.
         *
         * @var array<string, string> $fragmentOwners
         */
        $fragmentOwners = [];

        foreach ($published as $index => $item) {
            $conn = self::createStubConnection($index + 1);
            self::send($health, $conn, ['action' => 'validateSource', 'target' => ['id' => $item->id]]);

            if ([] === $conn->sent) {
                $unaddressable[$item->id] = 'answered with no frames at all';
                continue;
            }

            foreach ($conn->sent as $raw) {
                $frame = json_decode($raw);
                if ($frame instanceof \stdClass && 'protocolError' === ( $frame->type ?? null )) {
                    // `protocolError` is the rejection shape: the server declined to check this at
                    // all. An `error` frame is a different thing entirely and is fine here — it means
                    // the target was addressed and checked, and the check reported something. This
                    // test is about whether an id can be sent, not about whether its data is valid.
                    $unaddressable[$item->id] = (string) ( $frame->text ?? '' );
                    continue;
                }

                // Sent is not the same as usable. A frame the client cannot address is as good as
                // no frame — a fragment with a space in it matches zero cards and one with a colon
                // makes `querySelectorAll()` throw — so the whole published set is swept for the
                // shape a selector has to have, not only the three ids the happy-path rows cover.
                if ($frame instanceof \stdClass) {
                    $fragment = self::classFragmentOf($frame);
                    self::assertMatchesRegularExpression(
                        self::USABLE_CLASS_FRAGMENT,
                        $fragment,
                        "{$item->id} is addressable but its frames are not: {$frame->classes}"
                    );

                    // Two ids that address the same card are worse than one id that addresses none:
                    // the client paints one check's result over another's and neither reader can
                    // tell. The substitution is many-to-one — `a:b` and `a-b` would both yield
                    // `a-b` — so uniqueness of the *ids*, which CheckableInventoryTest already
                    // asserts, does not carry over to the fragments and has to be asserted here.
                    //
                    // Compared lowercased, because the client lowercases: `slugifySelector()` runs
                    // every class token through `slugify()`, which begins with `toLowerCase()`, so a
                    // pair colliding only after lowering collides for real. No realizable collision
                    // exists in the published set today; this is the guard that keeps it that way
                    // when a new id kind is added.
                    $key   = strtolower($fragment);
                    $owner = $fragmentOwners[$key] ?? $item->id;
                    self::assertSame(
                        $owner,
                        $item->id,
                        "the class fragment {$fragment} is claimed by both {$owner} and {$item->id}"
                    );
                    $fragmentOwners[$key] = $item->id;
                }
            }
        }

        self::assertSame(
            [],
            $unaddressable,
            'ids that GET /validations advertises but validateSource will not accept: ' . json_encode($unaddressable)
        );
    }
}
