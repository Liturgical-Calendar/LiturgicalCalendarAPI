# LiturgicalCalendar Infrastructure

This directory contains the infrastructure configuration for the LiturgicalCalendar RBAC and registration system.

## Components

- **Zitadel** - Identity provider for user authentication, registration, and role management
- **Login V2** - Next.js-based login UI with passkeys, flexible onboarding, and modern authentication features
- **PostgreSQL** - Database for Zitadel and application-specific data

## Architecture

```text
┌─────────────────┐     ┌─────────────────┐     ┌─────────────────┐
│   PostgreSQL    │────▶│     Zitadel     │────▶│    Login V2     │
│   Port: 5432    │     │   Port: 8080    │     │   Port: 8081    │
└─────────────────┘     └─────────────────┘     └─────────────────┘
                               │
                               ▼
                    ┌─────────────────────┐
                    │  Your Application   │
                    │  (API + Frontend)   │
                    └─────────────────────┘
```

## Quick Start

### 1. Start the Infrastructure

From the `LiturgicalCalendarAPI` directory:

```bash
docker compose up -d
```

### 2. Access Zitadel Console

Open [http://localhost:8080/ui/console](http://localhost:8080/ui/console) in your browser.

**Default Admin Credentials:**

- Username: `root@LiturgicalCalendar.localhost`
- Password: `RootPassword1!`

### 3. Configure Zitadel Project

After logging in to Zitadel Console:

1. **Create Project**: "LiturgicalCalendar"

2. **Create Roles** (in Project > Roles):
   - `admin` - System administrator
   - `developer` - API consumer (register apps, generate API keys)
   - `calendar_editor` - Calendar data contributor
   - `test_editor` - Test definition author

3. **Create Applications**:

   **API Application** (Machine-to-Machine):
   - Name: "LiturgicalCalendar API"
   - Type: API
   - Auth Method: Private Key JWT or Client Credentials

   **Frontend Application** (Web with PKCE):
   - Name: "LiturgicalCalendar Frontend"
   - Type: Web
   - Auth Method: PKCE
   - Redirect URIs:
     - `http://localhost:3000/auth/callback` (development)
     - `https://litcal.johnromanodorazio.com/auth/callback` (production)
   - Post Logout URIs:
     - `http://localhost:3000` (development)
     - `https://litcal.johnromanodorazio.com` (production)

4. **Enable User Self-Registration** (Organization Settings > Login Behavior):
   - Enable self-registration
   - Configure email verification
   - Set default role for new users (optional)

### 4. Configure Environment Variables

Copy the client IDs and configure your applications:

**API (.env):**

```env
# Zitadel Configuration
ZITADEL_ISSUER=http://localhost:8080
ZITADEL_CLIENT_ID=<frontend-client-id>
ZITADEL_PROJECT_ID=<project-id>

# PostgreSQL (for app-specific data)
DB_HOST=localhost
DB_PORT=5432
DB_NAME=litcal
DB_USER=litcal
DB_PASSWORD=litcal_secure_password
```

**Frontend (.env):**

```env
# Zitadel Configuration
ZITADEL_ISSUER=http://localhost:8080
ZITADEL_CLIENT_ID=<frontend-client-id>
ZITADEL_PROJECT_ID=<project-id>
```

## Port Summary

| Service    | Port | Purpose                                    |
|------------|------|--------------------------------------------|
| Zitadel    | 8080 | API, Console, OIDC endpoints               |
| Login V2   | 8081 | Login/Logout UI (passkeys, registration)   |
| PostgreSQL | 5432 | Database                                   |

## Database Schema

The `init-db.sql` script creates the application database:

### Zitadel Database (`zitadel`)

Managed entirely by Zitadel. Contains:

- Users
- Organizations
- Projects
- Roles
- Authentication data

### Application Database (`litcal`)

Application-specific data:

| Table                       | Purpose                                                              |
|-----------------------------|----------------------------------------------------------------------|
| `role_requests`             | Tracks user role assignment requests and approval workflow           |
| `user_calendar_permissions` | Calendar-specific permissions (e.g., "user X can edit USA calendar") |
| `permission_requests`       | Workflow for requesting calendar access                              |
| `applications`              | Registered applications for API key management                       |
| `api_keys`                  | API keys for application authentication                              |
| `audit_log`                 | Security and compliance audit trail                                  |

## Useful Commands

```bash
# Start services
docker compose up -d

# Stop services
docker compose down

# View logs
docker compose logs -f zitadel
docker compose logs -f login
docker compose logs -f db

# Reset everything (WARNING: destroys data)
docker compose down -v
docker compose up -d

# Connect to PostgreSQL
docker compose exec db psql -U postgres

# Connect to application database
docker compose exec db psql -U litcal -d litcal
```

## Troubleshooting

### Zitadel won't start

Check if PostgreSQL is healthy:

```bash
docker compose ps
docker compose logs db
```

### Login V2 not working

Check if Zitadel is healthy and the PAT was generated:

```bash
docker compose logs login
docker compose exec zitadel cat /current-dir/login-client.pat
```

### Cannot connect to Zitadel Console

Ensure port 8080 is not in use:

```bash
lsof -i :8080
```

### Database connection issues

Verify the database was initialized:

```bash
docker compose exec db psql -U postgres -c '\l'
```

You should see both `zitadel` and `litcal` databases.

## Production Considerations

For production deployment:

1. **Change all default passwords** in `docker-compose.yml` and `init-db.sql`
2. **Generate a secure master key**: `openssl rand -hex 16`
3. **Enable TLS**: Set `ZITADEL_EXTERNALSECURE=true` and configure certificates
4. **Update Login V2 URLs**: Change `localhost:8081` to your production domain
5. **Use environment-specific configuration files**
6. **Set up database backups**
7. **Configure proper CORS origins**
