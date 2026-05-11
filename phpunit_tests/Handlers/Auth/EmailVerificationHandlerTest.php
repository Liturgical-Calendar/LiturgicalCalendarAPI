<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers\Auth;

use LiturgicalCalendar\Api\Handlers\Auth\EmailVerificationHandler;
use LiturgicalCalendar\Api\Http\Exception\MethodNotAllowedException;
use LiturgicalCalendar\Api\Http\Exception\UnauthorizedException;
use LiturgicalCalendar\Api\Http\Exception\ValidationException;
use LiturgicalCalendar\Tests\Handlers\AbstractHandlerTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(EmailVerificationHandler::class)]
final class EmailVerificationHandlerTest extends AbstractHandlerTestCase
{
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
        // We don't set up Zitadel envs in the test bootstrap, so the handler
        // should fail at the isConfigured() gate when reached.
        $request = $this->requestFor('POST', '/auth/email-verification/resend')
            ->withAttribute('oidc_user', ['sub' => 'user-123', 'email_verified' => false]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Zitadel service not configured');

        ( new EmailVerificationHandler() )->handle($request);
    }
}
