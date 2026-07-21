<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Models\Calendar\Precedence;

use LiturgicalCalendar\Api\Enum\LitGrade;
use LiturgicalCalendar\Api\Enum\LitSeason;
use LiturgicalCalendar\Api\Enum\LitLocale;
use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\LocaleDateFormatter;
use LiturgicalCalendar\Api\Models\Calendar\LiturgicalEventCollection;
use LiturgicalCalendar\Api\Models\Calendar\Precedence\AmbrosianPrecedenceResolver;
use LiturgicalCalendar\Api\Models\Calendar\Precedence\PrecedenceContext;
use LiturgicalCalendar\Api\Params\CalendarParams;
use LiturgicalCalendar\Api\Router;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Coincidence + suppression core of `AmbrosianPrecedenceResolver`: on a
 * contested date (more than one event), the event with the lowest
 * `AmbrosianLiturgicalDayRank::rankOf()` value wins and every other event on
 * that date is suppressed (removed from the active collection, recorded on
 * the suppressed-events ledger, and explained via a message). Task 5 only
 * implements the suppression fallback -- the transfer rules that the real
 * Ambrosian Tabella prescribes ahead of suppression are Tasks 6-7.
 */
#[CoversClass(AmbrosianPrecedenceResolver::class)]
final class AmbrosianPrecedenceResolverTest extends TestCase
{
    use AmbrosianEventFactoryTrait;

    private static string $originalPrimaryLanguage = '';
    private static string $originalRuntimeLocale   = '';

    public static function setUpBeforeClass(): void
    {
        // JsonData::path() (used transitively by LiturgicalEventCollection's
        // lectionary bootstrap) concatenates Router::$apiFilePath; populate it.
        Router::getApiPaths();

        // Capture the global locale statics before buildContext() forces Italian,
        // so tearDownAfterClass() can restore them and the mutation does not leak
        // into other suites sharing the same process.
        self::$originalPrimaryLanguage = LitLocale::$PRIMARY_LANGUAGE;
        self::$originalRuntimeLocale   = LitLocale::$RUNTIME_LOCALE;
    }

    public static function tearDownAfterClass(): void
    {
        // Restore the global locale statics (runs even when a test fails).
        LitLocale::$PRIMARY_LANGUAGE = self::$originalPrimaryLanguage;
        LitLocale::$RUNTIME_LOCALE   = self::$originalRuntimeLocale;
    }

    /**
     * Builds a PrecedenceContext wrapping a real LiturgicalEventCollection,
     * mirroring how CalendarHandler wires a PrecedenceContext and how
     * AmbrosianTemporaleHarnessTrait wires a TemporaleContext.
     *
     * @param array<string> $messages
     */
    private function buildContext(int $year, array &$messages): PrecedenceContext
    {
        // Force the runtime primary language so LocaleDateFormatter + i18n load deterministically.
        LitLocale::$PRIMARY_LANGUAGE = 'it';
        LitLocale::$RUNTIME_LOCALE   = 'it_IT';

        $params = new CalendarParams();
        $params->setParams(['year' => $year]);
        $params->setRite(Rite::AMBROSIAN);

        $cal = new LiturgicalEventCollection($params);

        return new PrecedenceContext(
            $cal,
            $params,
            new LocaleDateFormatter(LitLocale::$RUNTIME_LOCALE),
            $messages
        );
    }

    public function testHigherRankSolemnitySuppressesLowerRankMemorialOnSameDate(): void
    {
        $messages = [];
        $ctx      = $this->buildContext(2026, $messages);

        // Rank 5: comune, non-dominical saint solemnity.
        $solemnity = $this->makeEvent([
            'key'       => 'SomeSaintSolemnity',
            'date'      => '2026-07-20',
            'grade'     => LitGrade::HIGHER_SOLEMNITY,
            'season'    => LitSeason::ORDINARY_TIME,
            'dominical' => false,
            'proper'    => false,
        ]);

        // Rank 10: comune obligatory memorial, same date.
        $memorial = $this->makeEvent([
            'key'    => 'SomeSaintMemorial',
            'date'   => '2026-07-20',
            'grade'  => LitGrade::MEMORIAL,
            'proper' => false,
        ]);

        $ctx->cal->addLiturgicalEvent('SomeSaintSolemnity', $solemnity);
        $ctx->cal->addLiturgicalEvent('SomeSaintMemorial', $memorial);

        // Sanity check before resolving: both events are present and uncontested.
        self::assertNotNull($ctx->cal->getLiturgicalEvent('SomeSaintSolemnity'));
        self::assertNotNull($ctx->cal->getLiturgicalEvent('SomeSaintMemorial'));
        self::assertFalse($ctx->cal->isSuppressed('SomeSaintMemorial'));

        ( new AmbrosianPrecedenceResolver() )->resolve($ctx);

        // The higher-ranking solemnity remains, on the same date.
        $winner = $ctx->cal->getLiturgicalEvent('SomeSaintSolemnity');
        self::assertNotNull($winner);
        self::assertSame('2026-07-20', $winner->date->format('Y-m-d'));

        // The lower-ranking memorial is no longer retrievable from the active collection...
        self::assertNull($ctx->cal->getLiturgicalEvent('SomeSaintMemorial'));

        // ...but is recorded on the suppressed-events ledger...
        self::assertTrue($ctx->cal->isSuppressed('SomeSaintMemorial'));
        $suppressed = $ctx->cal->getSuppressedEventByKey('SomeSaintMemorial');
        self::assertNotNull($suppressed);
        self::assertSame('SomeSaintMemorial', $suppressed->event_key);

        // ...and a message explains the suppression.
        self::assertCount(1, $messages);
        self::assertStringContainsString('SomeSaintMemorial', $messages[0]);
        self::assertStringContainsString('SomeSaintSolemnity', $messages[0]);
    }

    public function testUncontestedDateIsLeftUntouched(): void
    {
        $messages = [];
        $ctx      = $this->buildContext(2026, $messages);

        $memorial = $this->makeEvent([
            'key'    => 'LoneMemorial',
            'date'   => '2026-07-21',
            'grade'  => LitGrade::MEMORIAL,
            'proper' => false,
        ]);

        $ctx->cal->addLiturgicalEvent('LoneMemorial', $memorial);

        ( new AmbrosianPrecedenceResolver() )->resolve($ctx);

        self::assertNotNull($ctx->cal->getLiturgicalEvent('LoneMemorial'));
        self::assertFalse($ctx->cal->isSuppressed('LoneMemorial'));
        self::assertCount(0, $messages);
    }
}
