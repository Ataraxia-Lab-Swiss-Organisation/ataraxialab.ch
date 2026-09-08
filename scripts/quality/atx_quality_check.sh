#!/usr/bin/env bash
# ============================================================
# atx_quality_check.sh — Script qualité partagé Ataraxia Lab
# VERSION   : 1.0
# DATE      : 08.09.2026
# USAGE     : bash scripts/quality/atx_quality_check.sh pre-commit
#             bash scripts/quality/atx_quality_check.sh pre-push
# REPOS     : seo · AtxCron · ataraxialab.ch · ataraxia-journaux
# PHILOSOPHIE: ENRICHIT les hooks existants, ne les supplante pas.
#   pre-commit  : fichiers STAGÉS uniquement (<5s) — bloquant
#   pre-push    : diff vs remote (fichiers du push) — bloquant
#   Garde [skip ci] : INTERDIT si src/*.php|css|js présent
#   Notice prod : rappel systématique après chaque push de code
# ============================================================
set -uo pipefail

PHASE="${1:-pre-commit}"
REPO_ROOT="$(git rev-parse --show-toplevel)"
REPO_NAME="$(basename "$REPO_ROOT")"
WARN_COUNT=0

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'
BLUE='\033[0;34m'; BOLD='\033[1m'; NC='\033[0m'
ok()   { echo -e "${GREEN}  ✓${NC} $1"; }
warn() { echo -e "${YELLOW}  ⚠${NC} $1"; WARN_COUNT=$((WARN_COUNT+1)); }
fail() { echo -e "${RED}  ✗ BLOQUANT${NC} $1"; exit 1; }
info() { echo -e "${BLUE}[ATX-Q]${NC} $1"; }

echo -e "${BOLD}${BLUE}╔══ ATX QUALITY — ${PHASE} ══ ${REPO_NAME} ══╗${NC}"

# ── Collecter les fichiers selon la phase ─────────────────
if [ "$PHASE" = "pre-commit" ]; then
    FILES=$(git diff --cached --name-only --diff-filter=ACM 2>/dev/null || true)
else
    FILES=$(git diff --name-only "@{u}" HEAD 2>/dev/null \
         || git diff --name-only HEAD~1 HEAD 2>/dev/null || true)
fi

PHP_FILES=$(echo  "$FILES" | grep -E '\.php$'          || true)
CSS_FILES=$(echo  "$FILES" | grep -E '\.(css|scss)$'   || true)
JS_FILES=$(echo   "$FILES" | grep -E '\.(js|ts|mjs)$'  || true)
HTML_FILES=$(echo "$FILES" | grep -E '\.html$'         || true)
PY_FILES=$(echo   "$FILES" | grep -E '\.py$'           || true)
SRC_CODE=$(echo   "$FILES" | grep -E '^src/.*\.(php|css|scss|js|ts)$' || true)

# ══ GARDE [skip ci] ══════════════════════════════════════
if [ "$PHASE" = "pre-push" ] && [ -n "$SRC_CODE" ]; then
    MSG=$(git log --format=%s -1 2>/dev/null || true)
    if echo "$MSG" | grep -qiE '\[skip ci\]|\[no ci\]|skip-ci'; then
        echo ""
        echo -e "${RED}${BOLD}⛔  [skip ci] INTERDIT — fichiers src/ dans ce push :${NC}"
        echo "$SRC_CODE" | while IFS= read -r f; do echo "     → $f"; done
        echo ""
        echo -e "${YELLOW}   Retire [skip ci] ou utilise --no-verify (urgence absolue uniquement)${NC}"
        echo ""
        exit 1
    fi
fi

# ══ PHP — php -l ═════════════════════════════════════════
if [ -n "$PHP_FILES" ]; then
    PHPBIN=$(command -v php 2>/dev/null || echo "/c/xampp/php/php.exe")
    info "php -l sur $(echo "$PHP_FILES" | grep -c . ) fichier(s)..."
    ERR=0
    while IFS= read -r f; do
        [ -z "$f" ] && continue
        OUT=$("$PHPBIN" -l "$REPO_ROOT/$f" 2>&1)
        if echo "$OUT" | grep -qE 'Parse error|Fatal error'; then
            echo -e "  ${RED}✗${NC} $f"; echo "$OUT" | grep -E 'error' | head -2; ERR=1
        fi
    done <<< "$PHP_FILES"
    [ "$ERR" -eq 0 ] && ok "php -l OK" || fail "Syntaxe PHP — corriger avant $PHASE"
fi

# ══ CSS/SCSS — stylelint ══════════════════════════════════
if [ -n "$CSS_FILES" ]; then
    STYLELINT=$(cd "$REPO_ROOT" && \
        { [ -f "node_modules/.bin/stylelint" ] && echo "./node_modules/.bin/stylelint"; } \
        || command -v stylelint 2>/dev/null || echo "")
    if [ -n "$STYLELINT" ]; then
        info "stylelint sur $(echo "$CSS_FILES" | grep -c . ) fichier(s)..."
        CSS_ARGS=$(echo "$CSS_FILES" | tr '\n' ' ')
        SCFG=""; [ -f "$REPO_ROOT/.stylelintrc.json" ] && SCFG="--config $REPO_ROOT/.stylelintrc.json"
        # shellcheck disable=SC2086
        if ! (cd "$REPO_ROOT" && $STYLELINT $SCFG $CSS_ARGS 2>&1); then
            fail "stylelint — corriger les erreurs CSS avant $PHASE"
        fi
        ok "stylelint OK"
    else
        warn "stylelint absent — CSS non vérifié (npm i -g stylelint stylelint-config-standard)"
    fi
fi

# ══ JS/TS — Biome (prioritaire) ou ESLint (fallback) ══════
if [ -n "$JS_FILES" ]; then
    JS_ARGS=$(echo "$JS_FILES" | tr '\n' ' ')
    BIOME=$(cd "$REPO_ROOT" && \
        { [ -f "node_modules/.bin/biome" ] && echo "./node_modules/.bin/biome"; } \
        || command -v biome 2>/dev/null || echo "")
    ESLINT=$(cd "$REPO_ROOT" && \
        { [ -f "node_modules/.bin/eslint" ] && echo "./node_modules/.bin/eslint"; } \
        || command -v eslint 2>/dev/null || echo "")

    if [ -n "$BIOME" ]; then
        info "Biome check sur $(echo "$JS_FILES" | grep -c . ) fichier(s) [10-25x plus rapide qu'ESLint]..."
        # shellcheck disable=SC2086
        if ! (cd "$REPO_ROOT" && $BIOME check $JS_ARGS 2>&1); then
            fail "Biome — corriger avant $PHASE"
        fi
        ok "Biome OK"
    elif [ -n "$ESLINT" ]; then
        info "ESLint sur $(echo "$JS_FILES" | grep -c . ) fichier(s)..."
        ECFG=""
        [ -f "$REPO_ROOT/eslint.config.mjs" ] && ECFG="--config $REPO_ROOT/eslint.config.mjs"
        [ -f "$REPO_ROOT/.eslintrc.json" ]     && ECFG="--config $REPO_ROOT/.eslintrc.json"
        # shellcheck disable=SC2086
        if ! (cd "$REPO_ROOT" && $ESLINT $ECFG --max-warnings=0 $JS_ARGS 2>&1); then
            fail "ESLint — corriger avant $PHASE"
        fi
        ok "ESLint OK"
    else
        warn "Biome et ESLint absents — JS/TS non vérifié"
    fi
fi

# ══ HTML — HTMLHint ═══════════════════════════════════════
if [ -n "$HTML_FILES" ]; then
    HTMLHINT=$(cd "$REPO_ROOT" && \
        { [ -f "node_modules/.bin/htmlhint" ] && echo "./node_modules/.bin/htmlhint"; } \
        || command -v htmlhint 2>/dev/null || echo "")
    if [ -n "$HTMLHINT" ]; then
        info "HTMLHint sur $(echo "$HTML_FILES" | grep -c . ) fichier(s)..."
        HTML_ARGS=$(echo "$HTML_FILES" | tr '\n' ' ')
        HCFG=""; [ -f "$REPO_ROOT/.htmlhintrc" ] && HCFG="--config $REPO_ROOT/.htmlhintrc"
        # shellcheck disable=SC2086
        if ! (cd "$REPO_ROOT" && $HTMLHINT $HCFG $HTML_ARGS 2>&1); then
            fail "HTMLHint — corriger les erreurs HTML avant $PHASE"
        fi
        ok "HTMLHint OK"
    else
        warn "HTMLHint absent — HTML non vérifié (npm i -g htmlhint)"
    fi
fi

# ══ Python — py_compile ═══════════════════════════════════
if [ -n "$PY_FILES" ]; then
    info "py_compile sur $(echo "$PY_FILES" | grep -c . ) fichier(s)..."
    ERR=0
    while IFS= read -r f; do
        [ -z "$f" ] && continue
        if ! py -3 -m py_compile "$REPO_ROOT/$f" 2>/dev/null; then
            echo -e "  ${RED}✗${NC} $f"
            py -3 -m py_compile "$REPO_ROOT/$f" 2>&1 || true
            ERR=1
        fi
    done <<< "$PY_FILES"
    [ "$ERR" -eq 0 ] && ok "py_compile OK" || fail "Syntaxe Python — corriger avant $PHASE"
fi

# ══ NOTICE PROD ════════════════════════════════════════════
if [ "$PHASE" = "pre-push" ] && [ -n "$SRC_CODE" ]; then
    echo ""
    echo -e "${YELLOW}${BOLD}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
    echo -e "${YELLOW}${BOLD}  ⚡  CODE PUSHÉ — PAS ENCORE EN PROD${NC}"
    echo -e "${YELLOW}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
    echo -e "${YELLOW}  → Surveiller CI : http://localhost:3000/ataraxia-lab/${REPO_NAME}/actions${NC}"
    echo -e "${YELLOW}  → Deploy IK = CI verte uniquement (jamais SCP direct pour src/)${NC}"
    echo -e "${YELLOW}  → Vérifier [ CI STATUS ] dans atx_checkin au prochain check-in${NC}"
    echo -e "${YELLOW}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
fi

# ══ RÉSUMÉ ════════════════════════════════════════════════
if [ "$WARN_COUNT" -gt 0 ]; then
    echo -e "${YELLOW}[ATX-Q] $PHASE terminé — $WARN_COUNT avertissement(s) non bloquant(s)${NC}"
else
    echo -e "${GREEN}[ATX-Q] $PHASE OK${NC}"
fi
exit 0
