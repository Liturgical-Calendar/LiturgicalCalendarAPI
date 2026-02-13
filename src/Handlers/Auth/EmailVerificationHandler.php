<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Handlers\Auth;

use LiturgicalCalendar\Api\Handlers\AbstractHandler;
use LiturgicalCalendar\Api\Http\Enum\AcceptabilityLevel;
use LiturgicalCalendar\Api\Http\Enum\AcceptHeader;
use LiturgicalCalendar\Api\Http\Enum\RequestMethod;
use LiturgicalCalendar\Api\Http\Enum\StatusCode;
use LiturgicalCalendar\Api\Http\Exception\UnauthorizedException;
use LiturgicalCalendar\Api\Http\Exception\ValidationException;
use LiturgicalCalendar\Api\Services\ZitadelService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Email Verification Handler
 *
 * Handles email verification operations:
 * - POST /auth/email-verification/resend - Resend verification email
 *
 * Requires authentication.
 */
final class EmailVerificationHandler extends AbstractHandler
{
    public function __construct()
    {
        parent::__construct();

        $this->allowedRequestMethods = [RequestMethod::POST];
        $this->allowedAcceptHeaders  = [AcceptHeader::JSON];
        $this->allowCredentials      = true;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $response = static::initResponse($request);
        $method   = RequestMethod::from($request->getMethod());

        if ($method === RequestMethod::OPTIONS) {
            return $this->handlePreflightRequest($request, $response);
        }

        $response = $this->setAccessControlAllowOriginHeader($request, $response);
        $this->validateRequestMethod($request);

        $mime     = $this->validateAcceptHeader($request, AcceptabilityLevel::LAX);
        $response = $response->withHeader('Content-Type', $mime);

        // Check authentication via OIDC token
        /** @var array{sub?: string, email_verified?: bool}|null $oidcUser */
        $oidcUser = $request->getAttribute('oidc_user');

        if ($oidcUser === null) {
            throw new UnauthorizedException('Authentication required');
        }

        $userId = $oidcUser['sub'] ?? null;
        if (!is_string($userId) || empty($userId)) {
            throw new UnauthorizedException('Invalid user identification');
        }

        // Check if email is already verified
        $emailVerified = $oidcUser['email_verified'] ?? false;
        if ($emailVerified === true) {
            throw new ValidationException('Email is already verified');
        }

        // Check if Zitadel is configured
        if (!ZitadelService::isConfigured()) {
            throw new \RuntimeException('Zitadel service not configured');
        }

        // Resend verification email
        $zitadel = ZitadelService::fromEnv();
        $result  = $zitadel->resendEmailVerification($userId);

        if ($result['success'] === false) {
            $response = $response->withStatus(StatusCode::INTERNAL_SERVER_ERROR->value);
            return $this->encodeResponseBody($response, [
                'success' => false,
                'message' => 'Failed to send verification email. Please try again later.',
                'error'   => $result['error'],
            ]);
        }

        $response = $response->withStatus(StatusCode::OK->value);

        return $this->encodeResponseBody($response, [
            'success' => true,
            'message' => 'Verification email sent. Please check your inbox.',
        ]);
    }
}
