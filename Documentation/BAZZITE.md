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
`http://localhost:8080/admin` (o «KutPod» en el menú de aplicaciones). Los plugins de edición web (**Estudio** y **Shorts**) son experimentales y quedan **desactivados**; actívalos desde *Plugins* o instala con `--studio`.

Opciones: `--port 9000` · `--lan` (accesible desde otros equipos) · `--whisper` / `--no-whisper` ·
`--gpu` (faster-whisper con NVIDIA, experimental) · `--studio` (activa los plugins de edición web) · `--yes` (sin preguntas; contraseña en `KUTPOD_ADMIN_PASSWORD`).

## Probar el Estudio de edición (experimental)

La edición de episodios está pensada para vivir en **KutEditor**; este editor web es un extra opcional.

0. Actívalo en *Plugins* (o instala con `--studio`).
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

## Grabar con la Zoom P4 (pistas con fallos)

Flujo previsto para tu grabación: la **pista 1** (tu micro) y la **pista 3** (el cohost, grabado por la P4) salen de la
misma grabadora y duran lo mismo; la **pista 2** es la grabación local del cohost, que hay que sincronizar con la 3.

1. Sube las pistas en este orden: P4 pista 1 (será el ancla), P4 pista 3, pista local del cohost.
2. Marca la P4 pista 3 como «Solo sincronizar» y ponle el retraso **0** a mano (misma grabadora que la 1).
3. En la pista local, elige «Sincronizar con: P4 pista 3».

Si la P4 pierde audio o inserta silencio durante la grabación, el plugin lo detecta, **corta la pista local** en esos
puntos (o añade silencio) para que siga a la P4 de principio a fin, y lo muestra en la pestaña *Sincronización*
(«0:40 · recortados 2,50 s sobrantes»). El original se conserva hasta que liberes espacio. Se puede desactivar por pista.

## Shorts y Reels

Con el plugin **Estudio · Shorts y Reels** (activado por el instalador; si ya tenías KutPod instalado, actívalo en
*Plugins*), la pestaña *Exportar* de un episodio exportado ofrece:

1. **Sugerir fragmentos**: propone hasta 3 tramos de ~45-60 s que empiezan y acaban en frase, con mucha voz y
   cambios de interlocutor, fuera de la música de entradilla y de salida.
2. Ajustar inicio y fin (botones ±0,5 s, o seleccionando texto en la transcripción), escuchar el fragmento y
   poner título.
3. **Generar vídeo**: MP4 vertical 1080×1920 (H.264 + AAC) con la portada del podcast, forma de onda, título,
   nombre del podcast y subtítulos palabra a palabra. Se descarga desde la propia lista.

La portada y el color de acento salen de los ajustes del podcast. Instagram Reels admite hasta 90 s y YouTube
Shorts hasta 3 min; el panel avisa si te pasas.
