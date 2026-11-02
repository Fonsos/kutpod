#!/usr/bin/env bash
# ============================================================================
# KutPod · Release Script (Compatible con Submódulos y Repositorios Standalone)
# ============================================================================
# Uso:
#   ./release.sh 1.2.15              → release con notas vacías
#   ./release.sh 1.2.15 "Changelog"  → release con notas
#   ./release.sh patch               → auto-bump patch (1.2.14 → 1.2.15)
#   ./release.sh minor               → auto-bump minor (1.2.14 → 1.3.0)
#   ./release.sh major               → auto-bump major (1.2.14 → 2.0.0)
#
# Requisitos: git, gh (GitHub CLI autenticado)
# ============================================================================

set -euo pipefail

KUTPOD_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$KUTPOD_DIR"

VERSION_FILE="$KUTPOD_DIR/version.php"

# Verificar que estamos en un repositorio Git válido
if ! git rev-parse --is-inside-work-tree &>/dev/null; then
  echo "❌ Error: No se detectó un repositorio git en $KUTPOD_DIR."
  exit 1
fi

# --- Leer versión actual ---
CURRENT=$(grep -oP "KUTPOD_VERSION',\s*'\K[0-9.]+" "$VERSION_FILE")
echo "📦 Versión actual de KutPod: v$CURRENT"

# --- Calcular nueva versión ---
if [[ -z "${1:-}" ]]; then
  echo "Uso: $0 <version|patch|minor|major> [notas]"
  echo "  Ejemplos:"
  echo "    $0 patch              → $(echo "$CURRENT" | awk -F. '{print $1"."$2"."$3+1}')"
  echo "    $0 minor              → $(echo "$CURRENT" | awk -F. '{print $1"."$2+1".0"}')"
  echo "    $0 major              → $(echo "$CURRENT" | awk -F. '{print $1+1".0.0"}')"
  echo "    $0 1.2.15 \"Mi changelog\""
  exit 1
fi

INPUT="$1"
NOTES="${2:-}"

case "$INPUT" in
  patch) NEW=$(echo "$CURRENT" | awk -F. '{print $1"."$2"."$3+1}') ;;
  minor) NEW=$(echo "$CURRENT" | awk -F. '{print $1"."$2+1".0"}') ;;
  major) NEW=$(echo "$CURRENT" | awk -F. '{print $1+1".0.0"}') ;;
  *)     NEW="$INPUT" ;;
esac

# --- Validar formato de versión ---
if [[ ! "$NEW" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
  echo "❌ Error: La versión '$NEW' no es válida. Debe tener formato X.Y.Z o usar: patch, minor, major."
  exit 1
fi

echo "🚀 Nueva versión: v$NEW"
echo ""

# --- Confirmar ---
read -rp "¿Continuar? (s/N) " confirm
if [[ "$confirm" != "s" && "$confirm" != "S" ]]; then
  echo "Cancelado."
  exit 0
fi

# --- 1. Git stage de archivos modificados y versión ---
UNSTAGED=$(git status --porcelain | grep -v 'version.php' | wc -l || true)

if [[ "$UNSTAGED" -gt 0 ]]; then
  echo "📋 Hay $UNSTAGED archivo(s) modificados sin stage."
  read -rp "¿Incluirlos en este release? (s/N) " inc
  if [[ "$inc" == "s" || "$inc" == "S" ]]; then
    git add -A
  fi
fi

# Actualizar version.php y añadirlo al stage
sed -i "s/KUTPOD_VERSION',\s*'$CURRENT'/KUTPOD_VERSION', '$NEW'/" "$VERSION_FILE"
git add version.php
echo "✅ version.php actualizado a $NEW"

# --- 2. Crear el ZIP de producción limpio (ahora incluye archivos recién añadidos) ---
ZIP_NAME="kutpod-v${NEW}.zip"
ZIP_PATH="/tmp/$ZIP_NAME"

STAGE_DIR=$(mktemp -d)
cd "$KUTPOD_DIR"

# Copiar solo archivos rastreados por git (excluyendo storage, media, tests y configs locales)
git ls-files -z \
  | grep -zZv -e '^storage/' -e '^media/' -e '^\.htaccess$' -e '^scratch/' \
    -e '^test/' -e '^tests/' -e '^release.*\.sh$' -e '^\.git' \
  | xargs -0 -I{} install -D "{}" "${STAGE_DIR}/{}"

# Empaquetar con python3 (máxima compatibilidad universal) o bsdtar
cd "$STAGE_DIR"
python3 -c "
import zipfile, os
with zipfile.ZipFile('$ZIP_PATH', 'w', zipfile.ZIP_DEFLATED) as z:
    for root, dirs, files in os.walk('.'):
        for file in files:
            filepath = os.path.join(root, file)
            arcname = os.path.relpath(filepath, '.')
            z.write(filepath, arcname)
"
rm -rf "$STAGE_DIR"

# IMPORTANTE: Regresar siempre al directorio del repositorio
cd "$KUTPOD_DIR"

ZIP_SIZE=$(du -h "$ZIP_PATH" | cut -f1)
echo "✅ ZIP creado: $ZIP_NAME ($ZIP_SIZE)"

# --- 3. Git: commit + tag dentro del repositorio de KutPod ---
git commit -m "Release KutPod v$NEW"
git tag -a "v$NEW" -m "KutPod v$NEW"
echo "✅ Commit y tag v$NEW creados"

# --- 4. Push al repositorio de KutPod ---
BRANCH=$(git branch --show-current || git rev-parse --abbrev-ref HEAD || echo "main")
git push origin "$BRANCH"
git push origin "v$NEW"
echo "✅ Pushed a GitHub (rama: $BRANCH)"

# --- 5. Crear GitHub Release con el ZIP adjunto ---
if [[ -z "$NOTES" ]]; then
  PREV_TAG=$(git tag -l 'v*' --sort=-v:refname | grep -v "v$NEW" | head -1 || true)
  if [[ -n "$PREV_TAG" ]]; then
    NOTES=$(git log "${PREV_TAG}..v${NEW}" --oneline --no-merges 2>/dev/null | head -20 || true)
    if [[ -z "$NOTES" ]]; then
      NOTES="Release v$NEW"
    fi
  else
    NOTES="Release v$NEW"
  fi
fi

gh release create "v$NEW" "$ZIP_PATH" \
  --repo "ernestoacostame/kutpod" \
  --title "KutPod v$NEW" \
  --notes "$NOTES"

# Invalida la caché en el servidor central de actualizaciones inmediatamente
curl -s "https://kutstudio.org/updates/?app=kutpod&action=check&nocache=1" >/dev/null 2>&1 || true

echo ""
echo "✅ Release v$NEW publicada exitosamente en GitHub con $ZIP_NAME adjunto"
echo "   https://github.com/ernestoacostame/kutpod/releases/tag/v$NEW"

# --- Limpiar ---
rm -f "$ZIP_PATH"

