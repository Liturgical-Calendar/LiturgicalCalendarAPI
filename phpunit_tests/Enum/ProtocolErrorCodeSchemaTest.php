<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Enum;

use LiturgicalCalendar\Api\Enum\ProtocolErrorCode;
use PHPUnit\Framework\TestCase;

final class ProtocolErrorCodeSchemaTest extends TestCase
{
    public function testTheAuthenticationCodesExist(): void
    {
        $this->assertSame('not_authenticated', ProtocolErrorCode::NOT_AUTHENTICATED->value);
        $this->assertSame('insufficient_role', ProtocolErrorCode::INSUFFICIENT_ROLE->value);
    }

    /**
     * An enum case with no schema entry produces a frame the published contract rejects, and the two
     * lists are edited in different files — so the pairing is asserted rather than remembered.
     */
    public function testEveryEnumCaseIsDeclaredInTheFrameSchema(): void
    {
        $schemaPath = dirname(__DIR__, 2) . '/jsondata/schemas/WebSocketFrame.json';
        $contents   = file_get_contents($schemaPath);
        $this->assertIsString($contents);

        /** @var array<string, mixed> $schema */
        $schema = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

        /** @var array<int, string> $declared */
        $declared = $schema['definitions']['protocolError']['properties']['errorCode']['enum'];
        /** @var array<int, string> $cases */
        $cases = array_column(ProtocolErrorCode::cases(), 'value');

        sort($declared);
        sort($cases);

        $this->assertSame($cases, $declared, 'Every ProtocolErrorCode must be declared in WebSocketFrame.json');
    }
}
