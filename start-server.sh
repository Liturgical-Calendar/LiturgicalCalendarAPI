#!/bin/bash

# Colors
RED='\033[0;31m'
YELLOW='\033[0;33m'
GREEN='\033[0;32m'
NC='\033[0m' # No Color

# Check for VS Code PID file
if [ -f "server.vscode.pid" ]; then
  pid=$(cat "server.vscode.pid")
  if ! kill -0 "$pid" 2>/dev/null; then
    echo -e "${YELLOW}Found stale server.vscode.pid (no process with PID $pid). Removing.${NC}"
    rm -f "server.vscode.pid"
  else
    echo -e "${YELLOW}Server already started in VSCode with PID $pid${NC}"
    exit 0
  fi
fi
if [ -f "server.pid" ]; then
  pid=$(cat server.pid)
  if kill -0 "$pid" 2>/dev/null; then
    if [ "$RUN_MODE" = "vscode" ]; then
      echo -e "❌ ${YELLOW}Server already started in background with PID $pid, please stop it from there before starting in VSCode.${NC}"
      exit 1
    else
      echo -e "${YELLOW}Server already started in background with PID $pid${NC}"
      exit 0
    fi
  else
    echo -e "${YELLOW}No process with PID $pid found. Removing stale server.pid.${NC}"
    rm server.pid
  fi
fi

echo "Starting PHP built-in web server..."

set -a  # automatic export

load_env_file() {
  local file="$1"
  if [ -f "$file" ]; then
    export $(grep -Ev '^[[:space:]]*#' "$file" | grep -Ev '^[[:space:]]*$' | xargs)
  fi
}

# Order in which files are loaded (later files' values will overwrite previous files' values)
load_env_file ".env"
load_env_file ".env.local"
load_env_file ".env.development"
load_env_file ".env.production"

set +a  # stop auto export

# Set defaults if variables are unset or empty
: "${API_PROTOCOL:=http}"
: "${API_HOST:=localhost}"
: "${API_PORT:=8000}"

if [ "$RUN_MODE" = "vscode" ]; then
  # Run in foreground
  # Save PID
  pid=$$
  echo "$pid" > server.vscode.pid
  exec php -S "${API_HOST}:${API_PORT}" -t public
else
  # Run in background
  PHP_CLI_SERVER_WORKERS=6 php -S "${API_HOST}:${API_PORT}" -t public > /dev/null 2>&1 &
  # Save PID
  pid=$!
  echo "$pid" > server.pid
fi

# Feedback
echo "Server started successfully at ${API_PROTOCOL}://${API_HOST}:${API_PORT}/ (PID: $pid)"
