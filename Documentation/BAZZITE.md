# Instalar KutPod en Bazzite (nativo, sin contenedores)

PHP y ffmpeg salen de **Homebrew** (Bazzite lo trae), KutPod corre como **servicio de usuario de systemd** y
aparece en el menú de aplicaciones. No hace falta `sudo`, ni reiniciar, ni tocar el sistema inmutable.

## Instalar

Con el instalador de un solo fichero:

```bash
bash kutpod-bazzite.run
```

o desde el código (`git clone …`):

```bash
./installer/bazzite/install.sh
```

Pregunta puerto (8080), si quieres **faster-whisper local** y crea la cuenta de administrador. Al terminar:
`http://localhost:8080/admin` (o «KutPod» en el menú de aplicaciones). El plugin **Estudio de edición** queda activado.

Opciones: `--port 9000` · `--lan` (accesible desde otros equipos) · `--whisper` / `--no-whisper` ·
`--gpu` (faster-whisper con NVIDIA, experimental) · `--yes` (sin preguntas; contraseña en `KUTPOD_ADMIN_PASSWORD`).

## Probar el Estudio de edición

1. *Estudio de edición* en el menú «Producción» → nuevo episodio.
2. Transcripción: en *Plugins → Estudio → Configurar* elige motor (API tipo OpenAI/Groq o faster-whisper local).
3. Grabaciones largas: déjalas en `~/KutPod/inbox` y elígelas desde el proyecto («Archivos grandes»).

## Día a día

| Acción | Comando |
|---|---|
| Actualizar | bajar el `.run` nuevo y ejecutarlo con `update`, o `git pull && ./installer/bazzite/install.sh update` |
| Estado / registros | `install.sh status` · `install.sh logs` (o `journalctl --user -u kutpod -f`) |
| Parar / arrancar | `install.sh stop` · `install.sh start` |
| Desinstalar | `install.sh uninstall` |

`update` conserva la base de datos, los audios, los proyectos y el puerto.

Dónde queda todo: `~/.local/share/kutpod/` (`app/storage`, `app/media`, `fw` si instalaste faster-whisper) y los
servicios en `~/.config/systemd/user/kutpod*.{service,timer}`.

## Notas

- Usa el servidor web integrado de PHP (con un router que equivale al `.htaccess`), pensado para uso personal y
  pruebas en casa. Para exponerlo a Internet conviene un proxy inverso con HTTPS delante, o Apache/Nginx.
- Cada hora de audio y pista ocupa ~300 MB de copia de trabajo mientras editas; en *Exportar* hay «Liberar espacio».
- Alternativa con contenedor Podman: `installer/container/` (menos probada).
