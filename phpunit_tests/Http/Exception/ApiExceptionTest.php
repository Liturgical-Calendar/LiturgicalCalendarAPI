<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Http\Exception;

use LiturgicalCalendar\Api\Http\Exception\ApiException;
use LiturgicalCalendar\Api\Http\Exception\ConflictException;
use LiturgicalCalendar\Api\Http\Exception\ForbiddenException;
use LiturgicalCalendar\Api\Http\Exception\ImplementationException;
use LiturgicalCalendar\Api\Http\Exception\InternalServerErrorException;
use LiturgicalCalendar\Api\Http\Exception\MethodNotAllowedException;
use LiturgicalCalendar\Api\Http\Exception\NotAcceptableException;
use LiturgicalCalendar\Api\Http\Exception\NotFoundException;
use LiturgicalCalendar\Api\Http\Exception\ResourceConflictException;
use LiturgicalCalendar\Api\Http\Exception\ServiceUnavailableException;
use LiturgicalCalendar\Api\Http\Exception\TooManyRequestsException;
use LiturgicalCalendar\Api\Http\Exception\UnauthorizedException;
use LiturgicalCalendar\Api\Http\Exception\UnprocessableContentException;
use LiturgicalCalendar\Api\Http\Exception\UnsupportedCharsetException;
use LiturgicalCalendar\Api\Http\Exception\UnsupportedMediaTypeException;
use LiturgicalCalendar\Api\Http\Exception\ValidationException;
use LiturgicalCalendar\Api\Http\Exception\YamlException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the ApiException base contract plus each concrete subclass's
 * canonical status / title / type. Some exceptions add extras (TooManyRequests
 * retry-after, Yaml custom status) which are spot-checked separately.
 */
#[CoversClass(ApiException::class)]
#[CoversClass(ConflictException::class)]
#[CoversClass(ForbiddenException::class)]
#[CoversClass(ImplementationException::class)]
#[CoversClass(InternalServerErrorException::class)]
#[CoversClass(MethodNotAllowedException::class)]
#[CoversClass(NotAcceptableException::class)]
#[CoversClass(NotFoundException::class)]
#[CoversClass(ResourceConflictException::class)]
#[CoversClass(ServiceUnavailableException::class)]
#[CoversClass(TooManyRequestsException::class)]
#[CoversClass(UnauthorizedException::class)]
#[CoversClass(UnprocessableContentException::class)]
#[CoversClass(UnsupportedCharsetException::class)]
#[CoversClass(UnsupportedMediaTypeException::class)]
#[CoversClass(ValidationException::class)]
#[CoversClass(YamlException::class)]
final class ApiExceptionTest extends TestCase
{
    public function testToArrayProducesProblemDetails(): void
    {
        $err = new NotFoundException('missing widget');
        self::assertSame(404, $err->getStatus());
        self::assertSame('Not Found', $err->getTitle());
        self::assertStringContainsString('404-not-found', $err->getType());

        $arr = $err->toArray();
        self::assertSame([
            'type'   => $err->getType(),
            'title'  => 'Not Found',
            'status' => 404,
            'detail' => 'missing widget',
        ], $arr);
    }

    public function testToArrayDebugIncludesFileLineTrace(): void
    {
        $err = new NotFoundException('boom');
        $arr = $err->toArray(includeDebug: true);
        self::assertArrayHasKey('file', $arr);
        self::assertArrayHasKey('line', $arr);
        self::assertArrayHasKey('trace', $arr);
        self::assertIsArray($arr['trace']);
    }

    public function testPreviousExceptionPropagates(): void
    {
        $cause = new \RuntimeException('cause');
        $err   = new ValidationException('bad input', $cause);
        self::assertSame($cause, $err->getPrevious());
    }

    /**
     * @return iterable<string, array{class-string<ApiException>, int, string}>
     */
    public static function exceptionShapeProvider(): iterable
    {
        return [
            'conflict'            => [ConflictException::class, 409, 'Conflict'],
            'forbidden'           => [ForbiddenException::class, 403, 'Forbidden'],
            'implementation'      => [ImplementationException::class, 501, 'Not Implemented'],
            'internal'            => [InternalServerErrorException::class, 500, 'Internal Server Error'],
            'method-not-allowed'  => [MethodNotAllowedException::class, 405, 'Method Not Allowed'],
            'not-acceptable'      => [NotAcceptableException::class, 406, 'Not Acceptable'],
            'not-found'           => [NotFoundException::class, 404, 'Not Found'],
            'resource-conflict'   => [ResourceConflictException::class, 409, 'Conflict'],
            'service-unavail'     => [ServiceUnavailableException::class, 503, 'Service Unavailable'],
            'unauthorized'        => [UnauthorizedException::class, 401, 'Unauthorized'],
            'unprocessable'       => [UnprocessableContentException::class, 422, 'Unprocessable Content'],
            'unsupported-media'   => [UnsupportedMediaTypeException::class, 415, 'Unsupported Media Type'],
            'unsupported-charset' => [UnsupportedCharsetException::class, 415, 'Unsupported Media Type'],
            'validation'          => [ValidationException::class, 400, 'Bad Request'],
            'yaml'                => [YamlException::class, 422, 'Invalid YAML data'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('exceptionShapeProvider')]
    public function testEachExceptionHasCorrectStatusAndTitle(
        string $class,
        int $expectedStatus,
        string $expectedTitle
    ): void {
        /** @var ApiException $err */
        $err = new $class('custom message');
        self::assertSame($expectedStatus, $err->getStatus());
        self::assertSame($expectedTitle, $err->getTitle());
        self::assertSame('custom message', $err->getMessage());
        self::assertNotEmpty($err->getType());
    }

    public function testTooManyRequestsRetryAfter(): void
    {
        $err = new TooManyRequestsException('Slow down', retryAfter: 30);
        self::assertSame(429, $err->getStatus());
        self::assertSame(30, $err->getRetryAfter());
        $arr = $err->toArray();
        self::assertSame(30, $arr['retryAfter']);
    }

    public function testTooManyRequestsOmitsRetryAfterWhenZero(): void
    {
        $err = new TooManyRequestsException();
        self::assertSame(0, $err->getRetryAfter());
        self::assertArrayNotHasKey('retryAfter', $err->toArray());
    }

    public function testTooManyRequestsClampsNegativeRetryAfter(): void
    {
        $err = new TooManyRequestsException(retryAfter: -10);
        self::assertSame(0, $err->getRetryAfter());
    }

    public function testYamlExceptionAllowsCustomStatus(): void
    {
        $err = new YamlException('bad yaml', 400);
        self::assertSame(400, $err->getStatus());
        self::assertSame('Invalid YAML data', $err->getTitle());
    }

    public function testUnauthorizedHasRfcTypeUrl(): void
    {
        $err = new UnauthorizedException();
        self::assertStringContainsString('401-unauthorized', $err->getType());
    }
}
