<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Handlers\Auth;

use LiturgicalCalendar\Api\Services\ResourceAdminService;

/**
 * Test Scopes Handler
 *
 * GET /auth/test-scopes — report the authenticated caller's test-editing scopes:
 *   - is_global_admin: the Zitadel `admin` role is present in the token.
 *   - editor: OpenFGA `editor` tuples across the *_test object types (gates edit).
 *   - admin:  OpenFGA `admin` tuples across the *_test object types (gates delete).
 *
 * Fails closed: when OpenFGA is unavailable, editor/admin are empty, but
 * is_global_admin is still honored from the token.
 */
final class TestScopesHandler extends AbstractScopesHandler
{
    /**
     * @return array<string, mixed>
     */
    protected function buildScopePayload(string $sub, bool $isGlobalAdmin, ?ResourceAdminService $service): array
    {
        $scopes = $service?->resolveTestScopes($sub) ?? ['editor' => [], 'admin' => []];

        return [
            'is_global_admin' => $isGlobalAdmin,
            'editor'          => $scopes['editor'],
            'admin'           => $scopes['admin'],
        ];
    }
}
