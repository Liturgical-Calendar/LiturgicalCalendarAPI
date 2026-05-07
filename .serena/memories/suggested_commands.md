# Suggested Commands — LiturgicalCalendarAPI

## First-time setup

```bash
composer install                       # installs deps + runs Utilities::postInstall
cp .env.example .env.local             # then edit
vendor/bin/captainhook install --force # (re)install git hooks
```

## API dev server (needs >=6 PHP workers — internal route-to-route requests)

```bash
composer start                                                # uses start-server.sh (6 workers)
composer stop                                                 # uses stop-server.sh

# Or manually:
PHP_CLI_SERVER_WORKERS=6 php -S localhost:8000 -t public

# VSCode: Ctrl+Shift+B → litcal-api-with-browser  or  api-server-no-browser
```

Server PID written to `server.pid`; stop script removes it.

## WebSocket server (for UnitTestInterface)

```bash
composer ws:start          # ws-start-server.sh
composer ws:stop           # ws-stop-server.sh
# VSCode: Ctrl+Shift+B → litcal-tests-websockets
```

## Tests

```bash
composer test              # phpunit --testdox --display-warnings  (full)
composer test:quick        # excludes @group slow
```

## Static analysis & linting

```bash
composer analyse           # phpstan analyse  (level 10)
composer lint              # phpcs            (PSR-12)
composer lint:fix          # phpcbf
composer parallel-lint     # syntax check whole tree (excludes .git, vendor)
composer lint:openapi      # @redocly/cli lint
composer lint:md           # markdownlint-cli2 over **/*.md
composer lint:md:fix
```

## Markdown / docs (automatic via CaptainHook pre-commit)

Pre-commit auto-runs `composer lint:md` on staged `.md` files.
Reinstall hooks after editing `captainhook.json`:

```bash
vendor/bin/captainhook install --force
```

## Docker

```bash
DOCKER_BUILDKIT=1 docker build -t liturgy-api:{branch} .
docker run -p 8000:8000 -d liturgy-api:{branch}
# Or build directly from remote:
DOCKER_BUILDKIT=1 docker build -t liturgy-api:{branch} https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI.git#{branch}
```

## Auth helpers

```bash
# Generate JWT_SECRET (>=32 chars):
php -r "echo bin2hex(random_bytes(32));"

# Generate ADMIN_PASSWORD_HASH (Argon2id):
php -r "echo password_hash('yourpassword', PASSWORD_ARGON2ID);"
```

## Git workflow

```bash
git checkout development
git pull origin development
git checkout -b feature/your-feature-name   # always branch off development
# PRs always target `development`, never `stable` directly
```

## System utilities (Linux/WSL2)

Standard GNU coreutils. Prefer Serena's `find_file`, `search_for_pattern`, `find_symbol` over shell `find`/`grep` inside the repo.
