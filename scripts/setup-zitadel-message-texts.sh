#!/bin/bash
# Zitadel Message Text Setup Script for LiturgicalCalendar.
#
# Walks infrastructure/zitadel-message-texts/{locale}/{template}.json and
# PUTs each file to the umbrella Zitadel Management API:
#
#     PUT {ZITADEL_URL}/management/v1/text/message/{template}/{locale}
#
# Scope: PUTs are made authenticated as a machine user inside the
# LiturgicalCalendar Org, so overrides only apply to that org.
#
# Usage:
#   ZITADEL_URL=https://auth.catholicdigitalcommons.org \
#   ZITADEL_PAT=<personal-access-token> \
#     ./scripts/setup-zitadel-message-texts.sh
#
#   ./scripts/setup-zitadel-message-texts.sh --dry-run
#   ./scripts/setup-zitadel-message-texts.sh --locale=en
#   ./scripts/setup-zitadel-message-texts.sh --template=verify-email
#   ./scripts/setup-zitadel-message-texts.sh --locale=en --template=verify-email
#
# Required env (unless --dry-run):
#   ZITADEL_URL   base URL of the umbrella Zitadel
#   ZITADEL_PAT   PAT for a machine user with ORG_OWNER or
#                 ORG_SETTINGS_MANAGER inside LiturgicalCalendar Org
#
# Idempotent: PUTting the same body twice is a no-op on Zitadel's side.

set -euo pipefail

if [ -z "${BASH_VERSINFO[0]:-}" ] || [ "${BASH_VERSINFO[0]}" -lt 4 ]; then
    echo "Error: Bash >= 4 is required (current: ${BASH_VERSION:-unknown})." >&2
    echo "On macOS, run: brew install bash && /opt/homebrew/bin/bash $0 $*" >&2
    exit 1
fi

# Colors (skip when not a TTY)
if [ -t 1 ]; then
    RED='\033[0;31m'
    GREEN='\033[0;32m'
    YELLOW='\033[1;33m'
    BLUE='\033[0;34m'
    DIM='\033[2m'
    NC='\033[0m'
else
    RED=''; GREEN=''; YELLOW=''; BLUE=''; DIM=''; NC=''
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TEMPLATES_DIR="${SCRIPT_DIR}/../infrastructure/zitadel-message-texts"

# Zitadel's 22 built-in locales. Any locale dir not in this set is rejected
# upstream — we fail fast locally with a clear message instead of letting
# the API return 400 for every PUT.
SUPPORTED_LOCALES=(ar bg cs de en es fr hu id it ja ko mk nl pl pt ro ru sv tr uk zh)

DRY_RUN=false
LOCALE_FILTER=""
TEMPLATE_FILTER=""

usage() {
    cat <<EOF
Usage: $(basename "$0") [options]

Options:
  --dry-run              Print what would be PUT without making network calls
  --locale=<code>        Only push templates under {locale}/ (e.g. --locale=en)
  --template=<name>      Only push {name}.json across all locales (e.g. --template=verify-email)
  -h, --help             Show this help

Required env (unless --dry-run):
  ZITADEL_URL            e.g. https://auth.catholicdigitalcommons.org
  ZITADEL_PAT            Personal Access Token (machine user in target org)

EOF
}

for arg in "$@"; do
    case "$arg" in
        --dry-run) DRY_RUN=true ;;
        --locale=*) LOCALE_FILTER="${arg#*=}" ;;
        --template=*) TEMPLATE_FILTER="${arg#*=}" ;;
        -h|--help) usage; exit 0 ;;
        *)
            echo -e "${RED}Unknown argument: ${arg}${NC}" >&2
            usage >&2
            exit 2
            ;;
    esac
done

if ! command -v jq >/dev/null 2>&1; then
    echo -e "${RED}Error: jq is required but not installed.${NC}" >&2
    exit 1
fi
if ! command -v curl >/dev/null 2>&1; then
    echo -e "${RED}Error: curl is required but not installed.${NC}" >&2
    exit 1
fi

if [ ! -d "$TEMPLATES_DIR" ]; then
    echo -e "${RED}Error: templates directory not found: ${TEMPLATES_DIR}${NC}" >&2
    exit 1
fi

# Validate --locale filter against the supported set if provided.
if [ -n "$LOCALE_FILTER" ]; then
    valid=false
    for sl in "${SUPPORTED_LOCALES[@]}"; do
        [ "$sl" = "$LOCALE_FILTER" ] && valid=true && break
    done
    if ! $valid; then
        echo -e "${RED}Error: locale '${LOCALE_FILTER}' is not in Zitadel's supported set.${NC}" >&2
        echo -e "${DIM}Supported: ${SUPPORTED_LOCALES[*]}${NC}" >&2
        exit 2
    fi
fi

if ! $DRY_RUN; then
    : "${ZITADEL_URL:?need ZITADEL_URL (e.g. https://auth.catholicdigitalcommons.org)}"
    : "${ZITADEL_PAT:?need ZITADEL_PAT (Personal Access Token for an org machine user)}"
    # Strip trailing slash from URL so we don't end up with double slashes.
    ZITADEL_URL="${ZITADEL_URL%/}"
fi

echo -e "${BLUE}Zitadel message-text sync${NC}"
echo -e "  templates dir: ${DIM}${TEMPLATES_DIR}${NC}"
if $DRY_RUN; then
    echo -e "  mode:          ${YELLOW}DRY RUN${NC} (no network calls)"
else
    echo -e "  target:        ${ZITADEL_URL}"
fi
[ -n "$LOCALE_FILTER" ]   && echo -e "  locale filter: ${LOCALE_FILTER}"
[ -n "$TEMPLATE_FILTER" ] && echo -e "  template filter: ${TEMPLATE_FILTER}"
echo

pushed=0
skipped=0
failed=0

for locale_dir in "$TEMPLATES_DIR"/*/; do
    locale=$(basename "$locale_dir")

    # Skip a dir that's not a recognised Zitadel locale — surfaces typos.
    in_supported=false
    for sl in "${SUPPORTED_LOCALES[@]}"; do
        [ "$sl" = "$locale" ] && in_supported=true && break
    done
    if ! $in_supported; then
        echo -e "${YELLOW}skip${NC} ${locale}/  (not in Zitadel's supported locales)"
        continue
    fi

    if [ -n "$LOCALE_FILTER" ] && [ "$locale" != "$LOCALE_FILTER" ]; then
        continue
    fi

    for tmpl_file in "$locale_dir"*.json; do
        [ -f "$tmpl_file" ] || continue

        template=$(basename "$tmpl_file" .json)

        if [ -n "$TEMPLATE_FILTER" ] && [ "$template" != "$TEMPLATE_FILTER" ]; then
            continue
        fi

        # Validate JSON before sending.
        if ! jq empty "$tmpl_file" >/dev/null 2>&1; then
            echo -e "${RED}fail${NC} ${locale}/${template}.json — invalid JSON"
            failed=$((failed + 1))
            continue
        fi

        url="${ZITADEL_URL:-(dry)}/management/v1/text/message/${template}/${locale}"

        if $DRY_RUN; then
            echo -e "${BLUE}PUT${NC}  ${url}"
            jq -c . "$tmpl_file"
            pushed=$((pushed + 1))
            continue
        fi

        # Make the request, capture HTTP status + body.
        http_code=$(curl -sS -o /tmp/.zitadel-msg-resp.$$ -w "%{http_code}" \
            -X PUT \
            -H "Authorization: Bearer ${ZITADEL_PAT}" \
            -H "Content-Type: application/json" \
            -d @"$tmpl_file" \
            "$url" || echo "000")

        if [ "$http_code" = "200" ]; then
            echo -e "${GREEN}ok${NC}   PUT ${template}/${locale}"
            pushed=$((pushed + 1))
        else
            echo -e "${RED}fail${NC} PUT ${template}/${locale} — HTTP ${http_code}"
            if [ -s /tmp/.zitadel-msg-resp.$$ ]; then
                jq -r '.message // .' /tmp/.zitadel-msg-resp.$$ 2>/dev/null | sed 's/^/       /' || \
                    sed 's/^/       /' /tmp/.zitadel-msg-resp.$$
            fi
            failed=$((failed + 1))
        fi
        rm -f /tmp/.zitadel-msg-resp.$$
    done
done

echo
echo -e "${BLUE}Summary${NC}"
echo -e "  pushed:  ${GREEN}${pushed}${NC}"
echo -e "  skipped: ${skipped}"
if [ "$failed" -gt 0 ]; then
    echo -e "  failed:  ${RED}${failed}${NC}"
    exit 1
fi
echo -e "  failed:  ${failed}"
