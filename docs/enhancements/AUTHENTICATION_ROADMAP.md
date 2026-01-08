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

### Future Evolution

The comprehensive roadmap below (Phases 1-6) outlines the **long-term vision** for authentication, including:

- Developer API keys and usage tracking
- Full RBAC with calendar-specific permissions
- Admin dashboards
- Audit logging
- Rate limiting
- Multi-user management

These features are not part of the initial JWT implementation but provide a blueprint for future enhancements as the project grows.

---

## Overview

### Goals

1. **Developer API Access**
   - Developers register on the website
   - Register applications with name and purpose
   - Generate API keys for tracking and rate limiting
   - Track endpoint usage and collect statistics per application

2. **Protected Calendar Data Operations**
   - Authenticate users for PUT/PATCH/DELETE operations
   - Role-based access control (RBAC) for calendar data
   - Users can only modify calendars for which they have appropriate roles

### User Types

1. **API Consumers (Developers)**
   - Register account
   - Create and manage applications
   - Generate and rotate API keys
   - View usage statistics and quotas

2. **Calendar Data Editors**
   - Register account with verified credentials
   - Request access to specific calendars (national/diocesan)
   - Submit/modify liturgical calendar data
   - Review and approve changes (for administrators)

3. **Administrators**
   - Manage user roles and permissions
   - Review and approve calendar data changes
   - Monitor API usage and abuse
   - Configure system-wide settings

## Technology Stack Options

### Option 1: WorkOS (Recommended for Rapid Development)

**Pros:**

- Enterprise-grade authentication (SSO, MFA)
- Built-in user management and directory sync
- Audit logs and compliance features
- Excellent documentation and SDKs
- Handles RBAC natively

**Cons:**

- Cost scales with users (free tier available)
- External dependency
- Less customization control

**Backend (PHP):**

- `workos/workos-php` SDK
- JWT verification middleware
- Session management

**Frontend (JavaScript):**

- `@workos-inc/authkit-js` for authentication UI
- OAuth 2.0 flows
- Dashboard for user management

### Option 2: Supabase Auth (Good Balance)

**Pros:**

- Open source and self-hostable
- PostgreSQL-based (good for relational data)
- Real-time subscriptions (useful for admin dashboards)
- Built-in storage and database
- Row-level security for fine-grained access

**Cons:**

- Younger ecosystem than WorkOS/Auth0
- Self-hosting requires infrastructure management
- PHP SDK less mature than JS/Python

**Backend (PHP):**

- `supabase-community/supabase-php` SDK
- JWT verification with Supabase signing keys
- PostgreSQL for user/role/API key storage

**Frontend (JavaScript):**

- `@supabase/supabase-js` client
- Pre-built auth UI components
- Real-time dashboard updates

### Option 3: Self-Hosted OAuth 2.0 + JWT (Full Control)

**Pros:**

- Complete control over authentication flow
- No external service dependencies
- No recurring costs
- Data sovereignty

**Cons:**

- More development time
- Security responsibility
- Need to implement all features (MFA, password reset, etc.)
- Ongoing maintenance burden

**Backend (PHP):**

- `league/oauth2-server` for OAuth 2.0 provider
- `firebase/php-jwt` for token generation/validation
- `paragonie/paseto` (alternative to JWT, more secure)
- `spomky-labs/otphp` for MFA/TOTP
- Database schema for users, roles, permissions, API keys

**Frontend (JavaScript):**

- Custom authentication UI
- OAuth 2.0 client library
- Token management and refresh logic

## Recommended Approach: Hybrid Solution

**Use Supabase Auth for user authentication + self-managed API keys**

This provides the best balance of:

- Rapid authentication implementation (Supabase)
- Full control over API key lifecycle
- Flexibility for custom rate limiting and statistics
- PostgreSQL integration for relational data

## Implementation Roadmap

### Phase 0: Basic JWT Authentication (CURRENT - Issue #262)

**Timeline:** 1-2 weeks

**Goal:** Protect PUT/PATCH/DELETE operations on the `/data` endpoint (RegionalDataHandler) with JWT authentication.

#### Backend Implementation

1. **Install Dependencies**

   ```bash
   composer require firebase/php-jwt
   ```

2. **Environment Configuration**

   Add to `.env.example` and `.env.local`:

   ```env
   # JWT Authentication
   JWT_SECRET=your-secret-key-here-change-in-production
   JWT_ALGORITHM=HS256
   JWT_EXPIRY=3600  # 1 hour in seconds
   JWT_REFRESH_EXPIRY=604800  # 7 days in seconds
   ```

3. **Create User Model (Simple)**

   ```php
   // src/Models/Auth/User.php
   // Simple in-memory or file-based user for MVP
   // Later: migrate to database
   class User {
      public function __construct(
         public readonly string $username,
         public readonly string $passwordHash,
         public readonly array $roles = ['admin']
      ) {}

      public static function authenticate(string $username, string $password): ?self
      {
         // For now: check against hardcoded admin user from .env
         // Future: check against database
      }
   }
   ```

4. **Create JWT Service**

   ```php
   // src/Services/JwtService.php
   use Firebase\JWT\JWT;
   use Firebase\JWT\Key;

   class JwtService {
      public function __construct(
         private readonly string $secret,
         private readonly string $algorithm = 'HS256',
         private readonly int $expiry = 3600
      ) {}

      public function generate(string $username, array $claims = []): string
      public function verify(string $token): ?object
      public function refresh(string $token): ?string
   }
   ```

5. **Create Authentication Middleware**

   ```php
   // src/Http/Middleware/JwtAuthMiddleware.php
   class JwtAuthMiddleware implements MiddlewareInterface {
      public function process(
         ServerRequestInterface $request,
         RequestHandlerInterface $handler
      ): ResponseInterface {
         // Extract token from Authorization: Bearer header
         // Verify token with JwtService
         // Attach user info to request attribute
         // Return 401 if invalid/missing
      }
   }
   ```

6. **Create Login Handler**

   ```php
   // src/Handlers/Auth/LoginHandler.php
   class LoginHandler extends AbstractHandler {
      // POST /auth/login
      // Accept: username, password
      // Return: { access_token, refresh_token, expires_in, token_type }
   }
   ```

7. **Create Refresh Handler**

   ```php
   // src/Handlers/Auth/RefreshHandler.php
   class RefreshHandler extends AbstractHandler {
      // POST /auth/refresh
      // Accept: { refresh_token }
      // Return: { access_token, expires_in, token_type }
   }
   ```

8. **Update Router**

   ```php
   // src/Router.php
   // Add new routes:
   case '/auth/login':
      return $this->handleRequest(new LoginHandler(), $request);
   case '/auth/refresh':
      return $this->handleRequest(new RefreshHandler(), $request);

   // Apply JWT middleware to RegionalDataHandler for PUT/PATCH/DELETE
   case '/data':
      if (in_array($method, [RequestMethod::PUT, RequestMethod::PATCH, RequestMethod::DELETE])) {
         $request = $this->applyMiddleware($request, new JwtAuthMiddleware());
      }
      return $this->handleRequest(new RegionalDataHandler(), $request);
   ```

9. **Update RegionalDataHandler**

   ```php
   // src/Handlers/RegionalDataHandler.php
   // Extract authenticated user from request attribute
   // Log who performed the operation
   // Future: check calendar-specific permissions
   ```

#### Testing

```bash
# Test login (using development defaults; override password in production via ADMIN_PASSWORD_HASH env var)
curl -X POST http://localhost:8000/auth/login \
  -H "Content-Type: application/json" \
  -d '{"username":"admin","password":"password"}'

# Expected response:
# {"access_token":"eyJ0eXAiOiJKV1...", "refresh_token":"...", "expires_in":3600, "token_type":"Bearer"}

# Test authenticated DELETE
TOKEN="your-token-here"
curl -X DELETE http://localhost:8000/data?category=national&calendar=TEST \
  -H "Authorization: Bearer $TOKEN"

# Expected: 200 OK (if authorized)

# Test unauthenticated DELETE
curl -X DELETE http://localhost:8000/data?category=national&calendar=TEST

# Expected: 401 Unauthorized
```

#### PHPUnit Tests

```php
// phpunit_tests/Auth/JwtServiceTest.php
// phpunit_tests/Auth/JwtAuthMiddlewareTest.php
// phpunit_tests/Auth/LoginHandlerTest.php
// phpunit_tests/Routes/AuthenticatedDataTest.php
```

#### Security Measures - Phase 0

**Currently Implemented:**

- ✅ **Short-lived access tokens** - Default 1 hour (configurable via `JWT_EXPIRY`)
- ✅ **Longer-lived refresh tokens** - Default 7 days (configurable via `JWT_REFRESH_EXPIRY`)
- ✅ **Password hashing** - Uses `password_hash()` with `PASSWORD_ARGON2ID`
- ✅ **JWT signature verification** - All tokens validated with HS256 algorithm
- ✅ **HttpOnly cookies** - Tokens stored in HttpOnly cookies, inaccessible to JavaScript (XSS protection)
- ✅ **SameSite cookie attribute** - CSRF protection via `SameSite=Lax` (access token) and `SameSite=Strict` (refresh token)
- ✅ **Secure cookie flag** - Cookies only sent over HTTPS in production
- ✅ **Path restriction** - Refresh token cookie only sent to `/auth` endpoints
- ✅ **Backwards compatibility** - Also accepts Authorization header for clients not using cookies
- ✅ **Authentication logging** - All login attempts (success/failure) and logouts logged to dedicated `auth.log` file

**Recommended for Production (Implemented 2025-12-06):**

- ✅ **Rate limiting** - Brute-force protection on `/auth/login` endpoint (5 attempts per 15 minutes, configurable)
- ✅ **HTTPS enforcement** - `HttpsEnforcementMiddleware` requires HTTPS for auth endpoints in staging/production
- ✅ **Strong JWT secret** - `JwtServiceFactory` detects and rejects placeholder secrets in staging/production
- ✅ **Production security documentation** - See `docs/PRODUCTION_SECURITY.md`
- ⚠️ **Token expiry monitoring** - Consider implementing token refresh alerts (future enhancement)

#### Known Limitations (To Be Addressed in Future Phases)

- **No user management UI** - Admin credentials hardcoded in `.env`
- **No password reset** - Requires manual `.env` update
- **No RBAC** - All authenticated users have same permissions
- **No calendar-specific permissions** - Any authenticated user can modify any calendar
- **Limited audit logging** - Authentication events logged, but data modifications not yet tracked
- **No refresh token rotation** - Refresh tokens don't expire on use

These limitations are acceptable for the initial implementation to protect against unauthorized modifications. Future phases will address them.

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

### Phase 2.5 Support: Full Cookie-Only Authentication (2025-12-02)

The API backend fully supports Phase 2.5 (Full Cookie-Only Authentication) from the Frontend Authentication Roadmap.
This allows frontends to use HttpOnly cookies exclusively, without needing to store tokens in localStorage/sessionStorage.

**Backend Support Already Implemented:**

1. **RefreshHandler** (`src/Handlers/Auth/RefreshHandler.php`)
   - ✅ Reads refresh token from HttpOnly cookie first, falling back to request body
   - ✅ No request body required when refresh token cookie is present

2. **JwtAuthMiddleware** (`src/Http/Middleware/JwtAuthMiddleware.php`)
   - ✅ Reads access token from HttpOnly cookie first, falling back to Authorization header
   - ✅ Automatic token validation from cookies

3. **CookieHelper** (`src/Http/CookieHelper.php`)
   - ✅ `setAccessTokenCookie()` - Sets HttpOnly access token cookie
   - ✅ `setRefreshTokenCookie()` - Sets HttpOnly refresh token cookie (path restricted to `/auth`)
   - ✅ `clearAuthCookies()` - Clears both token cookies on logout
   - ✅ `getAccessToken()` / `getRefreshToken()` - Reads tokens from cookie array

4. **MeHandler** (`src/Handlers/Auth/MeHandler.php`)
   - ✅ `GET /auth/me` endpoint for checking authentication state
   - ✅ Essential for cookie-based auth since JavaScript cannot read HttpOnly cookies
   - ✅ Returns `{ authenticated, username, roles, exp }` from token

**Frontend Migration (Phase 2.5):**

When frontends migrate to full cookie-only authentication:

- No need to store tokens in localStorage/sessionStorage
- Requests use `credentials: 'include'` to send HttpOnly cookies automatically
- No Authorization header needed (cookies are automatic)
- Auth state checked via `/auth/me` endpoint instead of parsing localStorage token

**Backwards Compatibility:**

The API maintains full backwards compatibility:

- Authorization header still works for clients not using cookies
- Request body refresh tokens still accepted alongside cookie-based refresh
- Both authentication methods can coexist during migration

#### Authentication Logging

Authentication events are logged to dedicated log files in the `logs/` directory:

- **Log files:** `auth-YYYY-MM-DD.log` (plain text) and `auth.json-YYYY-MM-DD.log` (JSON format)
- **Rotation:** Daily rotation, 30 days retention
- **Events logged:**
  - Successful logins (INFO level) - username, client IP
  - Failed login attempts (WARNING level) - attempted username, client IP, reason
  - Logouts (INFO level) - username (extracted from token if provided), client IP

**Example log entries:**

```text
[2025-11-25 14:30:00] INFO: Login successful
    username: admin
    client_ip: 192.168.1.100

[2025-11-25 14:35:00] WARNING: Login failed
    username: hacker
    client_ip: 192.168.1.50
    reason: Invalid credentials

[2025-11-25 15:00:00] INFO: Logout
    username: admin
    client_ip: 192.168.1.100
```

This logging helps with:

- **Security monitoring** - Detect brute force attacks via repeated failed logins
- **Audit trails** - Track who authenticated and when
- **Debugging** - Troubleshoot authentication issues
- **Compliance** - Meet audit requirements for access logging

---

### Phase 1: Infrastructure Setup (Weeks 1-2)

#### Backend API

1. **Database Schema Design**
   - Users table (synced from Supabase Auth or self-managed)
   - Roles table (developer, editor, admin)
   - Permissions table (calendar-specific permissions)
   - Applications table (registered apps by developers)
   - API keys table (keys, quotas, rate limits)
   - API usage statistics table (request logs, endpoint tracking)
   - User-calendar associations (which users can edit which calendars)

2. **Dependencies Installation**

   ```bash
   # For Supabase approach
   composer require supabase-community/supabase-php
   composer require firebase/php-jwt
   composer require guzzlehttp/guzzle  # Already installed

   # For self-hosted approach
   composer require league/oauth2-server
   composer require firebase/php-jwt
   composer require spomky-labs/otphp
   composer require ramsey/uuid

   # For all approaches
   composer require predis/predis  # Redis for rate limiting
   composer require symfony/rate-limiter
   ```

3. **Environment Configuration**

   ```env
   # .env additions
   AUTH_PROVIDER=supabase  # or workos, self-hosted
   SUPABASE_URL=https://your-project.supabase.co
   SUPABASE_ANON_KEY=your-anon-key
   SUPABASE_SERVICE_ROLE_KEY=your-service-role-key
   JWT_SECRET=your-jwt-secret
   JWT_ALGORITHM=HS256
   API_KEY_HEADER=X-API-Key
   REDIS_HOST=localhost
   REDIS_PORT=6379
   ```

#### Frontend Website

1. **Authentication UI Setup**
   - Login/registration pages
   - Password reset flow
   - Email verification
   - Profile management

2. **Developer Dashboard**
   - Application management interface
   - API key generation and display
   - Usage statistics visualization
   - Documentation for API consumption

3. **Calendar Editor Dashboard**
   - Calendar selection interface
   - Data editing forms
   - Change review workflow
   - Permission request system

### Phase 2: Authentication Core (Weeks 3-4)

#### Backend Implementation

1. **Create Authentication Middleware**

   ```php
   // src/Http/Middleware/AuthenticationMiddleware.php
   // Validates JWT tokens from Supabase or self-hosted OAuth
   // Extracts user information and attaches to request
   ```

2. **Create API Key Middleware**

   ```php
   // src/Http/Middleware/ApiKeyMiddleware.php
   // Validates API keys from X-API-Key header
   // Tracks usage statistics
   // Enforces rate limits per key
   ```

3. **Create Authorization Middleware**

   ```php
   // src/Http/Middleware/AuthorizationMiddleware.php
   // Checks user roles and permissions
   // Validates calendar-specific access
   ```

4. **Update Router**
   - Add middleware pipeline configuration
   - Protect PUT/PATCH/DELETE routes
   - Allow GET routes with optional API key (for tracking)

5. **Create Auth Models**

   ```php
   // src/Models/Auth/User.php
   // src/Models/Auth/Role.php
   // src/Models/Auth/Permission.php
   // src/Models/Auth/Application.php
   // src/Models/Auth/ApiKey.php
   ```

6. **Create Auth Handlers**

   ```php
   // src/Handlers/Auth/LoginHandler.php (if self-hosted)
   // src/Handlers/Auth/RegisterHandler.php (if self-hosted)
   // src/Handlers/Auth/ApiKeyHandler.php
   // src/Handlers/Auth/ApplicationHandler.php
   ```

#### Frontend Implementation

1. **Integrate Supabase Auth**

   ```javascript
   // Authentication context/provider
   // Login/logout flows
   // Session management
   // Protected routes
   ```

2. **Developer Portal Pages**
   - `/dashboard/applications` - List and manage apps
   - `/dashboard/applications/new` - Register new app
   - `/dashboard/applications/:id` - App details and API keys
   - `/dashboard/usage` - Usage statistics and analytics

3. **Calendar Editor Pages**
   - `/dashboard/calendars` - List accessible calendars
   - `/dashboard/calendars/:id/edit` - Edit calendar data
   - `/dashboard/permissions` - Request access to calendars

### Phase 3: API Key Management (Weeks 5-6)

#### Backend Implementation

1. **API Key Generation Service**

   ```php
   // src/Services/ApiKeyService.php
   class ApiKeyService {
      public function generateKey(int $applicationId, string $prefix = 'litcal'): string
      public function validateKey(string $key): ?ApiKey
      public function revokeKey(string $key): bool
      public function rotateKey(string $oldKey): string
      public function recordUsage(string $key, string $endpoint, string $method): void
   }
   ```

2. **Rate Limiting Service**

   ```php
   // src/Services/RateLimitService.php
   class RateLimitService {
      public function checkLimit(string $apiKey, int $maxRequests = 1000): bool
      public function incrementUsage(string $apiKey): void
      public function getRemainingQuota(string $apiKey): int
      public function resetQuota(string $apiKey): void
   }
   ```

3. **Usage Statistics Service**

   ```php
   // src/Services/UsageStatisticsService.php
   class UsageStatisticsService {
      public function recordRequest(string $apiKey, string $endpoint, string $method): void
      public function getStatsByApplication(int $appId, ?DateTimeInterface $from, ?DateTimeInterface $to): array
      public function getEndpointUsage(int $appId): array
      public function getVersionUsage(int $appId): array
   }
   ```

4. **New API Endpoints**

   POST   /auth/applications              - Create application
   GET    /auth/applications              - List user's applications
   GET    /auth/applications/:id          - Get application details
   PATCH  /auth/applications/:id          - Update application
   DELETE /auth/applications/:id          - Delete application

   POST   /auth/applications/:id/keys     - Generate new API key
   GET    /auth/applications/:id/keys     - List application keys
   DELETE /auth/keys/:id                  - Revoke API key
   POST   /auth/keys/:id/rotate           - Rotate API key

   GET    /auth/applications/:id/usage    - Get usage statistics
   GET    /auth/usage/summary             - Get aggregated usage

#### Frontend Implementation

1. **Application Management UI**
   - Create application form (name, description, website, callback URLs)
   - Application list with status indicators
   - Edit application details
   - Delete application with confirmation

2. **API Key Management UI**
   - Display API keys with copy-to-clipboard
   - One-time display of new keys with security warning
   - Revoke keys with confirmation
   - Rotate keys with automated process
   - Key usage indicators

3. **Usage Statistics Dashboard**
   - Request count graphs (daily/weekly/monthly)
   - Endpoint usage breakdown
   - API version distribution
   - Error rate monitoring
   - Quota usage indicators

### Phase 4: Role-Based Access Control (Weeks 7-8)

#### Backend Implementation

1. **Permission System**

   ```php
   // src/Services/PermissionService.php
   class PermissionService {
      public function hasPermission(int $userId, string $permission, ?string $calendarId = null): bool
      public function grantPermission(int $userId, string $permission, ?string $calendarId = null): void
      public function revokePermission(int $userId, string $permission, ?string $calendarId = null): void
      public function getUserPermissions(int $userId): array
      public function getCalendarEditors(string $calendarId): array
   }
   ```

2. **Permission Definitions**

   ```php
   // src/Enum/Permission.php
   enum Permission: string {
      // Developer permissions
      case CREATE_APPLICATION = 'create:application';
      case MANAGE_OWN_APPLICATIONS = 'manage:own:applications';

      // Calendar data permissions
      case VIEW_CALENDAR = 'view:calendar';
      case EDIT_NATIONAL_CALENDAR = 'edit:calendar:national';
      case EDIT_DIOCESAN_CALENDAR = 'edit:calendar:diocesan';
      case EDIT_WIDER_REGION_CALENDAR = 'edit:calendar:wider_region';
      case APPROVE_CALENDAR_CHANGES = 'approve:calendar:changes';

      // Admin permissions
      case MANAGE_USERS = 'manage:users';
      case MANAGE_ROLES = 'manage:roles';
      case VIEW_ALL_STATISTICS = 'view:statistics:all';
      case MANAGE_API_KEYS = 'manage:api_keys:all';
   }
   ```

3. **Role Definitions**

   ```php
   // src/Enum/Role.php
   enum Role: string {
      case DEVELOPER = 'developer';
      case CALENDAR_EDITOR = 'calendar_editor';
      case CALENDAR_ADMIN = 'calendar_admin';
      case SYSTEM_ADMIN = 'system_admin';

      public function getPermissions(): array;
   }
   ```

4. **Authorization Middleware Enhancement**
   - Check user role
   - Validate calendar-specific permissions
   - Return 403 Forbidden for unauthorized access
   - Log authorization failures

5. **Calendar Access Endpoints**

   POST   /auth/permissions/request        - Request calendar access
   GET    /auth/permissions                - List user permissions
   GET    /auth/calendars/accessible       - List calendars user can edit

   **Admin only**
   GET    /admin/permissions               - List all permissions
   POST   /admin/permissions               - Grant permission
   DELETE /admin/permissions/:id           - Revoke permission
   GET    /admin/calendars/:id/editors     - List editors for calendar

#### Frontend Implementation

1. **Permission Request Workflow**
   - Form to request calendar editing access
   - Justification/credential submission
   - Status tracking (pending/approved/denied)
   - Email notifications

2. **Admin Dashboard**
   - Pending permission requests
   - User management interface
   - Role assignment UI
   - Calendar editor assignments
   - Audit log viewer

3. **Calendar Editor UI**
   - List of editable calendars
   - Calendar data editing forms (PUT/PATCH)
   - Validation and preview before submission
   - Change history viewer
   - Delete confirmation dialogs

### Phase 5: Security Hardening (Weeks 9-10)

#### Backend Implementation

1. **Security Enhancements**
   - HTTPS enforcement
   - CORS configuration for specific origins
   - Request signature validation (HMAC)
   - IP whitelisting for admin operations
   - Brute force protection (login attempts)
   - API key rotation policies
   - Suspicious activity detection

2. **Audit Logging**

   ```php
   // src/Services/AuditLogService.php
   class AuditLogService {
      public function logAuthentication(int $userId, bool $success, string $ip): void
      public function logAuthorization(int $userId, string $action, string $resource, bool $granted): void
      public function logApiKeyUsage(string $apiKey, string $endpoint, int $statusCode): void
      public function logDataModification(int $userId, string $calendarId, string $action, array $changes): void
      public function getAuditLog(array $filters): array
   }
   ```

3. **Rate Limiting Tiers**

   ```php
   // Different rate limits based on user type
   const RATE_LIMITS = [
      'anonymous' => 100,      // requests per hour
      'developer' => 1000,     // requests per hour
      'editor' => 500,         // requests per hour
      'admin' => 10000,        // requests per hour
   ];
   ```

4. **API Key Scopes**

   ```php
   // src/Enum/ApiKeyScope.php
   enum ApiKeyScope: string {
      case READ_ONLY = 'read';
      case READ_WRITE = 'read_write';
      case ADMIN = 'admin';
   }
   ```

5. **Webhook System (Optional)**
   - Notify applications of calendar updates
   - Webhook signature verification
   - Retry mechanism for failed deliveries

#### Frontend Implementation

1. **Security Features**
   - HTTPS only
   - Content Security Policy headers
   - Secure cookie settings
   - XSS protection
   - CSRF tokens for forms

2. **User Security Settings**
   - Two-factor authentication setup
   - Active sessions management
   - API key security best practices documentation
   - Security notifications (new login, API key created, etc.)

3. **Admin Security Tools**
   - Audit log viewer with filtering
   - Suspicious activity alerts
   - Failed authentication attempts monitor
   - API abuse detection dashboard

### Phase 6: Testing & Documentation (Weeks 11-12)

#### Backend Testing

1. **PHPUnit Tests**

   ```bash
   # New test suites
   phpunit_tests/Auth/
   ├── AuthenticationMiddlewareTest.php
   ├── ApiKeyMiddlewareTest.php
   ├── AuthorizationMiddlewareTest.php
   ├── ApiKeyServiceTest.php
   ├── RateLimitServiceTest.php
   ├── PermissionServiceTest.php
   └── AuditLogServiceTest.php
   ```

2. **Integration Tests**
   - Full authentication flow
   - API key generation and usage
   - Permission checking
   - Rate limiting behavior
   - Multi-user scenarios

3. **Security Tests**
   - JWT token tampering
   - API key brute force
   - Permission bypass attempts
   - SQL injection prevention
   - XSS prevention

#### Documentation

1. **API Documentation Updates**
   - Authentication section in OpenAPI spec
   - API key usage examples
   - Rate limiting documentation
   - Error codes for auth failures
   - Security best practices

2. **Developer Guides**
   - "Getting Started" guide for new developers
   - API key management tutorial
   - Rate limiting and quotas explanation
   - Code examples in multiple languages (PHP, JavaScript, Python, etc.)
   - Migration guide for existing consumers

3. **Calendar Editor Guides**
   - How to request calendar editing access
   - Calendar data schema documentation
   - Editing workflow and approval process
   - Best practices for data submission

4. **Admin Guides**
   - User and role management
   - Permission granting workflow
   - Monitoring and analytics
   - Security incident response

## Database Schema

### Users Table

   ```sql
   CREATE TABLE users (
      id SERIAL PRIMARY KEY,
      uuid UUID UNIQUE NOT NULL DEFAULT gen_random_uuid(),
      email VARCHAR(255) UNIQUE NOT NULL,
      name VARCHAR(255) NOT NULL,
      role VARCHAR(50) NOT NULL DEFAULT 'developer',
      email_verified BOOLEAN DEFAULT FALSE,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      last_login_at TIMESTAMP,
      is_active BOOLEAN DEFAULT TRUE
   );

   CREATE INDEX idx_users_email ON users(email);
   CREATE INDEX idx_users_uuid ON users(uuid);
   ```

### Applications Table

   ```sql
   CREATE TABLE applications (
      id SERIAL PRIMARY KEY,
      user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
      name VARCHAR(255) NOT NULL,
      description TEXT,
      website VARCHAR(500),
      callback_url VARCHAR(500),
      is_active BOOLEAN DEFAULT TRUE,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
   );

   CREATE INDEX idx_applications_user_id ON applications(user_id);
   ```

### API Keys Table

   ```sql
   CREATE TABLE api_keys (
      id SERIAL PRIMARY KEY,
      application_id INTEGER NOT NULL REFERENCES applications(id) ON DELETE CASCADE,
      key_hash VARCHAR(255) UNIQUE NOT NULL,  -- Hashed API key
      key_prefix VARCHAR(20) NOT NULL,         -- First few chars for identification
      scope VARCHAR(50) NOT NULL DEFAULT 'read',
      rate_limit_per_hour INTEGER DEFAULT 1000,
      quota_limit_per_month INTEGER,
      is_active BOOLEAN DEFAULT TRUE,
      last_used_at TIMESTAMP,
      expires_at TIMESTAMP,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      revoked_at TIMESTAMP
   );

   CREATE INDEX idx_api_keys_hash ON api_keys(key_hash);
   CREATE INDEX idx_api_keys_app_id ON api_keys(application_id);
   ```

### API Usage Statistics Table

   ```sql
   CREATE TABLE api_usage_stats (
      id SERIAL PRIMARY KEY,
      api_key_id INTEGER REFERENCES api_keys(id) ON DELETE SET NULL,
      endpoint VARCHAR(255) NOT NULL,
      method VARCHAR(10) NOT NULL,
      status_code INTEGER NOT NULL,
      response_time_ms INTEGER,
      ip_address INET,
      user_agent TEXT,
      requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
   );

   CREATE INDEX idx_usage_api_key_id ON api_usage_stats(api_key_id);
   CREATE INDEX idx_usage_requested_at ON api_usage_stats(requested_at);
   CREATE INDEX idx_usage_endpoint ON api_usage_stats(endpoint);
   ```

### Permissions Table

   ```sql
   CREATE TABLE permissions (
      id SERIAL PRIMARY KEY,
      user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
      permission VARCHAR(100) NOT NULL,
      calendar_id VARCHAR(50),  -- NULL for global permissions
      granted_by INTEGER REFERENCES users(id),
      granted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      expires_at TIMESTAMP,
      UNIQUE(user_id, permission, calendar_id)
   );

   CREATE INDEX idx_permissions_user_id ON permissions(user_id);
   CREATE INDEX idx_permissions_calendar_id ON permissions(calendar_id);
   ```

### Audit Log Table

   ```sql
   CREATE TABLE audit_log (
      id SERIAL PRIMARY KEY,
      user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
      action VARCHAR(100) NOT NULL,
      resource_type VARCHAR(50) NOT NULL,
      resource_id VARCHAR(100),
      details JSONB,
      ip_address INET,
      user_agent TEXT,
      success BOOLEAN DEFAULT TRUE,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
   );

   CREATE INDEX idx_audit_user_id ON audit_log(user_id);
   CREATE INDEX idx_audit_created_at ON audit_log(created_at);
   CREATE INDEX idx_audit_action ON audit_log(action);
   ```

## Migration Strategy

### For Existing API Consumers

1. **Grace Period (6 months)**
   - Announce authentication requirements
   - Provide migration timeline
   - Offer support for migration

2. **Dual Mode Operation**
   - Support both authenticated and anonymous requests
   - Apply stricter rate limits to anonymous requests
   - Track both in usage statistics

3. **Gradual Enforcement**
   - Month 1-2: Warnings for unauthenticated requests
   - Month 3-4: Reduced rate limits for unauthenticated
   - Month 5-6: Encourage migration with support
   - Month 7+: Require authentication for all write operations

### For New Features

- All new sensitive endpoints require authentication from day one
- Read-only endpoints can remain public with API key tracking

## Monitoring & Metrics

### Key Metrics to Track

1. **Authentication**
   - Login success/failure rates
   - Registration conversion rate
   - Email verification rates
   - MFA adoption rate

2. **API Usage**
   - Total requests per day/month
   - Requests per application
   - Endpoint popularity
   - API version distribution
   - Error rates per endpoint

3. **Performance**
   - Authentication middleware latency
   - Rate limiting overhead
   - Database query performance
   - API response times

4. **Security**
   - Failed authentication attempts
   - Revoked API keys
   - Rate limit violations
   - Permission denial events
   - Suspicious activity alerts

## Cost Estimation

### Supabase Approach (Recommended)

- **Free tier**: Up to 50,000 monthly active users
- **Pro tier**: $25/month + usage
- **Self-hosting**: Infrastructure costs only

### Infrastructure Requirements

- **PostgreSQL Database**: User data, API keys, statistics
- **Redis**: Rate limiting, session storage
- **Application Server**: Existing PHP infrastructure
- **Monitoring**: Logs, metrics, alerts

## Success Criteria

1. **Developer Experience**
   - < 5 minutes to register and get first API key
   - Clear documentation and examples
   - Usage statistics accessible in dashboard
   - Support response time < 24 hours

2. **Security**
   - Zero unauthorized data modifications
   - 100% of write operations authenticated
   - All sensitive actions logged
   - GDPR/privacy compliance

3. **Performance**
   - < 10ms authentication overhead
   - < 5ms rate limiting check
   - 99.9% uptime for auth service
   - No degradation in API response times

4. **Adoption**
   - 80% of active developers registered within 6 months
   - 90% of API requests tracked within 12 months
   - 100% of write operations authenticated within 12 months

## Next Steps

1. Review and approve this roadmap
2. Choose authentication provider (Supabase recommended)
3. Set up development environment with Supabase/chosen provider
4. Begin Phase 1: Infrastructure Setup
5. Iterate on feedback from early adopters
