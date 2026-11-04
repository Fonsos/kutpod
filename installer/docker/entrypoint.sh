#!/bin/sh
# ============================================================================
# KutPod · arranque de la imagen única
#  - PUID / PGID: usuario y grupo con los que corre Apache y se guardan los datos
#    (por defecto 33:33 = www-data; en Unraid se usa 99:100 = nobody:users)
#  - TZ: zona horaria de PHP
#  - lanza los trabajos periódicos que recomiendan los propios scripts:
#      cli/ap-deliver.php  cada minuto (entrega a inboxes federados)
#      cli/op3-worker.php  cada 15 minutos (estadísticas OP3)
# ============================================================================
APP="${KUTPOD_ROOT:-/var/www/html}"
SEED="${KUTPOD_SEED:-/opt/kutpod-storage-seed}"
PUID="${PUID:-33}"; PGID="${PGID:-33}"
case "$PUID" in ''|*[!0-9]*) echo "PUID no válido: $PUID" >&2; exit 1 ;; esac
case "$PGID" in ''|*[!0-9]*) echo "PGID no válido: $PGID" >&2; exit 1 ;; esac

# Apache (imagen php:apache) acepta un uid/gid numérico con «#»
export APACHE_RUN_USER="#$PUID" APACHE_RUN_GROUP="#$PGID"

# Zona horaria de PHP
if [ -n "$TZ" ] && [ -w "${PHP_INI_DIR:-/usr/local/etc/php}/conf.d" ]; then
  printf 'date.timezone = %s\n' "$TZ" > "${PHP_INI_DIR:-/usr/local/etc/php}/conf.d/zz-tz.ini"
fi

# Carpetas de datos. Si storage/ es una carpeta del servidor (p. ej. Unraid) llega vacía: Docker solo copia el contenido
# de la imagen a volúmenes con nombre. Se reponen los archivos que trae la aplicación (schema.sql, geoip…);
# -u no pisa nada más nuevo (ni toca la base de datos ni las subidas).
mkdir -p "$APP/storage" "$APP/media"
[ -d "$SEED" ] && cp -a -u --no-preserve=ownership "$SEED"/. "$APP/storage"/
chown -R "$PUID:$PGID" "$APP/storage"        # pequeña: BD, ajustes, copias
# media/ puede ser enorme: chown recursivo solo si el propietario de la carpeta no coincide
[ "$(stat -c %u:%g "$APP/media")" = "$PUID:$PGID" ] || chown -R "$PUID:$PGID" "$APP/media"
for d in storage/uploads storage/backups storage/studio storage/studio/inbox media/audio media/covers; do
  mkdir -p "$APP/$d"
  [ "$(stat -c %u:%g "$APP/$d")" = "$PUID:$PGID" ] || chown "$PUID:$PGID" "$APP/$d"
done
chown "$PUID:$PGID" "$APP" 2>/dev/null   # el instalador escribe ahí el .env

as_app() { setpriv --reuid="$PUID" --regid="$PGID" --clear-groups "$@"; }

(
  # Esperar a que exista la base de datos (instalación hecha desde el asistente web)
  while [ ! -f "$APP/storage/kutpod.db" ]; do sleep 10; done
  n=0
  while true; do
    as_app php "$APP/cli/ap-deliver.php"   >/dev/null 2>&1
    [ $((n % 15)) -eq 0 ] && as_app php "$APP/cli/op3-worker.php" >/dev/null 2>&1
    n=$((n + 1)); sleep 60
  done
) &

exec docker-php-entrypoint "$@"
