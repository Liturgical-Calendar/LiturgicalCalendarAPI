<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests;

use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Router;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;

/**
 * `/tests` resolves its own tri-state rite rather than joining extractRiteSegment():
 * that helper resolves an absent segment to Roman, whereas bare `/tests` must mean
 * *every* rite (the corpus-wide index). null therefore means "all rites", not "Roman".
 */
#[CoversMethod(Router::class, 'extractTestsRite')]
final class RouterTestsRiteSegmentTest extends TestCase
{
    public function testAmbrosianSegmentIsStrippedAndSelectsAmbrosian(): void
    {
        $parts = ['ambrosian', 'StIgnatiusOfLoyolaTest'];
        self::assertSame(Rite::AMBROSIAN, Router::extractTestsRite($parts));
        self::assertSame(['StIgnatiusOfLoyolaTest'], $parts);
    }

    public function testRomanSegmentIsStrippedAndSelectsRoman(): void
    {
        $parts = ['roman', 'MaryMotherChurchTest'];
        self::assertSame(Rite::ROMAN, Router::extractTestsRite($parts));
        self::assertSame(['MaryMotherChurchTest'], $parts);
    }

    public function testBareRiteSegmentIsThePerRiteCollection(): void
    {
        $parts = ['ambrosian'];
        self::assertSame(Rite::AMBROSIAN, Router::extractTestsRite($parts));
        self::assertSame([], $parts);
    }

    public function testNoSegmentAtAllMeansAllRites(): void
    {
        $parts = [];
        self::assertNull(Router::extractTestsRite($parts));
        self::assertSame([], $parts);
    }

    public function testBareTestNameIsNotARiteAndIsLeftIntact(): void
    {
        // The hard break: this shape reaches the handler with a null rite and one
        // path param, which TestsHandler rejects with a 400.
        $parts = ['MaryMotherChurchTest'];
        self::assertNull(Router::extractTestsRite($parts));
        self::assertSame(['MaryMotherChurchTest'], $parts);
    }
}
