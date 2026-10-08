#!/usr/bin/env bash
# ============================================================================
# KutPod · instalador nativo para Bazzite (y Linux con systemd)
#
# Sin contenedores ni sudo: PHP y ffmpeg salen de Homebrew (que Bazzite trae),
# KutPod corre como servicio de usuario de systemd y se abre desde el menú de apps.
#
#   ./install.sh [install]   instala (o reinstala) y arranca
#   ./install.sh update      aplica el código nuevo y reinicia (conserva todos los datos)
#   ./install.sh status|logs|stop|start
#   ./install.sh uninstall   desinstala (pregunta antes de borrar los datos)
#
# Opciones: --port N · --lan (accesible desde otros equipos) · --whisper / --no-whisper
#           --gpu (faster-whisper con NVIDIA, experimental) · --yes (sin preguntas)
# Variables: KUTPOD_DATA (por defecto ~/.local/share/kutpod) · KUTPOD_INBOX (~/KutPod/inbox)
#            KUTPOD_ADMIN_PASSWORD (contraseña del admin en modo no interactivo)
# ============================================================================
set -euo pipefail

SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
DATA="${KUTPOD_DATA:-$HOME/.local/share/kutpod}"
INBOX="${KUTPOD_INBOX:-$HOME/KutPod/inbox}"
APP="$DATA/app"
CONF="$DATA/installer.conf"
UNIT_DIR="${XDG_CONFIG_HOME:-$HOME/.config}/systemd/user"
APPS_DIR="${XDG_DATA_HOME:-$HOME/.local/share}/applications"

PORT=""; WHISPER=""; GPU=""; LAN=""; YES=0
c_ok=$'\e[32m'; c_err=$'\e[31m'; c_off=$'\e[0m'
say() { printf '%s\n' "$*"; }
ok()  { printf '%s✓%s %s\n' "$c_ok" "$c_off" "$*"; }
die() { printf '%s✗ %s%s\n' "$c_err" "$*" "$c_off" >&2; exit 1; }
ask() { local a; if [ "$YES" = 1 ]; then echo "$2"; return; fi; read -r -p "$1 [$2]: " a </dev/tty || a=""; echo "${a:-$2}"; }
ask_yn() { local a; a=$(ask "$1 (s/n)" "$2"); [[ "$a" =~ ^[sSyY] ]]; }

CMD="install"
while [ $# -gt 0 ]; do
  case "$1" in
    install|update|status|logs|stop|start|uninstall) CMD="$1" ;;
    --port) PORT="${2:?falta el puerto}"; shift ;;
    --lan) LAN=1 ;;
    --whisper) WHISPER=1 ;;
    --no-whisper) WHISPER=0 ;;
    --gpu) GPU=1; WHISPER=1 ;;
    --yes|-y) YES=1 ;;
    -h|--help) sed -n '2,19p' "$0"; exit 0 ;;
    *) die "Opción desconocida: $1" ;;
  esac
  shift
done

[ "$(id -u)" -ne 0 ] || die "Ejecútalo como tu usuario normal, no como root."
command -v systemctl >/dev/null || die "Hace falta systemd."

load_conf() { # lo guardado solo rellena lo que no se pasó por línea de comandos
  [ -f "$CONF" ] || return 0
  local k v
  while IFS='=' read -r k v; do
    case "$k" in PORT) [ -n "$PORT" ] || PORT="$v" ;; WHISPER) [ -n "$WHISPER" ] || WHISPER="$v" ;;
                 GPU) [ -n "$GPU" ] || GPU="$v" ;; LAN) [ -n "$LAN" ] || LAN="$v" ;; esac
  done < "$CONF"
}
save_conf() { mkdir -p "$DATA"; printf 'PORT=%s\nWHISPER=%s\nGPU=%s\nLAN=%s\n' "$PORT" "${WHISPER:-0}" "${GPU:-0}" "${LAN:-0}" > "$CONF"; }

# ── Homebrew + PHP + ffmpeg ─────────────────────────────────────────────────
find_brew() {
  command -v brew 2>/dev/null && return
  for b in /home/linuxbrew/.linuxbrew/bin/brew "$HOME/.linuxbrew/bin/brew"; do [ -x "$b" ] && { echo "$b"; return; }; done
}
ensure_deps() {
  local brew; brew=$(find_brew || true)
  if [ -z "$brew" ]; then
    say "Homebrew no está instalado (es el gestor de paquetes que usa Bazzite para programas de terminal)."
    ask_yn "¿Instalarlo ahora con el instalador oficial?" "s" || die "Sin Homebrew no puedo instalar PHP y ffmpeg."
    NONINTERACTIVE=1 /bin/bash -c "$(curl -fsSL https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh)"
    brew=$(find_brew) || die "No se pudo instalar Homebrew."
  fi
  eval "$("$brew" shellenv)"
  BREW_PREFIX="$("$brew" --prefix)"
  for pkg in php ffmpeg; do
    if "$brew" list --versions "$pkg" >/dev/null 2>&1; then ok "$pkg ya instalado"; else say "Instalando $pkg con Homebrew…"; "$brew" install "$pkg"; fi
  done
  PHP="$BREW_PREFIX/bin/php"; [ -x "$PHP" ] || die "No encuentro php en $BREW_PREFIX/bin"
  for ext in pdo_sqlite curl mbstring gd zip; do
    "$PHP" -m | grep -qix "$ext" || die "A este PHP le falta la extensión $ext"
  done
  ok "PHP $("$PHP" -r 'echo PHP_VERSION;') · ffmpeg $("$BREW_PREFIX/bin/ffmpeg" -version | head -1 | awk '{print $3}')"
}

# ── Código y datos ──────────────────────────────────────────────────────────
sync_code() {
  mkdir -p "$APP" "$INBOX"
  ( cd "$SRC" && tar --exclude=./.git --exclude=./installer --exclude=./.env \
      --exclude=./storage/kutpod.db --exclude='./storage/kutpod.db-*' --exclude=./storage/install.lock \
      --exclude=./storage/studio --exclude=./media -cf - . ) | tar -xf - -C "$APP"
  mkdir -p "$APP/media" "$APP/storage/studio"
  # La carpeta de entrada de audios del Estudio apunta a ~/KutPod/inbox
  if [ ! -L "$APP/storage/studio/inbox" ]; then rmdir "$APP/storage/studio/inbox" 2>/dev/null || true; ln -s "$INBOX" "$APP/storage/studio/inbox"; fi
  cp "$SRC/installer/bazzite/router.php" "$DATA/router.php"
  ok "Código copiado a $APP"
}

# ── faster-whisper opcional ─────────────────────────────────────────────────
install_whisper() {
  command -v python3 >/dev/null || die "Hace falta python3 para faster-whisper"
  [ -x "$DATA/fw/bin/python" ] || python3 -m venv "$DATA/fw"
  say "Instalando faster-whisper (descarga bastante)…"
  "$DATA/fw/bin/pip" install --quiet --upgrade pip faster-whisper
  if [ "$GPU" = 1 ]; then "$DATA/fw/bin/pip" install --quiet nvidia-cublas-cu12 'nvidia-cudnn-cu12==9.*'; fi
  ok "faster-whisper instalado en $DATA/fw"
}
cuda_ld_path() {
  "$DATA/fw/bin/python" - <<'PY' 2>/dev/null || true
import importlib
paths = []
for m in ("nvidia.cublas.lib", "nvidia.cudnn.lib"):
    try: paths.append(list(importlib.import_module(m).__path__)[0])
    except Exception: pass
print(":".join(paths))
PY
}

# ── systemd ─────────────────────────────────────────────────────────────────
write_units() {
  mkdir -p "$UNIT_DIR" "$APPS_DIR"
  local bind="127.0.0.1" ld="" path="$BREW_PREFIX/bin:$BREW_PREFIX/sbin:/usr/local/bin:/usr/bin:/bin"
  [ "${LAN:-0}" = 1 ] && bind="0.0.0.0"
  [ "${GPU:-0}" = 1 ] && ld="$(cuda_ld_path)"
  cat > "$UNIT_DIR/kutpod.service" <<UNIT
[Unit]
Description=KutPod (alojamiento de podcasts y Estudio de edición)
After=network.target

[Service]
Environment=PATH=$path
Environment=PHP_CLI_SERVER_WORKERS=8
Environment=HF_HOME=$DATA/cache/huggingface
$( [ -n "$ld" ] && echo "Environment=LD_LIBRARY_PATH=$ld" )
WorkingDirectory=$APP
ExecStart=$PHP -d upload_max_filesize=4G -d post_max_size=4G -d memory_limit=1G -d max_input_time=-1 -d max_execution_time=300 -S $bind:$PORT -t $APP $DATA/router.php
Restart=always
RestartSec=3

[Install]
WantedBy=default.target
UNIT
  # Tareas periódicas (equivalen al cron que recomienda KutPod)
  for job in "ap:ap-deliver.php:1min" "op3:op3-worker.php:15min"; do
    IFS=: read -r id script every <<<"$job"
    cat > "$UNIT_DIR/kutpod-$id.service" <<UNIT
[Unit]
Description=KutPod · $script
[Service]
Type=oneshot
Environment=PATH=$path
WorkingDirectory=$APP
ExecStart=$PHP $APP/cli/$script
UNIT
    cat > "$UNIT_DIR/kutpod-$id.timer" <<UNIT
[Unit]
Description=KutPod · $script cada $every
[Timer]
OnBootSec=2min
OnUnitActiveSec=$every
[Install]
WantedBy=timers.target
UNIT
  done
  cat > "$APPS_DIR/kutpod.desktop" <<DESK
[Desktop Entry]
Type=Application
Name=KutPod
Comment=Podcasts y Estudio de edición
Exec=xdg-open http://localhost:$PORT/admin
Icon=audio-input-microphone
Categories=AudioVideo;Audio;
DESK
  systemctl --user daemon-reload
  ok "Servicios systemd de usuario creados en $UNIT_DIR"
}

wait_http() {
  printf 'Arrancando'
  for _ in $(seq 1 40); do
    code=$(curl -s -o /dev/null -w '%{http_code}' "http://localhost:$PORT/" 2>/dev/null || true)
    if [ -n "$code" ] && [ "$code" != "000" ]; then echo; ok "KutPod responde en http://localhost:$PORT"; return; fi
    printf '.'; sleep 1
  done
  echo; die "No responde. Mira: journalctl --user -u kutpod -e"
}

run_app() { ( cd "$APP" && PATH="$BREW_PREFIX/bin:$PATH" "$PHP" "$@" ); }

first_install() {
  [ -f "$APP/storage/install.lock" ] && return
  say; say "Primera instalación: crea la cuenta de administrador."
  local inst name email pw
  inst=$(ask "Nombre de la instancia" "KutPod"); name=$(ask "Tu nombre" "$USER"); email=$(ask "Email" "admin@localhost.local")
  pw="${KUTPOD_ADMIN_PASSWORD:-}"
  while [ "${#pw}" -lt 8 ]; do read -r -s -p "Contraseña (mínimo 8 caracteres): " pw </dev/tty; echo; [ "${#pw}" -ge 8 ] || say "Demasiado corta."; done
  printf '%s\n' "$inst" "localhost:$PORT" "$name" "$email" "$pw" | run_app cli/install.php >/dev/null
  ok "Cuenta de administrador creada ($email)"
}

configure_plugins() {
  run_app cli/plugin.php activate studio >/dev/null && ok "Plugin «Estudio de edición» activado"
  run_app cli/plugin.php activate studio-shorts >/dev/null && ok "Plugin «Shorts y Reels» activado"
  if [ "${WHISPER:-0}" = 1 ]; then
    run_app cli/plugin.php set studio_engine faster-whisper >/dev/null
    run_app cli/plugin.php set studio_python "$DATA/fw/bin/python" >/dev/null
    if [ "${GPU:-0}" = 1 ]; then
      run_app cli/plugin.php set studio_fw_device cuda >/dev/null; run_app cli/plugin.php set studio_fw_model large-v3 >/dev/null
      ok "Transcripción local con faster-whisper en GPU NVIDIA (large-v3)"
    else
      run_app cli/plugin.php set studio_fw_device cpu >/dev/null; run_app cli/plugin.php set studio_fw_model small >/dev/null
      ok "Transcripción local con faster-whisper en CPU (modelo small; cámbialo en Plugins → Estudio → Configurar)"
    fi
  fi
}

case "$CMD" in
  install|update)
    load_conf
    if [ "$CMD" = install ]; then
      [ -n "$PORT" ] || PORT=$(ask "Puerto web" "8080")
      if [ -z "$WHISPER" ]; then
        say; say "Transcripción: puedes usar una API externa (OpenAI, Groq…) o faster-whisper local (más pesado, sin enviar audio fuera)."
        if ask_yn "¿Instalar faster-whisper local?" "n"; then WHISPER=1; else WHISPER=0; fi
      fi
      if [ "$WHISPER" = 1 ] && [ -z "$GPU" ] && command -v nvidia-smi >/dev/null && nvidia-smi >/dev/null 2>&1; then
        ask_yn "Detectada GPU NVIDIA. ¿Usarla para transcribir? (experimental)" "s" && GPU=1 || GPU=0
      fi
    else
      [ -n "$PORT" ] || PORT=8080; [ -n "$WHISPER" ] || WHISPER=0
    fi
    save_conf
    ensure_deps
    sync_code
    [ "${WHISPER:-0}" = 1 ] && [ "$CMD" = install ] && install_whisper
    write_units
    systemctl --user enable kutpod.service kutpod-ap.timer kutpod-op3.timer >/dev/null 2>&1 || true
    systemctl --user restart kutpod.service
    systemctl --user start kutpod-ap.timer kutpod-op3.timer 2>/dev/null || true
    wait_http
    if [ "$CMD" = install ]; then first_install; configure_plugins; fi
    if ! loginctl show-user "$USER" 2>/dev/null | grep -q '^Linger=yes'; then
      ask_yn "¿Arrancar KutPod al encender el equipo, aunque no hayas iniciado sesión?" "s" && loginctl enable-linger "$USER" && ok "Arranque automático activado"
    fi
    say; ok "Listo → http://localhost:$PORT/admin   (también en el menú de aplicaciones: «KutPod»)"
    say "  Grabaciones grandes: déjalas en $INBOX"
    say "  Datos (BD, audios, proyectos): $APP/{storage,media}"
    [ "${LAN:-0}" = 1 ] && say "  Accesible desde la red local en el puerto $PORT (sin HTTPS): sudo firewall-cmd --add-port=$PORT/tcp --permanent && sudo firewall-cmd --reload"
    ;;
  status) systemctl --user status kutpod.service --no-pager || true ;;
  logs)   exec journalctl --user -u kutpod -f ;;
  stop)   systemctl --user stop kutpod.service kutpod-ap.timer kutpod-op3.timer ;;
  start)  systemctl --user start kutpod.service kutpod-ap.timer kutpod-op3.timer ;;
  uninstall)
    systemctl --user disable --now kutpod.service kutpod-ap.timer kutpod-op3.timer 2>/dev/null || true
    rm -f "$UNIT_DIR"/kutpod*.service "$UNIT_DIR"/kutpod*.timer "$APPS_DIR/kutpod.desktop"
    systemctl --user daemon-reload
    ok "Servicios eliminados (PHP y ffmpeg de Homebrew se quedan por si los usas para otras cosas)"
    if ask_yn "¿Borrar también los datos ($DATA)? Se pierden la base de datos, los audios y los proyectos" "n"; then rm -rf "$DATA"; ok "Datos eliminados"; else say "Datos conservados en $DATA"; fi
    ;;
esac
