<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Models;

use LiturgicalCalendar\Api\DateTime;
use LiturgicalCalendar\Api\Enum\LitGrade;
use LiturgicalCalendar\Api\Models\ConditionalRule;
use LiturgicalCalendar\Api\Models\ConditionalRuleAction;
use LiturgicalCalendar\Api\Models\ConditionalRuleCondition;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConditionalRule::class)]
#[CoversClass(ConditionalRuleCondition::class)]
#[CoversClass(ConditionalRuleAction::class)]
final class ConditionalRuleTest extends TestCase
{
    public function testConditionFromArrayWeekday(): void
    {
        $c = ConditionalRuleCondition::fromArray(['if_weekday' => 'Monday']);
        self::assertSame('monday', $c->if_weekday);
        self::assertNull($c->if_grade);
    }

    public function testConditionFromArrayGrade(): void
    {
        $c = ConditionalRuleCondition::fromArray(['if_grade' => LitGrade::SOLEMNITY->value]);
        self::assertSame(LitGrade::SOLEMNITY, $c->if_grade);
        self::assertNull($c->if_weekday);
    }

    public function testConditionRequiresAtLeastOneProperty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('at least one of `if_weekday` or `if_grade`');
        ConditionalRuleCondition::fromArray([]);
    }

    public function testConditionRejectsBothProperties(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ConditionalRuleCondition::fromArray(['if_weekday' => 'Monday', 'if_grade' => 3]);
    }

    public function testConditionRejectsInvalidWeekdayValue(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ConditionalRuleCondition::fromArray(['if_weekday' => 'Funday']);
    }

    public function testConditionRejectsNonStringWeekday(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ConditionalRuleCondition::fromArray(['if_weekday' => 123]);
    }

    public function testConditionRejectsNonIntGrade(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ConditionalRuleCondition::fromArray(['if_grade' => 'six']);
    }

    public function testConditionRejectsOutOfRangeGrade(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ConditionalRuleCondition::fromArray(['if_grade' => 99]);
    }

    public function testConditionFromObjectAlsoSupported(): void
    {
        $c = ConditionalRuleCondition::fromObject((object) ['if_weekday' => 'Friday']);
        self::assertSame('friday', $c->if_weekday);
    }

    public function testConditionFromObjectRequiresAtLeastOneProperty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ConditionalRuleCondition::fromObject(new \stdClass());
    }

    public function testActionFromArrayMove(): void
    {
        $a = ConditionalRuleAction::fromArray(['move' => 'P1D']);
        self::assertSame('P1D', $a->move);
        self::assertNull($a->move_to);
    }

    public function testActionFromArrayMoveTo(): void
    {
        $a = ConditionalRuleAction::fromArray(['move_to' => 'next monday']);
        self::assertSame('next monday', $a->move_to);
        self::assertNull($a->move);
    }

    public function testActionRequiresAtLeastOneProperty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ConditionalRuleAction::fromArray([]);
    }

    public function testActionRejectsBothProperties(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ConditionalRuleAction::fromArray(['move' => 'P1D', 'move_to' => 'next monday']);
    }

    public function testActionRejectsNonStringMove(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ConditionalRuleAction::fromArray(['move' => 5]);
    }

    public function testActionApplyForwardInterval(): void
    {
        $action = ConditionalRuleAction::fromArray(['move' => 'P3D']);
        $date   = new DateTime('2025-01-01');
        $moved  = $action->apply($date);
        self::assertSame('2025-01-04', $moved->format('Y-m-d'));
    }

    public function testActionApplyBackwardInterval(): void
    {
        $action = ConditionalRuleAction::fromArray(['move' => '-P2D']);
        $date   = new DateTime('2025-01-10');
        $moved  = $action->apply($date);
        self::assertSame('2025-01-08', $moved->format('Y-m-d'));
    }

    public function testActionApplyRejectsInvalidMoveInterval(): void
    {
        $action = ConditionalRuleAction::fromArray(['move' => 'oneday']);
        $this->expectException(\ValueError::class);
        $this->expectExceptionMessage('Invalid `move` interval');
        $action->apply(new DateTime('2025-01-01'));
    }

    public function testActionApplyMoveTo(): void
    {
        // 2025-01-01 is a Wednesday.
        $action = ConditionalRuleAction::fromArray(['move_to' => 'next monday']);
        $moved  = $action->apply(new DateTime('2025-01-01'));
        self::assertSame('2025-01-06', $moved->format('Y-m-d'));
    }

    public function testConditionalRuleFromArrayWiresChildren(): void
    {
        $rule = ConditionalRule::fromArray([
            'condition' => ['if_weekday' => 'Monday'],
            'then'      => ['move' => 'P1D'],
        ]);
        self::assertSame('monday', $rule->condition->if_weekday);
        self::assertSame('P1D', $rule->then->move);
    }

    public function testConditionalRuleFromArrayRequiresBothKeys(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ConditionalRule::fromArray([
            'condition' => ['if_weekday' => 'Monday'],
        ]);
    }

    public function testConditionalRuleFromObjectWiresChildren(): void
    {
        $rule = ConditionalRule::fromObject((object) [
            'condition' => (object) ['if_weekday' => 'Tuesday'],
            'then'      => (object) ['move_to' => 'next wednesday'],
        ]);
        self::assertSame('tuesday', $rule->condition->if_weekday);
        self::assertSame('next wednesday', $rule->then->move_to);
    }

    public function testConditionalRuleFromObjectRequiresBothProperties(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ConditionalRule::fromObject((object) ['condition' => (object) ['if_weekday' => 'Monday']]);
    }
}
