# Authentication & Authorization Roadmap

This document outlines the implementation plan for adding authentication, authorization, and API key management to the Liturgical Calendar API and Frontend.

## Current Implementation Status (2025-12-06)

**Status:** ✅ Phase 0 Complete + Phase 2.5 Support + Production Security - JWT authentication with cookie-only auth and production-ready security features

**Related Issue:** [#262 - Implement JWT authentication for PUT/PATCH/DELETE requests](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/issues/262)

**Related Documentation:**

- [Frontend Authentication Roadmap](../../../LiturgicalCalendarFrontend/docs/AUTHENTICATION_ROADMAP.md)
- [OpenAPI Evaluation Roadmap](OPENAPI_EVALUATION_ROADMAP.md) - Authentication gaps and missing CRUD operations
- [Serialization Roadmap](SERIALIZATION_ROADMAP.md) - Data serialization coordination
- [API Client Libraries Roadmap](../../../docs/API_CLIENT_LIBRARIES_ROADMAP.md) - Client library endpoint coverage

### Why Self-Hosted JWT First?

After reviewing the options below (Supabase, WorkOS, self-hosted OAuth), we've decided to start with a **simplified self-hosted JWT implementation** for the following reasons:

1. **Right-sized for current needs** - We're protecting a handful of admin endpoints (PUT/PATCH/DELETE for calendar management), not building a multi-tenant SaaS
2. **Matches issue scope** - Issue #262 specifically requests JWT implementation as a focused, achievable goal
3. **No vendor lock-in** - Maintains the project's self-hosting philosophy (Docker support, self-contained API)
4. **Full control** - Complete ownership of authentication flow and data
5. **Incremental path** - Can migrate to Supabase/WorkOS later if the project's needs evolve (RBAC, OAuth, MFA, etc.)

### What We're Building (Phase 0)

**Backend (LiturgicalCalendarAPI):**

- Install `firebase/php-jwt` library
- Create JWT generation endpoint (`/auth/login`)
- Create JWT validation middleware
- Protect `RegionalDataHandler` PUT/PATCH/DELETE routes
- Environment-based secret key management
- Basic user validation (hardcoded or simple file-based initially)

**Frontend (LiturgicalCalendarFrontend):**

- Simple login UI (modal or page)
- Token storage (sessionStorage/localStorage)
- Add `Authorization: Bearer <token>` header to write operations
- Basic error handling for 401/403 responses
- User session indicators

See [Frontend Authentication Roadmap](../../../LiturgicalCalendarFrontend/docs/AUTHENTICATION_ROADMAP.md) for detailed frontend implementation plan.

### ✅ Phase 0 Implementation Complete

**Completed Components:**

1. **Dependencies**
   - ✅ Installed `firebase/php-jwt` v6.11.1

2. **Core Services**
   - ✅ `JwtService` (`src/Services/JwtService.php`) - Token generation, verification, and refresh
   - ✅ `User` model (`src/Models/Auth/User.php`) - Environment-based authentication
   - ✅ `CookieHelper` (`src/Http/CookieHelper.php`) - Secure HttpOnly cookie management

3. **Middleware**
   - ✅ `JwtAuthMiddleware` (`src/Http/Middleware/JwtAuthMiddleware.php`) - JWT validation for protected routes

4. **HTTP Handlers**
   - ✅ `LoginHandler` (`src/Handlers/Auth/LoginHandler.php`) - POST `/auth/login`
   - ✅ `LogoutHandler` (`src/Handlers/Auth/LogoutHandler.php`) - POST `/auth/logout`
   - ✅ `RefreshHandler` (`src/Handlers/Auth/RefreshHandler.php`) - POST `/auth/refresh`
   - ✅ `MeHandler` (`src/Handlers/Auth/MeHandler.php`) - GET `/auth/me` (authentication state check)

5. **HTTP Exceptions**
   - ✅ `UnauthorizedException` (401) - Missing/invalid authentication
   - ✅ `ForbiddenException` (403) - Insufficient permissions
   - ✅ Updated `StatusCode` enum with UNAUTHORIZED and FORBIDDEN cases

6. **Router Updates**
   - ✅ Added `/auth/login`, `/auth/logout`, `/auth/refresh`, and `/auth/me` routes
   - ✅ Applied JWT middleware to `/data` endpoint for PUT/PATCH/DELETE operations
   - ✅ CORS credentials support (`Access-Control-Allow-Credentials: true`)

7. **Configuration**
   - ✅ Added JWT environment variables to `.env.example`
   - ✅ Configured development environment in `.env.local`

8. **HttpOnly Cookie Authentication (2025-11-27)**
   - ✅ `CookieHelper` class for secure cookie management
   - ✅ `LoginHandler` sets HttpOnly cookies after successful authentication
   - ✅ `RefreshHandler` reads refresh token from cookie, sets new access token cookie
   - ✅ `LogoutHandler` clears HttpOnly cookies on logout
   - ✅ `JwtAuthMiddleware` reads token from cookie first, falls back to Authorization header
   - ✅ `MeHandler` for checking authentication state (essential for cookie-based auth)
   - ✅ Supports both HttpOnly cookies (preferred) and Authorization header (backwards compatible)

**Testing Results:**

- ✅ Login with valid credentials returns access and refresh tokens
- ✅ Login with invalid credentials returns 401 Unauthorized
- ✅ Token refresh successfully generates new access token
- ✅ DELETE/PATCH/PUT without authentication returns 401 Unauthorized
- ✅ DELETE/PATCH/PUT with valid JWT token passes authentication
- ✅ Invalid/malformed tokens rejected with 401 Unauthorized
- ✅ HttpOnly cookies set correctly on login
- ✅ `/auth/me` returns authentication state from cookie
- ✅ Logout clears HttpOnly cookies

**API Endpoints:**

- `POST /auth/login` - Authenticate and receive tokens (sets HttpOnly cookies)
- `POST /auth/logout` - End session (clears HttpOnly cookies)
- `POST /auth/refresh` - Refresh access token using refresh token (reads/sets cookies)
- `GET /auth/me` - Check authentication state (returns user info from token)
- `PUT /data/{category}/{calendar}` - Protected (requires JWT)
- `PATCH /data/{category}/{calendar}` - Protected (requires JWT)
- `DELETE /data/{category}/{calendar}` - Protected (requires JWT)
- `PUT /tests` - Protected (requires JWT)
- `PATCH /tests/{test_name}` - Protected (requires JWT)
- `DELETE /tests/{test_name}` - Protected (requires JWT)

**Endpoints Requiring JWT Protection (To Be Implemented):**

Per the API Client Libraries Roadmap, the following CRUD endpoints also need JWT protection:

- `PUT /missals` - Create missal (not yet implemented)
- `PATCH /missals/{missal_id}` - Update missal (not yet implemented)
- `DELETE /missals/{missal_id}` - Delete missal (not yet implemented)
- `PUT /decrees` - Create decree (not yet implemented)
- `PATCH /decrees/{decree_id}` - Update decree (not yet implemented)
- `DELETE /decrees/{decree_id}` - Delete decree (not yet implemented)

See `docs/enhancements/OPENAPI_EVALUATION_ROADMAP.md` for the full gap analysis.

**Development Credentials:**

- Username: `admin` (configurable via `ADMIN_USERNAME` env var)
- Password: `password` (change in production via `ADMIN_PASSWORD_HASH` env var - Argon2id hash, e.g., `password_hash('your-password', PASSWORD_ARGON2ID)`)

### Evolution: From JWT to Zitadel RBAC

After Phase 0 proved the concept with self-hosted JWT, the project evaluated identity providers
for full RBAC support. The options considered were:

- **WorkOS** — enterprise-grade but vendor lock-in and cost at scale
- **Supabase Auth** — open source but less mature PHP SDK
- **Zitadel** — open source, self-hostable, OIDC-native, built-in RBAC, Login V2 UI with passkeys

**Decision: Zitadel** was chosen for the following reasons:

1. **Self-hostable** — aligns with the project's Docker-based deployment philosophy
2. **OIDC-native** — standards-based authentication with PKCE support
3. **Built-in user management** — registration, email verification, passkeys, MFA
4. **Role-based access control** — project roles managed via Zitadel Console or Management API
5. **Login V2 UI** — modern, customizable login experience out of the box
6. **No recurring costs** — self-hosted, no per-user fees
7. **Management API** — programmatic user/role/grant management for admin workflows

The legacy JWT authentication (Phase 0) is preserved as a fallback when Zitadel is not configured.

---

## Zitadel RBAC Implementation (Current State)

For detailed documentation on the Zitadel implementation, see the project wiki:

- **[Zitadel RBAC Overview](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/wiki/Zitadel-RBAC-Overview)** —
  architecture, roles, protected routes, middleware pipeline, database schema
- **[Zitadel Implementation Status](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/wiki/Zitadel-Implementation-Status)** —
  completed features and outstanding work with linked GitHub issues
- **[Zitadel Infrastructure Setup](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/wiki/Zitadel-Infrastructure-Setup)** —
  Docker Compose setup, automated setup script, configuration
- **[Zitadel Production Deployment](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/wiki/Zitadel-Production-Deployment)** —
  production configuration and deployment guide
- **[Zitadel Production Security](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/wiki/Zitadel-Production-Security)** —
  security hardening for production environments

### Summary of What Was Built

**Identity & Authentication:**

- OIDC token validation via JWKS with per-issuer keyset caching (`OidcAuthMiddleware`)
- Support for both HttpOnly cookie and Authorization header authentication
- OIDC availability checking with 503 fallback (`OidcAvailabilityMiddleware`)
- HTTPS enforcement for auth endpoints in staging/production
- Legacy JWT authentication preserved as fallback (`JwtAuthMiddleware`)

**Roles (defined in Zitadel project):**

| Role              | Purpose                                                  |
|-------------------|----------------------------------------------------------|
| `admin`           | System administrator; bypasses all permission checks     |
| `developer`       | Register applications and generate API keys              |
| `calendar_editor` | Contribute calendar data (with per-calendar permissions) |
| `test_editor`     | Create and modify test definitions                       |

**Role Request Workflow:**

- Users submit requests via `POST /auth/role-requests`
- Admins approve via `POST /admin/role-requests/{id}/approve`
- Admins reject via `POST /admin/role-requests/{id}/reject`
- Approval triggers role grant in Zitadel via Management API
- Role revocation support

**Application & API Key Management:**

- Developers register applications with approval workflow
- API key generation with scope control (read/write) and rate limiting
- Key rotation and revocation
- API key rate limiting enforcement via `ApiKeyRateLimitMiddleware`

**Admin Features:**

- User management with role revocation
- Application approval workflow
- Notification counts for pending items
- Audit logging of all administrative actions

**Infrastructure:**

- Docker Compose stack (Zitadel, Login V2, PostgreSQL, Adminer, API, Frontend, Tests)
- Automated `setup-zitadel.sh` script for project/role/app creation
- `ZITADEL_INTERNAL_URL` support for Docker networking
- PostgreSQL schema with UUID primary keys and CHECK constraints

**Database (PostgreSQL `litcal` database):**

| Table                       | Purpose                                            |
|-----------------------------|----------------------------------------------------|
| `role_requests`             | User role assignment request workflow              |
| `user_calendar_permissions` | Calendar-specific read/write permissions           |
| `permission_requests`       | Workflow for requesting calendar access            |
| `applications`              | Registered developer applications                  |
| `api_keys`                  | API keys with rate limiting, scope, and expiration |
| `audit_log`                 | Security and compliance audit trail                |

### Outstanding Work

See the [Implementation Status wiki page](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/wiki/Zitadel-Implementation-Status#outstanding-work)
for the current list of outstanding work with linked GitHub issues.

---

## Future: Fine-Grained Authorization with OpenFGA

### The Problem

Zitadel provides coarse-grained role-based access control (RBAC): a user is a `calendar_editor` or they are not.
The current `user_calendar_permissions` table adds a layer of per-calendar permissions, but only supports
`read`/`write` — it cannot express fine-grained policies like:

- "User X can **edit** the national calendar of Italy but cannot **delete** it"
- "User Y can **create** diocesan calendars under Italy but cannot modify existing ones"
- "User Z can **edit** any calendar in the Americas wider region"

These relationship-based, resource-level permissions require a dedicated authorization engine.

### Why OpenFGA

[OpenFGA](https://openfga.dev/) is the open-source implementation of
[Google's Zanzibar](https://research.google/pubs/zanzibar-googles-consistent-global-authorization-system/)
authorization system. It was evaluated for fine-grained authorization because:

1. **Relationship-based access control (ReBAC)** — permissions are modeled as relationships between users
   and resources, not just roles
2. **Open source and self-hostable** — aligns with the project's Docker-based philosophy (like Zitadel)
3. **Language-agnostic** — HTTP/gRPC API, usable from PHP without an SDK
4. **CNCF project** — part of the Cloud Native Computing Foundation, with active development
5. **Designed for scale** — handles complex permission graphs efficiently
6. **Composable with Zitadel** — Zitadel handles identity/authentication, OpenFGA handles
   fine-grained authorization

### Proposed Architecture

```text
Browser → API → Zitadel (who is this user? what roles do they have?)
                    ↓
                OpenFGA (can this user perform this action on this resource?)
```

- **Zitadel** — identity, authentication, coarse-grained roles
- **OpenFGA** — fine-grained, per-resource permission checks

### Authorization Model (Draft)

Each calendar type (wider region, national, diocesan) is an independent resource type with no
permission inheritance between them. This reflects the ecclesial reality: national calendars fall
under the jurisdiction of bishops' conferences, diocesan calendars under individual dioceses, and
wider region calendars may be coordinated across multiple bishops' conferences. Each is a distinct
institution with its own jurisdiction.

```yaml
model
  schema 1.1

type user

type wider_region
  relations
    define viewer: [user]
    define editor: [user]
    define deleter: [user]

type national_calendar
  relations
    define viewer: [user]
    define editor: [user]
    define deleter: [user]

type diocesan_calendar
  relations
    define viewer: [user]
    define editor: [user]
    define deleter: [user]

type test_definition
  relations
    define viewer: [user]
    define editor: [user]
    define deleter: [user]
```

This model enables policies like:

- Grant `editor` on `national_calendar:IT` → user can edit Italy's calendar
- Grant `editor` on `diocesan_calendar:roma_lazio_it` → user can edit Rome's diocesan calendar
- `deleter` is separate from `editor` → edit without delete is possible
- Permissions on a wider region do **not** cascade to national or diocesan calendars;
  each must be granted independently

### Permission Checks in the API

The middleware would check OpenFGA before allowing operations:

```text
PUT    /data/national/IT  →  check(user, "editor", "national_calendar:IT")
DELETE /data/national/IT  →  check(user, "deleter", "national_calendar:IT")
PATCH  /data/diocesan/roma_lazio_it  →  check(user, "editor", "diocesan_calendar:roma_lazio_it")
```

### Infrastructure

OpenFGA would be added to the Docker Compose stack:

- **openfga** container (port 8081 or similar)
- **PostgreSQL** or **MySQL** backend for OpenFGA (can share the existing PostgreSQL instance)
- The `setup-zitadel.sh` script would be extended to also initialize the OpenFGA authorization model

### Migration Path

1. **Phase 1**: Add OpenFGA to Docker stack, define authorization model
2. **Phase 2**: Create middleware that checks OpenFGA for calendar write operations
3. **Phase 3**: Build admin UI for managing per-resource permissions (replaces `user_calendar_permissions` table)
4. **Phase 4**: Migrate existing `user_calendar_permissions` data to OpenFGA relationship tuples
5. **Phase 5**: Remove the `user_calendar_permissions` and `permission_requests` tables

### CORS Configuration Design

The API uses different CORS configurations for public and authenticated endpoints:

#### Public Endpoints (`Access-Control-Allow-Origin: *`)

The following endpoints are intentionally configured with wildcard CORS:

- `GET /calendars` (MetadataHandler) - Discovery endpoint listing available calendars
- `GET /calendar` (CalendarHandler) - Calendar data retrieval
- `GET /temporale` (TemporaleHandler) - Temporale data retrieval
- `GET /events` (EventsHandler) - Event catalog
- Other read-only GET endpoints

**Why wildcard CORS for these endpoints:**

1. **Public data** - These endpoints return the same data regardless of who's requesting
2. **Maximum accessibility** - Any website/application can consume this public API
3. **No authentication needed** - No user-specific or protected information

**Important:** Wildcard CORS (`Access-Control-Allow-Origin: *`) is incompatible with `credentials: 'include'`.
Browsers block credential requests to wildcard-CORS endpoints as a security measure.
This is intentional for public endpoints.

#### Authenticated Endpoints (Origin-Specific CORS)

The following endpoints require specific CORS origins configured via `CORS_ALLOWED_ORIGINS`:

- `POST /auth/login`, `/auth/logout`, `/auth/refresh`, `GET /auth/me`
- `PUT /data`, `PATCH /data`, `DELETE /data`
- Other write operations

**Why origin-specific CORS for these endpoints:**

1. **Cookie-based authentication** - HttpOnly cookies require `credentials: 'include'`
2. **Security** - Prevents credential leakage to arbitrary origins
3. **CORS spec requirement** - Credentialed requests cannot use wildcard origins

**Client-side handling:**

```javascript
// For public endpoints (no credentials needed)
fetch('https://api.example.com/calendars', {
    credentials: 'omit'  // or simply omit the credentials option
});

// For authenticated endpoints (credentials required)
fetch('https://api.example.com/data', {
    method: 'PUT',
    credentials: 'include',  // Send HttpOnly cookies
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(data)
});
```

**Configuration:**

```env
# .env - Configure allowed origins for credentialed requests
CORS_ALLOWED_ORIGINS=https://frontend.example.com,https://admin.example.com
```
