#!/usr/bin/env bash
#
# Fail if a deploy would ship a tracked file that is not part of the runtime
# payload (#deploy-hygiene).
#
# Why this exists: the vhost docroot is the *application root*, not public/,
# so every deployed file is readable over HTTP. rsync-exclude.txt is a
# denylist, which means each new dev-only file at the repo root has to be
# remembered — and twice it was not (phpstan-baseline.neon, .coderabbit.yaml),
# both of which ended up publicly fetchable.
#
# This flips the guarantee: the payload is checked against an ALLOWlist of
# top-level entries. A new dev-only file fails CI instead of reaching the web.
# Adding a genuinely new runtime path is a deliberate one-line edit here.
#
# Only git-tracked files are considered: a deploy runs from a fresh checkout,
# so untracked local scratch files are never on the runner.
set -euo pipefail

cd "$(dirname "$0")/../.."

# Top-level entries that legitimately ship. Keep the justification with each.
ALLOWED=(
  "src"                     # the application
  "jsondata"                # calendar source data + schemas
  "i18n"                    # compiled translations
  "public"                  # front controller + assets
  "scripts"                 # operational scripts run ON the server (RBAC runbook)
  "bin"                     # reconcile-outbox: systemd/cron server-side CLI
  "composer.json"           # Router::findProjectRoot() uses it as a root marker
  "doctrine-migrations.php" # read by MigrateHandler via PhpFile
  ".env.example"            # deliberately shipped so admins can diff against .env.production
)

tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT

git ls-files | sort -u > "$tmp/tracked"
rsync -a --dry-run --out-format='%n' \
  --exclude-from=.github/deploy/rsync-exclude.txt \
  ./ "$tmp/dest/" 2>/dev/null | sed 's:/$::' | awk 'NF' | sort -u > "$tmp/shipped"

# Tracked ∩ shipped, reduced to top-level entry.
comm -12 "$tmp/tracked" "$tmp/shipped" | cut -d/ -f1 | sort -u > "$tmp/top"

status=0
while IFS= read -r entry; do
  [ -n "$entry" ] || continue
  for allowed in "${ALLOWED[@]}"; do
    [ "$entry" = "$allowed" ] && continue 2
  done
  if [ "$status" -eq 0 ]; then
    echo "::error::Deploy payload contains tracked entries that are not runtime files."
    echo "The vhost serves the application root, so anything below would be publicly readable."
    echo
  fi
  echo "  unexpected: $entry"
  comm -12 "$tmp/tracked" "$tmp/shipped" | grep -E "^${entry}(/|$)" | sed 's/^/      /'
  status=1
done < "$tmp/top"

if [ "$status" -ne 0 ]; then
  echo
  echo "Either add it to .github/deploy/rsync-exclude.txt (dev-only), or add the"
  echo "top-level entry to ALLOWED in this script (genuinely needed at runtime)."
  echo
  echo "NOTE: excluding a file does NOT remove copies already on the server —"
  echo "excluded paths are also protected from --delete. Delete them there by hand."
  exit 1
fi

echo "Deploy payload OK: only runtime entries would ship."
