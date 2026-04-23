#!/bin/bash
# Zitadel Setup Script for LiturgicalCalendar API
# Automates the creation of the project, roles, and OIDC applications in Zitadel.
# Run this after a fresh `docker compose up -d` with clean volumes.
#
# Usage:
#   ./scripts/setup-zitadel.sh                    # Display credentials only
#   ./scripts/setup-zitadel.sh --update-env       # Update .env files with credentials
#   ./scripts/setup-zitadel.sh --docker-init      # Start Docker stack and configure
#   ./scripts/setup-zitadel.sh --update-env --docker-init  # Full automated setup
#   ./scripts/setup-zitadel.sh --force-secrets    # Regenerate client secrets
#   ./scripts/setup-zitadel.sh --show-secrets     # Display full secrets in output

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Configuration
ZITADEL_URL="${ZITADEL_URL:-http://localhost:8080}"
FRONTEND_PORT="${FRONTEND_PORT:-3000}"
TESTS_PORT="${TESTS_PORT:-3003}"
MAX_RETRIES=30
RETRY_INTERVAL=5

# Project and role configuration
PROJECT_NAME="LiturgicalCalendar"
ROLES=("admin" "developer" "calendar_editor" "test_editor")

# Directories
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="${SCRIPT_DIR}/.."

# Parse command line arguments
UPDATE_ENV="${UPDATE_ENV:-false}"
DOCKER_INIT="${DOCKER_INIT:-false}"
FORCE_SECRETS="${FORCE_SECRETS:-false}"
SHOW_SECRETS="${SHOW_SECRETS:-false}"
for arg in "$@"; do
    case $arg in
        --update-env)
            UPDATE_ENV="true"
            ;;
        --docker-init)
            DOCKER_INIT="true"
            ;;
        --force-secrets)
            FORCE_SECRETS="true"
            ;;
        --show-secrets)
            SHOW_SECRETS="true"
            ;;
    esac
done

echo -e "${BLUE}========================================${NC}"
echo -e "${BLUE}  LiturgicalCalendar Zitadel Setup${NC}"
echo -e "${BLUE}========================================${NC}"
echo

# Function to check if stack is running
is_stack_running() {
    cd "$PROJECT_DIR"
    docker compose ps --status running 2>/dev/null | grep -q "zitadel"
}

# Function to wait for Zitadel to be ready
wait_for_zitadel() {
    echo -e "${YELLOW}Waiting for Zitadel to be ready...${NC}"
    for i in $(seq 1 $MAX_RETRIES); do
        if curl -s "${ZITADEL_URL}/debug/healthz" > /dev/null 2>&1; then
            echo -e "${GREEN}Zitadel is ready!${NC}"
            return 0
        fi
        echo "  Attempt $i/$MAX_RETRIES - Zitadel not ready yet..."
        sleep $RETRY_INTERVAL
    done
    echo -e "${RED}Zitadel did not become ready in time${NC}"
    exit 1
}

# Function to get the admin PAT from the project root (bind-mounted from Zitadel container)
get_admin_pat() {
    echo -e "${YELLOW}Getting admin PAT from Zitadel...${NC}" >&2
    local pat_file="${PROJECT_DIR}/admin.pat"
    for i in $(seq 1 $MAX_RETRIES); do
        if [ -f "$pat_file" ]; then
            PAT=$(cat "$pat_file" 2>/dev/null || true)
            if [ -n "$PAT" ] && [ ${#PAT} -gt 10 ]; then
                echo -e "${GREEN}Admin PAT retrieved successfully${NC}" >&2
                echo "$PAT"
                return 0
            fi
        fi
        echo "  Attempt $i/$MAX_RETRIES - PAT not available yet..." >&2
        sleep $RETRY_INTERVAL
    done
    echo -e "${RED}Failed to get admin PAT${NC}" >&2
    exit 1
}

# Function to get the organization ID of the default organization
get_org_id() {
    local pat="$1"
    echo -e "${YELLOW}Getting organization ID...${NC}" >&2

    local result
    result=$(curl -s -X POST "${ZITADEL_URL}/zitadel.org.v2.OrganizationService/ListOrganizations" \
        -H "Authorization: Bearer $pat" \
        -H "Connect-Protocol-Version: 1" \
        -H "Content-Type: application/json" \
        -d "{}")

    local org_id
    org_id=$(echo "$result" | jq -r '.result[0].id // empty')

    if [ -n "$org_id" ]; then
        echo -e "${GREEN}Organization ID: $org_id${NC}" >&2
        echo "$org_id"
    else
        echo -e "${RED}Failed to get organization ID: $result${NC}" >&2
        exit 1
    fi
}

# Function to create or find the project
create_project() {
    local pat="$1"
    local org_id="$2"
    echo -e "${YELLOW}Creating ${PROJECT_NAME} project...${NC}" >&2

    # Check if project already exists
    local existing
    existing=$(curl -s -X POST "${ZITADEL_URL}/zitadel.project.v2.ProjectService/ListProjects" \
        -H "Authorization: Bearer $pat" \
        -H "Connect-Protocol-Version: 1" \
        -H "Content-Type: application/json" \
        -d "{\"filters\": [{\"project_name_filter\": {\"projectName\": \"${PROJECT_NAME}\", \"method\": \"TEXT_FILTER_METHOD_EQUALS\"}}, {\"organization_id_filter\": {\"organizationId\": \"${org_id}\"}}]}")

    local existing_id
    existing_id=$(echo "$existing" | jq -r '.projects[0].projectId // empty')

    if [ -n "$existing_id" ]; then
        echo -e "${GREEN}Project already exists with ID: $existing_id${NC}" >&2
        # Ensure projectRoleAssertion is enabled (required for role claims in tokens)
        local update_result
        update_result=$(curl -s -X POST "${ZITADEL_URL}/zitadel.project.v2.ProjectService/UpdateProject" \
            -H "Authorization: Bearer $pat" \
            -H "Connect-Protocol-Version: 1" \
            -H "Content-Type: application/json" \
            -d "{\"projectId\": \"${existing_id}\", \"projectRoleAssertion\": true}")
        if echo "$update_result" | jq -e '.changeDate' > /dev/null 2>&1; then
            echo -e "${GREEN}Enabled projectRoleAssertion${NC}" >&2
        elif echo "$update_result" | jq -e '.code == "failed_precondition"' > /dev/null 2>&1; then
            echo -e "${GREEN}projectRoleAssertion already enabled${NC}" >&2
        else
            echo -e "${RED}Failed to enable projectRoleAssertion: $update_result${NC}" >&2
            exit 1
        fi
        echo "$existing_id"
        return 0
    fi

    # Create new project
    local result
    result=$(curl -s -X POST "${ZITADEL_URL}/zitadel.project.v2.ProjectService/CreateProject" \
        -H "Authorization: Bearer $pat" \
        -H "Connect-Protocol-Version: 1" \
        -H "Content-Type: application/json" \
        -d "{\"name\": \"${PROJECT_NAME}\", \"organizationId\": \"${org_id}\"}")

    local project_id
    project_id=$(echo "$result" | jq -r '.projectId // empty')

    if [ -n "$project_id" ]; then
        echo -e "${GREEN}Project created with ID: $project_id${NC}" >&2
        # Enable projectRoleAssertion so role claims appear in tokens
        local update_result
        update_result=$(curl -s -X POST "${ZITADEL_URL}/zitadel.project.v2.ProjectService/UpdateProject" \
            -H "Authorization: Bearer $pat" \
            -H "Connect-Protocol-Version: 1" \
            -H "Content-Type: application/json" \
            -d "{\"projectId\": \"${project_id}\", \"projectRoleAssertion\": true}")
        if ! echo "$update_result" | jq -e '.changeDate' > /dev/null 2>&1; then
            echo -e "${RED}Failed to enable projectRoleAssertion: $update_result${NC}" >&2
            exit 1
        fi
        echo -e "${GREEN}Enabled projectRoleAssertion${NC}" >&2
        echo "$project_id"
    else
        echo -e "${RED}Failed to create project: $result${NC}" >&2
        exit 1
    fi
}

# Function to create roles
create_roles() {
    local pat="$1"
    local project_id="$2"
    echo -e "${YELLOW}Creating project roles...${NC}" >&2

    # Fetch existing roles once
    local existing
    existing=$(curl -s -X POST "${ZITADEL_URL}/zitadel.project.v2.ProjectService/ListProjectRoles" \
        -H "Authorization: Bearer $pat" \
        -H "Connect-Protocol-Version: 1" \
        -H "Content-Type: application/json" \
        -d "{\"projectId\": \"${project_id}\"}")

    for role in "${ROLES[@]}"; do
        local existing_key
        existing_key=$(echo "$existing" | jq -r --arg r "$role" '(.projectRoles // [])[] | select(.key == $r) | .key // empty')

        if [ -n "$existing_key" ]; then
            echo -e "  ${GREEN}Role '${role}' already exists${NC}" >&2
            continue
        fi

        local result
        result=$(curl -s -X POST "${ZITADEL_URL}/zitadel.project.v2.ProjectService/AddProjectRole" \
            -H "Authorization: Bearer $pat" \
            -H "Connect-Protocol-Version: 1" \
        -H "Content-Type: application/json" \
            -d "{\"projectId\": \"${project_id}\", \"roleKey\": \"${role}\", \"displayName\": \"${role}\"}")

        if echo "$result" | jq -e '.creationDate' > /dev/null 2>&1; then
            echo -e "  ${GREEN}Role '${role}' created${NC}" >&2
        else
            echo -e "${RED}Failed to create role '${role}': ${result}${NC}" >&2
            exit 1
        fi
    done
}

# Function to resolve the best .env file for a given directory.
# Checks for .env.local, .env.development, and .env in order of priority.
# Returns the path to the first existing file, or empty if none found.
resolve_env_file() {
    local base_dir="$1"
    for variant in ".env.local" ".env.development" ".env"; do
        if [ -f "${base_dir}/${variant}" ]; then
            echo "${base_dir}/${variant}"
            return 0
        fi
    done
    echo ""
}

# Function to get existing client secret from .env file
get_existing_client_secret() {
    local client_id="$1"
    local existing_secret=""

    for base_dir in "$PROJECT_DIR" "${PROJECT_DIR}/../LiturgicalCalendarFrontend" "${PROJECT_DIR}/../UnitTestInterface"; do
        local env_file
        env_file=$(resolve_env_file "$base_dir")
        if [ -n "$env_file" ]; then
            local env_client_id
            env_client_id=$(grep "^ZITADEL_CLIENT_ID=" "$env_file" 2>/dev/null | cut -d= -f2-)
            if [ "$env_client_id" = "$client_id" ]; then
                existing_secret=$(grep "^ZITADEL_CLIENT_SECRET=" "$env_file" 2>/dev/null | cut -d= -f2-)
                if [ -n "$existing_secret" ]; then
                    break
                fi
            fi
        fi
    done

    echo "$existing_secret"
}

# Function to create or update an OIDC application
create_oidc_app() {
    local pat="$1"
    local project_id="$2"
    local app_name="$3"
    local redirect_uri="$4"
    local post_logout_uri="$5"

    echo -e "${YELLOW}Creating OIDC app: $app_name...${NC}" >&2

    # Check if app already exists
    local existing
    existing=$(curl -s -X POST "${ZITADEL_URL}/zitadel.application.v2.ApplicationService/ListApplications" \
        -H "Authorization: Bearer $pat" \
        -H "Connect-Protocol-Version: 1" \
        -H "Content-Type: application/json" \
        -d "{\"filters\": [{\"project_id_filter\": {\"projectId\": \"${project_id}\"}}, {\"name_filter\": {\"name\": \"${app_name}\"}}]}")

    local existing_id
    existing_id=$(echo "$existing" | jq -r '.applications[0].applicationId // empty')

    if [ -n "$existing_id" ]; then
        echo -e "${YELLOW}App already exists, getting client ID...${NC}" >&2
        local client_id
        client_id=$(echo "$existing" | jq -r '.applications[0].oidcConfiguration.clientId // empty')

        # Check if we have an existing secret
        local existing_secret
        existing_secret=$(get_existing_client_secret "$client_id")

        local client_secret
        if [ -n "$existing_secret" ] && [ "$FORCE_SECRETS" != "true" ]; then
            echo -e "${GREEN}Using existing client secret (sessions preserved)${NC}" >&2
            client_secret="$existing_secret"
        else
            if [ "$FORCE_SECRETS" = "true" ]; then
                echo -e "${YELLOW}Regenerating client secret (--force-secrets)...${NC}" >&2
            else
                echo -e "${YELLOW}No existing secret found, generating new one...${NC}" >&2
            fi
            local secret_result
            secret_result=$(curl -s -X POST "${ZITADEL_URL}/zitadel.application.v2.ApplicationService/GenerateClientSecret" \
                -H "Authorization: Bearer $pat" \
                -H "Connect-Protocol-Version: 1" \
        -H "Content-Type: application/json" \
                -d "{\"applicationId\": \"${existing_id}\", \"projectId\": \"${project_id}\"}")
            client_secret=$(echo "$secret_result" | jq -r '.clientSecret // empty')
        fi

        # Update config
        local update_result
        update_result=$(curl -s -X POST "${ZITADEL_URL}/zitadel.application.v2.ApplicationService/UpdateApplication" \
            -H "Authorization: Bearer $pat" \
            -H "Connect-Protocol-Version: 1" \
        -H "Content-Type: application/json" \
            -d "{
                \"projectId\": \"${project_id}\",
                \"applicationId\": \"${existing_id}\",
                \"oidcConfiguration\": {
                    \"redirectUris\": [\"$redirect_uri\"],
                    \"postLogoutRedirectUris\": [\"$post_logout_uri\"],
                    \"responseTypes\": [\"OIDC_RESPONSE_TYPE_CODE\"],
                    \"grantTypes\": [\"OIDC_GRANT_TYPE_AUTHORIZATION_CODE\", \"OIDC_GRANT_TYPE_REFRESH_TOKEN\"],
                    \"application_type\": \"OIDC_APP_TYPE_WEB\",
                    \"authMethodType\": \"OIDC_AUTH_METHOD_TYPE_NONE\",
                    \"accessTokenType\": \"OIDC_TOKEN_TYPE_JWT\",
                    \"development_mode\": true,
                    \"idTokenRoleAssertion\": true,
                    \"accessTokenRoleAssertion\": true,
                    \"idTokenUserinfoAssertion\": true
                }
            }")

        if ! echo "$update_result" | jq -e '.changeDate' > /dev/null 2>&1; then
            if echo "$update_result" | jq -e '.code == "failed_precondition"' > /dev/null 2>&1; then
                echo -e "${GREEN}OIDC config already up to date${NC}" >&2
            else
                echo -e "${RED}Failed to update OIDC config: $update_result${NC}" >&2
                exit 1
            fi
        fi

        echo "${client_id}:${client_secret}"
        return 0
    fi

    # Create new app
    local result
    result=$(curl -s -X POST "${ZITADEL_URL}/zitadel.application.v2.ApplicationService/CreateApplication" \
        -H "Authorization: Bearer $pat" \
        -H "Connect-Protocol-Version: 1" \
        -H "Content-Type: application/json" \
        -d "{
            \"projectId\": \"${project_id}\",
            \"name\": \"$app_name\",
            \"oidcConfiguration\": {
                \"redirectUris\": [\"$redirect_uri\"],
                \"postLogoutRedirectUris\": [\"$post_logout_uri\"],
                \"responseTypes\": [\"OIDC_RESPONSE_TYPE_CODE\"],
                \"grantTypes\": [\"OIDC_GRANT_TYPE_AUTHORIZATION_CODE\", \"OIDC_GRANT_TYPE_REFRESH_TOKEN\"],
                \"application_type\": \"OIDC_APP_TYPE_WEB\",
                \"authMethodType\": \"OIDC_AUTH_METHOD_TYPE_NONE\",
                \"accessTokenType\": \"OIDC_TOKEN_TYPE_JWT\",
                \"development_mode\": true,
                \"idTokenRoleAssertion\": true,
                \"accessTokenRoleAssertion\": true,
                \"idTokenUserinfoAssertion\": true
            }
        }")

    local client_id
    client_id=$(echo "$result" | jq -r '.oidcConfiguration.clientId // .clientId // empty')
    local client_secret
    client_secret=$(echo "$result" | jq -r '.oidcConfiguration.clientSecret // .clientSecret // empty')

    if [ -n "$client_id" ]; then
        echo -e "${GREEN}App created successfully${NC}" >&2
        echo "${client_id}:${client_secret}"
    else
        echo -e "${RED}Failed to create app: $result${NC}" >&2
        exit 1
    fi
}

# Function to update .env file
update_env_file() {
    local file="$1"
    local key="$2"
    local value="$3"

    if [ ! -f "$file" ]; then
        echo -e "${YELLOW}Creating $file${NC}"
        touch "$file"
    fi

    if grep -q "^${key}=" "$file" 2>/dev/null; then
        if [[ "$OSTYPE" == "darwin"* ]]; then
            sed -i '' "s|^${key}=.*|${key}=${value}|" "$file"
        else
            sed -i "s|^${key}=.*|${key}=${value}|" "$file"
        fi
    else
        echo "${key}=${value}" >> "$file"
    fi
}

# Function to create a service account (machine user) for tests
create_test_service_account() {
    local pat="$1"
    local org_id="$2"
    local username="test-service-account"
    local display_name="Test Service Account"

    echo -e "${YELLOW}Creating test service account...${NC}" >&2

    # Check if machine user already exists
    local existing
    existing=$(curl -s -X POST "${ZITADEL_URL}/v2/users" \
        -H "Authorization: Bearer $pat" \
        -H "Connect-Protocol-Version: 1" \
        -H "Content-Type: application/json" \
        -d "{\"queries\": [{\"user_name_query\": {\"userName\": \"${username}\", \"method\": \"TEXT_QUERY_METHOD_EQUALS\"}}, {\"organization_id_query\": {\"organizationId\": \"${org_id}\"}}]}")

    local existing_id
    existing_id=$(echo "$existing" | jq -r '.result[0].userId // empty')

    if [ -n "$existing_id" ]; then
        echo -e "${GREEN}Service account already exists with ID: $existing_id${NC}" >&2
        echo "$existing_id"
        return 0
    fi

    # Create machine user
    local result
    result=$(curl -s -X POST "${ZITADEL_URL}/v2/users/new" \
        -H "Authorization: Bearer $pat" \
        -H "Connect-Protocol-Version: 1" \
        -H "Content-Type: application/json" \
        -d "{
            \"username\": \"${username}\",
            \"organizationId\": \"${org_id}\",
            \"machine\": {
                \"name\": \"${display_name}\",
                \"accessTokenType\": \"ACCESS_TOKEN_TYPE_JWT\"
            }
        }")

    local user_id
    user_id=$(echo "$result" | jq -r '.id // empty')

    if [ -n "$user_id" ]; then
        echo -e "${GREEN}Service account created with ID: $user_id${NC}" >&2
        echo "$user_id"
    else
        echo -e "${RED}Failed to create service account: $result${NC}" >&2
        exit 1
    fi
}

# Function to assign a project role to a user via v2 AuthorizationService
assign_project_role() {
    local pat="$1"
    local project_id="$2"
    local org_id="$3"
    local user_id="$4"
    local role="$5"

    echo -e "${YELLOW}Assigning role '${role}' to user ${user_id}...${NC}" >&2

    # Check if an authorization for this project/user already exists
    local existing
    existing=$(curl -s -X POST "${ZITADEL_URL}/zitadel.authorization.v2.AuthorizationService/ListAuthorizations" \
        -H "Authorization: Bearer $pat" \
        -H "Connect-Protocol-Version: 1" \
        -H "Content-Type: application/json" \
        -d "{\"filters\": [{\"in_user_ids\": {\"ids\": [\"${user_id}\"]}}, {\"project_id\": {\"id\": \"${project_id}\"}}]}")

    local existing_auth_id
    existing_auth_id=$(echo "$existing" | jq -r '.authorizations[0].id // empty')

    if [ -n "$existing_auth_id" ]; then
        # Check if the requested role is already present
        local has_role
        has_role=$(echo "$existing" | jq -r --arg r "$role" \
            '[.authorizations[0].roles[].key] | if index($r) then "yes" else "no" end')

        if [ "$has_role" = "yes" ]; then
            echo -e "${GREEN}Role '${role}' already assigned${NC}" >&2
            return 0
        fi

        # Authorization exists but lacks the requested role — merge it in
        local current_roles
        current_roles=$(echo "$existing" | jq -r '[.authorizations[0].roles[].key] | join(",")')
        local merged_roles="${current_roles},${role}"

        # Build JSON array from comma-separated roles
        local role_keys_json
        role_keys_json=$(echo "$merged_roles" | tr ',' '\n' | sort -u | jq -R . | jq -s .)

        local result
        result=$(curl -s -X POST "${ZITADEL_URL}/zitadel.authorization.v2.AuthorizationService/UpdateAuthorization" \
            -H "Authorization: Bearer $pat" \
            -H "Connect-Protocol-Version: 1" \
            -H "Content-Type: application/json" \
            -d "{\"id\": \"${existing_auth_id}\", \"roleKeys\": ${role_keys_json}}")

        if echo "$result" | jq -e '.changeDate' > /dev/null 2>&1; then
            echo -e "${GREEN}Role '${role}' added to existing authorization${NC}" >&2
        else
            echo -e "${RED}Failed to update authorization: ${result}${NC}" >&2
            exit 1
        fi
        return 0
    fi

    # No authorization exists — create a new one
    local result
    result=$(curl -s -X POST "${ZITADEL_URL}/zitadel.authorization.v2.AuthorizationService/CreateAuthorization" \
        -H "Authorization: Bearer $pat" \
        -H "Connect-Protocol-Version: 1" \
        -H "Content-Type: application/json" \
        -d "{
            \"userId\": \"${user_id}\",
            \"projectId\": \"${project_id}\",
            \"organizationId\": \"${org_id}\",
            \"roleKeys\": [\"${role}\"]
        }")

    if echo "$result" | jq -e '.id' > /dev/null 2>&1; then
        echo -e "${GREEN}Role '${role}' assigned successfully${NC}" >&2
    else
        echo -e "${RED}Failed to create authorization: ${result}${NC}" >&2
        exit 1
    fi
}

# Function to assign an org-level role to a user via the v2 InternalPermissionService
# Required for Management API access (e.g., user lookups, grant management)
assign_org_role() {
    local pat="$1"
    local user_id="$2"
    local org_id="$3"
    local role="$4"

    echo -e "${YELLOW}Assigning org role '${role}' to user ${user_id}...${NC}" >&2

    local result
    result=$(curl -s -X POST "${ZITADEL_URL}/zitadel.internal_permission.v2.InternalPermissionService/CreateAdministrator" \
        -H "Authorization: Bearer $pat" \
        -H "Connect-Protocol-Version: 1" \
        -H "Content-Type: application/json" \
        -d "{
            \"userId\": \"${user_id}\",
            \"resource\": { \"organizationId\": \"${org_id}\" },
            \"roles\": [\"${role}\"]
        }")

    if echo "$result" | jq -e '.creationDate' > /dev/null 2>&1; then
        echo -e "${GREEN}Org role '${role}' assigned successfully${NC}" >&2
    elif echo "$result" | jq -e '.code == "already_exists"' > /dev/null 2>&1; then
        echo -e "${GREEN}Org role '${role}' already assigned${NC}" >&2
    else
        echo -e "${RED}Failed to assign org role '${role}': ${result}${NC}" >&2
        exit 1
    fi
}

# Function to create a personal access token for a service account
create_service_account_pat() {
    local pat="$1"
    local user_id="$2"
    local pat_file="${PROJECT_DIR}/service-account.pat"

    # Reuse existing PAT file unless --force-secrets is set
    if [ -f "$pat_file" ] && [ "$FORCE_SECRETS" != "true" ]; then
        echo -e "${GREEN}PAT file already exists (use --force-secrets to regenerate)${NC}" >&2
        cat "$pat_file"
        return 0
    fi

    echo -e "${YELLOW}Creating PAT for service account ${user_id}...${NC}" >&2

    local result
    result=$(curl -s -X POST "${ZITADEL_URL}/v2/users/${user_id}/pats" \
        -H "Authorization: Bearer $pat" \
        -H "Connect-Protocol-Version: 1" \
        -H "Content-Type: application/json" \
        -d "{
            \"expirationDate\": \"2030-01-01T00:00:00Z\"
        }")

    local token
    token=$(echo "$result" | jq -r '.token // empty')

    if [ -n "$token" ]; then
        echo "$token" > "$pat_file"
        chmod 600 "$pat_file" || { echo -e "${RED}Failed to set permissions on $pat_file${NC}" >&2; exit 1; }
        echo -e "${GREEN}Service account PAT created and saved to $pat_file${NC}" >&2
        echo "$token"
    else
        echo -e "${RED}Failed to create service account PAT: $result${NC}" >&2
        exit 1
    fi
}

# Function to generate a JWT key for the service account
generate_service_account_key() {
    local pat="$1"
    local user_id="$2"
    local key_file="${PROJECT_DIR}/test-service-account-key.json"

    echo -e "${YELLOW}Generating JWT key for service account...${NC}" >&2

    # Check if key file already exists
    if [ -f "$key_file" ] && [ "$FORCE_SECRETS" != "true" ]; then
        echo -e "${GREEN}Key file already exists (use --force-secrets to regenerate)${NC}" >&2
        echo "$key_file"
        return 0
    fi

    local result
    result=$(curl -s -X POST "${ZITADEL_URL}/v2/users/${user_id}/keys" \
        -H "Authorization: Bearer $pat" \
        -H "Connect-Protocol-Version: 1" \
        -H "Content-Type: application/json" \
        -d "{
            \"expirationDate\": \"2030-01-01T00:00:00Z\"
        }")

    local key_content
    key_content=$(echo "$result" | jq -r '.keyContent // empty')

    if [ -n "$key_content" ]; then
        # Decode base64 portably (GNU uses -d, BSD/macOS uses -D)
        if echo "dGVzdA==" | base64 -d >/dev/null 2>&1; then
            echo "$key_content" | base64 -d > "$key_file"
        elif echo "dGVzdA==" | base64 -D >/dev/null 2>&1; then
            echo "$key_content" | base64 -D > "$key_file"
        else
            echo -e "${RED}No supported base64 decode option found${NC}" >&2
            exit 1
        fi
        chmod 600 "$key_file" || { echo -e "${RED}Failed to set permissions on $key_file${NC}" >&2; exit 1; }
        echo -e "${GREEN}Key saved to $key_file${NC}" >&2
        echo "$key_file"
    else
        echo -e "${RED}Failed to generate key: $result${NC}" >&2
        exit 1
    fi
}

# Main execution
main() {
    # Handle --docker-init: start Docker stack if not running
    if [[ "$DOCKER_INIT" == "true" ]]; then
        cd "$PROJECT_DIR"
        if ! is_stack_running; then
            echo -e "${YELLOW}Starting Docker stack...${NC}"
            docker compose up -d
            echo -e "${GREEN}Docker stack started${NC}"
            echo
        fi
    fi

    # Wait for Zitadel
    wait_for_zitadel

    # Get admin PAT
    PAT=$(get_admin_pat)

    # Get organization ID
    ORG_ID=$(get_org_id "$PAT")

    # Create project
    PROJECT_ID=$(create_project "$PAT" "$ORG_ID")

    # Create roles
    echo
    create_roles "$PAT" "$PROJECT_ID"

    # Assign admin role to the root user (username includes org domain, e.g. root@org.localhost)
    echo
    local root_user_result
    root_user_result=$(curl -s -X POST "${ZITADEL_URL}/v2/users" \
        -H "Authorization: Bearer $PAT" \
        -H "Connect-Protocol-Version: 1" \
        -H "Content-Type: application/json" \
        -d '{"queries": [{"user_name_query": {"userName": "root", "method": "TEXT_QUERY_METHOD_STARTS_WITH"}}]}')
    ADMIN_USER_ID=$(echo "$root_user_result" | jq -r '.result[0].userId // empty')
    if [ -n "$ADMIN_USER_ID" ]; then
        assign_project_role "$PAT" "$PROJECT_ID" "$ORG_ID" "$ADMIN_USER_ID" "admin"
    fi

    # Create test service account
    echo
    SERVICE_ACCOUNT_ID=$(create_test_service_account "$PAT" "$ORG_ID")
    assign_project_role "$PAT" "$PROJECT_ID" "$ORG_ID" "$SERVICE_ACCOUNT_ID" "admin"
    assign_org_role "$PAT" "$SERVICE_ACCOUNT_ID" "$ORG_ID" "ORG_OWNER"
    SERVICE_KEY_FILE=$(generate_service_account_key "$PAT" "$SERVICE_ACCOUNT_ID")
    SERVICE_ACCOUNT_PAT=$(create_service_account_pat "$PAT" "$SERVICE_ACCOUNT_ID")

    # Create Frontend OIDC app
    echo
    FRONTEND_CREDS=$(create_oidc_app "$PAT" "$PROJECT_ID" "LiturgicalCalendar Frontend" \
        "http://localhost:${FRONTEND_PORT}/auth/callback.php" \
        "http://localhost:${FRONTEND_PORT}")
    FRONTEND_CLIENT_ID=$(echo "$FRONTEND_CREDS" | cut -d: -f1)

    # Create Tests OIDC app
    echo
    TESTS_CREDS=$(create_oidc_app "$PAT" "$PROJECT_ID" "LiturgicalCalendar Tests" \
        "http://localhost:${TESTS_PORT}/auth/callback.php" \
        "http://localhost:${TESTS_PORT}")
    TESTS_CLIENT_ID=$(echo "$TESTS_CREDS" | cut -d: -f1)

    echo
    echo -e "${BLUE}========================================${NC}"
    echo -e "${BLUE}  Configuration Complete!${NC}"
    echo -e "${BLUE}========================================${NC}"
    echo
    echo -e "${GREEN}Zitadel Credentials:${NC}"
    echo -e "  Project ID:         ${PROJECT_ID}"
    echo -e "  Frontend Client ID: ${FRONTEND_CLIENT_ID}"
    echo -e "  Tests Client ID:    ${TESTS_CLIENT_ID}"
    if [ "${SHOW_SECRETS}" = "true" ]; then
        echo -e "  SA PAT:          ${SERVICE_ACCOUNT_PAT}"
    else
        echo -e "  SA PAT:          ****${SERVICE_ACCOUNT_PAT: -4}"
    fi
    echo -e "  Test SA Key:     ${SERVICE_KEY_FILE}"
    echo
    echo -e "${GREEN}Roles created:${NC}"
    for role in "${ROLES[@]}"; do
        echo -e "  - ${role}"
    done
    echo

    # Update .env files
    if [[ "$UPDATE_ENV" == "true" ]]; then
        echo -e "${YELLOW}Updating environment files...${NC}"

        # Update each project's .env file (finds .env.local, .env.development, or .env)
        local projects=(
            "API:${PROJECT_DIR}"
            "Frontend:${PROJECT_DIR}/../LiturgicalCalendarFrontend"
            "Tests:${PROJECT_DIR}/../UnitTestInterface"
        )
        for entry in "${projects[@]}"; do
            local label="${entry%%:*}"
            local dir="${entry#*:}"
            local target
            target=$(resolve_env_file "$dir")
            if [ -n "$target" ]; then
                update_env_file "$target" "ZITADEL_ISSUER" "${ZITADEL_URL}"
                update_env_file "$target" "ZITADEL_PROJECT_ID" "$PROJECT_ID"
                if [ "$label" = "Tests" ]; then
                    update_env_file "$target" "ZITADEL_CLIENT_ID" "$TESTS_CLIENT_ID"
                else
                    update_env_file "$target" "ZITADEL_CLIENT_ID" "$FRONTEND_CLIENT_ID"
                fi
                # Only the API needs a machine token for Management API calls
                if [ "$label" = "API" ]; then
                    update_env_file "$target" "ZITADEL_MACHINE_TOKEN" "$SERVICE_ACCOUNT_PAT"
                else
                    # Remove stale ZITADEL_MACHINE_TOKEN from non-API env files
                    if grep -q "^ZITADEL_MACHINE_TOKEN=" "$target" 2>/dev/null; then
                        if [[ "$OSTYPE" == "darwin"* ]]; then
                            sed -i '' '/^ZITADEL_MACHINE_TOKEN=/d' "$target"
                        else
                            sed -i '/^ZITADEL_MACHINE_TOKEN=/d' "$target"
                        fi
                    fi
                fi
                echo -e "${GREEN}Updated ${label}: $target${NC}"
            else
                echo -e "${YELLOW}Skipped ${label} (no .env file found in $dir)${NC}"
            fi
        done

        echo
        echo -e "${GREEN}Environment files updated!${NC}"
        echo -e "${YELLOW}Remember to restart the API server to pick up the new credentials.${NC}"
    else
        echo -e "${YELLOW}To automatically update .env files, run with --update-env flag${NC}"
        echo
        echo -e "Manual configuration:"
        echo -e "  Add these to your .env.local file:"
        echo -e "     ZITADEL_ISSUER=${ZITADEL_URL}"
        echo -e "     ZITADEL_CLIENT_ID=${FRONTEND_CLIENT_ID}"
        echo -e "     ZITADEL_PROJECT_ID=${PROJECT_ID}"
    fi

    echo
    echo -e "${GREEN}Zitadel Admin Login:${NC}"
    echo -e "  URL:      ${ZITADEL_URL}/ui/console"
    echo -e "  Username: root@liturgicalcalendar.localhost"
    echo -e "  Password: RootPassword1!"
    echo
}

main "$@"
