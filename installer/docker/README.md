# KutPod en Docker — imagen única

Una sola imagen, `ghcr.io/ernestoacostame/kutpod`, con la aplicación dentro (PHP 8.3 + Apache + ffmpeg). Los datos van fuera, en dos
carpetas/volúmenes, así que actualizar es descargar la imagen nueva y recrear el contenedor.

| | |
|---|---|
| Puerto | `80` (en el servidor, por ejemplo `8080`) |
| `/var/www/html/storage` | base de datos SQLite, ajustes, copias de seguridad — **imprescindible conservarla** |
| `/var/www/html/media` | audios, portadas, banners |
| `PUID` / `PGID` | usuario y grupo con el que corre y con el que se guardan los datos (por defecto 33:33; **Unraid: 99:100**) |
| `TZ` | zona horaria (por defecto `Europe/Madrid`) |

Al arrancar, el contenedor repone en `storage/` los archivos que trae la aplicación (`schema.sql`, `geoip`…), así que funciona
igual con volúmenes con nombre que con carpetas del servidor. También ejecuta los trabajos periódicos: `cli/ap-deliver.php` cada
minuto y `cli/op3-worker.php` cada 15.

## 1 · `docker run`

```bash
docker run -d --name kutpod --restart unless-stopped -p 8080:80 \
  -v kutpod-storage:/var/www/html/storage \
  -v kutpod-media:/var/www/html/media \
  -e TZ=Europe/Madrid \
  ghcr.io/ernestoacostame/kutpod:latest
```

Abre `http://IP-DEL-SERVIDOR:8080` y sigue el instalador web (crea el usuario administrador).

## 2 · Docker Compose con la imagen publicada (sin clonar ni compilar)

```bash
mkdir kutpod && cd kutpod
curl -O https://raw.githubusercontent.com/ernestoacostame/kutpod/main/installer/docker/docker-compose.imagen.yml
docker compose -f docker-compose.imagen.yml up -d
# actualizar:
docker compose -f docker-compose.imagen.yml pull && docker compose -f docker-compose.imagen.yml up -d
```

## 3 · Unraid

Plantilla: [`installer/unraid/kutpod.xml`](../unraid/kutpod.xml).

- **Rápido:** *Docker → Add Container* → en «Template» pega
  `https://raw.githubusercontent.com/ernestoacostame/kutpod/main/installer/unraid/kutpod.xml` → *Apply*.
  (O copia el XML a `/boot/config/plugins/dockerMan/templates-user/kutpod.xml` y aparecerá en «Template».)
- Campos: puerto (8080), datos (`/mnt/user/appdata/kutpod/storage`), medios (`/mnt/user/appdata/kutpod/media`, puede ser un share
  del array), `PUID=99`, `PGID=100`, `TZ`.
- Actualizar: «Check for Updates» / «Force update» como cualquier contenedor.
- Para el catálogo de Community Applications, la plantilla debe vivir en un repositorio de plantillas propio (un repo con el XML
  en la raíz que se registra en el foro de CA); el `TemplateURL` y el `Registry` del XML apuntan a este repositorio.

## 4 · Construir la imagen tú mismo

```bash
docker build -f installer/docker/Dockerfile -t kutpod:local .          # contexto = raíz del repositorio
# o con Compose (ya apunta a ese Dockerfile):
cd installer/docker && cp .env.example .env && docker compose up -d --build
```

`--build-arg WITH_WHISPER=1` añade faster-whisper (solo lo usa el plugin Estudio, que viene desactivado; ~1 GB más).

## 5 · Publicar la imagen (GitHub Actions)

El flujo [`.github/workflows/docker.yml`](../../.github/workflows/docker.yml) construye `linux/amd64` y `linux/arm64` y la sube a GHCR:

| Evento | Etiquetas |
|---|---|
| subir a `main` | `edge` |
| subir una etiqueta `vX.Y.Z` o `vX.Y.Z-kut.N` | `X.Y.Z` y `latest` |
| a mano (Actions → *Imagen Docker* → Run workflow) | según la rama/etiqueta elegida |

Una sola vez:
1. En el repositorio: **Settings → Actions → General → Allow all actions** (en los forks vienen desactivadas).
2. Tras la primera publicación: GitHub → tu perfil → *Packages* → `kutpod` → **Package settings → Change visibility → Public**
   (los paquetes nuevos nacen privados y Unraid no podría descargarlo sin iniciar sesión).

Publicar una versión estable: `./release.sh ... (o simplemente: git tag vX.Y.Z && git push origin vX.Y.Z)`.


## Notas

- **HTTPS**: pon un proxy inverso delante (Caddy, Nginx Proxy Manager, Traefik, SWAG…) apuntando al puerto del contenedor.
- **Copia de seguridad**: copia las dos carpetas (`storage` y `media`). Con volúmenes con nombre:
  `docker run --rm -v kutpod-storage:/s -v "$PWD":/b alpine tar czf /b/storage.tgz -C /s .`
- **Actualizador del panel**: el código va dentro de la imagen; una actualización hecha desde el panel se pierde al recrear el
  contenedor. Actualiza descargando la imagen nueva.
- **Plugins** de Estudio y Shorts: vienen desactivados (`docker exec -u www-data kutpod php cli/plugin.php activate studio`).
- Esta imagen **no se ha construido ni probado todavía en un servidor real**; el flujo de GitHub Actions tampoco se ha ejecutado.
