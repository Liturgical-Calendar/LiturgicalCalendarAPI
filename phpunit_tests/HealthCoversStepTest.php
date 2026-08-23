<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests;

use LiturgicalCalendar\Api\Health;
use LiturgicalCalendar\Api\Models\ValidationsPath\CheckableInventory;
use LiturgicalCalendar\Api\Router;
use LiturgicalCalendar\Tests\Support\HealthQueueIsolationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ratchet\ConnectionInterface;

/**
 * The `covers` step: does a folder hold a file for every locale its owner declares?
 *
 * A different question from the other three steps, which ask whether what is present is well-formed.
 * The verdict is a subset test **by locale identity, never by count**: two files where two are declared
 * proves nothing unless they are the same two.
 */
#[CoversClass(Health::class)]
final class HealthCoversStepTest extends TestCase
{
    use HealthQueueIsolationTrait;

    public static function setUpBeforeClass(): void
    {
        Router::getApiPaths();
        CheckableInventory::reset();
    }

    public static function tearDownAfterClass(): void
    {
        CheckableInventory::reset();
    }

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
     * Run a folder check with an explicit expectation, bypassing the inventory so the expectation is
     * the test's own rather than whatever the repository's source data happens to declare today.
     *
     * @param ?list<string> $expectedLocales
     * @return list<\stdClass>
     */
    private function framesFor(string $folder, ?array $expectedLocales): array
    {
        $conn   = self::createStubConnection();
        $health = new Health();

        $method = new \ReflectionMethod(Health::class, 'runValidationSteps');
        $method->invoke(
            $health,
            $folder,
            'folder',
            \LiturgicalCalendar\Api\Enum\LitSchema::I18N->path(),
            'coverage probe',
            $conn,
            null,
            'sourceDataCheck',
            null,
            null,
            'coverage-probe',
            null,
            'req-covers',
            $expectedLocales
        );

        return array_values(array_map(
            static fn (string $raw): \stdClass => (object) json_decode($raw, false, 512, JSON_THROW_ON_ERROR),
            $conn->sent
        ));
    }

    /** @return list<\stdClass> */
    private function coversFrames(string $folder, ?array $expectedLocales): array
    {
        return array_values(array_filter(
            $this->framesFor($folder, $expectedLocales),
            static fn (\stdClass $f): bool => isset($f->step) && 'covers' === $f->step
        ));
    }

    /** The US national i18n folder holds exactly `en_US.json`. */
    private static function usI18nFolder(): string
    {
        return Router::$apiFilePath . 'jsondata/sourcedata/rite/roman/calendars/nations/US/i18n';
    }

    public function testAFolderHoldingEveryDeclaredLocalePasses(): void
    {
        $frames = $this->coversFrames(self::usI18nFolder(), ['en_US']);

        $this->assertCount(1, $frames, 'exactly one covers frame per folder check');
        $this->assertSame('pass', $frames[0]->status);
    }

    public function testADeclaredLocaleWithNoFileFailsAndIsNamed(): void
    {
        $frames = $this->coversFrames(self::usI18nFolder(), ['en_US', 'es_US']);

        $this->assertCount(1, $frames);
        $this->assertSame('fail', $frames[0]->status);
        $this->assertStringContainsString('es_US.json', (string) $frames[0]->text . json_encode($frames[0]->details ?? []));
    }

    public function testAFileForAnUndeclaredLocaleDoesNotFailButIsNamed(): void
    {
        // The folder holds en_US.json; nothing declares it. Holding data you do not declare is worth
        // seeing — it is how a stale `locales` declaration surfaces — but it is not a failure.
        $frames = $this->coversFrames(self::usI18nFolder(), []);

        $this->assertCount(1, $frames);
        $this->assertSame('pass', $frames[0]->status);
        $this->assertStringContainsString('en_US', (string) $frames[0]->text);
        $this->assertStringContainsString('not declared', (string) $frames[0]->text);
    }

    public function testAFailureNamesBothTheMissingAndTheUndeclared(): void
    {
        // The Europe-lectionary shape: declared locales with no file, *and* a file for a locale nothing
        // declares. Both belong in the failure text — the second is how a stale `locales` declaration
        // shows itself, and withholding it on the failing outcome hides it exactly when it is relevant.
        // The US i18n folder holds en_US.json alone; declare fr_CA and it_IT instead.
        $frames = $this->coversFrames(self::usI18nFolder(), ['fr_CA', 'it_IT']);

        $this->assertCount(1, $frames);
        $this->assertSame('fail', $frames[0]->status);

        $text = (string) $frames[0]->text . json_encode($frames[0]->details ?? []);
        $this->assertStringContainsString('fr_CA.json', $text, 'the missing declared locales must be named');
        $this->assertStringContainsString('it_IT.json', $text, 'the missing declared locales must be named');
        $this->assertStringContainsString('en_US', $text, 'the undeclared but present locale must be named too');
        $this->assertStringContainsString('not declared', $text);
    }

    public function testCountingIsNotEnough(): void
    {
        // One declared, one present, but not the same one. A count-based check would pass this.
        $frames = $this->coversFrames(self::usI18nFolder(), ['fr_CA']);

        $this->assertCount(1, $frames);
        $this->assertSame('fail', $frames[0]->status);
    }

    public function testAFolderWithNoExpectationEmitsNoCoversFrame(): void
    {
        $this->assertSame([], $this->coversFrames(self::usI18nFolder(), null));
    }

    public function testAnAbsentFolderStillReportsCoversAsFailed(): void
    {
        // Every arm must deliver one frame per advertised step: a client sizes its cards from `steps`,
        // so a short arm leaves a card no frame ever paints and the phase waits out the watchdog.
        $frames = $this->coversFrames(Router::$apiFilePath . 'jsondata/sourcedata/nope', ['en']);

        $this->assertCount(1, $frames);
        $this->assertSame('fail', $frames[0]->status);
    }
}
