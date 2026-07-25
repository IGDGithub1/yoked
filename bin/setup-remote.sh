#!/usr/bin/env bash
#
# One-time SiteGround setup. Idempotent — safe to re-run.
#
# Creates the directory layout, puts storage outside the web root, and
# verifies the environment can actually run Yoked (PHP version, required
# extensions, DB reachability). Does NOT write config.php — that holds
# credentials and is created by hand on the server, once.
#
#   bin/setup-remote.sh
#   bin/setup-remote.sh --check    verify only, change nothing

set -euo pipefail

cd "$(dirname "$0")/.."

ENV_FILE="bin/deploy.env"
if [[ ! -f "$ENV_FILE" ]]; then
    echo "Missing $ENV_FILE — copy bin/deploy.env.example and fill it in." >&2
    exit 1
fi
# shellcheck disable=SC1090
source "$ENV_FILE"

: "${SG_HOST:?}" "${SG_USER:?}" "${SG_APP_DIR:?}"
: "${SG_PORT:=18765}"
: "${SG_SSH_KEY:=$HOME/.ssh/yoked_sg}"

SSH_OPTS=(-p "$SG_PORT" -o StrictHostKeyChecking=accept-new -o ConnectTimeout=20)
[[ -f "$SG_SSH_KEY" ]] && SSH_OPTS+=(-i "$SG_SSH_KEY")

CHECK_ONLY=0
[[ "${1:-}" == "--check" ]] && CHECK_ONLY=1

echo "→ ${SG_USER}@${SG_HOST}:${SG_APP_DIR}"

# Run the whole check remotely in one session rather than a dozen round trips.
ssh "${SSH_OPTS[@]}" "${SG_USER}@${SG_HOST}" \
    "APP_DIR='${SG_APP_DIR}' CHECK_ONLY='${CHECK_ONLY}' bash -s" <<'REMOTE'
set -uo pipefail

fail=0
say()  { printf '  %-34s %s\n' "$1" "$2"; }
ok()   { say "$1" "ok${2:+ — $2}"; }
warn() { say "$1" "WARN${2:+ — $2}"; }
bad()  { say "$1" "FAIL${2:+ — $2}"; fail=1; }

echo
echo "environment"

# --- PHP -------------------------------------------------------------------
PHPV=$(php -r 'echo PHP_VERSION;' 2>/dev/null || echo "")
if [[ -z "$PHPV" ]]; then
    bad "php" "not on PATH"
elif php -r 'exit(version_compare(PHP_VERSION, "8.1", ">=") ? 0 : 1);'; then
    ok "php" "$PHPV"
else
    bad "php" "$PHPV — need 8.1+"
fi

for ext in pdo_mysql mbstring json; do
    if php -m 2>/dev/null | grep -qix "$ext"; then
        ok "ext:$ext"
    else
        bad "ext:$ext" "missing"
    fi
done

# Imagick is only needed for progress-photo re-encoding, which isn't built
# yet — a warning, not a failure.
if php -m 2>/dev/null | grep -qix imagick; then
    ok "ext:imagick"
else
    warn "ext:imagick" "needed later for photo uploads"
fi

# Outbound HTTPS — required for the Anthropic API and Open Food Facts.
if php -r 'exit(function_exists("curl_init") ? 0 : 1);'; then
    ok "ext:curl"
else
    bad "ext:curl" "missing — cannot reach the Claude API"
fi

echo
echo "layout"

if [[ "$CHECK_ONLY" != "1" ]]; then
    mkdir -p "$APP_DIR"/{src/lib,database/migrations,bin,storage/uploads}
fi

for d in "$APP_DIR" "$APP_DIR/public_html"; do
    [[ -d "$d" ]] && ok "dir $(basename "$d")" || bad "dir $(basename "$d")" "missing"
done

# storage/ MUST sit outside public_html. Progress photos are served through a
# gateway that checks ownership; if they're under the web root they're
# fetchable by anyone who guesses a filename.
if [[ -d "$APP_DIR/storage" ]]; then
    if [[ "$APP_DIR/storage" == *"/public_html/"* ]]; then
        bad "storage location" "inside the web root"
    else
        ok "storage location" "outside web root"
    fi
    chmod 700 "$APP_DIR/storage" 2>/dev/null || true
fi

# Same argument for src/ — it holds config.php.
if [[ -d "$APP_DIR/src" ]]; then
    if [[ "$APP_DIR/src" == *"/public_html/"* ]]; then
        bad "src location" "inside the web root — config.php would be public"
    else
        ok "src location" "outside web root"
    fi
fi

echo
echo "config"

CFG="$APP_DIR/src/config.php"
if [[ -f "$CFG" ]]; then
    ok "src/config.php" "present"
    chmod 600 "$CFG" 2>/dev/null || true

    # Reachability, not a query: proves creds + pdo_mysql together.
    # CFG is exported so the PHP one-liners can read it without interpolating
    # a path into single-quoted code.
    export CFG
    DBCHECK=$(php -r '
        $c = require getenv("CFG");
        try {
            new PDO(
                sprintf("mysql:host=%s;dbname=%s;charset=utf8mb4",
                        $c["db"]["host"], $c["db"]["name"]),
                $c["db"]["user"], $c["db"]["pass"],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            echo "ok";
        } catch (Throwable $e) {
            echo "ERR: " . $e->getMessage();
        }
    ' 2>&1) || DBCHECK="ERR: php failed"

    if [[ "$DBCHECK" == "ok" ]]; then
        ok "database" "connected"
    else
        bad "database" "$DBCHECK"
    fi

    if php -r '$c = require getenv("CFG"); exit(empty($c["anthropic"]["api_key"]) ? 1 : 0);' 2>/dev/null; then
        ok "anthropic key" "set"
    else
        warn "anthropic key" "empty — coaching calls will fail"
    fi
else
    warn "src/config.php" "not yet created"
    echo
    echo "  Create it once:"
    echo "    cd $APP_DIR"
    echo "    cp src/config.example.php src/config.php"
    echo "    nano src/config.php"
fi

echo
if [[ $fail -eq 1 ]]; then
    echo "✗ blocking problems above."
    exit 1
fi
echo "✓ ready"
REMOTE
