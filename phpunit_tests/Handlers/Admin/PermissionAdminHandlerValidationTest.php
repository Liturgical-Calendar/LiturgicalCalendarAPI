<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers\Admin;

use LiturgicalCalendar\Api\Handlers\Admin\PermissionAdminHandler;
use LiturgicalCalendar\Api\Http\Exception\ValidationException;
use LiturgicalCalendar\Tests\Handlers\AbstractHandlerTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Validation tests for PermissionAdminHandler — object-id constraints for
 * the general_roman_calendar object type.
 *
 * These tests exercise the grant entry point (POST /admin/permissions) as a
 * global admin so that `requireResourceAdmin` is bypassed and validation is
 * the only gate before the outbox/FGA path is attempted.
 *
 * `tupleParamsValid()` returns false on ValidationException, true otherwise
 * (downstream DB/FGA errors are non-fatal for the assertion in question).
 */
#[CoversClass(PermissionAdminHandler::class)]
final class PermissionAdminHandlerValidationTest extends AbstractHandlerTestCase
{
    /**
     * Dispatch POST /admin/permissions as a global admin with the given tuple
     * fields. Returns true unless a ValidationException is thrown; any
     * downstream (DB / FGA) error is caught and treated as "validation passed".
     */
    private function tupleParamsValid(
        string $user,
        string $objectType,
        string $objectId,
        string $relation
    ): bool {
        try {
            ( new PermissionAdminHandler() )->handle(
                $this->withOidcUser($this->requestFor('POST', '/admin/permissions', [], [
                    'user'        => $user,
                    'object_type' => $objectType,
                    'object_id'   => $objectId,
                    'relation'    => $relation,
                ]))
            );
        } catch (ValidationException) {
            return false;
        } catch (\Throwable) {
            // Downstream (DB / FGA) error — validation passed.
        }
        return true;
    }

    public function testGrantGrcTupleWithValidIdPasses(): void
    {
        self::assertTrue($this->tupleParamsValid('user:abc', 'general_roman_calendar', 'decrees', 'editor'));
    }

    public function testGrantGrcTupleWithInvalidIdFails(): void
    {
        self::assertFalse($this->tupleParamsValid('user:abc', 'general_roman_calendar', 'nonsense', 'editor'));
    }

    public function testGrantTupleWithInvalidObjectTypeFails(): void
    {
        self::assertFalse($this->tupleParamsValid('user:abc', 'not_a_real_type', 'temporale', 'editor'));
    }
}
