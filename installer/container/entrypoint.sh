#!/bin/sh
# Arranca los trabajos periódicos de KutPod (equivalente al cron recomendado) y Apache.
# Los workers corren con el mismo usuario que Apache para no crear ficheros con otro propietario.
APP_UID="${APACHE_RUN_USER#\#}"; APP_GID="${APACHE_RUN_GROUP#\#}"
case "$APP_UID" in ''|*[!0-9]*) APP_UID=33 ;; esac
case "$APP_GID" in ''|*[!0-9]*) APP_GID=33 ;; esac

as_app() { setpriv --reuid="$APP_UID" --regid="$APP_GID" --clear-groups "$@"; }

mkdir -p /cache/huggingface 2>/dev/null

(
  # Esperar a que exista la base de datos (instalación hecha)
  while [ ! -f /var/www/html/storage/kutpod.db ]; do sleep 10; done
  n=0
  while true; do
    as_app php /var/www/html/cli/ap-deliver.php   >/dev/null 2>&1
    [ $((n % 15)) -eq 0 ] && as_app php /var/www/html/cli/op3-worker.php >/dev/null 2>&1
    n=$((n + 1)); sleep 60
  done
) &

exec docker-php-entrypoint "$@"
