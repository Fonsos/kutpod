# KutPod con Docker

```bash
cd installer/docker
cp .env.example .env        # opcional: puerto, zona horaria, Whisper
docker compose up -d --build
```

Abre `http://IP-DEL-SERVIDOR:8080` y completa el instalador web. Los datos viven en volúmenes con nombre
(`kutpod-storage`: base de datos y ajustes · `kutpod-media`: audios e imágenes · `kutpod-cache`), así que
actualizar no los toca:

```bash
git pull && docker compose up -d --build
```

- **HTTPS**: pon un proxy inverso delante (Caddy, Nginx, Traefik…) apuntando al puerto del contenedor.
- **Copia de seguridad**: `docker run --rm -v docker_kutpod-storage:/s -v "$PWD":/b alpine tar czf /b/storage.tgz -C /s .`
  (igual con `kutpod-media`). El prefijo del volumen es el nombre de la carpeta del compose.
- **Plugins Studio** (edición y shorts en el servidor): vienen **desactivados**. La edición se hace ahora en KutEditor;
  si aun así los quieres: `docker compose exec -u www-data kutpod php cli/plugin.php activate studio`
  (y `WITH_WHISPER=1` en `.env` para la transcripción local).
- Esta imagen no se ha construido en el entorno donde se escribió: si falla algo, enséñame el error.
