<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests;

use LiturgicalCalendar\Api\Enum\ProtocolErrorCode;
use LiturgicalCalendar\Api\Health;
use LiturgicalCalendar\Api\Models\Metadata\MetadataCalendars;
use LiturgicalCalendar\Api\Models\ValidationsPath\CheckableInventory;
use LiturgicalCalendar\Api\Router;
use LiturgicalCalendar\Tests\Support\HealthQueueIsolationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ratchet\ConnectionInterface;

/**
 * The typed calendar identity — #806 sections D and E.
 *
 * Two actions carry one, and they live in the same file because the identity is what they share:
 * `validateCalendar` (section D), which computes a calendar and validates the response, and
 * `runTest` (section E), which computes a calendar and runs one named unit test against it. The
 * `kind`→category mapping and the rite-disagreement check are resolved once, by
 * `Health::resolveCalendarIdentity()`, for both — so the rejection rules asserted below are asserted
 * for one implementation, not for two copies that could drift apart.
 *
 * ## `validateCalendar`
 *
 * The action keeps its name because the action is unchanged: compute a calendar for a year and
 * validate the response. What changes is how the calendar is named. A v1 message spreads the
 * identity across two properties — an opaque `calendar` string plus a `category` whose vocabulary
 * is shared, confusingly, with `executeValidation`'s unrelated schema-resolution strategies — and
 * carries the rite as an optional hint the server is free to guess at. A v2 message carries one
 * `calendar` **object** holding `kind`, `id` and `rite`.
 *
 * Because the action name is unchanged, the shape of `calendar` is the only discriminator there
 * is, and it has to be applied before `validateMessageProperties()` compares against
 * `ACTION_PROPERTIES['validateCalendar']` — the v2 form has neither `category` nor `responsetype`,
 * so the list would turn every v2 message away before it reached a handler.
 *
 * Nothing legacy is touched: a string `calendar` still takes the old path byte for byte.
 *
 * ## `runTest`
 *
 * `runTest` replaces `executeUnitTest` the way `validateSource` replaced the `executeValidation`
 * slugs: by taking a **new name**, which is the whole of its discrimination — a v1 client cannot
 * accidentally emit a name it does not know, so there is no shape to test. It carries a `test` name,
 * the same `CalendarIdentity`, and a year; there is no `responseFormat`, because a test runs against
 * the parsed calendar rather than against a chosen representation of it.
 *
 * `executeUnitTest` keeps working byte for byte, and is asserted below to.
 *
 * ## What these tests assert against
 *
 * `validateCalendar()` is asynchronous — it hands a URL to `cachedGet()`, which appends it to
 * `Health::$queue` and returns a promise. Nothing is dispatched until the ReactPHP loop runs, which
 * it never does here, so the queued entry is a complete and inert record of the decision the
 * dispatch made: the URL encodes the category (`/nation/`, `/diocese/`, or neither), the calendar
 * id and the rite segment, and the request options carry the Accept header the response format
 * chose. That is the whole of what this task decides, observable without a WebSocket server, an
 * HTTP API, or a mock of the method under test. `executeUnitTest()` queues the same way, so the same
 * assertions reach it.
 *
 * One thing the queued URL does *not* record is which test `runTest` asked for — that is closed over
 * by the promise handlers rather than written into the request. {@see failSoleQueuedRequest()}
 * settles the queued entry as a failure, which makes `executeUnitTest()` emit the frame it addresses
 * `.<test>.year-<year>.test-valid`; that frame names the test, the category, the calendar id and the
 * URL at once, which is the whole dispatch in a single observation and still no network.
 */
#[CoversClass(Health::class)]
final class HealthTypedCalendarTest extends TestCase
{
    // Every Health here queues a real calendar URL; see the trait for why that must be defused.
    use HealthQueueIsolationTrait;

    public static function setUpBeforeClass(): void
    {
        // Route::CALENDAR->path() is built from the resolved API paths.
        Router::getApiPaths();
    }

    // ---------------------------------------------------------------- the typed identity dispatches

    /**
     * Each row is one message and the request it must produce.
     *
     * The URL is the assertion that matters: `/{rite}/nation/{id}`, `/{rite}/diocese/{id}` and
     * `/{rite}` are three different categories, so a row that lands on the right URL has had its
     * `kind` mapped, its `id` carried and its `rite` honoured, all three at once.
     *
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function typedIdentityProvider(): array
    {
        return [
            'ambrosian diocesan' => [
                ['kind' => 'diocesan', 'id' => 'lugano_ch', 'rite' => 'ambrosian'],
                '/ambrosian/diocese/lugano_ch/2026?year_type=CIVIL'
            ],
            'roman diocesan'     => [
                ['kind' => 'diocesan', 'id' => 'rotter_nl', 'rite' => 'roman'],
                '/roman/diocese/rotter_nl/2026?year_type=CIVIL'
            ],
            'roman national'     => [
                ['kind' => 'national', 'id' => 'IT', 'rite' => 'roman'],
                '/roman/nation/IT/2026?year_type=CIVIL'
            ],
            'rite level'         => [
                ['kind' => 'rite', 'id' => 'ambrosian', 'rite' => 'ambrosian'],
                '/ambrosian/2026?year_type=CIVIL'
            ],
            // `general` names the one General Roman Calendar, so it is the only kind that needs no
            // id: there is nothing to choose between.
            'general'            => [
                ['kind' => 'general', 'rite' => 'roman'],
                '/roman/2026?year_type=CIVIL'
            ],
        ];
    }

    /**
     * @param array<string, mixed> $calendar
     */
    #[DataProvider('typedIdentityProvider')]
    public function testATypedIdentityReachesTheCalendarRequestItNames(array $calendar, string $expectedPath): void
    {
        $health = $this->newHealth();
        $conn   = self::createStubConnection();

        self::withMetadata(static function () use ($health, $conn, $calendar): void {
            self::send($health, $conn, [
                'action'         => 'validateCalendar',
                'calendar'       => $calendar,
                'year'           => 2026,
                'responseFormat' => 'JSON'
            ]);
        });

        self::assertSame([], $conn->sent, 'a well-formed v2 message must not be answered with a frame before it is checked: ' . implode(' ', $conn->sent));
        self::assertSame($expectedPath, self::soleQueuedPath($health));
    }

    /**
     * The discriminator has to be applied ahead of the `ACTION_PROPERTIES` list, not inside the
     * handler. This is the test that says so: the message below carries neither `category` nor
     * `responsetype`, both of which that list requires, so if the list ran first the message would
     * come back as the generic `Invalid message properties` and never reach a calendar request at
     * all.
     */
    public function testTheV2FormIsNotTurnedAwayForMissingCategoryAndResponsetype(): void
    {
        $health = $this->newHealth();
        $conn   = self::createStubConnection();

        self::withMetadata(static function () use ($health, $conn): void {
            self::send($health, $conn, [
                'action'         => 'validateCalendar',
                'calendar'       => ['kind' => 'diocesan', 'id' => 'lugano_ch', 'rite' => 'ambrosian'],
                'year'           => 2026,
                'responseFormat' => 'JSON'
            ]);
        });

        self::assertSame(
            [],
            array_map(static fn (string $raw): mixed => json_decode($raw)->errorMsg ?? null, $conn->sent),
            'the v2 shape was rejected by the property list that only the legacy shape satisfies'
        );
    }

    // ---------------------------------------------------------------- the legacy shape is untouched

    /**
     * A string `calendar` is a v1 message and must behave exactly as it did: `category` names the
     * calendar type, `responsetype` names the format, and the rite is *resolved* rather than
     * asserted — here from the diocese metadata, which is how a rite-unaware client gets the
     * `/ambrosian/` segment its Ambrosian diocese needs (issue #767).
     */
    public function testTheStringFormStillTakesTheLegacyPath(): void
    {
        $health = $this->newHealth();
        $conn   = self::createStubConnection();

        self::withMetadata(static function () use ($health, $conn): void {
            self::send($health, $conn, [
                'action'       => 'validateCalendar',
                'calendar'     => 'lugano_ch',
                'category'     => 'diocesancalendar',
                'year'         => 2026,
                'responsetype' => 'JSON'
            ]);
        });

        self::assertSame([], $conn->sent);
        self::assertSame('/ambrosian/diocese/lugano_ch/2026?year_type=CIVIL', self::soleQueuedPath($health));
    }

    /**
     * The branch must not have loosened the legacy list on its way past. A v1 message missing
     * `category` is still an invalid v1 message — it is not a v2 message merely because it is
     * missing the properties a v2 message would also be missing.
     */
    public function testTheStringFormStillRequiresCategoryAndResponsetype(): void
    {
        $health = $this->newHealth();
        $conn   = self::createStubConnection();

        self::send($health, $conn, [
            'action'   => 'validateCalendar',
            'calendar' => 'lugano_ch',
            'year'     => 2026
        ]);

        self::assertCount(1, $conn->sent);
        $frame = json_decode($conn->sent[0]);
        self::assertSame('protocolError', $frame->type);
        self::assertSame(ProtocolErrorCode::INVALID_MESSAGE->value, $frame->errorCode);
        self::assertSame([], self::queuedPaths($health), 'an invalid message must not have queued a request');
    }

    // ---------------------------------------------------------------- half-migrated clients

    /**
     * The failure mode this guards against is a message that *works*.
     *
     * A client that typed its `calendar` but left `category` behind gets the right calendar anyway —
     * `calendar.kind` supplies the category and the stale property is simply never read — so nothing
     * tells it that `category` has stopped meaning anything. It finds out the day the two disagree,
     * which is the worst possible day. Silently-correct is not the same as correct.
     */
    public function testALeftoverCategoryOnTheObjectFormIsRejected(): void
    {
        $health = $this->newHealth();
        $conn   = self::createStubConnection();

        self::withMetadata(static function () use ($health, $conn): void {
            self::send($health, $conn, [
                'action'         => 'validateCalendar',
                'calendar'       => ['kind' => 'diocesan', 'id' => 'lugano_ch', 'rite' => 'ambrosian'],
                // Left over from the v1 shape, and — note — naming the very category `kind` implies,
                // so this message would otherwise be dispatched correctly and say nothing.
                'category'       => 'diocesancalendar',
                'year'           => 2026,
                'responseFormat' => 'JSON'
            ]);
        });

        self::assertCount(1, $conn->sent);
        $frame = json_decode($conn->sent[0]);
        self::assertSame('protocolError', $frame->type);
        self::assertSame(ProtocolErrorCode::RETIRED_PROPERTY->value, $frame->errorCode);
        self::assertSame('category is not part of a validateCalendar message with an object calendar: calendar.kind replaces it.', $frame->text);
        self::assertSame([], self::queuedPaths($health));
    }

    /**
     * The mirrored half-migration, asserted rather than reasoned about: a typed `calendar` with the
     * *old* spelling of the format and no new one. This is the **absent-`responseFormat`** reading,
     * and it is answered by the property list, which requires `responseFormat` — so the message
     * never reaches a handler and the answer is the generic protocol error.
     *
     * Sending `responsetype` *alongside* a correct `responseFormat` is a different case with a
     * different answer; see `testResponsetypeAlongsideResponseFormatIsRejected()`. Keeping the two
     * readings separate is the point: one is a missing required property, the other is a retired one
     * still being sent.
     */
    public function testTheOldSpellingOfTheFormatOnTheObjectFormIsRejected(): void
    {
        $health = $this->newHealth();
        $conn   = self::createStubConnection();

        self::send($health, $conn, [
            'action'       => 'validateCalendar',
            'calendar'     => ['kind' => 'national', 'id' => 'IT', 'rite' => 'roman'],
            'year'         => 2026,
            'responsetype' => 'JSON'
        ]);

        self::assertCount(1, $conn->sent);
        $frame = json_decode($conn->sent[0]);
        self::assertSame('protocolError', $frame->type);
        self::assertSame(ProtocolErrorCode::INVALID_MESSAGE->value, $frame->errorCode);
        self::assertSame([], self::queuedPaths($health));
    }

    // ---------------------------------------------------------------- the response format

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function responseFormatProvider(): array
    {
        return [
            'JSON' => ['JSON', 'application/json'],
            'XML'  => ['XML', 'application/xml'],
            'ICS'  => ['ICS', 'text/calendar'],
            'YML'  => ['YML', 'application/yaml'],
        ];
    }

    /**
     * `responseFormat` is the v2 spelling of `responsetype`, and it has to reach the Accept header
     * the request is made with — a format that is accepted and then ignored would validate the
     * wrong representation and say nothing about it.
     */
    #[DataProvider('responseFormatProvider')]
    public function testResponseFormatIsHonouredOnTheObjectForm(string $format, string $expectedAccept): void
    {
        $health = $this->newHealth();
        $conn   = self::createStubConnection();

        self::send($health, $conn, [
            'action'         => 'validateCalendar',
            'calendar'       => ['kind' => 'national', 'id' => 'IT', 'rite' => 'roman'],
            'year'           => 2026,
            'responseFormat' => $format
        ]);

        self::assertSame([], $conn->sent);
        self::assertSame($expectedAccept, self::soleQueuedAccept($health));
    }

    /**
     * And the v1 spelling keeps working on the v1 shape. The two spellings are not interchangeable
     * across shapes on purpose: `responsetype` on a v2 message is a client that half-migrated, and
     * `responseFormat` on a v1 message is the same mistake mirrored.
     */
    #[DataProvider('responseFormatProvider')]
    public function testResponsetypeIsHonouredOnTheStringForm(string $format, string $expectedAccept): void
    {
        $health = $this->newHealth();
        $conn   = self::createStubConnection();

        self::send($health, $conn, [
            'action'       => 'validateCalendar',
            'calendar'     => 'IT',
            'category'     => 'nationalcalendar',
            'year'         => 2026,
            'responsetype' => $format
        ]);

        self::assertSame([], $conn->sent);
        self::assertSame($expectedAccept, self::soleQueuedAccept($health));
    }

    // ---------------------------------------------------------------- rejections

    /**
     * `resolveCalendarIdentity()`'s own "Unknown calendar kind" message is no longer reachable
     * through the WebSocket dispatch: `calendarIdentity.kind` in `WebSocketMessage.json` is a closed
     * enum of the four known kinds, so `WebSocketMessageValidator` refuses `widerregion` at the door
     * with the schema's own (verbose, library-generated) message before `resolveCalendarIdentity()`
     * ever runs. Only the error code is asserted here for that reason — the exact text belongs to
     * `swaggest/json-schema`, not to this codebase, and is not a contract worth pinning.
     */
    public function testAnUnknownKindIsRejected(): void
    {
        $health = $this->newHealth();
        $conn   = self::createStubConnection();

        self::send($health, $conn, [
            'action'         => 'validateCalendar',
            'calendar'       => ['kind' => 'widerregion', 'id' => 'Europe', 'rite' => 'roman'],
            'year'           => 2026,
            'responseFormat' => 'JSON'
        ]);

        self::assertCount(1, $conn->sent, 'an unusable identity is answered once and not computed');
        $frame = json_decode($conn->sent[0]);
        self::assertSame('protocolError', $frame->type);
        self::assertSame(ProtocolErrorCode::INVALID_MESSAGE->value, $frame->errorCode);
        self::assertSame([], self::queuedPaths($health));
    }

    /**
     * The rite-disagreement rejections, one per kind that can be checked.
     *
     * Why a wrong `rite` is rejected rather than resolved is argued once, in
     * `Health::resolveCalendarIdentity()`'s docblock; these rows are that argument's coverage, one
     * per kind whose actual rite the server can establish.
     *
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function riteDisagreementProvider(): array
    {
        return [
            // lugano_ch is one of the four Ambrosian dioceses; /calendars says so.
            'diocesan claimed roman'        => [['kind' => 'diocesan', 'id' => 'lugano_ch', 'rite' => 'roman']],
            'diocesan claimed ambrosian'    => [['kind' => 'diocesan', 'id' => 'rotter_nl', 'rite' => 'ambrosian']],
            // There are no Ambrosian national calendars: /calendar/ambrosian/nation/IT is a 400.
            'national claimed ambrosian'    => [['kind' => 'national', 'id' => 'IT', 'rite' => 'ambrosian']],
            // For a rite-level calendar the id IS the rite, so the message contradicts itself.
            'rite level contradicts itself' => [['kind' => 'rite', 'id' => 'ambrosian', 'rite' => 'roman']],
            // `general` is the General *Roman* Calendar; a rite-level Ambrosian calendar is kind `rite`.
            'general claimed ambrosian'     => [['kind' => 'general', 'rite' => 'ambrosian']],
        ];
    }

    /**
     * @param array<string, mixed> $calendar
     */
    #[DataProvider('riteDisagreementProvider')]
    public function testARiteThatDisagreesWithTheCalendarIsRejected(array $calendar): void
    {
        $health = $this->newHealth();
        $conn   = self::createStubConnection();

        // Inside withMetadata: the diocesan rows are only *checkable* when /calendars has been
        // fetched. Without it the server has no opinion to disagree with and, deliberately, lets
        // the request through — see testARiteIsNotCheckedWhenTheServerHasNoOpinionToCheckItAgainst().
        self::withMetadata(static function () use ($health, $conn, $calendar): void {
            self::send($health, $conn, [
                'action'         => 'validateCalendar',
                'calendar'       => $calendar,
                'year'           => 2026,
                'responseFormat' => 'JSON'
            ]);
        });

        self::assertCount(1, $conn->sent, 'a contradicted rite is answered once and not computed');
        $frame = json_decode($conn->sent[0]);
        self::assertSame('protocolError', $frame->type);
        self::assertSame(ProtocolErrorCode::INVALID_MESSAGE->value, $frame->errorCode);
        self::assertStringContainsString('calendar.rite says', (string) $frame->text);
        self::assertSame([], self::queuedPaths($health), 'a rejected message must not have queued a request');
    }

    /**
     * What the server cannot check it must not pretend to have checked.
     *
     * A diocese's real rite comes from `/calendars`, which `Health` fetches asynchronously when the
     * WebSocket connection opens. Two states have no answer to compare an assertion against: the
     * metadata not being loaded at all, and its being loaded without the diocese in it. Rejecting on
     * either would turn a server-side condition into a reported client bug — the #800 blindness in
     * miniature — so the assertion is taken at its word and the request goes out.
     * `resolveRite()` already makes the same call, for the same reason: the request itself reports
     * the real problem, as a 404 the client can see.
     *
     * This drives the second of the two states. The first cannot be driven deterministically:
     * `Health::$metadata` is a typed static with no default, and once any test in the process has
     * set it PHP offers no way back to genuinely uninitialised. Both funnel into the same `null`
     * from `actualRiteForKind()`, so the branch is covered either way.
     *
     * Both rites are asserted because each catches a different way of getting this wrong, and
     * either alone would pass against the other's mutation. `roman` catches an implementation that
     * *rejects* what it cannot look up — the honest-sounding mistake. `ambrosian` catches one that
     * substitutes the default rite for "unknown", which reads as a check and is not one: it would
     * agree with `roman` for every diocese in the world and disagree with `ambrosian` for all four
     * that are.
     *
     * @return array<string, array{0: string}>
     */
    public static function unknowableRiteProvider(): array
    {
        return [
            'roman'     => ['roman'],
            'ambrosian' => ['ambrosian'],
        ];
    }

    #[DataProvider('unknowableRiteProvider')]
    public function testARiteIsNotCheckedWhenTheServerHasNoOpinionToCheckItAgainst(string $rite): void
    {
        $health = $this->newHealth();
        $conn   = self::createStubConnection();

        // Force the state rather than inheriting whatever an earlier test left: lugano_ch really is
        // Ambrosian, and the whole point is that nothing loaded here knows that.
        self::withEmptyMetadata(static function () use ($health, $conn, $rite): void {
            self::send($health, $conn, [
                'action'         => 'validateCalendar',
                'calendar'       => ['kind' => 'diocesan', 'id' => 'lugano_ch', 'rite' => $rite],
                'year'           => 2026,
                'responseFormat' => 'JSON'
            ]);
        });

        self::assertSame([], $conn->sent, 'an unverifiable assertion was reported as a client bug');
        self::assertSame("/{$rite}/diocese/lugano_ch/2026?year_type=CIVIL", self::soleQueuedPath($health));
    }

    /**
     * `$expected === null` marks a row `WebSocketMessageValidator` now intercepts before
     * `resolveCalendarIdentity()` runs at all: `calendarIdentity` in `WebSocketMessage.json` requires
     * `kind` and `rite` and types `id` as a string, so a missing `kind`/`rite` or a non-string
     * `kind`/`id` fails schema validation with the library's own (verbose) message rather than
     * reaching this method's curated one. Only `rite` being an *unknown* value, and `id` being
     * *absent*, survive to reach `resolveCalendarIdentity()` — the schema types `rite` as any string
     * and does not require `id` at all — which is why those two rows, and the two `general` rows
     * built on `id` being present-but-wrong, still assert exact text.
     *
     * @return array<string, array{0: array<string, mixed>, 1: ?string}>
     */
    public static function malformedIdentityProvider(): array
    {
        return [
            'kind missing'         => [['id' => 'IT', 'rite' => 'roman'], null],
            'kind not a string'    => [['kind' => 42, 'id' => 'IT', 'rite' => 'roman'], null],
            'rite missing'         => [['kind' => 'national', 'id' => 'IT'], null],
            'rite unknown'         => [['kind' => 'national', 'id' => 'IT', 'rite' => 'byzantine'], 'Unknown rite: byzantine'],
            'id missing'           => [['kind' => 'national', 'rite' => 'roman'], 'calendar.id is required for kind national.'],
            'id not a string'      => [['kind' => 'diocesan', 'id' => 42, 'rite' => 'roman'], null],
            // `general` accepts no id but `roman`. The message must not name a kind to try instead:
            // `IT` would want `national`, and `kind: rite` — the obvious thing to suggest — rejects
            // `IT` in turn, so the advice would point at another failure.
            'nation id on general' => [['kind' => 'general', 'id' => 'IT', 'rite' => 'roman'], 'Kind general names the General Roman Calendar; its only valid id is roman, not IT.'],
            // The same message for an id that *is* a rite, which is the case a "use kind rite"
            // wording would have got right and is exactly why it read as good advice.
            'rite id on general'   => [['kind' => 'general', 'id' => 'ambrosian', 'rite' => 'roman'], 'Kind general names the General Roman Calendar; its only valid id is roman, not ambrosian.'],
        ];
    }

    /**
     * @param array<string, mixed> $calendar
     */
    #[DataProvider('malformedIdentityProvider')]
    public function testAMalformedIdentityIsRejectedWithWhatIsWrongWithIt(array $calendar, ?string $expected): void
    {
        $health = $this->newHealth();
        $conn   = self::createStubConnection();

        self::send($health, $conn, [
            'action'         => 'validateCalendar',
            'calendar'       => $calendar,
            'year'           => 2026,
            'responseFormat' => 'JSON'
        ]);

        self::assertCount(1, $conn->sent);
        $frame = json_decode($conn->sent[0]);
        self::assertSame('protocolError', $frame->type);
        self::assertSame(ProtocolErrorCode::INVALID_MESSAGE->value, $frame->errorCode);
        if (null !== $expected) {
            self::assertSame($expected, $frame->text);
        }
        self::assertSame([], self::queuedPaths($health));
    }

    /**
     * A format `validateCalendar()` has no branch for used to have to be turned away by hand, ahead
     * of `ReturnTypeParam::from()`, which throws a `\ValueError` on an unknown case — an `\Error`
     * that Ratchet's `IoServer::handleData` does not catch, so it would take the whole WebSocket
     * process down over one malformed message, the hazard `cancelRun()` documents, reached by a
     * different door. `WebSocketMessageValidator` now catches it first: `responseFormat` on
     * `validateCalendarTyped` is a closed schema enum, so `'PDF'` never reaches
     * `validateTypedCalendar()`'s own check, and the client sees the schema's own (verbose) message
     * instead of the curated one. Only the error code is asserted for that reason.
     */
    public function testAResponseFormatWithNoValidationBranchIsRejectedRatherThanThrown(): void
    {
        $health = $this->newHealth();
        $conn   = self::createStubConnection();

        self::send($health, $conn, [
            'action'         => 'validateCalendar',
            'calendar'       => ['kind' => 'national', 'id' => 'IT', 'rite' => 'roman'],
            'year'           => 2026,
            'responseFormat' => 'PDF'
        ]);

        self::assertCount(1, $conn->sent);
        $frame = json_decode($conn->sent[0]);
        self::assertSame('protocolError', $frame->type);
        self::assertSame(ProtocolErrorCode::INVALID_MESSAGE->value, $frame->errorCode);
        self::assertSame([], self::queuedPaths($health));
    }

    /**
     * `year` used to be re-checked by hand in `readYear()` because the old required-property check
     * established only that `year` was *present*, not what it was. `WebSocketMessageValidator` now
     * types `year: integer` on the schema itself, so a `null` year is refused there — with the
     * schema's own message, not `readYear()`'s — before `validateTypedCalendar()` runs at all. Only
     * the error code is asserted for that reason.
     */
    public function testAYearThatIsNotAnIntegerIsRejectedRatherThanThrown(): void
    {
        $health = $this->newHealth();
        $conn   = self::createStubConnection();

        self::send($health, $conn, [
            'action'         => 'validateCalendar',
            'calendar'       => ['kind' => 'national', 'id' => 'IT', 'rite' => 'roman'],
            'year'           => null,
            'responseFormat' => 'JSON'
        ]);

        self::assertCount(1, $conn->sent);
        $frame = json_decode($conn->sent[0]);
        self::assertSame('protocolError', $frame->type);
        self::assertSame(ProtocolErrorCode::INVALID_MESSAGE->value, $frame->errorCode);
        self::assertSame([], self::queuedPaths($health));
    }

    // ---------------------------------------------------------------- runTest: the identity is the same identity

    /**
     * The same rows as `validateCalendar`, deliberately: `runTest` resolves its calendar through the
     * very same `resolveCalendarIdentity()`, so the two actions must agree about what every identity
     * names. A row that passed here and failed there — or the reverse — would mean the mapping had
     * been copied rather than shared, which is the one outcome this task exists to prevent.
     *
     * @param array<string, mixed> $calendar
     */
    #[DataProvider('typedIdentityProvider')]
    public function testRunTestReachesTheCalendarRequestItsIdentityNames(array $calendar, string $expectedPath): void
    {
        $health = $this->newHealth();
        $conn   = self::createStubConnection();

        self::withMetadata(static function () use ($health, $conn, $calendar): void {
            self::send($health, $conn, [
                'action'   => 'runTest',
                'test'     => 'StIgnatiusOfLoyolaTest',
                'calendar' => $calendar,
                'year'     => 2026
            ]);
        });

        self::assertSame([], $conn->sent, 'a well-formed runTest must not be answered with a frame before its calendar is fetched');
        self::assertSame($expectedPath, self::soleQueuedPath($health));
    }

    /**
     * The dispatch in full: the test name, the mapped category, the id and the rite, all four read
     * back off the frame `executeUnitTest()` emits.
     *
     * The queued URL alone would leave the test name unasserted — it is closed over by the promise
     * handler, not written into the request — and a `runTest` that dropped or transposed `test`
     * would fetch exactly the right calendar and then run the wrong test, or none. Settling the
     * queued request as a failure is what makes that observable here.
     *
     * `diocesancalendar` in the text is the assertion that `kind: diocesan` was *mapped* rather than
     * passed through: `diocesan` is the wire word and `diocesancalendar` is the internal one, and
     * only the internal one builds a `/diocese/` URL.
     */
    public function testRunTestCarriesTheTestNameAndTheMappedCategoryIntoTheRun(): void
    {
        $health = $this->newHealth();
        $conn   = self::createStubConnection();

        self::withMetadata(static function () use ($health, $conn): void {
            self::send($health, $conn, [
                'action'   => 'runTest',
                'test'     => 'StIgnatiusOfLoyolaTest',
                'calendar' => ['kind' => 'diocesan', 'id' => 'lugano_ch', 'rite' => 'ambrosian'],
                'year'     => 2026
            ]);
        });

        self::failSoleQueuedRequest($health);

        self::assertCount(1, $conn->sent);
        $frame = json_decode($conn->sent[0]);
        self::assertSame('error', $frame->type);
        self::assertSame('.StIgnatiusOfLoyolaTest.year-2026.test-valid', $frame->classes, 'the frame a client matches on must name the test that was asked for');
        self::assertStringContainsString(
            'The diocesancalendar of lugano_ch for the year 2026 was not retrieved at the URL '
                . \LiturgicalCalendar\Api\Enum\Route::CALENDAR->path() . '/ambrosian/diocese/lugano_ch/2026?year_type=CIVIL',
            (string) $frame->text
        );
    }

    // ---------------------------------------------------------------- runTest: rejections

    /**
     * The rejection rules are `resolveCalendarIdentity()`'s, so these two rows are not a second
     * corpus — they are the assertion that `runTest` reaches the same resolver rather than a copy of
     * it. `lugano_ch` is one of the four Ambrosian dioceses, so `rite: roman` contradicts what
     * `/calendars` says and must be refused rather than quietly corrected.
     *
     * `unknown kind`'s expected text is `null`: `calendarIdentity.kind` is a closed schema enum, so
     * `WebSocketMessageValidator` now refuses `widerregion` before `resolveCalendarIdentity()` runs,
     * with the schema's own message rather than "Unknown calendar kind: widerregion". `rite
     * disagreement` uses a schema-valid `kind`, so it still reaches the resolver unchanged.
     *
     * @return array<string, array{0: array<string, mixed>, 1: ?string}>
     */
    public static function runTestRejectedIdentityProvider(): array
    {
        return [
            'unknown kind'      => [
                ['kind' => 'widerregion', 'id' => 'Europe', 'rite' => 'roman'],
                null
            ],
            'rite disagreement' => [
                ['kind' => 'diocesan', 'id' => 'lugano_ch', 'rite' => 'roman'],
                'calendar.rite says roman but lugano_ch is ambrosian.'
            ],
        ];
    }

    /**
     * @param array<string, mixed> $calendar
     */
    #[DataProvider('runTestRejectedIdentityProvider')]
    public function testRunTestRefusesAnIdentityItCannotHonour(array $calendar, ?string $expected): void
    {
        $health = $this->newHealth();
        $conn   = self::createStubConnection();

        self::withMetadata(static function () use ($health, $conn, $calendar): void {
            self::send($health, $conn, [
                'action'   => 'runTest',
                'test'     => 'StIgnatiusOfLoyolaTest',
                'calendar' => $calendar,
                'year'     => 2026
            ]);
        });

        self::assertCount(1, $conn->sent, 'an unusable identity is answered once and no test is run');
        $frame = json_decode($conn->sent[0]);
        self::assertSame('protocolError', $frame->type);
        self::assertSame(ProtocolErrorCode::INVALID_MESSAGE->value, $frame->errorCode);
        if (null !== $expected) {
            self::assertSame($expected, $frame->text);
        }
        self::assertSame([], self::queuedPaths($health), 'a rejected message must not have queued a request');
    }

    /**
     * `runTest` needs no *shape* test — its name is its discriminator — but the typed identity is the
     * entire point of the action, so a `calendar` that is not an object is not a legacy message to
     * fall back for; there is no legacy `runTest`. It is a malformed one.
     *
     * The string used here is the one a client would reach for by habit, copied straight from an
     * `executeUnitTest` message.
     *
     * `runTest.calendar` is typed as `#/definitions/calendarIdentity` — an object — on the schema, so
     * `WebSocketMessageValidator` now refuses a string `calendar` before `readCalendarIdentity()`'s
     * own "runTest calendar must be an object…" check ever runs. Only the error code is asserted for
     * that reason.
     */
    public function testRunTestRefusesACalendarThatIsNotAnObject(): void
    {
        $health = $this->newHealth();
        $conn   = self::createStubConnection();

        self::send($health, $conn, [
            'action'   => 'runTest',
            'test'     => 'StIgnatiusOfLoyolaTest',
            'calendar' => 'lugano_ch',
            'year'     => 2026
        ]);

        self::assertCount(1, $conn->sent);
        $frame = json_decode($conn->sent[0]);
        self::assertSame('protocolError', $frame->type);
        self::assertSame(ProtocolErrorCode::INVALID_MESSAGE->value, $frame->errorCode);
        self::assertSame([], self::queuedPaths($health));
    }

    /**
     * Both scalars used to be re-checked by hand because the old required-property check established
     * only that `test`/`year` were *present*, not what they were. `WebSocketMessageValidator` now
     * types both on the schema — `test: string`, `year: integer` — so every row here is now refused
     * by the schema, with its own message, before `runTest()`'s own checks ever run. Only the error
     * code is asserted for that reason; see `testAMessageThatUsedToKillTheProcessIsRefused()` in
     * `HealthProtocolValidationTest` for the crash-vector coverage this now overlaps with.
     *
     * Every row here has its property **present**, which is what used to carry it past the property
     * list and into the handler these rejections belonged to. A property that is genuinely absent
     * never got that far — see `testRunTestRequiresItsThreeProperties()`, a different code path with
     * a different answer. `null` is the case most easily mistaken for absence and is therefore
     * spelled `test null`, not `test missing`.
     *
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function runTestMalformedScalarProvider(): array
    {
        return [
            'test not a string' => [['test' => 42]],
            'test null'         => [['test' => null]],
            'year not an int'   => [['year' => '2026']],
            'year null'         => [['year' => null]],
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    #[DataProvider('runTestMalformedScalarProvider')]
    public function testRunTestRefusesAScalarItWouldOtherwiseThrowOn(array $overrides): void
    {
        $health = $this->newHealth();
        $conn   = self::createStubConnection();

        self::send($health, $conn, array_merge([
            'action'   => 'runTest',
            'test'     => 'StIgnatiusOfLoyolaTest',
            'calendar' => ['kind' => 'national', 'id' => 'IT', 'rite' => 'roman'],
            'year'     => 2026
        ], $overrides));

        self::assertCount(1, $conn->sent);
        $frame = json_decode($conn->sent[0]);
        self::assertSame('protocolError', $frame->type);
        self::assertSame(ProtocolErrorCode::INVALID_MESSAGE->value, $frame->errorCode);
        self::assertSame([], self::queuedPaths($health));
    }

    /**
     * A property the action declares required is turned away by `validateMessageProperties()` before
     * any handler sees it, on the generic protocol-error path — which is how the absence of `test`
     * differs from `test` being present and unusable, tested above.
     *
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function runTestMissingPropertyProvider(): array
    {
        return [
            'no test'     => [['action' => 'runTest', 'calendar' => ['kind' => 'national', 'id' => 'IT', 'rite' => 'roman'], 'year' => 2026]],
            'no calendar' => [['action' => 'runTest', 'test' => 'StIgnatiusOfLoyolaTest', 'year' => 2026]],
            'no year'     => [['action' => 'runTest', 'test' => 'StIgnatiusOfLoyolaTest', 'calendar' => ['kind' => 'national', 'id' => 'IT', 'rite' => 'roman']]],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('runTestMissingPropertyProvider')]
    public function testRunTestRequiresItsThreeProperties(array $payload): void
    {
        $health = $this->newHealth();
        $conn   = self::createStubConnection();

        self::send($health, $conn, $payload);

        self::assertCount(1, $conn->sent);
        $frame = json_decode($conn->sent[0]);
        self::assertSame('protocolError', $frame->type);
        self::assertSame(ProtocolErrorCode::INVALID_MESSAGE->value, $frame->errorCode);
        self::assertSame([], self::queuedPaths($health));
    }

    // ---------------------------------------------------------------- executeUnitTest is untouched

    /**
     * The legacy action, byte for byte: `category` names the calendar type, the test name is a bare
     * string, and the rite is *resolved* from the diocese metadata rather than asserted — which is
     * how a rite-unaware client gets the `/ambrosian/` segment its Ambrosian diocese needs (#767).
     */
    public function testExecuteUnitTestStillTakesTheLegacyPath(): void
    {
        $health = $this->newHealth();
        $conn   = self::createStubConnection();

        self::withMetadata(static function () use ($health, $conn): void {
            self::send($health, $conn, [
                'action'   => 'executeUnitTest',
                'test'     => 'StIgnatiusOfLoyolaTest',
                'calendar' => 'lugano_ch',
                'category' => 'diocesancalendar',
                'year'     => 2026
            ]);
        });

        self::assertSame([], $conn->sent);
        self::assertSame('/ambrosian/diocese/lugano_ch/2026?year_type=CIVIL', self::soleQueuedPath($health));

        self::failSoleQueuedRequest($health);
        $frame = json_decode($conn->sent[0]);
        self::assertSame('.StIgnatiusOfLoyolaTest.year-2026.test-valid', $frame->classes);
    }

    /**
     * A legacy message that has *not* migrated is still judged by the legacy list. `runTest` gaining
     * a shorter property list must not loosen `executeUnitTest`'s, which still requires `category`.
     */
    public function testExecuteUnitTestStillRequiresCategory(): void
    {
        $health = $this->newHealth();
        $conn   = self::createStubConnection();

        self::send($health, $conn, [
            'action'   => 'executeUnitTest',
            'test'     => 'StIgnatiusOfLoyolaTest',
            'calendar' => 'lugano_ch',
            'year'     => 2026
        ]);

        self::assertCount(1, $conn->sent);
        $frame = json_decode($conn->sent[0]);
        self::assertSame('protocolError', $frame->type);
        self::assertSame(ProtocolErrorCode::INVALID_MESSAGE->value, $frame->errorCode);
        self::assertSame([], self::queuedPaths($health));
    }

    // ---------------------------------------------------------------- a source check is not a test run

    /**
     * The same test file, two operations, two addresses — and the distinction is easy to lose,
     * because the word "test" appears in both.
     *
     * `test:ambrosian:StIgnatiusOfLoyolaTest` is an **inventory id**. `validateSource` resolves it to
     * a file on disk and asks the source-data question: does the test *definition* exist, parse, and
     * validate against `LitCalTest.json`. No calendar is computed; nothing is run.
     *
     * `runTest` names the same test as the bare `StIgnatiusOfLoyolaTest` and asks the other question
     * entirely: does the calendar this identity names, computed for this year, satisfy the test the
     * definition describes. A calendar *is* fetched; the definition is not schema-checked here.
     *
     * A definition can be valid while the test it describes fails, and a definition can be malformed
     * while the calendar is perfectly correct, so neither answer substitutes for the other. The third
     * assertion is what makes the addressing explicit rather than implied: the bare name is not an
     * inventory id, and the inventory rejects it — the two vocabularies do not overlap even though
     * one string is a substring of the other.
     */
    public function testTheTestDefinitionCheckAndTheTestRunAreDifferentOperations(): void
    {
        // The memo is per-process and an earlier test class may have built it against a fixture
        // tree; this test is about addressing, so it must resolve against the real source data.
        CheckableInventory::reset();

        // --- the source check: the definition, addressed by its inventory id.
        $sourceHealth = $this->newHealth();
        $sourceConn   = self::createStubConnection();
        self::send($sourceHealth, $sourceConn, [
            'action' => 'validateSource',
            'target' => ['id' => 'test:ambrosian:StIgnatiusOfLoyolaTest']
        ]);

        self::assertNotEmpty($sourceConn->sent, 'the test definition was not checked at all');
        foreach ($sourceConn->sent as $raw) {
            $frame = json_decode($raw);
            self::assertNotSame('protocolError', $frame->type, "the definition check was refused: {$frame->text}");
            // Addressed by the fragment derived from the id, not by the item's label — which for
            // this item is `Liturgical test: StIgnatiusOfLoyolaTest`, prose whose `: ` would make
            // the client's querySelectorAll() throw. See Health::cssClassFragmentForId().
            self::assertStringStartsWith(
                '.test-ambrosian-StIgnatiusOfLoyolaTest.',
                (string) $frame->classes,
                'a source check addresses its frames by the fragment derived from the inventory id'
            );
        }
        self::assertSame([], self::queuedPaths($sourceHealth), 'a source check reads the filesystem; it must not compute a calendar');

        // --- the test run: the same test, addressed by its bare name against a calendar.
        $runHealth = $this->newHealth();
        $runConn   = self::createStubConnection();
        self::send($runHealth, $runConn, [
            'action'   => 'runTest',
            'test'     => 'StIgnatiusOfLoyolaTest',
            'calendar' => ['kind' => 'rite', 'id' => 'ambrosian', 'rite' => 'ambrosian'],
            'year'     => 2026
        ]);

        self::assertSame([], $runConn->sent, 'a test run emits nothing until its calendar has been fetched');
        self::assertSame('/ambrosian/2026?year_type=CIVIL', self::soleQueuedPath($runHealth), 'a test run computes a calendar; it must not read the definition off disk instead');

        // --- and the two addresses are not interchangeable.
        $crossHealth = $this->newHealth();
        $crossConn   = self::createStubConnection();
        self::send($crossHealth, $crossConn, [
            'action' => 'validateSource',
            'target' => ['id' => 'StIgnatiusOfLoyolaTest']
        ]);

        self::assertCount(1, $crossConn->sent);
        $frame = json_decode($crossConn->sent[0]);
        self::assertSame('protocolError', $frame->type);
        self::assertSame(ProtocolErrorCode::UNKNOWN_TARGET_ID->value, $frame->errorCode);
        self::assertSame('Unknown validation target: StIgnatiusOfLoyolaTest', $frame->text, 'the bare test name is a runTest address, not an inventory id');
    }

    // ---------------------------------------------------------------- retired properties

    /**
     * Task 3 accepted this and the maintainer has overruled it.
     *
     * The old reasoning was that `responsetype` sent alongside a correct `responseFormat` is stale
     * noise from a client that has already done the rename right, not a misunderstanding about what
     * names the format. That is exactly backwards as a diagnostic: a client still sending the old
     * property has *not* finished migrating, and this is the cheapest moment it will ever get to
     * find out — the message works today because `responseFormat` is what gets read, and it breaks
     * the day the legacy branch is removed.
     *
     * The message names the property and its replacement, so a migrating client is told what to do
     * rather than only that it is wrong.
     */
    public function testResponsetypeAlongsideResponseFormatIsRejected(): void
    {
        $health = $this->newHealth();
        $conn   = self::createStubConnection();

        self::send($health, $conn, [
            'action'         => 'validateCalendar',
            'calendar'       => ['kind' => 'national', 'id' => 'IT', 'rite' => 'roman'],
            'year'           => 2026,
            // Both spellings, and — note — naming the same format, so this message would otherwise
            // be dispatched correctly and say nothing.
            'responseFormat' => 'JSON',
            'responsetype'   => 'JSON'
        ]);

        self::assertCount(1, $conn->sent);
        $frame = json_decode($conn->sent[0]);
        self::assertSame('protocolError', $frame->type);
        self::assertSame(ProtocolErrorCode::RETIRED_PROPERTY->value, $frame->errorCode);
        self::assertSame(
            'responsetype is not part of a validateCalendar message with an object calendar: responseFormat replaces it.',
            $frame->text
        );
        self::assertSame([], self::queuedPaths($health));
    }

    /**
     * The same rule on `runTest`, and the reason it is not merely symmetry: `runTest`'s predecessor
     * `executeUnitTest` *required* `category`, so a client that renamed the action and kept the
     * property is the likeliest half-migration there is. `calendar.kind` supplies the category, so
     * the message would otherwise work while the client kept believing `category` selected it.
     */
    public function testALeftoverCategoryOnRunTestIsRejected(): void
    {
        $health = $this->newHealth();
        $conn   = self::createStubConnection();

        self::withMetadata(static function () use ($health, $conn): void {
            self::send($health, $conn, [
                'action'   => 'runTest',
                'test'     => 'StIgnatiusOfLoyolaTest',
                'calendar' => ['kind' => 'diocesan', 'id' => 'lugano_ch', 'rite' => 'ambrosian'],
                // Left over from executeUnitTest, and naming the very category `kind` implies.
                'category' => 'diocesancalendar',
                'year'     => 2026
            ]);
        });

        self::assertCount(1, $conn->sent);
        $frame = json_decode($conn->sent[0]);
        self::assertSame('protocolError', $frame->type);
        self::assertSame(ProtocolErrorCode::RETIRED_PROPERTY->value, $frame->errorCode);
        self::assertSame('category is not part of a runTest message: calendar.kind replaces it.', $frame->text);
        self::assertSame([], self::queuedPaths($health), 'a rejected message must not have queued a request');
    }

    /**
     * A top-level `rite` is retired on both calendar actions, and it is the retired property whose
     * silent acceptance would do real damage.
     *
     * `rite` was **optional** on v1 `validateCalendar` and on `executeUnitTest` — read by
     * `readRiteHint()`, honoured by `resolveRite()` — and the v2 shapes moved it inside
     * `calendar.rite` and stopped reading the top-level one. So a client that objectified `calendar`
     * but kept its old `rite` gets a *rite disagreement* ignored, which is the one thing the typed
     * identity went out of its way to make loud: `resolveCalendarIdentity()` refuses to pick a
     * winner when `calendar.rite` contradicts the calendar's actual rite, and a stale top-level
     * `rite` reintroduces exactly that ambiguity one level up.
     *
     * The payloads deliberately carry a `rite` that **agrees** with `calendar.rite`, so what is
     * rejected is the retired property itself and not a disagreement — the message would otherwise
     * be dispatched perfectly and say nothing.
     *
     * This property was missed by the original retired-property audit because that audit derived the
     * retired set from `ACTION_PROPERTIES`, which lists only *required* properties. Optional v1
     * properties were structurally invisible to it; see `Health::RETIRED_PROPERTIES`.
     *
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function retiredRiteMessageProvider(): array
    {
        return [
            'validateCalendar' => [
                [
                    'action'         => 'validateCalendar',
                    'calendar'       => ['kind' => 'national', 'id' => 'IT', 'rite' => 'roman'],
                    'year'           => 2026,
                    'responseFormat' => 'JSON',
                    'rite'           => 'roman'
                ],
                'rite is not part of a validateCalendar message with an object calendar: calendar.rite replaces it.'
            ],
            'runTest'          => [
                [
                    'action'   => 'runTest',
                    'test'     => 'StIgnatiusOfLoyolaTest',
                    'calendar' => ['kind' => 'national', 'id' => 'IT', 'rite' => 'roman'],
                    'year'     => 2026,
                    'rite'     => 'roman'
                ],
                'rite is not part of a runTest message: calendar.rite replaces it.'
            ],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('retiredRiteMessageProvider')]
    public function testALeftoverTopLevelRiteIsRejected(array $payload, string $expectedText): void
    {
        $health = $this->newHealth();
        $conn   = self::createStubConnection();

        self::send($health, $conn, $payload);

        self::assertCount(1, $conn->sent, 'a half-migrated message is answered once and not dispatched');
        $frame = json_decode($conn->sent[0]);
        self::assertSame('protocolError', $frame->type);
        self::assertSame(ProtocolErrorCode::RETIRED_PROPERTY->value, $frame->errorCode);
        self::assertSame($expectedText, $frame->text);
        self::assertSame([], self::queuedPaths($health), 'a rejected message must not have queued a request');
    }

    /**
     * The rule must not reach the v1 actions it is named after: `rite` is a *current, supported*
     * optional hint on the string form of `validateCalendar` and on `executeUnitTest`, and a rule
     * that swept it up there would delete v1 rite awareness (issue #767) rather than guard the v2
     * shapes. The queued path is what is asserted, because that is where the hint has its effect —
     * an accepted-but-ignored `rite` would leave no trace in the frames.
     *
     * The hint deliberately *contradicts* what the metadata says, so the assertion distinguishes a
     * hint that was honoured from a rite that was merely looked up: `rotter_nl` is Roman in the
     * fixture, and only the top-level `rite` can put `/ambrosian/` in front of it.
     */
    public function testTheV1ShapeStillHonoursATopLevelRite(): void
    {
        $health = $this->newHealth();
        $conn   = self::createStubConnection();

        self::withMetadata(static function () use ($health, $conn): void {
            self::send($health, $conn, [
                'action'       => 'validateCalendar',
                'calendar'     => 'rotter_nl',
                'category'     => 'diocesancalendar',
                'year'         => 2026,
                'responsetype' => 'JSON',
                'rite'         => 'ambrosian'
            ]);
        });

        self::assertSame([], $conn->sent, 'the legacy shape was refused a property it still supports');
        self::assertSame(
            '/ambrosian/diocese/rotter_nl/2026?year_type=CIVIL',
            self::soleQueuedPath($health),
            'the v1 rite hint stopped reaching the request path'
        );
    }

    /**
     * `runTest` retires `category` and `rite`, and no third property, because there is no third
     * property to retire: `ACTION_PROPERTIES['executeUnitTest']` is
     * `['category', 'calendar', 'year', 'test']` and its only optional property was `rite`, so no
     * `responsetype` ever named anything on it. A rule that invented one would reject a property no
     * client was ever told to send, for a reason that is not true.
     */
    public function testRunTestDoesNotRetireAResponsetypeItNeverHad(): void
    {
        $health = $this->newHealth();
        $conn   = self::createStubConnection();

        self::send($health, $conn, [
            'action'       => 'runTest',
            'test'         => 'StIgnatiusOfLoyolaTest',
            'calendar'     => ['kind' => 'national', 'id' => 'IT', 'rite' => 'roman'],
            'year'         => 2026,
            'responsetype' => 'JSON'
        ]);

        self::assertSame([], $conn->sent, 'runTest rejected a property that was never part of executeUnitTest');
        self::assertSame('/roman/nation/IT/2026?year_type=CIVIL', self::soleQueuedPath($health));
    }

    /**
     * `runToken` is shared, current, and retired by nothing — a rule that swept it up would break
     * run correlation on every v2 message at once, which is the failure mode a blanket
     * "reject unknown properties" would have had.
     *
     * Tolerating it is not enough to assert, so the queued request's own `runToken` tag is what is
     * checked: that is what `processQueue()` reads to drop the backlog of a superseded run, so a
     * token that arrived and was discarded would look identical from the frames alone.
     *
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function runTokenBearingMessageProvider(): array
    {
        return [
            'validateCalendar' => [
                [
                    'action'         => 'validateCalendar',
                    'calendar'       => ['kind' => 'national', 'id' => 'IT', 'rite' => 'roman'],
                    'year'           => 2026,
                    'responseFormat' => 'JSON',
                    'runToken'       => 'run-a'
                ]
            ],
            'runTest'          => [
                [
                    'action'   => 'runTest',
                    'test'     => 'StIgnatiusOfLoyolaTest',
                    'calendar' => ['kind' => 'national', 'id' => 'IT', 'rite' => 'roman'],
                    'year'     => 2026,
                    'runToken' => 'run-a'
                ]
            ],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('runTokenBearingMessageProvider')]
    public function testARunTokenIsNotARetiredProperty(array $payload): void
    {
        $health = $this->newHealth();
        $conn   = self::createStubConnection();

        self::send($health, $conn, $payload);

        self::assertSame([], $conn->sent, 'a runToken was mistaken for a retired property');
        self::assertSame('/roman/nation/IT/2026?year_type=CIVIL', self::soleQueuedPath($health));

        $queued = self::queuedRequests($health);
        self::assertSame('run-a', $queued[0]['runToken'], 'the run token reached the queue, where cancelRun reads it');
    }

    // ---------------------------------------------------------------- harness

    /**
     * A minimal Ratchet connection that records every outbound frame. `resourceId` is a dynamic
     * public property Ratchet assigns and is not part of `ConnectionInterface`, so this mirrors the
     * stub convention already used by HealthValidateSourceTest and HealthCancelRunTest rather than
     * a PHPUnit mock, which would trigger a dynamic-property deprecation.
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

    /**
     * The requests `cachedGet()` parked on the queue, as paths relative to `/calendar`.
     *
     * @return list<string>
     */
    private static function queuedPaths(Health $health): array
    {
        $prefix = \LiturgicalCalendar\Api\Enum\Route::CALENDAR->path();

        return array_map(
            static function (array $entry) use ($prefix): string {
                /** @var array{url:string} $entry */
                self::assertStringStartsWith($prefix, $entry['url'], 'a calendar request was made against something other than /calendar');

                return substr($entry['url'], strlen($prefix));
            },
            self::queuedRequests($health)
        );
    }

    private static function soleQueuedPath(Health $health): string
    {
        $paths = self::queuedPaths($health);
        self::assertCount(1, $paths, 'expected exactly one queued calendar request, got: ' . json_encode($paths));

        return $paths[0];
    }

    /**
     * Settle the sole queued request as a network failure, synchronously.
     *
     * The queue entry carries the promise's own `reject` closure, so calling it drives the handler
     * the dispatching method registered — without a loop, an HTTP client, or a server. That is the
     * only way to observe what a handler closed over rather than wrote into the URL, which for
     * `executeUnitTest()` is the test name.
     *
     * The *failure* arm is used rather than the success one on purpose: the success arm would have
     * to be fed a plausible calendar document and would then run a real `LitTestRunner`, making this
     * a test of the test corpus. The failure arm names the test, the category, the calendar and the
     * URL and does nothing else, which is exactly the dispatch and nothing beyond it.
     *
     * One cosmetic artifact, so a reader of the run log is not puzzled by it: the closure decrements
     * `inFlight`, which `processQueue()` would have incremented on dispatch and which nothing did
     * here, so this instance is left at `-1`. `drainHandler()` reads `inFlight > 0`, so a negative
     * count stops ticking exactly as zero does, and the queue is emptied by the isolation trait in
     * any case.
     */
    private static function failSoleQueuedRequest(Health $health): void
    {
        $queued = self::queuedRequests($health);
        self::assertCount(1, $queued, 'expected exactly one queued calendar request to settle');
        self::assertArrayHasKey('reject', $queued[0]);
        /** @var \Closure(\Throwable):void $reject */
        $reject = $queued[0]['reject'];
        $reject(new \RuntimeException('the network is not this test\'s business'));
    }

    private static function soleQueuedAccept(Health $health): string
    {
        $queued = self::queuedRequests($health);
        self::assertCount(1, $queued, 'expected exactly one queued calendar request');
        /** @var array{options:array{headers?:array<string, string>}} $entry */
        $entry = $queued[0];
        self::assertArrayHasKey('headers', $entry['options']);
        self::assertArrayHasKey('Accept', $entry['options']['headers']);

        return $entry['options']['headers']['Accept'];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function queuedRequests(Health $health): array
    {
        /** @var list<array<string, mixed>> */
        return ( new \ReflectionProperty(Health::class, 'queue') )->getValue($health);
    }

    /**
     * Populate `Health::$metadata` with the same two-diocese stand-in HealthRiteRequestPathTest
     * uses — one Ambrosian, one Roman — for the duration of $fn, then put back what was there.
     *
     * `Health::$metadata` is a typed static with no default, so PHP offers no way to return it to
     * the genuinely uninitialised state. When it started out uninitialised we therefore leave an
     * *empty* MetadataCalendars behind rather than the fixture, so a later test in the same process
     * cannot resolve `lugano_ch` or `rotter_nl` from data this helper invented.
     *
     * **Warning for a future test.** Calling this leaves `Health::$metadata` initialised for the
     * rest of the process — empty, but initialised. `findDioceseMetadata()` has two failure arms and
     * only the `NotFoundException` one is reachable afterwards; its `\RuntimeException` arm needs
     * `isset(self::$metadata) === false`, which nothing can restore. A test written against that arm
     * would pass or fail depending on which file PHPUnit loaded first. Reach it in a process that
     * has not touched this helper, or not at all.
     */
    private static function withMetadata(callable $fn): void
    {
        $property = new \ReflectionProperty(Health::class, 'metadata');
        $wasSet   = $property->isInitialized();
        $previous = $wasSet ? $property->getValue() : null;

        $property->setValue(null, MetadataCalendars::fromObject((object) [
            'national_calendars'       => [],
            'national_calendars_keys'  => [],
            'diocesan_calendars_keys'  => ['lugano_ch', 'rotter_nl'],
            'diocesan_groups'          => [],
            'wider_regions'            => [],
            'wider_regions_keys'       => [],
            'ambrosian_calendars'      => [],
            'ambrosian_calendars_keys' => [],
            'locales'                  => ['en'],
            'diocesan_calendars'       => [
                (object) [
                    'calendar_id' => 'lugano_ch',
                    'diocese'     => 'Lugano',
                    'nation'      => 'CH',
                    'locales'     => ['it_IT'],
                    'timezone'    => 'Europe/Zurich',
                    'rite'        => 'ambrosian',
                ],
                (object) [
                    'calendar_id' => 'rotter_nl',
                    'diocese'     => 'Rotterdam',
                    'nation'      => 'NL',
                    'locales'     => ['nl_NL'],
                    'timezone'    => 'Europe/Amsterdam',
                    'rite'        => 'roman',
                ],
            ],
        ]));

        try {
            $fn();
        } finally {
            if ($wasSet && $previous !== null) {
                $property->setValue(null, $previous);
            } else {
                $property->setValue(null, self::emptyMetadata());
            }
        }
    }

    /**
     * Run $fn with `Health::$metadata` populated but empty — the state in which the server has no
     * opinion about any diocese's rite.
     */
    private static function withEmptyMetadata(callable $fn): void
    {
        $property = new \ReflectionProperty(Health::class, 'metadata');
        $wasSet   = $property->isInitialized();
        $previous = $wasSet ? $property->getValue() : null;

        $property->setValue(null, self::emptyMetadata());

        try {
            $fn();
        } finally {
            if ($wasSet && $previous !== null) {
                $property->setValue(null, $previous);
            }
        }
    }

    private static function emptyMetadata(): MetadataCalendars
    {
        return MetadataCalendars::fromObject((object) [
            'national_calendars'       => [],
            'national_calendars_keys'  => [],
            'diocesan_calendars'       => [],
            'diocesan_calendars_keys'  => [],
            'diocesan_groups'          => [],
            'wider_regions'            => [],
            'wider_regions_keys'       => [],
            'ambrosian_calendars'      => [],
            'ambrosian_calendars_keys' => [],
            'locales'                  => ['en'],
        ]);
    }
}
