#!/bin/bash
set -a  # automatic export

load_env_file() {
  local file="$1"
  if [ -f "$file" ]; then
    export $(grep -v '^\s*#' "$file" | grep -v '^\s*$' | xargs)
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

export $(grep -v '^#' .env.development | xargs)
PHP_CLI_SERVER_WORKERS=6 php -S "${API_HOST}:${API_PORT}" > /dev/null 2>&1 & echo $! > server.pid
