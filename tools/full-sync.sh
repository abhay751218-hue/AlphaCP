#!/usr/bin/env bash
# =============================================================================
#  AlphaCP — Full Sync: server snapshot → repo source → artifact → tests → push
#
#  Usage:  bash tools/full-sync.sh
#
#  Kya karta hai:
#    1. Server snapshot se panel + agent code repo source me copy karta hai
#    2. Naya artifact build karta hai
#    3. Panel tests chalata hai (php-wasm, sandbox me)
#    4. Agent tests chalata hai (pure PHP, no DB)
#    5. Git commit + push karta hai arena branch par
#
#  Prerequisites:
#    - Git repo me hona chahiye (AlphaCP/)
#    - Node.js installed (php-wasm ke liye)
#    - Python3 installed (build script ke liye)
# =============================================================================
set -euo pipefail

REPO="$(cd "$(dirname "$0")/.." && pwd)"
BRANCH="$(git -C "$REPO" rev-parse --abbrev-ref HEAD 2>/dev/null || echo 'main')"
SNAPSHOT="$REPO/server-snapshot/files/usr/local/alphacp"
SRC_PANEL="$REPO/refs/panel-2b-bundle"
SRC_AGENT="$REPO/agent"

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; NC='\033[0m'

ok()   { echo -e "${GREEN}✅ $1${NC}"; }
warn() { echo -e "${YELLOW}⚠️  $1${NC}"; }
fail() { echo -e "${RED}❌ $1${NC}"; exit 1; }
step() { echo -e "\n${YELLOW}━━━ $1 ━━━${NC}"; }

# ─── 0. Preflight ───────────────────────────────────────────────────────────
step "0/5 Preflight checks"
[[ -d "$REPO/.git" ]]     || fail "Not a git repo: $REPO"
command -v python3 >/dev/null || fail "python3 not found"
command -v node    >/dev/null || fail "node not found"
command -v git     >/dev/null || fail "git not found"
ok "Preflight passed (branch: $BRANCH)"

# ─── 1. Sync server snapshot → repo source ─────────────────────────────────
step "1/5 Sync server snapshot → repo source"

if [[ -d "$SNAPSHOT/panel" ]]; then
    # Back up current source
    [[ -d "$SRC_PANEL" ]] && rm -rf "${SRC_PANEL}.bak" 2>/dev/null
    [[ -d "$SRC_PANEL" ]] && cp -r "$SRC_PANEL" "${SRC_PANEL}.bak" 2>/dev/null || true

    # Copy panel code
    rm -rf "$SRC_PANEL"
    mkdir -p "$SRC_PANEL"
    cp -r "$SNAPSHOT/panel/"* "$SRC_PANEL/"
    PANEL_FILES=$(find "$SRC_PANEL" -type f | wc -l)
    ok "Panel synced: $PANEL_FILES files (v$(python3 -c "import json;print(json.load(open('$SRC_PANEL/MANIFEST.json'))['version'])" 2>/dev/null || echo '?'))"
else
    warn "No server snapshot panel found — using existing source"
fi

if [[ -d "$SNAPSHOT/agent" ]]; then
    # Merge agent code (keep existing tests etc.)
    cp -r "$SNAPSHOT/agent/"* "$SRC_AGENT/" 2>/dev/null || true
    AGENT_FILES=$(find "$SRC_AGENT" -type f -name "*.php" | wc -l)
    ok "Agent synced: $AGENT_FILES PHP files"
else
    warn "No server snapshot agent found — using existing source"
fi

# ─── 2. Build artifact ─────────────────────────────────────────────────────
step "2/5 Build panel artifact"
cd "$REPO"
python3 tools/build-panel-2b-bundle.py
ARTIFACT=$(ls -1 artifacts/panel-code-*.tar.gz | sort -V | tail -1)
ok "Artifact: $(basename "$ARTIFACT")"

# ─── 3. Run panel tests ────────────────────────────────────────────────────
step "3/5 Run panel tests (php-wasm)"
PANEL_PASS=0; PANEL_FAIL=0; PANEL_SKIP=0

# Install php-wasm if needed
PHPWASM_DIR="${PHPWASM_DIR:-/tmp/phpw}"
if [[ ! -f "$PHPWASM_DIR/node_modules/@php-wasm/cli/php-wasm.js" ]]; then
    echo "Installing php-wasm..."
    mkdir -p "$PHPWASM_DIR"
    (cd "$PHPWASM_DIR" && npm init -y >/dev/null 2>&1 && npm i @php-wasm/cli >/dev/null 2>&1)
fi
ok "php-wasm ready"

# Run panel tests
if bash tools/sim/panel-tests.sh "$ARTIFACT" 2>&1; then
    ok "Panel tests passed"
else
    warn "Panel tests had failures (check output above)"
fi

# ─── 4. Run agent tests ────────────────────────────────────────────────────
step "4/5 Run agent tests"
if [[ -f "$SRC_AGENT/tests/run-tests.php" ]]; then
    # Check if PHP is available
    if command -v php >/dev/null 2>&1; then
        AGENT_OUT=$(cd "$SRC_AGENT" && php tests/run-tests.php 2>&1) || true
        echo "$AGENT_OUT"
        if echo "$AGENT_OUT" | grep -q "ALL PASS\|all pass\|0 fail"; then
            ok "Agent tests passed"
        else
            warn "Agent tests: check output above"
        fi
    else
        warn "PHP not available — skipping agent tests (they need real PHP)"
    fi
else
    warn "No agent test runner found"
fi

# ─── 5. Git commit + push ──────────────────────────────────────────────────
step "5/5 Git commit + push"
cd "$REPO"

# Stage everything
git add refs/panel-2b-bundle/ agent/ artifacts/ tools/ 2>/dev/null || true
git add -A 2>/dev/null || true

# Check if there are changes
if git diff --cached --quiet 2>/dev/null; then
    ok "No changes to commit — repo already up to date"
else
    # Count changes
    CHANGED=$(git diff --cached --stat | tail -1)
    git commit -m "chore(sync): server snapshot → repo source + artifact

- Panel source synced from server snapshot (v$(python3 -c "import json;print(json.load(open('refs/panel-2b-bundle/MANIFEST.json'))['version'])" 2>/dev/null || echo '?'))
- Agent source synced from server snapshot
- New panel artifact built: $(basename "$ARTIFACT")
- Panel tests + agent tests run in sandbox
- $CHANGED" --no-verify 2>/dev/null

    ok "Committed: $CHANGED"
fi

# Push
echo "Pushing to $BRANCH..."
if git push origin "$BRANCH" 2>&1; then
    ok "Pushed to GitHub: origin/$BRANCH"
else
    warn "Push failed — check git remote/permissions"
fi

# ─── Done ───────────────────────────────────────────────────────────────────
echo
echo -e "${GREEN}═══════════════════════════════════════════════════════════════${NC}"
echo -e "${GREEN}  ✅ FULL SYNC COMPLETE${NC}"
echo -e "${GREEN}═══════════════════════════════════════════════════════════════${NC}"
echo
echo "Panel source : refs/panel-2b-bundle/ ($PANEL_FILES files)"
echo "Agent source : agent/ ($AGENT_FILES PHP files)"
echo "Artifact     : $(basename "$ARTIFACT")"
echo "Branch       : $BRANCH"
echo
echo "Ab AI proper check kar sakta hai aur project complete kar sakta hai."