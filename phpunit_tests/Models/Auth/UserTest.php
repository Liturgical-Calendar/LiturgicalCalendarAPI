<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Models\Auth;

use LiturgicalCalendar\Api\Models\Auth\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(User::class)]
final class UserTest extends TestCase
{
    private const UNSET = "\0__unset__\0";

    /** @var array<string,mixed> */
    private array $savedEnv = [];

    private const VARS = ['ADMIN_USERNAME', 'ADMIN_PASSWORD_HASH', 'APP_ENV'];

    protected function setUp(): void
    {
        foreach (self::VARS as $k) {
            $this->savedEnv[$k] = array_key_exists($k, $_ENV) ? $_ENV[$k] : self::UNSET;
            unset($_ENV[$k]);
        }

        // Reset the static dev password cache between tests for hermeticity.
        $devCache = new \ReflectionProperty(User::class, 'devPasswordHash');
        $devCache->setValue(null, null);
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $k => $v) {
            if ($v === self::UNSET) {
                unset($_ENV[$k]);
            } else {
                $_ENV[$k] = $v;
            }
        }
    }

    public function testConstructorAndDefaults(): void
    {
        $user = new User('alice', 'hashed');
        self::assertSame('alice', $user->username);
        self::assertSame('hashed', $user->passwordHash);
        self::assertSame(['admin'], $user->roles);
        self::assertTrue($user->hasRole('admin'));
        self::assertFalse($user->hasRole('developer'));
    }

    public function testToArray(): void
    {
        $user = new User('bob', 'h', ['developer', 'admin']);
        self::assertSame(['username' => 'bob', 'roles' => ['developer', 'admin']], $user->toArray());
    }

    public function testAuthenticateWithExplicitHash(): void
    {
        $_ENV['APP_ENV']             = 'production';
        $_ENV['ADMIN_USERNAME']      = 'admin';
        $_ENV['ADMIN_PASSWORD_HASH'] = password_hash('sekret', PASSWORD_BCRYPT);

        $user = User::authenticate('admin', 'sekret');
        self::assertNotNull($user);
        self::assertSame('admin', $user->username);
    }

    public function testAuthenticateRejectsWrongUsername(): void
    {
        $_ENV['APP_ENV']             = 'production';
        $_ENV['ADMIN_USERNAME']      = 'admin';
        $_ENV['ADMIN_PASSWORD_HASH'] = password_hash('sekret', PASSWORD_BCRYPT);
        self::assertNull(User::authenticate('not-admin', 'sekret'));
    }

    public function testAuthenticateRejectsWrongPassword(): void
    {
        $_ENV['APP_ENV']             = 'production';
        $_ENV['ADMIN_USERNAME']      = 'admin';
        $_ENV['ADMIN_PASSWORD_HASH'] = password_hash('sekret', PASSWORD_BCRYPT);
        self::assertNull(User::authenticate('admin', 'wrong'));
    }

    public function testAuthenticateRequiresAppEnv(): void
    {
        // No APP_ENV set.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('APP_ENV must be set');
        User::authenticate('admin', 'pw');
    }

    public function testAuthenticateRejectsInvalidAppEnv(): void
    {
        $_ENV['APP_ENV'] = 'qa';
        $this->expectException(\RuntimeException::class);
        User::authenticate('admin', 'pw');
    }

    public function testAuthenticateProductionRequiresHash(): void
    {
        $_ENV['APP_ENV'] = 'production';
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ADMIN_PASSWORD_HASH');
        User::authenticate('admin', 'whatever');
    }

    public function testAuthenticateDevelopmentDefaultPassword(): void
    {
        $_ENV['APP_ENV'] = 'development';
        // No hash set — dev fallback to literal "password".
        $user = User::authenticate('admin', 'password');
        self::assertNotNull($user);
        self::assertSame('admin', $user->username);
    }

    public function testAuthenticateDevelopmentWrongPasswordFails(): void
    {
        $_ENV['APP_ENV'] = 'development';
        self::assertNull(User::authenticate('admin', 'not-password'));
    }

    public function testFromJwtPayloadHappyPath(): void
    {
        $user = User::fromJwtPayload((object) ['sub' => 'admin', 'roles' => ['admin', 'developer']]);
        self::assertNotNull($user);
        self::assertSame('admin', $user->username);
        self::assertSame(['admin', 'developer'], $user->roles);
        self::assertSame('', $user->passwordHash);
    }

    public function testFromJwtPayloadDefaultsToAdminRoleWhenRolesMissing(): void
    {
        $user = User::fromJwtPayload((object) ['sub' => 'admin']);
        self::assertNotNull($user);
        self::assertSame(['admin'], $user->roles);
    }

    public function testFromJwtPayloadRejectsMissingSub(): void
    {
        self::assertNull(User::fromJwtPayload(new \stdClass()));
    }

    public function testFromJwtPayloadRejectsNonStringSub(): void
    {
        self::assertNull(User::fromJwtPayload((object) ['sub' => 123]));
    }

    public function testFromJwtPayloadRejectsNonArrayRoles(): void
    {
        self::assertNull(User::fromJwtPayload((object) ['sub' => 'admin', 'roles' => 'admin']));
    }

    public function testFromJwtPayloadRejectsNonStringRoleEntry(): void
    {
        self::assertNull(User::fromJwtPayload((object) ['sub' => 'admin', 'roles' => ['admin', 123]]));
    }
}
