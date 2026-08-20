<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests;

use LiturgicalCalendar\Api\Enum\Status;
use LiturgicalCalendar\Api\Enum\Step;
use LiturgicalCalendar\Api\Health;
use LiturgicalCalendar\Api\Router;
use LiturgicalCalendar\Tests\Support\HealthQueueIsolationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ratchet\ConnectionInterface;

/**
 * `Health::sendStepResult()` — the legacy frame fields as a projection of the structured ones.
 *
 * A frame used to say what it was about only through `classes`, a CSS selector the server built and
 * the browser matched with `querySelectorAll()`: attribution by string matching, with the server
 * knowing Bootstrap existed. Every frame now carries `target`, `step` and `status`, and `type` and
 * `classes` are *derived* from them rather than written out at each call site.
 *
 * **Every expectation here is a literal.** Deriving them from `Health::FRAME_CLASS_FOR_STEP` — the
 * very table under test — is how #806 section B produced four tests that could not fail: both sides
 * of the assertion read one production value, so any change to it changed the expectation with it.
 * The literals below are the only thing pinning the projection, which is why the other suites are
 * free to *use* the table to derive their expectations.
 */
#[CoversClass(Health::class)]
final class HealthFrameProjectionTest extends TestCase
{
    use HealthQueueIsolationTrait;

    /**
     * A minimal Ratchet connection that records every outbound frame. `resourceId` is a dynamic
     * public property Ratchet assigns and is not part of `ConnectionInterface`, so this mirrors the
     * stub convention already used by HealthValidateSourceTest and HealthFolderStepResultTest
     * rather than a PHPUnit mock, which would trigger a dynamic-property deprecation.
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
     * @param list<mixed> $args
     */
    private function invoke(Health $health, string $method, array $args): void
    {
        ( new \ReflectionMethod(Health::class, $method) )->invokeArgs($health, $args);
    }

    /**
     * @return array<string, array{string, Step, Status, string, string}>
     */
    public static function projectionProvider(): array
    {
        return [
            'exists passes'    => ['temporale-roman', Step::EXISTS,    Status::PASS, 'success', '.temporale-roman.file-exists'],
            'parses fails'     => ['temporale-roman', Step::PARSES,    Status::FAIL, 'error',   '.temporale-roman.json-valid'],
            'validates passes' => ['nation-roman-IT', Step::VALIDATES, Status::PASS, 'success', '.nation-roman-IT.schema-valid'],
        ];
    }

    #[DataProvider('projectionProvider')]
    public function testTheLegacyFieldsAreProjectedFromTheStructuredOnes(
        string $fragment,
        Step $step,
        Status $status,
        string $expectedType,
        string $expectedClasses
    ): void {
        $conn   = self::stubConnection();
        $health = $this->newHealth();
        $this->invoke($health, 'sendStepResult', [$conn, $fragment, (object) ['id' => 'temporale:roman'], $step, $status, 'text', null, null]);

        self::assertCount(1, $conn->sent);
        $frame = json_decode($conn->sent[0]);
        self::assertSame($expectedType, $frame->type, 'type is projected from status');
        self::assertSame($expectedClasses, $frame->classes, 'classes is projected from fragment and step');
        self::assertSame($step->value, $frame->step, 'the published step name reaches the wire');
        self::assertSame($status->value, $frame->status);
    }

    /**
     * The legacy fields keep their positions. `classes` is what today's clients match on and `type`
     * is what they paint with, so the structured fields are added *after* them and nothing is
     * reordered: a frame's first three properties are exactly the three it always had.
     */
    public function testTheLegacyFieldsComeFirstAndInTheirOriginalOrder(): void
    {
        $conn = self::stubConnection();
        $this->invoke(
            $this->newHealth(),
            'sendStepResult',
            [$conn, 'temporale-roman', (object) ['id' => 'temporale:roman'], Step::EXISTS, Status::PASS, 'The Data file exists', null, 'run-a']
        );

        $frame = json_decode($conn->sent[0], true);
        self::assertIsArray($frame);
        self::assertSame(
            ['type', 'text', 'classes'],
            array_slice(array_keys($frame), 0, 3),
            'the legacy trio must stay first, and in the order every shipped frame has used'
        );
        self::assertSame('The Data file exists', $frame['text'], 'the text is passed through untouched');
        self::assertSame('run-a', $frame['runToken'], 'the run token a client correlates by still reaches the frame');
    }

    /**
     * The target names what was checked, as an **object** carrying the id `GET /validations`
     * published — never a bare string, and never something derived from the selector. It is an object
     * so that clusters whose subject needs more than an id can say so without a breaking change:
     * a calendar validation adds `year`, a test run adds `calendar` and `year`.
     *
     * A v1 `executeValidation` message names no id, so its frames carry a null target rather than a
     * fabricated one.
     */
    public function testTheTargetIdReachesTheWireAndIsNullWhenThereIsNone(): void
    {
        $conn = self::stubConnection();
        $this->invoke(
            $this->newHealth(),
            'sendStepResult',
            [$conn, 'nation-roman-IT', (object) ['id' => 'nation:roman:IT'], Step::VALIDATES, Status::PASS, 'text', null, null]
        );
        $this->invoke(
            $this->newHealth(),
            'sendStepResult',
            [$conn, 'proprium-de-tempore', null, Step::VALIDATES, Status::PASS, 'text', null, null]
        );

        self::assertCount(2, $conn->sent);
        self::assertEquals((object) ['id' => 'nation:roman:IT'], json_decode($conn->sent[0])->target);
        self::assertNull(json_decode($conn->sent[1])->target);
    }

    /**
     * The calendar-validation address is the one in the protocol with a segment **after** the step:
     * `.calendar-{id}.{step}.year-{year}`. It is composed in exactly one place — the emitter's
     * `$classSuffix` — and nothing in the behavioural suites would notice if that concatenation were
     * dropped or reordered by a later edit: the frames would keep passing every other assertion while
     * `.calendar-IT.file-exists.year-2024` silently became `.calendar-IT.file-exists` and matched
     * zero cards, which is the exact failure mode #806 exists to end. Hence a literal.
     *
     * The target is asserted alongside it because the two say the same thing in the two vocabularies:
     * the year is in the selector for a v1 client and in `target.year` for a v2 one.
     */
    public function testACalendarFrameCarriesTheYearAfterTheStepAndInItsTarget(): void
    {
        $conn = self::stubConnection();
        $this->invoke(
            $this->newHealth(),
            'sendCalendarStepResult',
            [$conn, 'IT', 2024, Step::EXISTS, Status::PASS, 'The nationalcalendar of IT for the year 2024 exists']
        );

        $frame = json_decode($conn->sent[0]);
        self::assertSame(
            '.calendar-IT.file-exists.year-2024',
            $frame->classes,
            'the year segment rides after the step, and only the emitter puts it there'
        );
        self::assertEquals(
            (object) ['id' => 'IT', 'year' => 2024],
            $frame->target,
            'a calendar check is a calendar and a year; neither identifies it alone'
        );
    }

    /**
     * `responsetype` is legacy, so it keeps its legacy position: the four properties a decode-failure
     * frame has always led with, in the order it has always led with them, before any structured
     * field. Assigning it anywhere else would still decode to the same object, and would still be a
     * change to the wire format of a frame this migration promised not to touch.
     *
     * Absent — not null — when there is none, so a client cannot mistake "this frame is not about a
     * response format" for "the format was null".
     */
    public function testResponseTypeStaysInTheLegacyBlockAndIsAbsentWhenThereIsNone(): void
    {
        $conn = self::stubConnection();
        $this->invoke(
            $this->newHealth(),
            'sendCalendarStepResult',
            [$conn, 'IT', 2024, Step::PARSES, Status::FAIL, 'text', null, 'XML']
        );
        $this->invoke(
            $this->newHealth(),
            'sendCalendarStepResult',
            [$conn, 'IT', 2024, Step::PARSES, Status::PASS, 'text']
        );

        $withFormat = json_decode($conn->sent[0], true);
        self::assertIsArray($withFormat);
        self::assertSame(
            ['type', 'text', 'classes', 'responsetype'],
            array_slice(array_keys($withFormat), 0, 4),
            'the legacy block keeps its shape and its order; the structured fields follow it'
        );
        self::assertSame('XML', $withFormat['responsetype']);
        self::assertArrayNotHasKey('responsetype', json_decode($conn->sent[1], true));
    }

    /**
     * `retrieveXmlErrors()` returns the errors as a list, so the frame's `text` and its `details` are
     * built from the same values instead of one being recovered by re-splitting the other. The old
     * shape — join here, split there — looked safe because every message goes through
     * `htmlspecialchars()`, but the ` in file: …` suffix interpolates the URI raw, so a separator
     * arriving in a filename would have split one error into two.
     */
    public function testXmlErrorsComeBackAsAListSoTextAndDetailsCannotDrift(): void
    {
        $xml = '<a><b></a>';

        libxml_use_internal_errors(true);
        ( new \DOMDocument() )->loadXML($xml);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        self::assertNotEmpty($errors, 'the fixture must actually produce libxml errors');

        $list = ( new \ReflectionMethod(Health::class, 'retrieveXmlErrors') )->invoke(null, $errors, explode("\n", $xml));

        self::assertIsArray($list);
        self::assertCount(count($errors), $list, 'one entry per error, not one joined string');
        self::assertContainsOnlyString($list);
        self::assertSame([], ( new \ReflectionMethod(Health::class, 'retrieveXmlErrors') )->invoke(null, [], []));
    }

    /**
     * Details are structured, and optional: a passing step has none and must not carry an empty
     * array that a client would have to distinguish from "no detail available".
     */
    public function testDetailsAreCarriedOnlyWhenThereAreAny(): void
    {
        $conn = self::stubConnection();
        $this->invoke(
            $this->newHealth(),
            'sendStepResult',
            [$conn, 'nation-roman-US-i18n', null, Step::PARSES, Status::FAIL, 'text', ['it.json: Syntax error'], null]
        );
        $this->invoke(
            $this->newHealth(),
            'sendStepResult',
            [$conn, 'nation-roman-US-i18n', null, Step::PARSES, Status::PASS, 'text', [], null]
        );

        self::assertSame(['it.json: Syntax error'], json_decode($conn->sent[0], true)['details']);
        self::assertArrayNotHasKey('details', json_decode($conn->sent[1], true));
    }

    /**
     * The `details` rule, at the one file-branch site that has something structured to say.
     *
     * A schema failure's text is the schema's own error line joined to the validator's message with
     * a newline — two things the emitter already holds, flattened into one string. `details` hands
     * the pieces back rather than making a client split prose it did not build. Sites with nothing
     * structured behind their text (an unreadable file, a missing schema) manufacture nothing, which
     * {@see HealthValidationDataErrorTest} pins from the other side.
     *
     * The validator's second line quotes an absolute path, so it is asserted structurally rather
     * than as a literal; the first line is `LitSchema`'s own and is pinned exactly.
     */
    public function testASchemaFailureCarriesThePartsItsTextFlattens(): void
    {
        Router::getApiPaths();

        $schema = ( new \ReflectionMethod(Health::class, 'retrieveSchemaForCategory') )->invoke(null, 'sourceDataCheck', 'proprium-de-tempore');
        self::assertIsString($schema);

        $conn       = self::stubConnection(4);
        $validation = (object) [
            'action'     => 'executeValidation',
            'category'   => 'sourceDataCheck',
            'validate'   => 'proprium-de-tempore',
            'sourceFile' => 'jsondata/x.json'
        ];

        ob_start();
        $this->invoke(
            $this->newHealth(),
            'processValidationData',
            ['{"litcal":"nope"}', $conn, $validation, 'jsondata/x.json', $schema, 'proprium-de-tempore', 'run-token-4', null]
        );
        ob_end_clean();

        self::assertCount(3, $conn->sent);
        $passed = json_decode($conn->sent[0], true);
        $failed = json_decode($conn->sent[2], true);
        self::assertIsArray($passed);
        self::assertIsArray($failed);

        self::assertArrayNotHasKey('details', $passed, 'a step that passed has no failures to detail');
        self::assertSame('error', $failed['type']);
        self::assertSame('.proprium-de-tempore.schema-valid', $failed['classes']);
        self::assertCount(2, $failed['details'], 'the schema error line and the validator message');
        self::assertSame('Schema validation error: Proprium de Tempore data not created / updated', $failed['details'][0]);
        self::assertSame(
            $failed['text'],
            implode(PHP_EOL, $failed['details']),
            'details must be exactly the pieces the text flattens — no more, and nothing invented'
        );
    }

    /**
     * `complete` is not a check and has no legacy class: projecting it would have to invent one,
     * and a frame classed `.<fragment>.` matches nothing. The terminal frame is emitted elsewhere
     * (#821), so reaching this method with it is a programming error and says so.
     *
     * The refusal is general, not a `COMPLETE` special case: any step missing from
     * `FRAME_CLASS_FOR_STEP` is refused the same way, so a case added later cannot quietly ship an
     * unmatchable `.<fragment>.` — which PHPStan would not catch, the const being typed
     * `array<string, string>` rather than as a shape.
     */
    public function testTheTerminalStepIsRefusedBecauseItHasNoLegacyClass(): void
    {
        $conn = self::stubConnection();

        $this->expectException(\LogicException::class);
        try {
            $this->invoke(
                $this->newHealth(),
                'sendStepResult',
                [$conn, 'temporale-roman', (object) ['id' => 'temporale:roman'], Step::COMPLETE, Status::PASS, 'text', null, null]
            );
        } finally {
            self::assertSame([], $conn->sent, 'nothing may be sent for a step that cannot be projected');
        }
    }
}
