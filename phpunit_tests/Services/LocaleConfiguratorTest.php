<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services;

use LiturgicalCalendar\Api\Enum\LitLocale;
use LiturgicalCalendar\Api\Http\Exception\ServiceUnavailableException;
use LiturgicalCalendar\Api\Router;
use LiturgicalCalendar\Api\Services\LocaleConfigurator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(LocaleConfigurator::class)]
final class LocaleConfiguratorTest extends TestCase
{
    private string $savedApiFilePath    = '';
    private string|false $savedLanguage = false;
    private string $savedIcuDefault     = 'en';

    protected function setUp(): void
    {
        $this->savedApiFilePath = isset(Router::$apiFilePath) ? Router::$apiFilePath : '';
        $this->savedLanguage    = getenv('LANGUAGE');
        $this->savedIcuDefault  = \Locale::getDefault();

        // JsonData::FOLDER->path() prefixes Router::$apiFilePath; point it at the repo root.
        Router::$apiFilePath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR;

        // Start from a clean process-global locale.
        setlocale(LC_ALL, 'C');
        putenv('LANGUAGE');
    }

    protected function tearDown(): void
    {
        Router::$apiFilePath = $this->savedApiFilePath;
        setlocale(LC_ALL, 'C');
        if ($this->savedLanguage === false) {
            putenv('LANGUAGE');
        } else {
            putenv('LANGUAGE=' . $this->savedLanguage);
        }
        \Locale::setDefault($this->savedIcuDefault);
    }

    public function testRegionlessLanguageResolvesViaLikelySubtags(): void
    {
        $result = LocaleConfigurator::configure('en');
        self::assertSame('en', $result->primaryLanguage);
        self::assertSame('en_US', $result->runtimeLocale);
        self::assertFalse($result->isLatin);
        self::assertStringStartsWith('en_US', (string) getenv('LANGUAGE'));
    }

    public function testFrenchResolvesToInstalledRegionVariant(): void
    {
        $result = LocaleConfigurator::configure('fr');
        self::assertSame('fr', $result->primaryLanguage);
        self::assertSame('fr_FR', $result->runtimeLocale);
        self::assertFalse($result->isLatin);
    }

    public function testRegionBearingLocaleIsPreserved(): void
    {
        $result = LocaleConfigurator::configure('it_IT');
        self::assertSame('it', $result->primaryLanguage);
        self::assertSame('it_IT', $result->runtimeLocale);
    }

    public function testLatinResetsAndClearsLanguage(): void
    {
        putenv('LANGUAGE=it_IT.utf8:it_IT:it:en'); // simulate a prior translated request
        foreach (['la', 'la_VA'] as $latin) {
            $result = LocaleConfigurator::configure($latin);
            self::assertTrue($result->isLatin, "{$latin} should take the Latin reset branch");
            self::assertSame(LitLocale::LATIN_PRIMARY_LANGUAGE, $result->primaryLanguage);
            self::assertSame(LitLocale::LATIN_PRIMARY_LANGUAGE, $result->runtimeLocale);
            self::assertFalse(getenv('LANGUAGE'), 'Latin must clear the leaked LANGUAGE env var');
        }
    }

    public function testOverridesLeakedLanguageFromPriorRequest(): void
    {
        putenv('LANGUAGE=it_IT.utf8:it_IT:it:en');
        LocaleConfigurator::configure('fr');
        self::assertStringStartsWith('fr', (string) getenv('LANGUAGE'));
    }

    public function testThrowsWhenNoInstalledLocaleForLanguage(): void
    {
        $this->expectException(ServiceUnavailableException::class);
        LocaleConfigurator::configure('zz');
    }
}
