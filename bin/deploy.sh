#!/usr/bin/env bash
#
# Deploy Yoked to SiteGround shared hosting.
#
# Ships the tree over SSH as a tar stream, then runs pending migrations.
# Uses tar rather than rsync because Git Bash on Windows has no rsync, and
# SiteGround's shared hosting doesn't guarantee it either.
#
#   bin/deploy.sh              deploy + migrate
#   bin/deploy.sh --no-migrate deploy only
#   bin/deploy.sh --dry-run    show what would ship, touch nothing
#
# NEVER overwritten on the server:
#   src/config.php   live credentials
#   storage/         uploads (progress photos)
#
# Requires bin/deploy.env (gitignored) — copy from deploy.env.example.

set -euo pipefail

cd "$(dirname "$0")/.."
APP_ROOT="$(pwd)"

# ---- config ---------------------------------------------------------------

ENV_FILE="bin/deploy.env"
if [[ ! -f "$ENV_FILE" ]]; then
    echo "Missing $ENV_FILE — copy bin/deploy.env.example and fill it in." >&2
    exit 1
fi
# shellcheck disable=SC1090
source "$ENV_FILE"

: "${SG_HOST:?set SG_HOST in $ENV_FILE}"
: "${SG_USER:?set SG_USER in $ENV_FILE}"
: "${SG_PORT:=18765}"
: "${SG_APP_DIR:?set SG_APP_DIR in $ENV_FILE}"
: "${SG_SSH_KEY:=$HOME/.ssh/yoked_sg}"

SSH_OPTS=(-p "$SG_PORT" -o StrictHostKeyChecking=accept-new -o ConnectTimeout=20)
[[ -f "$SG_SSH_KEY" ]] && SSH_OPTS+=(-i "$SG_SSH_KEY")

DO_MIGRATE=1
DRY_RUN=0
for arg in "$@"; do
    case "$arg" in
        --no-migrate) DO_MIGRATE=0 ;;
        --dry-run)    DRY_RUN=1 ;;
        *) echo "Unknown option: $arg" >&2; exit 1 ;;
    esac
done

# ---- what ships -----------------------------------------------------------
#
# Explicit allowlist, not an exclude list. An exclude list silently ships
# anything new you forget to add to it — including, on this project, a
# source-projects tree with live credentials in it.

PATHS=(
    src
    database
    bin
    public_html
)

EXCLUDES=(
    --exclude=.git
    --exclude=.gitignore
    --exclude=node_modules
    --exclude=source-projects
    --exclude=storage
    --exclude=src/config.php          # live credentials stay put
    --exclude=bin/deploy.env          # local only
    --exclude='*.log'
    --exclude='.DS_Store'
)

# Only ship paths that exist yet — the tree is still being built out.
PRESENT=()
for p in "${PATHS[@]}"; do
    [[ -e "$p" ]] && PRESENT+=("$p")
done
if [[ ${#PRESENT[@]} -eq 0 ]]; then
    echo "Nothing to deploy." >&2
    exit 1
fi

echo "→ deploying to ${SG_USER}@${SG_HOST}:${SG_APP_DIR}"
echo "  paths: ${PRESENT[*]}"

if [[ $DRY_RUN -eq 1 ]]; then
    echo
    echo "would ship:"
    tar -cf /dev/null "${EXCLUDES[@]}" -v "${PRESENT[@]}" 2>/dev/null | sed 's/^/  /'
    echo
    echo "would then run: php bin/migrate.php  (unless --no-migrate)"
    exit 0
fi

# ---- preflight ------------------------------------------------------------

echo -n "→ checking connection … "
if ! ssh "${SSH_OPTS[@]}" -o BatchMode=yes "${SG_USER}@${SG_HOST}" 'true' 2>/dev/null; then
    echo "FAILED"
    cat >&2 <<MSG

Cannot authenticate over SSH.

SiteGround requires key auth (no passwords). If you haven't set one up:

  ssh-keygen -t ed25519 -f ~/.ssh/yoked_sg -C "yoked-deploy" -N ""
  cat ~/.ssh/yoked_sg.pub

Then paste the public key into Site Tools → Devs → SSH Keys Manager.
MSG
    exit 1
fi
echo "ok"

REMOTE_PHP=$(ssh "${SSH_OPTS[@]}" "${SG_USER}@${SG_HOST}" 'php -r "echo PHP_VERSION;"' 2>/dev/null || echo "unknown")
echo "→ remote PHP: ${REMOTE_PHP}"

# ---- ship -----------------------------------------------------------------
#
# Stream a tar over ssh and extract remotely. No temp files either side, and
# no dependency on remote tooling beyond tar.

echo -n "→ shipping … "
tar -czf - "${EXCLUDES[@]}" "${PRESENT[@]}" \
    | ssh "${SSH_OPTS[@]}" "${SG_USER}@${SG_HOST}" \
        "mkdir -p '${SG_APP_DIR}' && tar -xzf - -C '${SG_APP_DIR}'"
echo "ok"

# ---- config check ---------------------------------------------------------

if ! ssh "${SSH_OPTS[@]}" "${SG_USER}@${SG_HOST}" "test -f '${SG_APP_DIR}/src/config.php'"; then
    cat >&2 <<MSG

⚠  No src/config.php on the server. Nothing will run without it.

Create it once:
  ssh -p ${SG_PORT} ${SG_USER}@${SG_HOST}
  cd ${SG_APP_DIR}
  cp src/config.example.php src/config.php
  nano src/config.php        # db creds + Anthropic key

Then re-run this script (or bin/deploy.sh --no-migrate to skip migrating).
MSG
    exit 1
fi

# ---- migrate --------------------------------------------------------------

if [[ $DO_MIGRATE -eq 1 ]]; then
    echo "→ migrating"
    # Safe to run every deploy: schema_migrations makes it a no-op when
    # there's nothing pending.
    ssh "${SSH_OPTS[@]}" "${SG_USER}@${SG_HOST}" \
        "cd '${SG_APP_DIR}' && php bin/migrate.php" 2>&1 | sed 's/^/  /'
fi

echo "✓ deployed"
