#!/bin/bash
# OpenFGA Setup Script for LiturgicalCalendar API
# Creates the OpenFGA store if it doesn't exist yet, then discovers the
# authorization model already uploaded to it and wires the IDs into .env
# files. The authorization model itself is owned by cdcf-infra and uploaded
# by the `authz-seed` compose service (`docker compose up authz-seed`) — this
# script no longer creates or updates the model.
# Run this after `docker compose up -d` once the openfga service is healthy.
#
# Usage:
#   ./scripts/setup-openfga.sh                    # Display store/model IDs only
#   ./scripts/setup-openfga.sh --update-env       # Update .env files with IDs

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Configuration
OPENFGA_URL="${OPENFGA_API_URL:-http://localhost:${OPENFGA_HTTP_PORT:-8083}}"
STORE_NAME="LiturgicalCalendar"
MAX_RETRIES=30
RETRY_INTERVAL=5

# Directories
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="${SCRIPT_DIR}/.."

# Parse command line arguments
UPDATE_ENV="${UPDATE_ENV:-false}"
for arg in "$@"; do
    case $arg in
        --update-env)
            UPDATE_ENV="true"
            ;;
    esac
done

echo -e "${BLUE}========================================${NC}"
echo -e "${BLUE}  LiturgicalCalendar OpenFGA Setup${NC}"
echo -e "${BLUE}========================================${NC}"
echo

# Function to wait for OpenFGA to be ready
wait_for_openfga() {
    echo -e "${YELLOW}Waiting for OpenFGA to be ready...${NC}"
    for i in $(seq 1 $MAX_RETRIES); do
        if curl -s "${OPENFGA_URL}/healthz" > /dev/null 2>&1; then
            echo -e "${GREEN}OpenFGA is ready!${NC}"
            return 0
        fi
        echo "  Attempt $i/$MAX_RETRIES - OpenFGA not ready yet..."
        sleep $RETRY_INTERVAL
    done
    echo -e "${RED}OpenFGA did not become ready in time${NC}"
    exit 1
}

# Function to create or find the store
# Returns the store ID on stdout; all status messages go to stderr.
create_store() {
    echo -e "${YELLOW}Creating OpenFGA store '${STORE_NAME}'...${NC}" >&2

    # List existing stores to check if one already exists
    local existing
    existing=$(curl -sS --fail-with-body "${OPENFGA_URL}/stores") || {
        echo -e "${RED}Failed to list stores: ${existing}${NC}" >&2
        exit 1
    }

    local existing_id
    existing_id=$(echo "$existing" | jq -r --arg name "$STORE_NAME" '.stores[]? | select(.name == $name) | .id // empty')

    if [ -n "$existing_id" ]; then
        echo -e "${GREEN}Store already exists with ID: $existing_id${NC}" >&2
        echo "$existing_id"
        return 0
    fi

    # Create a new store
    local result
    result=$(curl -sS --fail-with-body -X POST "${OPENFGA_URL}/stores" \
        -H "Content-Type: application/json" \
        -d "{\"name\": \"${STORE_NAME}\"}") || {
        echo -e "${RED}Failed to create store: ${result}${NC}" >&2
        exit 1
    }

    local store_id
    store_id=$(echo "$result" | jq -r '.id // empty')

    if [ -n "$store_id" ]; then
        echo -e "${GREEN}Store created with ID: $store_id${NC}" >&2
        echo "$store_id"
    else
        echo -e "${RED}Failed to create store: $result${NC}" >&2
        exit 1
    fi
}

# The authorization model is owned by cdcf-infra and uploaded by the
# `authz-seed` compose service. This script no longer creates or updates it;
# it reads back what is in the store and wires the IDs into .env files.
get_latest_model_id() {
    local store_id="$1" body
    body=$(curl -sS --fail-with-body "${OPENFGA_URL}/stores/${store_id}/authorization-models?page_size=1") || {
        echo -e "${RED}Failed to read authorization models for store ${store_id}: ${body}${NC}" >&2
        exit 1
    }
    local model_id
    model_id=$(echo "$body" | jq -r '.authorization_models[0].id // empty')
    if [[ -z "$model_id" ]]; then
        echo -e "${RED}Store ${store_id} has no authorization model.${NC}" >&2
        echo -e "${YELLOW}Run 'docker compose up authz-seed' to seed it from cdcf-infra.${NC}" >&2
        exit 1
    fi
    echo "$model_id"
}

# Portable in-place sed (works on both GNU/Linux and macOS/BSD)
sed_inplace() {
    local expression="$1"
    local file="$2"
    local tmp
    tmp=$(mktemp)
    sed "$expression" "$file" > "$tmp" && mv "$tmp" "$file"
}

# Function to update .env files with OpenFGA configuration
update_env_file() {
    local file="$1"
    local store_id="$2"
    local model_id="$3"

    if [ ! -f "$file" ]; then
        return
    fi

    echo -e "${YELLOW}Updating $file...${NC}"

    # Update or add OPENFGA_API_URL
    if grep -q "^#\?[[:space:]]*OPENFGA_API_URL=" "$file"; then
        sed_inplace "s|^#\?[[:space:]]*OPENFGA_API_URL=.*|OPENFGA_API_URL=${OPENFGA_URL}|" "$file"
    else
        echo "" >> "$file"
        echo "OPENFGA_API_URL=${OPENFGA_URL}" >> "$file"
    fi

    # Update or add OPENFGA_STORE_ID
    if grep -q "^#\?[[:space:]]*OPENFGA_STORE_ID=" "$file"; then
        sed_inplace "s|^#\?[[:space:]]*OPENFGA_STORE_ID=.*|OPENFGA_STORE_ID=${store_id}|" "$file"
    else
        echo "OPENFGA_STORE_ID=${store_id}" >> "$file"
    fi

    # Update or add OPENFGA_MODEL_ID
    if grep -q "^#\?[[:space:]]*OPENFGA_MODEL_ID=" "$file"; then
        sed_inplace "s|^#\?[[:space:]]*OPENFGA_MODEL_ID=.*|OPENFGA_MODEL_ID=${model_id}|" "$file"
    else
        echo "OPENFGA_MODEL_ID=${model_id}" >> "$file"
    fi

    echo -e "${GREEN}Updated $file${NC}"
}

# Main execution
wait_for_openfga

STORE_ID=$(create_store)
MODEL_ID=$(get_latest_model_id "$STORE_ID")

echo
echo -e "${BLUE}========================================${NC}"
echo -e "${BLUE}  OpenFGA Setup Complete${NC}"
echo -e "${BLUE}========================================${NC}"
echo
echo -e "  Store ID:  ${GREEN}${STORE_ID}${NC}"
echo -e "  Model ID:  ${GREEN}${MODEL_ID}${NC}"
echo -e "  API URL:   ${GREEN}${OPENFGA_URL}${NC}"
if [ "${OPENFGA_PLAYGROUND_ENABLED:-true}" = "true" ]; then
    echo -e "  Playground: ${GREEN}http://localhost:${OPENFGA_PLAYGROUND_PORT:-3001}${NC}"
fi
echo

if [ "$UPDATE_ENV" = "true" ]; then
    echo -e "${YELLOW}Updating .env files...${NC}"

    # Find the best .env file to update (same priority as setup-zitadel.sh)
    # Checks .env.local first, then .env.development, then .env
    resolve_env_file() {
        local dir="$1"
        for variant in ".env.local" ".env.development" ".env"; do
            if [ -f "${dir}/${variant}" ]; then
                echo "${dir}/${variant}"
                return
            fi
        done
        echo ""
    }

    # Update the project's env file
    env_file=$(resolve_env_file "$PROJECT_DIR")
    if [ -n "$env_file" ]; then
        update_env_file "$env_file" "$STORE_ID" "$MODEL_ID"
    else
        echo -e "${YELLOW}No .env file found in ${PROJECT_DIR}. Creating .env.local${NC}"
        touch "${PROJECT_DIR}/.env.local"
        update_env_file "${PROJECT_DIR}/.env.local" "$STORE_ID" "$MODEL_ID"
    fi

    # Also update sibling repos if they exist
    for sibling_dir in "${PROJECT_DIR}/../LiturgicalCalendarFrontend" "${PROJECT_DIR}/../UnitTestInterface"; do
        sibling_env=$(resolve_env_file "$sibling_dir")
        if [ -n "$sibling_env" ]; then
            update_env_file "$sibling_env" "$STORE_ID" "$MODEL_ID"
        fi
    done

    # Write to Docker Compose .env files for variable substitution
    # Uses the internal Docker hostname (openfga:8080) instead of localhost
    for compose_dir in "$PROJECT_DIR" "${PROJECT_DIR}/../LiturgicalCalendarFrontend"; do
        if [ -f "${compose_dir}/docker-compose.yml" ] || [ -f "${compose_dir}/docker-compose.yaml" ]; then
            compose_env="${compose_dir}/.env"
            [ ! -f "$compose_env" ] && touch "$compose_env"
            sed_inplace "s|^OPENFGA_API_URL=.*|OPENFGA_API_URL=http://openfga:8080|" "$compose_env"
            grep -q "^OPENFGA_API_URL=" "$compose_env" || echo "OPENFGA_API_URL=http://openfga:8080" >> "$compose_env"
            sed_inplace "s|^OPENFGA_STORE_ID=.*|OPENFGA_STORE_ID=${STORE_ID}|" "$compose_env"
            grep -q "^OPENFGA_STORE_ID=" "$compose_env" || echo "OPENFGA_STORE_ID=${STORE_ID}" >> "$compose_env"
            sed_inplace "s|^OPENFGA_MODEL_ID=.*|OPENFGA_MODEL_ID=${MODEL_ID}|" "$compose_env"
            grep -q "^OPENFGA_MODEL_ID=" "$compose_env" || echo "OPENFGA_MODEL_ID=${MODEL_ID}" >> "$compose_env"
            echo -e "${GREEN}Updated compose .env: ${compose_env}${NC}"
        fi
    done

    echo
fi

echo -e "${YELLOW}Add these to your .env.local (if not using --update-env):${NC}"
echo
echo "  OPENFGA_API_URL=${OPENFGA_URL}"
echo "  OPENFGA_STORE_ID=${STORE_ID}"
echo "  OPENFGA_MODEL_ID=${MODEL_ID}"
echo
