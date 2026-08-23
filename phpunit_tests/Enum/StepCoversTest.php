<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Enum;

use LiturgicalCalendar\Api\Enum\FrameFamily;
use LiturgicalCalendar\Api\Enum\Step;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Step::class)]
#[CoversClass(FrameFamily::class)]
final class StepCoversTest extends TestCase
{
    public function testCoversIsAPublishedStep(): void
    {
        $this->assertSame('covers', Step::COVERS->value);
    }

    public function testAChecksCoversCardIsAddressable(): void
    {
        $this->assertSame(
            '.nation-roman-US-i18n.locales-covered',
            FrameFamily::CHECK->frameClasses('nation-roman-US-i18n', Step::COVERS)
        );
    }

    public function testATestRunHasNoCoversCard(): void
    {
        // A test run has no folder and no declared locales, so the step is refused rather than given
        // an invented class that would match no card.
        $this->expectException(\LogicException::class);
        FrameFamily::TEST_RUN->frameClasses('SomeTest', Step::COVERS);
    }
}
