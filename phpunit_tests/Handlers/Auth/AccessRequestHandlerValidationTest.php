<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers\Auth;

use LiturgicalCalendar\Api\Handlers\Auth\AccessRequestHandler;
use LiturgicalCalendar\Api\Http\Exception\ValidationException;
use LiturgicalCalendar\Api\Repositories\AccessRequestRepository;
use LiturgicalCalendar\Api\Services\RiteCalendarObjectIds;
use LiturgicalCalendar\Tests\Handlers\AbstractHandlerTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Focused tests that a rite-level calendar permission with a valid id is accepted and with an
 * invalid id is rejected, exercising the object-id validation in both the submit and resubmit
 * paths of AccessRequestHandler — for the #955 `rite_calendar` type and for its still-valid
 * predecessor `general_roman_calendar`.
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



    /**
     * A `calendar_editor` request for the #955 successor type must be ACCEPTED.
     *
     * Regression test. `validateRolePermissionConsistency()` used to enforce a PRIVATE duplicate of
     * the calendar object-type list, which was never updated when `rite_calendar` was added to
     * `AccessRequestRepository::ROLE_OBJECT_TYPES['calendar_editor']`. The result was a 400 on the
     * one type the whole issue exists to introduce: the repository accepted it,
     * `isValidObjectIdForType()` accepted the id, OpenAPI advertised it, and this validator alone
     * refused it — so nobody could self-serve a grant on the new tier. The handler now reads the
     * repository constant, and this test fails if a local copy is ever reintroduced.
     */
    public function testRiteCalendarPermissionIsAcceptedForCalendarEditor(): void
    {
        $perms = [['object_type' => 'rite_calendar', 'object_id' => 'roman/decrees', 'relation' => 'editor']];
        self::assertTrue($this->submitIsAccepted('calendar_editor', $perms));
    }

    /** Every valid rite_calendar id must be accepted (distinct user each, to dodge the duplicate-pending guard). */
    public function testAllValidRiteCalendarObjectIdsAreAccepted(): void
    {
        foreach (RiteCalendarObjectIds::allQualifiedIds() as $i => $validId) {
            $oidcUser = [
                'sub'   => 'user-rite-' . $i,
                'email' => 'rite' . $i . '@x.test',
                'name'  => 'Rite Tester ' . $i,
                'roles' => [],
            ];

            try {
                $request = $this->requestFor(
                    'POST',
                    '/auth/access-requests',
                    [],
                    [
                        'requested_role' => 'calendar_editor',
                        'permissions'    => [['object_type' => 'rite_calendar', 'object_id' => $validId, 'relation' => 'editor']],
                        'justification'  => 'rite_calendar access test',
                    ]
                )->withAttribute('oidc_user', $oidcUser);

                $response = ( new AccessRequestHandler() )->handle($request);
                $accepted = $response->getStatusCode() >= 200 && $response->getStatusCode() < 300;
            } catch (ValidationException) {
                $accepted = false;
            }

            self::assertTrue(
                $accepted,
                sprintf('Expected valid rite_calendar object_id "%s" to be accepted', $validId)
            );
        }
    }

    /** A bare (un-qualified) id is still refused on the new type: the rite qualifier is the point of it. */
    public function testRiteCalendarPermissionWithBareObjectIdIsRejected(): void
    {
        $perms = [['object_type' => 'rite_calendar', 'object_id' => 'decrees', 'relation' => 'editor']];
        self::assertFalse($this->submitIsAccepted('calendar_editor', $perms));
    }

    /** The role-scoped type list is the repository's, not a copy: a test-only type stays out of calendar_editor. */
    public function testTestScopedTypeIsStillRejectedForCalendarEditor(): void
    {
        $perms = [['object_type' => 'rite_calendar_test', 'object_id' => 'roman', 'relation' => 'editor']];
        self::assertFalse($this->submitIsAccepted('calendar_editor', $perms));
    }

    // ── resubmit path ────────────────────────────────────────────────────────



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
