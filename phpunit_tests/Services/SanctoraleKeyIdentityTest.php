<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services;

use LiturgicalCalendar\Api\Services\SanctoraleKeyIdentity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The rule `scripts/lint-missals.php` enforces over the corpus (its invariant 2, from #939),
 * asked prospectively of a row about to be written.
 *
 * The cases below are the corpus's own: `StPeterClaver`, legitimately declared by three missals
 * on the same day, and `StIsidore`, the one disagreement that made #939 necessary.
 */
#[CoversClass(SanctoraleKeyIdentity::class)]
final class SanctoraleKeyIdentityTest extends TestCase
{
    public function testAKeyNoOtherMissalDeclaresIsAdmissible(): void
    {
        self::assertSame([], SanctoraleKeyIdentity::dateDisagreements(5, 15, []));
    }

    /**
     * Re-declaring a key across delta layers is normal and correct: this is `StPeterClaver`,
     * declared by the 2002 editio typica, by IT_1983 and by US_2011, each with its own grade for
     * its own calendar and all three on 9 September.
     */
    public function testRedeclaringAKeyOnTheSameDateIsAdmissible(): void
    {
        self::assertSame([], SanctoraleKeyIdentity::dateDisagreements(9, 9, [
            'EDITIO_TYPICA_2002' => ['month' => 9, 'day' => 9],
            'IT_1983'            => ['month' => 9, 'day' => 9],
        ]));
    }

    /** This is `StIsidore`: Seville on 4 April, the Farmer on 15 May, one key. */
    public function testADisagreeingDateIsReported(): void
    {
        self::assertSame(
            ['EDITIO_TYPICA_1970 declares it on 4-4'],
            SanctoraleKeyIdentity::dateDisagreements(5, 15, [
                'EDITIO_TYPICA_1970' => ['month' => 4, 'day' => 4],
            ])
        );
    }

    /** Only the declarations that actually disagree are named; the agreeing ones are not noise. */
    public function testOnlyDisagreeingDeclarationsAreReported(): void
    {
        self::assertSame(
            ['IT_1983 declares it on 9-10'],
            SanctoraleKeyIdentity::dateDisagreements(9, 9, [
                'IT_1983'            => ['month' => 9, 'day' => 10],
                'EDITIO_TYPICA_2002' => ['month' => 9, 'day' => 9],
            ])
        );
    }

    /** Ascending by missal id, so the refusal message is stable rather than glob-order dependent. */
    public function testDisagreementsAreOrderedByMissalId(): void
    {
        self::assertSame(
            ['EDITIO_TYPICA_1970 declares it on 1-1', 'US_2011 declares it on 2-2'],
            SanctoraleKeyIdentity::dateDisagreements(3, 3, [
                'US_2011'            => ['month' => 2, 'day' => 2],
                'EDITIO_TYPICA_1970' => ['month' => 1, 'day' => 1],
            ])
        );
    }

    /**
     * The message has to say what is wrong AND what to do about it: an editor who has just been
     * refused needs to know that the remedy is a new key, not a different request.
     */
    public function testTheConflictMessageNamesTheKeyTheDatesAndTheRemedy(): void
    {
        $message = SanctoraleKeyIdentity::conflictMessage('StIsidore', 5, 15, ['EDITIO_TYPICA_1970 declares it on 4-4']);

        self::assertStringContainsString('StIsidore', $message);
        self::assertStringContainsString('5-15', $message);
        self::assertStringContainsString('EDITIO_TYPICA_1970 declares it on 4-4', $message);
        self::assertStringContainsString('cannot denote two saints', $message);
        self::assertStringContainsString('StIsidoreFarmer', $message);
    }
}
