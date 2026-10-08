#!/usr/bin/env bash
# ============================================================================
# KutPod · instalador para Bazzite (y cualquier Fedora Atomic / Linux con Podman)
#
# Corre KutPod en un contenedor Podman rootless, gestionado por systemd (Quadlet),
# sin instalar nada en el sistema inmutable.
#
#   ./install.sh [install]    instala (o reinstala) y arranca
#   ./install.sh update       copia el código nuevo, reconstruye la imagen y reinicia
#   ./install.sh status       estado del servicio
#   ./install.sh logs         registros en vivo
#   ./install.sh shell        shell dentro del contenedor
#   ./install.sh uninstall    desinstala (pregunta antes de borrar los datos)
#
# Opciones de install/update:  --port N   --whisper | --no-whisper   --yes
# Variables:  KUTPOD_DATA (datos, por defecto ~/.local/share/kutpod)
#             KUTPOD_INBOX (carpeta de entrada de audios, por defecto ~/KutPod/inbox)
# ============================================================================
set -euo pipefail

NAME=kutpod
IMAGE=localhost/kutpod:latest
SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
HERE="$SRC/installer/container"
DATA="${KUTPOD_DATA:-$HOME/.local/share/kutpod}"
INBOX="${KUTPOD_INBOX:-$HOME/KutPod/inbox}"
UNIT_DIR="${XDG_CONFIG_HOME:-$HOME/.config}/containers/systemd"
UNIT="$UNIT_DIR/$NAME.container"
CONF="$DATA/installer.conf"

PORT=""; WHISPER=""; STUDIO=""; YES=0
c_ok=$'\e[32m'; c_err=$'\e[31m'; c_dim=$'\e[2m'; c_off=$'\e[0m'
say()  { printf '%s\n' "$*"; }
ok()   { printf '%s✓%s %s\n' "$c_ok" "$c_off" "$*"; }
die()  { printf '%s✗ %s%s\n' "$c_err" "$*" "$c_off" >&2; exit 1; }
ask()  { # ask "pregunta" "defecto" → respuesta
  local a; if [ "$YES" = 1 ]; then echo "$2"; return; fi
  read -r -p "$1 [$2]: " a </dev/tty || a=""; echo "${a:-$2}"
}
ask_yn() { local a; a=$(ask "$1 (s/n)" "$2"); [[ "$a" =~ ^[sSyY] ]]; }

CMD="install"
while [ $# -gt 0 ]; do
  case "$1" in
    install|update|status|logs|shell|uninstall) CMD="$1" ;;
    --port) PORT="${2:?falta el número de puerto}"; shift ;;
    --whisper) WHISPER=1 ;;
    --no-whisper) WHISPER=0 ;;
    --studio) STUDIO=1 ;;
    --yes|-y) YES=1 ;;
    -h|--help) sed -n '2,20p' "$0"; exit 0 ;;
    *) die "Opción desconocida: $1" ;;
  esac
  shift
done

[ "$(id -u)" -ne 0 ] || die "Ejecútalo como tu usuario normal, no como root (el contenedor es rootless)."
command -v podman >/dev/null || die "No se encuentra podman (viene instalado en Bazzite)."
command -v systemctl >/dev/null || die "Hace falta systemd."

load_conf() { # los valores guardados solo rellenan lo que no se haya pasado por línea de comandos
  [ -f "$CONF" ] || return 0
  local k v
  while IFS='=' read -r k v; do
    case "$k" in PORT) [ -n "$PORT" ] || PORT="$v" ;; WHISPER) [ -n "$WHISPER" ] || WHISPER="$v" ;; esac
  done < "$CONF"
}
save_conf() { mkdir -p "$DATA"; printf 'PORT=%s\nWHISPER=%s\n' "$PORT" "$WHISPER" > "$CONF"; }
exec_app() { podman exec --user "$(id -u)" -w /var/www/html "$NAME" "$@"; }

sync_code() {
  mkdir -p "$DATA/app" "$DATA/cache"
  # Copia el código conservando los datos (BD, media, .env, proyectos del Estudio)
  ( cd "$SRC" && tar --exclude=./.git --exclude=./installer --exclude=./.env \
      --exclude=./storage/kutpod.db --exclude='./storage/kutpod.db-*' --exclude=./storage/install.lock \
      --exclude=./storage/studio --exclude=./media -cf - . ) | tar -xf - -C "$DATA/app"
  mkdir -p "$DATA/app/storage/studio/inbox" "$DATA/app/media" "$INBOX"
  ok "Código copiado a $DATA/app"
}

build_image() {
  if [ "${WHISPER:-0}" = 1 ]; then say "Construyendo la imagen con faster-whisper (tarda bastante, descarga ~1 GB)…"
  else say "Construyendo la imagen (la primera vez tarda unos minutos)…"; fi
  podman build -t "$IMAGE" --build-arg "WITH_WHISPER=${WHISPER:-0}" -f "$HERE/Containerfile" "$HERE"
  ok "Imagen construida"
}

write_unit() {
  mkdir -p "$UNIT_DIR"
  cat > "$UNIT" <<UNIT
[Unit]
Description=KutPod (alojamiento de podcasts y Estudio de edición)
After=network-online.target

[Container]
Image=$IMAGE
ContainerName=$NAME
PublishPort=$PORT:80
# El usuario de Apache dentro del contenedor es el mismo uid que el tuyo: los ficheros te pertenecen
UserNS=keep-id
User=root
Environment=APACHE_RUN_USER=#%U
Environment=APACHE_RUN_GROUP=#%G
Volume=$DATA/app:/var/www/html:Z
Volume=$INBOX:/var/www/html/storage/studio/inbox:Z
Volume=$DATA/cache:/cache:Z

[Service]
Restart=always
TimeoutStartSec=300

[Install]
WantedBy=default.target
UNIT
  systemctl --user daemon-reload
  ok "Servicio systemd de usuario creado ($UNIT)"
}

wait_http() {
  printf 'Esperando a que responda'
  for _ in $(seq 1 60); do
    code=$(curl -s -o /dev/null -w '%{http_code}' "http://localhost:$PORT/" 2>/dev/null || true)
    if [ "$code" != "000" ] && [ -n "$code" ]; then echo; ok "KutPod responde en http://localhost:$PORT"; return; fi
    printf '.'; sleep 2
  done
  echo; die "No responde. Mira: $0 logs"
}

first_install() {
  [ -f "$DATA/app/storage/install.lock" ] && return
  say; say "Primera instalación: crea la cuenta de administrador."
  local inst name email pw
  inst=$(ask "Nombre de la instancia" "KutPod")
  name=$(ask "Tu nombre" "${USER}")
  email=$(ask "Email" "admin@localhost.local")
  pw="${KUTPOD_ADMIN_PASSWORD:-}"
  while [ "${#pw}" -lt 8 ]; do
    read -r -s -p "Contraseña (mínimo 8 caracteres): " pw </dev/tty; echo
    [ "${#pw}" -ge 8 ] || say "Demasiado corta."
  done
  printf '%s\n' "$inst" "localhost:$PORT" "$name" "$email" "$pw" | exec_app php cli/install.php >/dev/null
  ok "Cuenta de administrador creada ($email)"
}

configure_plugins() {
  if [ "${STUDIO:-0}" = 1 ]; then
    exec_app php cli/plugin.php activate studio >/dev/null && ok "Plugin «Estudio de edición» activado"
    exec_app php cli/plugin.php activate studio-shorts >/dev/null && ok "Plugin «Shorts y Reels» activado"
  fi
  if [ "$WHISPER" = 1 ]; then
    exec_app php cli/plugin.php set studio_engine faster-whisper >/dev/null
    exec_app php cli/plugin.php set studio_python /opt/fw/bin/python >/dev/null
    exec_app php cli/plugin.php set studio_fw_model small >/dev/null
    exec_app php cli/plugin.php set studio_fw_device cpu >/dev/null
    ok "Transcripción local con faster-whisper (modelo «small» en CPU; cámbialo en Plugins → Estudio → Configurar)"
  fi
}

case "$CMD" in
  install|update)
    load_conf
    if [ "$CMD" = install ]; then
      [ -n "$PORT" ] || PORT=$(ask "Puerto web" "8080")
      if [ -z "$WHISPER" ]; then
        say; say "Transcripción: puedes usar una API externa (OpenAI, Groq…) o instalar faster-whisper local"
        say "(más pesado: ~1 GB extra en la imagen y más lento sin GPU)."
        if ask_yn "¿Incluir faster-whisper local?" "n"; then WHISPER=1; else WHISPER=0; fi
      fi
    else
      [ -n "$PORT" ] || PORT=8080; [ -n "$WHISPER" ] || WHISPER=0
    fi
    save_conf
    sync_code
    build_image
    write_unit
    systemctl --user restart "$NAME.service" || die "No arranca el servicio. Mira: journalctl --user -u $NAME"
    wait_http
    if [ "$CMD" = install ]; then
      first_install
      configure_plugins
    fi
    # Que arranque sin tener la sesión abierta
    if ! loginctl show-user "$USER" 2>/dev/null | grep -q '^Linger=yes'; then
      if ask_yn "¿Arrancar KutPod al encender el equipo, aunque no hayas iniciado sesión?" "s"; then loginctl enable-linger "$USER" && ok "Arranque automático activado"; fi
    fi
    say
    ok "Listo → http://localhost:$PORT/admin"
    say "  Carpeta para dejar grabaciones grandes: $INBOX"
    say "  Datos (BD, audios, proyectos):          $DATA/app/{storage,media}"
    say "  Para acceder desde otro equipo de la red abre el puerto: sudo firewall-cmd --add-port=$PORT/tcp --permanent && sudo firewall-cmd --reload"
    ;;
  status)  systemctl --user status "$NAME.service" --no-pager || true ;;
  logs)    exec podman logs -f "$NAME" ;;
  shell)   exec podman exec -it --user "$(id -u)" -w /var/www/html "$NAME" bash ;;
  uninstall)
    systemctl --user stop "$NAME.service" 2>/dev/null || true
    rm -f "$UNIT"; systemctl --user daemon-reload
    podman rmi -f "$IMAGE" 2>/dev/null || true
    ok "Servicio e imagen eliminados"
    if ask_yn "¿Borrar también los datos ($DATA)? Se pierden la base de datos, los audios y los proyectos" "n"; then
      rm -rf "$DATA"; ok "Datos eliminados"
    else
      say "Datos conservados en $DATA"
    fi
    ;;
esac
