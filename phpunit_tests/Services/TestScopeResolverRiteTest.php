<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services;

use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Services\TestScopeResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The collision this whole change exists to make possible: the same test name under two
 * rites is two different tests, resolving to two different FGA scopes.
 */
#[CoversClass(TestScopeResolver::class)]
final class TestScopeResolverRiteTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/litcal-scope-' . bin2hex(random_bytes(6));
        foreach ([Rite::ROMAN, Rite::AMBROSIAN] as $rite) {
            mkdir($this->root . '/' . $rite->value, 0777, true);
            file_put_contents(
                $this->root . '/' . $rite->value . '/StIgnatiusOfLoyolaTest.json',
                json_encode(['name' => 'StIgnatiusOfLoyolaTest', 'applies_to' => ['rite' => $rite->value]])
            );
        }
    }

    protected function tearDown(): void
    {
        foreach ([Rite::ROMAN, Rite::AMBROSIAN] as $rite) {
            @unlink($this->root . '/' . $rite->value . '/StIgnatiusOfLoyolaTest.json');
            @rmdir($this->root . '/' . $rite->value);
        }
        @rmdir($this->root);
    }

    public function testSameNameUnderTwoRitesResolvesToTwoScopes(): void
    {
        $resolver = new TestScopeResolver($this->root);

        self::assertSame(
            ['rite_calendar_test', 'roman'],
            $resolver->resolve(Rite::ROMAN, 'StIgnatiusOfLoyolaTest')
        );
        self::assertSame(
            ['rite_calendar_test', 'ambrosian'],
            $resolver->resolve(Rite::AMBROSIAN, 'StIgnatiusOfLoyolaTest')
        );
    }

    public function testMissingTestInThatPartitionResolvesToNull(): void
    {
        $resolver = new TestScopeResolver($this->root);
        self::assertNull($resolver->resolve(Rite::AMBROSIAN, 'NotARealTest'));
    }

    public function testUnsafeNameNeverTouchesTheFilesystem(): void
    {
        $resolver = new TestScopeResolver($this->root);
        self::assertNull($resolver->resolve(Rite::ROMAN, '../../etc/passwd'));
    }
}
