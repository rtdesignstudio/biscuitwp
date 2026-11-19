#!/usr/bin/env bash
# B7.1 browser test: zero requests to trackers before consent, for every
# library service, in WordPress (Pro Strict + Advanced) and standalone.
#
#   bash bin/e2e.sh
#
# Starts a throwaway WordPress Playground (the plugin as it ships + the
# tests/e2e/fixture mu-plugin) and a static server for the standalone page,
# runs tests/e2e/run.js (Playwright 1.59.1, Chromium from the local cache),
# stops everything (trap) and exits with the runner's code. Never downloads a
# browser. Needs: node_modules (PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD=1 npm install),
# python3, and the Playground CLI (npx; taken from the npm cache when present).
#
# Env overrides: CMP_E2E_READY_TIMEOUT (s, default 180), CMP_E2E_RUN_TIMEOUT
# (s, default 300: the whole browser run), CMP_E2E_PG_PORT,
# CMP_E2E_PLAYGROUND_CLI (default @wp-playground/cli@3.1.65), CMP_E2E_SETTLE_MS.
set -uo pipefail

cd "$(dirname "$0")/.."
ROOT="$(pwd)"
READY_TIMEOUT="${CMP_E2E_READY_TIMEOUT:-180}"
RUN_TIMEOUT="${CMP_E2E_RUN_TIMEOUT:-300}"
PLAYGROUND_CLI="${CMP_E2E_PLAYGROUND_CLI:-@wp-playground/cli@3.1.65}"
NPM_CACHE="${npm_config_cache:-/tmp/npmcache-cmp}"
PW_VERSION="1.59.1"
STARTED=$(date +%s)

fail() { echo "E2E: $*" >&2; exit 1; }

# 1. Prerequisites (nothing is downloaded here).
[ -f "$ROOT/node_modules/playwright/package.json" ] \
	|| fail "node_modules/playwright is missing. Install it without browsers: PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD=1 npm install"
HAVE_PW="$(node -p "require('$ROOT/node_modules/playwright/package.json').version" 2>/dev/null)"
[ "$HAVE_PW" = "$PW_VERSION" ] \
	|| fail "playwright $PW_VERSION expected, found '${HAVE_PW:-none}'. Run: PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD=1 npm install"
BROWSER="$(node -e "process.stdout.write(require('playwright').chromium.executablePath())" 2>/dev/null)"
if [ -z "$BROWSER" ] || [ ! -x "$BROWSER" ]; then
	fail "Chromium for Playwright $PW_VERSION is not in the local cache (${BROWSER:-unknown path}). This script never downloads browsers; install it yourself (npx playwright@$PW_VERSION install chromium) and run again."
fi
command -v python3 >/dev/null 2>&1 || fail "python3 is required for the standalone static server."
command -v npx >/dev/null 2>&1 || fail "npx is required to start WordPress Playground."

# 2. Throwaway workspace and free ports.
TMP="$(mktemp -d "${TMPDIR:-/tmp}/cmp-e2e.XXXXXX")" || fail "cannot create a temp directory"
PG_PID=""
HTTP_PID=""
RUN_PID=""
WD_PID=""

kill_tree() { # children first, so none is re-parented and missed
	local pid="$1" child
	for child in $(pgrep -P "$pid" 2>/dev/null); do
		kill_tree "$child"
	done
	kill "$pid" 2>/dev/null || true
}
cleanup() {
	local pid
	for pid in $WD_PID $RUN_PID $HTTP_PID $PG_PID; do
		[ -n "$pid" ] && kill_tree "$pid"
	done
	sleep 1
	for pid in $RUN_PID $HTTP_PID $PG_PID; do
		[ -n "$pid" ] && kill -9 "$pid" 2>/dev/null
	done
	wait 2>/dev/null
	rm -rf "$TMP"
}
trap cleanup EXIT
trap 'exit 130' INT TERM

free_port() {
	python3 -c 'import socket; s=socket.socket(); s.bind(("127.0.0.1",0)); print(s.getsockname()[1]); s.close()'
}
PG_PORT="${CMP_E2E_PG_PORT:-$(free_port)}"
ST_PORT="$(free_port)"
while [ "$ST_PORT" = "$PG_PORT" ]; do ST_PORT="$(free_port)"; done
python3 -c 'import socket,sys; s=socket.socket(); s.bind(("127.0.0.1",int(sys.argv[1]))); s.close()' "$PG_PORT" 2>/dev/null \
	|| fail "port $PG_PORT is already in use."

# The plugin exactly as release.sh ships it (same exclusions), plus the fixture.
mkdir -p "$TMP/plugin" "$TMP/mu" "$TMP/static/standalone" "$TMP/static/tests/e2e"
rsync -a --exclude 'docs' --exclude 'tests' --exclude 'bin' --exclude 'dist' \
	--exclude 'proxy' --exclude 'package.json' --exclude 'package-lock.json' --exclude 'node_modules' \
	--exclude '.superpowers' --exclude '.git*' --exclude '.DS_Store' \
	./ "$TMP/plugin/" || fail "cannot copy the plugin"
cp tests/e2e/fixture/cmp-e2e-fixture.php tests/e2e/fixture/cmp-e2e-expect.php "$TMP/mu/" || fail "cannot copy the fixture"
cp standalone/cmp.min.js standalone/cmp.min.css "$TMP/static/standalone/" || fail "cannot copy the standalone bundle"
node tests/e2e/fixture.js data/services.json "$TMP/mu/cmp-e2e-fragments.json" \
	tests/e2e/standalone.html "$TMP/static/tests/e2e/standalone.html" || fail "cannot generate the fixture"

cat >"$TMP/blueprint.json" <<'JSON'
{
	"landingPage": "/",
	"login": false,
	"steps": [
		{ "step": "activatePlugin", "pluginPath": "consent-manager-pro/consent-manager-pro.php" },
		{
			"step": "runPHP",
			"code": "<?php require_once '/wordpress/wp-load.php'; wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_name' => 'cmp-e2e', 'post_title' => 'CMP e2e', 'post_content' => '[cmp_e2e_embeds]' ) );"
		}
	]
}
JSON

# 3. WordPress Playground; wait for "Ready" (timeout READY_TIMEOUT s).
echo "E2E: starting WordPress Playground on 127.0.0.1:$PG_PORT …"
(
	cd "$TMP" && exec env npm_config_cache="$NPM_CACHE" npx -y --prefer-offline "$PLAYGROUND_CLI" server \
		--port="$PG_PORT" \
		--mount="$TMP/plugin:/wordpress/wp-content/plugins/consent-manager-pro" \
		--mount="$TMP/mu:/wordpress/wp-content/mu-plugins" \
		--blueprint="$TMP/blueprint.json"
) >"$TMP/playground.log" 2>&1 &
PG_PID=$!

waited=0
READY_LINE=""
while :; do
	READY_LINE="$(grep -m1 'Ready! WordPress is running on' "$TMP/playground.log" 2>/dev/null || true)"
	[ -n "$READY_LINE" ] && break
	if ! kill -0 "$PG_PID" 2>/dev/null; then
		grep -v 'EBADENGINE\|npm warn' "$TMP/playground.log" | tail -20 >&2
		fail "WordPress Playground exited before it was ready."
	fi
	if [ "$waited" -ge "$READY_TIMEOUT" ]; then
		grep -v 'EBADENGINE\|npm warn' "$TMP/playground.log" | tail -20 >&2
		fail "WordPress Playground was not ready after ${READY_TIMEOUT}s."
	fi
	sleep 1
	waited=$((waited + 1))
done
BASE="http://127.0.0.1:$PG_PORT"
case "$READY_LINE" in
	*"127.0.0.1:$PG_PORT"*|*"localhost:$PG_PORT"*) ;;
	*) echo "$READY_LINE" >&2; fail "WordPress Playground is not running on the expected port $PG_PORT." ;;
esac
# "Ready" must mean this site answers, with the fixture loaded.
curl -sfL --max-time 30 "$BASE/?cmp_e2e_expect=1" 2>/dev/null | grep -q '"advancedBefore"' \
	|| fail "WordPress Playground on $BASE does not answer with the e2e fixture."
echo "E2E: Playground ready after ${waited}s ($BASE)."

# 4. Static server for the standalone page.
python3 -m http.server "$ST_PORT" --bind 127.0.0.1 --directory "$TMP/static" >"$TMP/static.log" 2>&1 &
HTTP_PID=$!
STANDALONE="http://127.0.0.1:$ST_PORT/tests/e2e/standalone.html"
for _ in $(seq 1 50); do
	curl -sf -o /dev/null "$STANDALONE" && break
	kill -0 "$HTTP_PID" 2>/dev/null || { cat "$TMP/static.log" >&2; fail "the static server did not start."; }
	sleep 0.2
done
curl -sf -o /dev/null "$STANDALONE" || fail "the static server does not answer on $STANDALONE."

# 5. The browser test.
# Watchdog: a hung browser must never stall release.sh.
node tests/e2e/run.js --base "$BASE" --standalone "$STANDALONE" &
RUN_PID=$!
(
	sleep "$RUN_TIMEOUT"
	echo "E2E: the browser run exceeded ${RUN_TIMEOUT}s; stopping it." >&2
	touch "$TMP/run-timeout"
	kill_tree "$RUN_PID"
) &
WD_PID=$!
wait "$RUN_PID"
CODE=$?
kill_tree "$WD_PID"
WD_PID=""
RUN_PID=""
if [ -e "$TMP/run-timeout" ]; then
	CODE=1
	echo "E2E: FAIL (timeout after ${RUN_TIMEOUT}s)." >&2
fi
echo "E2E: finished in $(( $(date +%s) - STARTED ))s, exit code $CODE."
# 6-7. trap stops everything; exit with the runner's code.
exit "$CODE"
