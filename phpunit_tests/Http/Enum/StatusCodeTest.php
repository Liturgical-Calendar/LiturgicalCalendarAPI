<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Http\Enum;

use LiturgicalCalendar\Api\Http\Enum\StatusCode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(StatusCode::class)]
final class StatusCodeTest extends TestCase
{
    public function testNumericValues(): void
    {
        self::assertSame(102, StatusCode::PROCESSING->value);
        self::assertSame(200, StatusCode::OK->value);
        self::assertSame(201, StatusCode::CREATED->value);
        self::assertSame(204, StatusCode::NO_CONTENT->value);
        self::assertSame(304, StatusCode::NOT_MODIFIED->value);
        self::assertSame(400, StatusCode::BAD_REQUEST->value);
        self::assertSame(401, StatusCode::UNAUTHORIZED->value);
        self::assertSame(403, StatusCode::FORBIDDEN->value);
        self::assertSame(404, StatusCode::NOT_FOUND->value);
        self::assertSame(405, StatusCode::METHOD_NOT_ALLOWED->value);
        self::assertSame(406, StatusCode::NOT_ACCEPTABLE->value);
        self::assertSame(409, StatusCode::CONFLICT->value);
        self::assertSame(415, StatusCode::UNSUPPORTED_MEDIA_TYPE->value);
        self::assertSame(422, StatusCode::UNPROCESSABLE_CONTENT->value);
        self::assertSame(429, StatusCode::TOO_MANY_REQUESTS->value);
        self::assertSame(500, StatusCode::INTERNAL_SERVER_ERROR->value);
        self::assertSame(501, StatusCode::NOT_IMPLEMENTED->value);
        self::assertSame(503, StatusCode::SERVICE_UNAVAILABLE->value);
    }

    public function testReasonPhrases(): void
    {
        self::assertSame('Processing', StatusCode::PROCESSING->toString());
        self::assertSame('OK', StatusCode::OK->toString());
        self::assertSame('Created', StatusCode::CREATED->toString());
        self::assertSame('No Content', StatusCode::NO_CONTENT->toString());
        self::assertSame('Not Modified', StatusCode::NOT_MODIFIED->toString());
        self::assertSame('Bad Request', StatusCode::BAD_REQUEST->toString());
        self::assertSame('Unauthorized', StatusCode::UNAUTHORIZED->toString());
        self::assertSame('Forbidden', StatusCode::FORBIDDEN->toString());
        self::assertSame('Not Found', StatusCode::NOT_FOUND->toString());
        self::assertSame('Method Not Allowed', StatusCode::METHOD_NOT_ALLOWED->toString());
        self::assertSame('Not Acceptable', StatusCode::NOT_ACCEPTABLE->toString());
        self::assertSame('Conflict', StatusCode::CONFLICT->toString());
        self::assertSame('Unsupported Media Type', StatusCode::UNSUPPORTED_MEDIA_TYPE->toString());
        self::assertSame('Unprocessable Content', StatusCode::UNPROCESSABLE_CONTENT->toString());
        self::assertSame('Too Many Requests', StatusCode::TOO_MANY_REQUESTS->toString());
        self::assertSame('Internal Server Error', StatusCode::INTERNAL_SERVER_ERROR->toString());
        self::assertSame('Not Implemented', StatusCode::NOT_IMPLEMENTED->toString());
        self::assertSame('Service Unavailable', StatusCode::SERVICE_UNAVAILABLE->toString());
    }

    public function testReasonIsAliasForToString(): void
    {
        foreach (StatusCode::cases() as $case) {
            self::assertSame($case->toString(), $case->reason());
        }
    }
}
