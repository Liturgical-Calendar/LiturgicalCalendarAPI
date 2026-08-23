<?php

namespace LiturgicalCalendar\Api\Tests\Handlers;

use LiturgicalCalendar\Api\Enum\LitGrade;
use LiturgicalCalendar\Api\Enum\LitLocale;
use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Handlers\CalendarHandler;
use LiturgicalCalendar\Api\Models\Calendar\LiturgicalEventCollection;
use LiturgicalCalendar\Api\Models\Lectionary\ReadingsFerial;
use LiturgicalCalendar\Api\Models\RegionalData\DiocesanData\DiocesanData;
use LiturgicalCalendar\Api\Params\CalendarParams;
use LiturgicalCalendar\Api\Router;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the `setProperty` branch of `applyAmbrosianDiocesanCalendar()` with hand-built
 * diocesan data, so it does not depend on the on-disk re-authoring.
 */
final class CalendarHandlerAmbrosianDiocesanSetPropertyTest extends TestCase
{
    /**
     * Default to '' so tearDownAfterClass can always restore Router::$apiPath to a known
     * string value, even if it was uninitialised before setUpBeforeClass ran (mirrors
     * AbstractHandlerTestCase, which this test does not extend because it needs neither the
     * JWT_SECRET nor the database skip logic that lives there).
     */
    private static string $savedApiPath     = '';
    private static string $savedApiFilePath = '';

    /**
     * `runOverlayWith()` writes `LitLocale::$PRIMARY_LANGUAGE`/`$RUNTIME_LOCALE` on every call;
     * saved/restored the same way every sibling Ambrosian test does (e.g.
     * `CalendarHandlerAmbrosianDiocesanTest`), so this suite cannot leak `it`/`it_IT` into
     * whatever test runs next in-process — exactly what `CalendarHandlerLocaleLeakTest` guards
     * against.
     */
    private static string $originalPrimaryLanguage = '';
    private static string $originalRuntimeLocale   = '';

    public static function setUpBeforeClass(): void
    {
        self::$savedApiPath     = isset(Router::$apiPath) ? Router::$apiPath : '';
        self::$savedApiFilePath = isset(Router::$apiFilePath) ? Router::$apiFilePath : '';
        Router::$apiPath        = '';
        // apiFilePath is used as a prefix when JsonData enum cases build filesystem paths
        // like '<root>/jsondata/schemas/Foo.json' (CalendarMetadataProvider, reached via
        // CalendarParams::setParams() -> setRite()/setParams() metadata lookups).
        Router::$apiFilePath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR;

        self::$originalPrimaryLanguage = LitLocale::$PRIMARY_LANGUAGE;
        self::$originalRuntimeLocale   = LitLocale::$RUNTIME_LOCALE;
    }

    public static function tearDownAfterClass(): void
    {
        Router::$apiPath     = self::$savedApiPath;
        Router::$apiFilePath = self::$savedApiFilePath;

        LitLocale::$PRIMARY_LANGUAGE = self::$originalPrimaryLanguage;
        LitLocale::$RUNTIME_LOCALE   = self::$originalRuntimeLocale;
    }

    /**
     * Builds a handler whose comune sanctorale is already loaded, then swaps in synthetic
     * diocesan data and invokes only the diocesan overlay.
     *
     * @param array<int,array<string,mixed>> $litcal
     * @return array{0:LiturgicalEventCollection,1:CalendarHandler}
     */
    private function runOverlayWith(array $litcal, int $year = 2025): array
    {
        LitLocale::$PRIMARY_LANGUAGE = 'it';
        LitLocale::$RUNTIME_LOCALE   = 'it_IT';

        $params = new CalendarParams();
        $params->setRite(Rite::AMBROSIAN);
        $params->setParams(['year' => $year, 'locale' => 'it']);
        $params->DiocesanCalendar = 'lugano_ch';

        $handler    = new CalendarHandler([], Rite::AMBROSIAN);
        $handlerRef = new \ReflectionClass($handler);

        $paramsProp = $handlerRef->getProperty('CalendarParams');
        $paramsProp->setAccessible(true);
        $paramsProp->setValue($handler, $params);

        $calProp = $handlerRef->getProperty('Cal');
        $calProp->setAccessible(true);
        $calProp->setValue($handler, new LiturgicalEventCollection($params));

        $formatterProp = $handlerRef->getProperty('localeDateFormatter');
        $formatterProp->setAccessible(true);
        $formatterProp->setValue($handler, new \LiturgicalCalendar\Api\LocaleDateFormatter(LitLocale::$RUNTIME_LOCALE));

        // Load the comune sanctorale so the keys the overlay targets already exist.
        $sanctorale = $handlerRef->getMethod('addAmbrosianSanctoraleToCalendar');
        $sanctorale->setAccessible(true);
        $sanctorale->invoke($handler);

        $diocesanProp = $handlerRef->getProperty('DiocesanData');
        $diocesanProp->setAccessible(true);
        $diocesanProp->setValue($handler, DiocesanData::fromArray([
            'litcal'   => $litcal,
            'metadata' => [
                'diocese_id'   => 'lugano_ch',
                'diocese_name' => 'Lugano',
                'nation'       => 'CH',
                'locales'      => ['it_IT', 'la_VA'],
                'timezone'     => 'Europe/Zurich',
                'rite'         => 'ambrosian',
            ],
        ]));

        $overlay = $handlerRef->getMethod('applyAmbrosianDiocesanCalendar');
        $overlay->setAccessible(true);
        $overlay->invoke($handler);

        /** @var LiturgicalEventCollection $cal */
        $cal = $calProp->getValue($handler);

        return [$cal, $handler];
    }

    public function testSetPropertyGradeChangesTheComuneEventInPlace(): void
    {
        [$cal ] = $this->runOverlayWith([
            [
                'liturgical_event' => ['event_key' => 'StsProtaseGervase', 'grade' => 3],
                'metadata'         => ['action' => 'setProperty', 'property' => 'grade', 'since_year' => 2024, 'form_rownum' => 1],
            ],
        ]);

        $event = $cal->getLiturgicalEvent('StsProtaseGervase');
        self::assertNotNull($event);
        self::assertSame(LitGrade::MEMORIAL, $event->grade, 'The diocesan downgrade must apply in place.');
        self::assertFalse(
            $cal->isSuppressed('StsProtaseGervase'),
            'An in-place property change must never record the event as suppressed.'
        );
        self::assertInstanceOf(
            ReadingsFerial::class,
            $event->readings,
            'A downgrade to MEMORIAL must carry the ferial placeholder.'
        );
    }

    public function testSetPropertyCommonChangesTheComuneEventInPlace(): void
    {
        [$cal ] = $this->runOverlayWith([
            [
                'liturgical_event' => ['event_key' => 'StsProtaseGervase', 'common' => ['Proper']],
                'metadata'         => ['action' => 'setProperty', 'property' => 'common', 'since_year' => 2024, 'form_rownum' => 1],
            ],
        ]);

        $event = $cal->getLiturgicalEvent('StsProtaseGervase');
        self::assertNotNull($event);
        self::assertSame(['Proper'], $event->common->jsonSerialize());
    }

    /**
     * `DiocesanLitCalItemSetPropertyName` carries only an `event_key`; its `$name` is stamped by
     * `DiocesanData::setNames()` from the diocese's i18n file before the dispatch loop runs (see
     * `DiocesanLitCalItemSetPropertyName`'s own class docblock). `StFrancisOfAssisi` is used here
     * because its comune name (`propriumdesanctis_2024/i18n/it.json`: "S. Francesco d'Assisi,
     * patrono d'Italia") differs from `lugano_ch`'s diocesan name for the same key
     * (`lugano_ch/i18n/it_IT.json`: "S. Francesco d'Assisi") — so a passing assertion actually
     * proves the rename applied, rather than merely being consistent with a no-op.
     */
    public function testSetPropertyNameChangesTheComuneEventInPlace(): void
    {
        [$cal ] = $this->runOverlayWith([
            [
                'liturgical_event' => ['event_key' => 'StFrancisOfAssisi'],
                'metadata'         => ['action' => 'setProperty', 'property' => 'name', 'since_year' => 2024, 'form_rownum' => 1],
            ],
        ]);

        $event = $cal->getLiturgicalEvent('StFrancisOfAssisi');
        self::assertNotNull($event);
        self::assertSame(
            "S. Francesco d'Assisi",
            $event->name,
            'The diocesan i18n name (lugano_ch) must replace the comune name in place.'
        );
    }

    public function testKeyIsNotDuplicatedByAnOverride(): void
    {
        [$cal ] = $this->runOverlayWith([
            [
                'liturgical_event' => ['event_key' => 'StsProtaseGervase', 'grade' => 3],
                'metadata'         => ['action' => 'setProperty', 'property' => 'grade', 'since_year' => 2024, 'form_rownum' => 1],
            ],
        ]);

        $matching = array_filter(
            $cal->getLiturgicalEvents()->getKeys(),
            static fn (string $key): bool => str_contains($key, 'StsProtaseGervase')
        );

        self::assertSame(['StsProtaseGervase'], array_values($matching), 'Exactly one key must carry this celebration.');
    }

    public function testRowOutsideItsYearWindowIsSkipped(): void
    {
        [$cal ] = $this->runOverlayWith([
            [
                'liturgical_event' => ['event_key' => 'StsProtaseGervase', 'grade' => 3],
                'metadata'         => ['action' => 'setProperty', 'property' => 'grade', 'since_year' => 2030, 'form_rownum' => 1],
            ],
        ], 2025);

        $event = $cal->getLiturgicalEvent('StsProtaseGervase');
        self::assertNotNull($event);
        self::assertSame(LitGrade::FEAST, $event->grade, 'A row whose since_year has not been reached must not apply.');
    }

    /**
     * `BeatoManfredoSettala` is used here rather than a made-up key: `applyAmbrosianDiocesanCalendar()`
     * calls `DiocesanData::setNames()` over every row in `$litcal` before the per-row loop runs, and
     * that call throws `\ValueError` for any `event_key` absent from the diocese's i18n translations
     * file — so a genuinely nonexistent key would fail before reaching the code this task changes.
     * `BeatoManfredoSettala` IS a real key in `lugano_ch`'s i18n file (it is diocesan-proper there),
     * but this test's `runOverlayWith()` only loads the comune sanctorale into `$this->Cal` — it is
     * never added by a `createNew` row here — so it is absent from the calendar this setProperty row
     * targets, which is exactly the case under test.
     */
    public function testSetPropertyOnAnAbsentKeyIsANoOpAndRecordsAMessage(): void
    {
        [$cal, $handler] = $this->runOverlayWith([
            [
                'liturgical_event' => ['event_key' => 'BeatoManfredoSettala', 'grade' => 3],
                'metadata'         => ['action' => 'setProperty', 'property' => 'grade', 'since_year' => 2024, 'form_rownum' => 1],
            ],
        ]);

        self::assertNull(
            $cal->getLiturgicalEvent('BeatoManfredoSettala'),
            'A setProperty row must never create the event it fails to find.'
        );

        $messagesProp = ( new \ReflectionClass($handler) )->getProperty('Messages');
        $messagesProp->setAccessible(true);
        /** @var string[] $messages */
        $messages = $messagesProp->getValue($handler);

        $matching = array_filter(
            $messages,
            static fn (string $message): bool => str_contains($message, 'BeatoManfredoSettala')
        );

        self::assertNotEmpty($matching, 'A skipped setProperty row must record an explanatory message.');
    }

    /**
     * A `createNew` row whose `event_key` collides with an event already in `$this->Cal` (here,
     * the comune `StsProtaseGervase`, grade FEAST) is a data-authoring error, not a sanctioned
     * override: overriding an existing celebration is what `setProperty` is for. The row must be
     * skipped with a message, not silently registered alongside the comune definition.
     *
     * The third assertion is the one that actually pins the bug this task fixed: before the
     * collision guard, `LiturgicalEventCollection::addLiturgicalEvent()` had no key-collision
     * check, so the colliding `createNew` row was added to `getMemorials()` (its own grade,
     * MEMORIAL) *as well as* the comune definition staying in `getFeasts()` (its original grade,
     * FEAST) - the same event key registered under two grade sub-collections at once, which is
     * exactly what `CalendarHandler.php`'s `metadata->feasts`/`metadata->memorials` response
     * fields serialize directly from. The first two assertions (grade unchanged, message
     * recorded) would have passed even with that double registration.
     */
    public function testCreateNewRowCollidingWithAnExistingKeyIsSkippedNotDuplicated(): void
    {
        [$cal, $handler] = $this->runOverlayWith([
            [
                'liturgical_event' => [
                    'event_key' => 'StsProtaseGervase',
                    'day'       => 19,
                    'month'     => 6,
                    'color'     => ['red'],
                    'grade'     => 3,
                    'common'    => ['Martyrs:For Several Martyrs'],
                ],
                'metadata'         => ['since_year' => 2024, 'form_rownum' => 1],
            ],
        ]);

        $event = $cal->getLiturgicalEvent('StsProtaseGervase');
        self::assertNotNull($event);
        self::assertSame(
            LitGrade::FEAST,
            $event->grade,
            'The comune definition must survive untouched; the colliding createNew row must not overwrite it.'
        );

        $messagesProp = ( new \ReflectionClass($handler) )->getProperty('Messages');
        $messagesProp->setAccessible(true);
        /** @var string[] $messages */
        $messages = $messagesProp->getValue($handler);

        $matching = array_filter(
            $messages,
            static fn (string $message): bool => str_contains($message, 'StsProtaseGervase')
        );
        self::assertNotEmpty($matching, 'A skipped colliding createNew row must record an explanatory message.');

        self::assertTrue(
            $cal->getFeasts()->hasKey('StsProtaseGervase'),
            'The comune event must remain registered under its original grade (FEAST).'
        );
        self::assertFalse(
            $cal->getMemorials()->hasKey('StsProtaseGervase'),
            'The colliding row must NOT register the event a second time under its own (MEMORIAL) grade sub-collection.'
        );
    }
}
