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
}
