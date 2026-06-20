<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers\Auth;

use LiturgicalCalendar\Api\Handlers\Auth\AccessRequestHandler;
use LiturgicalCalendar\Api\Http\Exception\ValidationException;
use LiturgicalCalendar\Api\Repositories\AccessRequestRepository;
use LiturgicalCalendar\Tests\Handlers\AbstractHandlerTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Focused tests that a general_roman_calendar permission with a valid id is accepted
 * and with an invalid id is rejected, exercising the GRC object-id validation
 * added in both the submit and resubmit paths of AccessRequestHandler.
 */
#[CoversClass(AccessRequestHandler::class)]
final class AccessRequestHandlerValidationTest extends AbstractHandlerTestCase
{
    protected static bool $requiresDatabase = true;

    /** @return array<string, mixed> */
    private function oidcUser(): array
    {
        return [
            'sub'   => 'user-grc-test',
            'email' => 'grc@x.test',
            'name'  => 'GRC Tester',
            'roles' => [],
        ];
    }

    /**
     * Submit an access request and return true on 2xx, false on ValidationException / 4xx.
     *
     * @param string $role
     * @param array<int, array{object_type: string, object_id: string, relation: string}> $perms
     */
    private function submitIsAccepted(string $role, array $perms): bool
    {
        try {
            $request = $this->requestFor(
                'POST',
                '/auth/access-requests',
                [],
                [
                    'requested_role' => $role,
                    'permissions'    => $perms,
                    'justification'  => 'GRC access test',
                ]
            )->withAttribute('oidc_user', $this->oidcUser());

            $response = ( new AccessRequestHandler() )->handle($request);
            return $response->getStatusCode() >= 200 && $response->getStatusCode() < 300;
        } catch (ValidationException) {
            return false;
        }
    }

    // ── submit path ──────────────────────────────────────────────────────────

    public function testGrcPermissionWithValidObjectIdIsAccepted(): void
    {
        $perms = [['object_type' => 'general_roman_calendar', 'object_id' => 'temporale', 'relation' => 'editor']];
        self::assertTrue($this->submitIsAccepted('calendar_editor', $perms));
    }

    public function testGrcPermissionWithInvalidObjectIdIsRejected(): void
    {
        $perms = [['object_type' => 'general_roman_calendar', 'object_id' => 'EDITIO_TYPICA_1971', 'relation' => 'editor']];
        self::assertFalse($this->submitIsAccepted('calendar_editor', $perms));
    }

    /** All valid GRC object ids must be accepted (each with a distinct user to avoid the duplicate-pending guard). */
    public function testAllValidGrcObjectIdsAreAccepted(): void
    {
        foreach (AccessRequestRepository::GRC_OBJECT_IDS as $i => $validId) {
            $oidcUser = [
                'sub'   => 'user-grc-' . $i,
                'email' => 'grc' . $i . '@x.test',
                'name'  => 'GRC Tester ' . $i,
                'roles' => [],
            ];

            try {
                $request = $this->requestFor(
                    'POST',
                    '/auth/access-requests',
                    [],
                    [
                        'requested_role' => 'calendar_editor',
                        'permissions'    => [['object_type' => 'general_roman_calendar', 'object_id' => $validId, 'relation' => 'editor']],
                        'justification'  => 'GRC access test',
                    ]
                )->withAttribute('oidc_user', $oidcUser);

                $response = ( new AccessRequestHandler() )->handle($request);
                $accepted = $response->getStatusCode() >= 200 && $response->getStatusCode() < 300;
            } catch (ValidationException) {
                $accepted = false;
            }

            self::assertTrue(
                $accepted,
                sprintf('Expected valid GRC object_id "%s" to be accepted', $validId)
            );
        }
    }

    // ── resubmit path ────────────────────────────────────────────────────────

    public function testResubmitGrcWithValidObjectIdIsAccepted(): void
    {
        // Seed a rejected request so resubmit has something to work with.
        $repo  = new AccessRequestRepository(self::$pdo);
        $reqId = $repo->create('user-grc-test', 'grc@x.test', null, 'calendar_editor', []);
        $repo->reject($reqId, 'admin');

        $request = $this->requestFor(
            'POST',
            '/auth/access-requests/' . $reqId . '/resubmit',
            [],
            [
                'permissions' => [
                    ['object_type' => 'general_roman_calendar', 'object_id' => 'temporale', 'relation' => 'editor'],
                ],
            ]
        )->withAttribute('oidc_user', $this->oidcUser());

        $response = ( new AccessRequestHandler() )->handle($request);
        self::assertSame(200, $response->getStatusCode());
    }

    public function testResubmitGrcWithInvalidObjectIdIsRejected(): void
    {
        $repo  = new AccessRequestRepository(self::$pdo);
        $reqId = $repo->create('user-grc-test', 'grc@x.test', null, 'calendar_editor', []);
        $repo->reject($reqId, 'admin');

        $request = $this->requestFor(
            'POST',
            '/auth/access-requests/' . $reqId . '/resubmit',
            [],
            [
                'permissions' => [
                    ['object_type' => 'general_roman_calendar', 'object_id' => 'EDITIO_TYPICA_1971', 'relation' => 'editor'],
                ],
            ]
        )->withAttribute('oidc_user', $this->oidcUser());

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/object_id.*EDITIO_TYPICA_1971.*invalid.*general_roman_calendar/i');

        ( new AccessRequestHandler() )->handle($request);
    }

    public function testResubmitWithInvalidObjectTypeIsRejected(): void
    {
        $repo  = new AccessRequestRepository(self::$pdo);
        $reqId = $repo->create('user-grc-test', 'grc@x.test', null, 'calendar_editor', []);
        $repo->reject($reqId, 'admin');

        $request = $this->requestFor(
            'POST',
            '/auth/access-requests/' . $reqId . '/resubmit',
            [],
            [
                'permissions' => [
                    ['object_type' => 'not_a_real_type', 'object_id' => 'temporale', 'relation' => 'editor'],
                ],
            ]
        )->withAttribute('oidc_user', $this->oidcUser());

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/object_type.*is invalid/i');

        ( new AccessRequestHandler() )->handle($request);
    }
}
