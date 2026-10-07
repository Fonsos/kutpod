#!/usr/bin/env bash
# Crea dist/kutpod-bazzite.run: un único fichero autoextraíble con KutPod + el instalador.
# Uso (en el equipo que tenga el código):  ./installer/bazzite/make-bundle.sh
# Uso (en Bazzite):                        bash kutpod-bazzite.run   (acepta las opciones de install.sh)
set -euo pipefail
SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
OUT="${1:-$SRC/dist/kutpod-bazzite.run}"
mkdir -p "$(dirname "$OUT")"
VERSION="$(sed -n "s/.*KUTPOD_VERSION', '\(.*\)').*/\1/p" "$SRC/version.php")"
{
cat <<HEADER
#!/usr/bin/env bash
# KutPod $VERSION · instalador autoextraíble para Bazzite
# Uso: bash $(basename "$OUT") [--port N] [--whisper] [--lan] [--yes]   (más info: --help)
set -euo pipefail
TMP="\$(mktemp -d)"; trap 'rm -rf "\$TMP"' EXIT
START="\$(awk '/^__KUTPOD_PAYLOAD__\$/ {print NR + 1; exit}' "\$0")"
tail -n +"\$START" "\$0" | tar -xz -C "\$TMP"
bash "\$TMP/installer/bazzite/install.sh" "\$@"
exit \$?
__KUTPOD_PAYLOAD__
HEADER
# Las bases GeoLite2 (~84 MB) son opcionales; WITH_GEOIP=1 las incluye
GEO_EXCLUDE=(--exclude='./storage/geoip/*.mmdb'); [ "${WITH_GEOIP:-0}" = 1 ] && GEO_EXCLUDE=()
( cd "$SRC" && tar "${GEO_EXCLUDE[@]}" --exclude=./.git --exclude=./dist --exclude=./.env --exclude=./storage/kutpod.db \
    --exclude='./storage/kutpod.db-*' --exclude=./storage/install.lock --exclude=./storage/studio \
    --exclude=./media --exclude=./installer/container -czf - . )
} > "$OUT"
chmod +x "$OUT"
echo "Creado $OUT ($(du -h "$OUT" | cut -f1)) · KutPod $VERSION"
