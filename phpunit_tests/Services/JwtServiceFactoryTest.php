<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services;

use LiturgicalCalendar\Api\Services\JwtServiceFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(JwtServiceFactory::class)]
final class JwtServiceFactoryTest extends TestCase
{
    private const VALID_SECRET = 'test-fixture-secret-not-a-placeholder-32+';

    /** @var array<string,mixed> */
    private array $savedEnv = [];

    /** @var array<string,string|false> */
    private array $savedGetenv = [];

    /** @var array<string,mixed> */
    private array $savedServer = [];

    private const UNSET = "\0__unset__\0";

    /** @var list<string> */
    private const MANAGED = ['JWT_SECRET', 'JWT_ALGORITHM', 'JWT_EXPIRY', 'JWT_REFRESH_EXPIRY', 'APP_ENV'];

    /**
     * All three homes are cleared, not just `$_ENV`.
     *
     * Since the factory reads `getenv()` ahead of `$_ENV`, clearing only the latter would let a
     * developer who happens to export `JWT_SECRET` in their shell silently change what every test in
     * this file is measuring — and it would pass, against the wrong value.
     */
    protected function setUp(): void
    {
        foreach (self::MANAGED as $k) {
            $this->savedEnv[$k]    = array_key_exists($k, $_ENV) ? $_ENV[$k] : self::UNSET;
            $this->savedServer[$k] = array_key_exists($k, $_SERVER) ? $_SERVER[$k] : self::UNSET;
            $this->savedGetenv[$k] = getenv($k);
            unset($_ENV[$k], $_SERVER[$k]);
            putenv($k);
        }
        $_ENV['APP_ENV'] = 'test';
    }

    protected function tearDown(): void
    {
        foreach (self::MANAGED as $k) {
            $env = $this->savedEnv[$k];
            if ($env === self::UNSET) {
                unset($_ENV[$k]);
            } else {
                $_ENV[$k] = $env;
            }

            $server = $this->savedServer[$k];
            if ($server === self::UNSET) {
                unset($_SERVER[$k]);
            } else {
                $_SERVER[$k] = $server;
            }

            $orig = $this->savedGetenv[$k];
            if ($orig === false) {
                putenv($k);
            } else {
                putenv($k . '=' . $orig);
            }
        }
    }

    public function testMissingSecretThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('JWT_SECRET');
        JwtServiceFactory::fromEnv();
    }

    public function testEmptySecretThrows(): void
    {
        $_ENV['JWT_SECRET'] = '';
        $this->expectException(\RuntimeException::class);
        JwtServiceFactory::fromEnv();
    }

    public function testShortSecretThrows(): void
    {
        $_ENV['JWT_SECRET'] = 'too-short';
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('at least 32');
        JwtServiceFactory::fromEnv();
    }

    public function testValidEnvProducesService(): void
    {
        $_ENV['JWT_SECRET'] = self::VALID_SECRET;
        $svc                = JwtServiceFactory::fromEnv();
        self::assertSame(3600, $svc->getExpiry());
        self::assertSame(604800, $svc->getRefreshExpiry());
    }

    public function testAlgorithmEnvIsValidated(): void
    {
        $_ENV['JWT_SECRET']    = self::VALID_SECRET;
        $_ENV['JWT_ALGORITHM'] = 'RS256';
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('JWT_ALGORITHM');
        JwtServiceFactory::fromEnv();
    }

    public function testNonNumericExpiryIsRejected(): void
    {
        $_ENV['JWT_SECRET'] = self::VALID_SECRET;
        $_ENV['JWT_EXPIRY'] = 'forever';
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('JWT_EXPIRY');
        JwtServiceFactory::fromEnv();
    }

    public function testNonPositiveExpiryIsRejected(): void
    {
        $_ENV['JWT_SECRET'] = self::VALID_SECRET;
        $_ENV['JWT_EXPIRY'] = '0';
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('positive');
        JwtServiceFactory::fromEnv();
    }

    public function testNonNumericRefreshExpiryIsRejected(): void
    {
        $_ENV['JWT_SECRET']         = self::VALID_SECRET;
        $_ENV['JWT_REFRESH_EXPIRY'] = 'soon';
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('JWT_REFRESH_EXPIRY');
        JwtServiceFactory::fromEnv();
    }

    public function testNonPositiveRefreshExpiryIsRejected(): void
    {
        $_ENV['JWT_SECRET']         = self::VALID_SECRET;
        $_ENV['JWT_REFRESH_EXPIRY'] = '-5';
        $this->expectException(\RuntimeException::class);
        JwtServiceFactory::fromEnv();
    }

    public function testCustomExpiryAndAlgorithmAreApplied(): void
    {
        $_ENV['JWT_SECRET']         = self::VALID_SECRET;
        $_ENV['JWT_ALGORITHM']      = 'HS384';
        $_ENV['JWT_EXPIRY']         = '900';
        $_ENV['JWT_REFRESH_EXPIRY'] = '86400';

        $svc = JwtServiceFactory::fromEnv();
        self::assertSame(900, $svc->getExpiry());
        self::assertSame(86400, $svc->getRefreshExpiry());
    }

    public function testPlaceholderSecretRejectedInProduction(): void
    {
        $_ENV['APP_ENV'] = 'production';
        // 32+ chars but obviously a placeholder.
        $_ENV['JWT_SECRET'] = 'change-this-please-its-the-default-x';
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('placeholder');
        JwtServiceFactory::fromEnv();
    }

    public function testPlaceholderSecretAllowedInDevelopment(): void
    {
        $_ENV['APP_ENV']    = 'development';
        $_ENV['JWT_SECRET'] = 'change-this-please-its-the-default-x';
        // Should not throw; the placeholder check is production-only.
        $svc = JwtServiceFactory::fromEnv();
        self::assertSame(3600, $svc->getExpiry());
    }

    // ------------------------------------------------------- configuration that lives in the environment

    /**
     * The shape a CLI entrypoint actually sees, and the regression this guards.
     *
     * `variables_order` is `GPCS`, so an inherited environment variable never reaches `$_ENV`; the CLI
     * SAPI merges the environment into `$_SERVER` instead; and `Dotenv::createImmutable()` then
     * declines to write the value from `.env.local` into `$_ENV`, because it can already see one.
     * Reading `$_ENV` alone therefore found nothing and threw — which is how the WebSocket server came
     * to report `Caller verification: none` while the HTTP API, whose SAPI rebuilds `$_SERVER` per
     * request, signed tokens from the very same file.
     *
     * Reproduced here by hand rather than by spawning a process: the three homes are set exactly as
     * PHP leaves them under CLI.
     */
    public function testSecretIsFoundWhenItLivesOnlyInTheProcessEnvironment(): void
    {
        putenv('JWT_SECRET=' . self::VALID_SECRET);
        $_SERVER['JWT_SECRET'] = self::VALID_SECRET; // as the CLI SAPI merges it
        unset($_ENV['JWT_SECRET']);                  // as Dotenv leaves it

        $svc = JwtServiceFactory::fromEnv();

        self::assertSame(3600, $svc->getExpiry());
    }

    /**
     * `is_numeric('1.5')` is true and `(int) '1.5'` is 1, so a fractional lifetime used to be accepted
     * and silently became one second. A lifetime is a whole number of seconds or it is a mistake.
     */
    public function testFractionalExpiryIsRejected(): void
    {
        $_ENV['JWT_SECRET'] = self::VALID_SECRET;
        $_ENV['JWT_EXPIRY'] = '1.5';
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('JWT_EXPIRY');
        JwtServiceFactory::fromEnv();
    }

    public function testFractionalRefreshExpiryIsRejected(): void
    {
        $_ENV['JWT_SECRET']         = self::VALID_SECRET;
        $_ENV['JWT_REFRESH_EXPIRY'] = '86400.5';
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('JWT_REFRESH_EXPIRY');
        JwtServiceFactory::fromEnv();
    }

    /**
     * Scientific notation is numeric too, and casts to something unrecognisable.
     */
    public function testExponentialExpiryIsRejected(): void
    {
        $_ENV['JWT_SECRET'] = self::VALID_SECRET;
        $_ENV['JWT_EXPIRY'] = '1e3';
        $this->expectException(\RuntimeException::class);
        JwtServiceFactory::fromEnv();
    }

    public function testEveryOptionIsFoundInTheProcessEnvironment(): void
    {
        putenv('JWT_SECRET=' . self::VALID_SECRET);
        putenv('JWT_ALGORITHM=HS512');
        putenv('JWT_EXPIRY=120');
        putenv('JWT_REFRESH_EXPIRY=240');

        $svc = JwtServiceFactory::fromEnv();

        self::assertSame(120, $svc->getExpiry());
        self::assertSame(240, $svc->getRefreshExpiry());
    }

    /**
     * A pre-existing environment variable wins over the file, which is Dotenv's own immutable rule —
     * so the factory must not disagree with the loader about which value is in force.
     */
    public function testTheProcessEnvironmentWinsOverEnvSuperglobal(): void
    {
        putenv('JWT_EXPIRY=111');
        $_ENV['JWT_EXPIRY'] = '222';
        $_ENV['JWT_SECRET'] = self::VALID_SECRET;

        self::assertSame(111, JwtServiceFactory::fromEnv()->getExpiry());
    }

    /**
     * An empty environment variable is not an answer. `getenv()` returning `''` must fall through to
     * `$_ENV` rather than being taken as "configured, to nothing" — otherwise a stray `export
     * JWT_SECRET=` would take the service down with a confusing message.
     */
    public function testAnEmptyProcessEnvironmentValueFallsBackToEnvSuperglobal(): void
    {
        putenv('JWT_SECRET=');
        $_ENV['JWT_SECRET'] = self::VALID_SECRET;

        $svc = JwtServiceFactory::fromEnv();

        self::assertSame(3600, $svc->getExpiry());
    }
}
