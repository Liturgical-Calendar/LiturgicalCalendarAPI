<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services\SourceData;

use LiturgicalCalendar\Api\Services\SourceData\SubmitterIdentity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SubmitterIdentity::class)]
final class SubmitterIdentityTest extends TestCase
{
    private const PROFILE = ['name' => "John D'Orazio", 'email' => 'john@example.test', 'email_verified' => true];

    private const ZITADEL_VARS = ['ZITADEL_ISSUER', 'ZITADEL_PROJECT_ID', 'ZITADEL_MACHINE_TOKEN', 'ZITADEL_INTERNAL_URL'];

    /** @var array<string, array{env: mixed, process: string|false}> */
    private array $savedEnv = [];

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $name => $saved) {
            if ($saved['env'] === null) {
                unset($_ENV[$name]);
            } else {
                $_ENV[$name] = $saved['env'];
            }
            putenv($saved['process'] === false ? $name : "{$name}={$saved['process']}");
        }
        $this->savedEnv = [];
    }

    /**
     * Replace the Zitadel settings for one test; tearDown() restores them.
     *
     * @param array<string, string> $values
     */
    private function zitadelEnv(array $values): void
    {
        foreach (self::ZITADEL_VARS as $name) {
            $this->savedEnv[$name] = ['env' => $_ENV[$name] ?? null, 'process' => getenv($name)];
            unset($_ENV[$name]);
            putenv($name);
            if (isset($values[$name])) {
                $_ENV[$name] = $values[$name];
                putenv("{$name}={$values[$name]}");
            }
        }
    }

    public function testClaimsWithoutANameOrEmailAreCompletedFromTheDirectory(): void
    {
        $identity = new SubmitterIdentity(static fn (string $sub): ?array => self::PROFILE);

        $claims = $identity->complete(['sub' => '378260975448489986', 'roles' => ['admin']]);

        self::assertSame("John D'Orazio", $claims['name']);
        self::assertSame('john@example.test', $claims['email']);
        self::assertTrue($claims['email_verified']);
        self::assertSame(['admin'], $claims['roles']);
    }

    public function testATokenThatNamesItsUserIsLeftAlone(): void
    {
        $asked    = false;
        $identity = new SubmitterIdentity(static function (string $sub) use (&$asked): ?array {
            $asked = true;
            return self::PROFILE;
        });

        $claims = $identity->complete(['sub' => 's', 'name' => 'From the token', 'email' => null]);

        self::assertSame('From the token', $claims['name']);
        self::assertFalse($asked, 'a token that names its user needs no lookup');
    }

    public function testAnUnreachableDirectoryLeavesTheClaimsAsTheyWere(): void
    {
        $identity = new SubmitterIdentity(static function (string $sub): ?array {
            throw new \RuntimeException('directory down');
        });

        self::assertSame(['sub' => 's'], $identity->complete(['sub' => 's']));
    }

    public function testSummariesAreFilledOncePerSubmitter(): void
    {
        $calls    = 0;
        $identity = new SubmitterIdentity(static function (string $sub) use (&$calls): ?array {
            $calls++;
            return self::PROFILE;
        });

        $filled = $identity->fillSummaries([
            ['batch_id' => 'a', 'submitted_by_sub' => 'u1', 'submitted_by_name' => null, 'submitted_by_email' => null],
            ['batch_id' => 'b', 'submitted_by_sub' => 'u1', 'submitted_by_name' => null, 'submitted_by_email' => null],
            ['batch_id' => 'c', 'submitted_by_sub' => 'u2', 'submitted_by_name' => 'Named', 'submitted_by_email' => null],
        ]);

        self::assertSame("John D'Orazio", $filled[0]['submitted_by_name']);
        self::assertSame("John D'Orazio", $filled[1]['submitted_by_name']);
        self::assertSame('Named', $filled[2]['submitted_by_name']);
        self::assertSame(1, $calls);
    }

    public function testLookupsStopOnceTheRequestsBudgetIsSpent(): void
    {
        $now      = 100.0;
        $asked    = [];
        $identity = new SubmitterIdentity(
            static function (string $sub) use (&$asked): ?array {
                $asked[] = $sub;
                return self::PROFILE;
            },
            static function () use (&$now): float {
                return $now;
            }
        );

        self::assertSame(self::PROFILE, $identity->profileOf('u1'));
        $now += SubmitterIdentity::LOOKUP_BUDGET_SECONDS + 0.1;
        self::assertNull($identity->profileOf('u2'), 'a directory that has used up the budget is not asked again');
        self::assertSame(self::PROFILE, $identity->profileOf('u1'), 'answers already had are still served');
        self::assertSame(['u1'], $asked);
    }

    public function testWithoutAConfiguredDirectoryNothingIsLookedUp(): void
    {
        $this->zitadelEnv([]);

        self::assertNull(( new SubmitterIdentity() )->profileOf('u1'));
    }

    public function testAConfiguredDirectoryThatCannotBeReachedYieldsNoProfile(): void
    {
        // Port 9 on the loopback refuses the connection at once, so this exercises the real
        // Zitadel lookup without waiting on its timeout.
        $this->zitadelEnv([
            'ZITADEL_ISSUER'        => 'http://127.0.0.1:9',
            'ZITADEL_PROJECT_ID'    => 'project',
            'ZITADEL_MACHINE_TOKEN' => 'token',
        ]);

        self::assertNull(( new SubmitterIdentity() )->profileOf('u1'));
    }
}
