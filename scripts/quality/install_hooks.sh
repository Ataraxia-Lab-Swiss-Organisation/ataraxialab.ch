#!/usr/bin/env bash
# install_hooks.sh — ataraxialab.ch v1.1 — 08.09.2026
REPO_ROOT="$(git rev-parse --show-toplevel)"
HOOKS_DIR="$REPO_ROOT/.git/hooks"
echo "[install_hooks] ataraxialab.ch → $REPO_ROOT"

cat > "$HOOKS_DIR/pre-commit" << 'HOOK'
#!/usr/bin/env bash
set -e; ROOT="$(git rev-parse --show-toplevel)"
# ATX quality check (ESLint/Biome + stylelint + HTMLHint sur staged)
bash "$ROOT/scripts/quality/atx_quality_check.sh" pre-commit
HOOK
chmod +x "$HOOKS_DIR/pre-commit"
echo "  ✓ pre-commit → atx_quality_check (ESLint/Biome + stylelint + HTMLHint)"

cat > "$HOOKS_DIR/pre-push" << 'HOOK'
#!/usr/bin/env bash
set -uo pipefail; ROOT="$(git rev-parse --show-toplevel)"
# Couche 1 — ATX quality check (garde skip ci + stylelint/ESLint diff + notice prod)
bash "$ROOT/scripts/quality/atx_quality_check.sh" pre-push
# Couche 2 — Sonar QG (existant, conservé)
if [ -n "${SONAR_TOKEN:-}" ] && [ -f "$ROOT/sonar-project.properties" ]; then
    SONAR_PROJECT=$(grep "sonar.projectKey" "$ROOT/sonar-project.properties" | cut -d= -f2 | tr -d ' ')
    echo "[pre-push] Sonar QG check ($SONAR_PROJECT)..."
    for i in $(seq 1 18); do
        sleep 10
        QG=$(curl -s -u "$SONAR_TOKEN:" \
            "https://sonarcloud.io/api/qualitygates/project_status?projectKey=$SONAR_PROJECT" \
            2>/dev/null | python3 -c \
            "import sys,json; print(json.load(sys.stdin).get('projectStatus',{}).get('status','?'))" 2>/dev/null)
        [ "$QG" = "OK"    ] && { echo "[pre-push] ✓ Sonar QG OK"; break; }
        [ "$QG" = "ERROR" ] && { echo "[pre-push] ✗ Sonar QG ERROR"; exit 1; }
        echo "  [pre-push] Sonar en attente... (${i}0s/180s)"
    done
fi
HOOK
chmod +x "$HOOKS_DIR/pre-push"
echo "  ✓ pre-push → atx_quality_check + Sonar QG"
echo ""
echo "[install_hooks] ✓ Hooks ataraxialab.ch installés."
