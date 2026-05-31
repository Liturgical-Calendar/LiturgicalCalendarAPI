<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers\Auth;

use LiturgicalCalendar\Api\Handlers\Auth\EmailVerificationHandler;
use LiturgicalCalendar\Api\Http\Exception\MethodNotAllowedException;
use LiturgicalCalendar\Api\Http\Exception\UnauthorizedException;
use LiturgicalCalendar\Api\Http\Exception\ValidationException;
use LiturgicalCalendar\Tests\Handlers\AbstractHandlerTestCase;
use LiturgicalCalendar\Tests\Support\EnvIsolationTrait;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(EmailVerificationHandler::class)]
final class EmailVerificationHandlerTest extends AbstractHandlerTestCase
{
    use EnvIsolationTrait;

    public function testOptionsPreflightSucceeds(): void
    {
        $response = ( new EmailVerificationHandler() )->handle(
            $this->requestFor('OPTIONS', '/auth/email-verification/resend', [
                'Origin'                        => 'https://app.example.test',
                'Access-Control-Request-Method' => 'POST',
            ])
        );

        self::assertSame(204, $response->getStatusCode());
    }

    public function testGetIsMethodNotAllowed(): void
    {
        $this->expectException(MethodNotAllowedException::class);
        ( new EmailVerificationHandler() )->handle($this->requestFor('GET', '/auth/email-verification/resend'));
    }

    public function testMissingOidcUserIsUnauthorized(): void
    {
        $this->expectException(UnauthorizedException::class);
        $this->expectExceptionMessage('Authentication required');

        ( new EmailVerificationHandler() )->handle(
            $this->requestFor('POST', '/auth/email-verification/resend')
        );
    }

    public function testMissingSubIsUnauthorized(): void
    {
        $request = $this->requestFor('POST', '/auth/email-verification/resend')
            ->withAttribute('oidc_user', ['email_verified' => false]); // no 'sub'

        $this->expectException(UnauthorizedException::class);
        $this->expectExceptionMessage('Invalid user identification');

        ( new EmailVerificationHandler() )->handle($request);
    }

    public function testAlreadyVerifiedIsValidationError(): void
    {
        $request = $this->requestFor('POST', '/auth/email-verification/resend')
            ->withAttribute('oidc_user', ['sub' => 'user-123', 'email_verified' => true]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('already verified');

        ( new EmailVerificationHandler() )->handle($request);
    }

    public function testZitadelNotConfiguredIsRuntimeError(): void
    {
        // Bootstrap may load Zitadel envs from .env.local on dev machines,
        // so clear them locally to reach the handler's isConfigured() gate.
        $request = $this->requestFor('POST', '/auth/email-verification/resend')
            ->withAttribute('oidc_user', ['sub' => 'user-123', 'email_verified' => false]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Zitadel service not configured');

        $this->withoutEnv(self::ZITADEL_ENV_VARS, fn() => ( new EmailVerificationHandler() )->handle($request));
    }
}
