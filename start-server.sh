#!/bin/bash
if [ -f "server.pid" ]; then
  pid=$(cat server.pid)
  if kill -0 "$pid" 2>/dev/null; then
    echo "Server already started"
    exit 0
  else
    echo "No process with PID $pid found. Removing stale server.pid."
    rm server.pid
  fi
fi

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

PHP_CLI_SERVER_WORKERS=6 php -S "${API_HOST}:${API_PORT}" > /dev/null 2>&1 & echo $! > server.pid
