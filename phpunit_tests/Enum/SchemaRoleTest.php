<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Enum;

use LiturgicalCalendar\Api\Enum\LitSchema;
use LiturgicalCalendar\Api\Enum\SchemaRole;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SchemaRole::class)]
final class SchemaRoleTest extends TestCase
{
    public function testEveryLitSchemaCaseHasARole(): void
    {
        foreach (LitSchema::cases() as $case) {
            $this->assertInstanceOf(SchemaRole::class, $case->role(), "LitSchema::{$case->name} has no role");
        }
    }

    public function testSourceSchemasAreClassifiedAsSource(): void
    {
        $expected = [
            LitSchema::DIOCESAN,
            LitSchema::NATIONAL,
            LitSchema::WIDERREGION,
            LitSchema::PROPRIUMDESANCTIS,
            LitSchema::PROPRIUMDETEMPORE,
            LitSchema::DECREES_SRC,
            LitSchema::I18N,
            LitSchema::TEST_SRC,
            LitSchema::SUPPORTED_LOCALES
        ];

        foreach ($expected as $case) {
            $this->assertSame(SchemaRole::SOURCE, $case->role(), "LitSchema::{$case->name} should be SOURCE");
        }
    }

    public function testResponseSchemasAreClassifiedAsOutput(): void
    {
        $expected = [
            LitSchema::LITCAL,
            LitSchema::METADATA,
            LitSchema::EVENTS,
            LitSchema::TESTS,
            LitSchema::MISSALS,
            LitSchema::EASTER,
            LitSchema::DATA,
            LitSchema::SCHEMAS,
            LitSchema::VALIDATIONS,
            LitSchema::DECREES
        ];

        foreach ($expected as $case) {
            $this->assertSame(SchemaRole::OUTPUT, $case->role(), "LitSchema::{$case->name} should be OUTPUT");
        }
    }

    public function testProtocolAndPayloadRoles(): void
    {
        $this->assertSame(SchemaRole::PROTOCOL, LitSchema::WEBSOCKET_MESSAGE->role());
        $this->assertSame(SchemaRole::PROTOCOL, LitSchema::WEBSOCKET_FRAME->role());
        $this->assertSame(SchemaRole::PAYLOAD, LitSchema::DECREE_WRITE->role());
        $this->assertSame(SchemaRole::PAYLOAD, LitSchema::MISSAL_WRITE->role());
    }
}
