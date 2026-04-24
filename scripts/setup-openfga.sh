#!/bin/bash
# OpenFGA Setup Script for LiturgicalCalendar API
# Creates an OpenFGA store and loads the authorization model.
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
MODEL_FILE="${PROJECT_DIR}/infrastructure/openfga-model.json"

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
    existing=$(curl -s "${OPENFGA_URL}/stores")

    local existing_id
    existing_id=$(echo "$existing" | jq -r --arg name "$STORE_NAME" '.stores[]? | select(.name == $name) | .id // empty')

    if [ -n "$existing_id" ]; then
        echo -e "${GREEN}Store already exists with ID: $existing_id${NC}" >&2
        echo "$existing_id"
        return 0
    fi

    # Create a new store
    local result
    result=$(curl -s -X POST "${OPENFGA_URL}/stores" \
        -H "Content-Type: application/json" \
        -d "{\"name\": \"${STORE_NAME}\"}")

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

# Function to load the authorization model
# Returns the model ID on stdout; all status messages go to stderr.
load_model() {
    local store_id="$1"
    echo -e "${YELLOW}Loading authorization model...${NC}" >&2

    if [ ! -f "$MODEL_FILE" ]; then
        echo -e "${RED}Model file not found: $MODEL_FILE${NC}" >&2
        exit 1
    fi

    # Check if a model already exists
    local existing_models
    existing_models=$(curl -s "${OPENFGA_URL}/stores/${store_id}/authorization-models")

    local existing_model_id
    existing_model_id=$(echo "$existing_models" | jq -r '.authorization_models[0]?.id // empty')

    if [ -n "$existing_model_id" ]; then
        echo -e "${GREEN}Authorization model already exists with ID: $existing_model_id${NC}" >&2
        echo -e "${YELLOW}To update the model, a new version will be created.${NC}" >&2

        # Compare model structures by normalizing both sides.
        # The API response includes extra default fields (condition, module, source_info,
        # empty relations objects) that aren't in the model file, so we strip them.
        local normalize_filter='walk(if type == "object" then with_entries(select(.value != "" and .value != null and .value != {})) else . end)'
        local existing_model
        existing_model=$(echo "$existing_models" | jq -cS ".authorization_models[0].type_definitions | ${normalize_filter}")
        local file_model
        file_model=$(jq -cS ".type_definitions | ${normalize_filter}" "$MODEL_FILE")

        if [ "$existing_model" = "$file_model" ]; then
            echo -e "${GREEN}Model is up to date — using existing model${NC}" >&2
            echo "$existing_model_id"
            return 0
        fi

        echo -e "${YELLOW}Model has changed — creating new version...${NC}" >&2
    fi

    # Load the model
    local model_payload
    model_payload=$(jq -c '.' "$MODEL_FILE")

    local result
    result=$(curl -s -X POST "${OPENFGA_URL}/stores/${store_id}/authorization-models" \
        -H "Content-Type: application/json" \
        -d "$model_payload")

    local model_id
    model_id=$(echo "$result" | jq -r '.authorization_model_id // empty')

    if [ -n "$model_id" ]; then
        echo -e "${GREEN}Authorization model loaded with ID: $model_id${NC}" >&2
        echo "$model_id"
    else
        echo -e "${RED}Failed to load authorization model: $result${NC}" >&2
        exit 1
    fi
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
MODEL_ID=$(load_model "$STORE_ID")

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
            local compose_env="${compose_dir}/.env"
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
