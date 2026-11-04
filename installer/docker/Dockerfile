# ============================================================================
# KutPod · IMAGEN ÚNICA (el código va dentro; los datos, en volúmenes)
#
#   Construir:  docker build -f installer/docker/Dockerfile -t kutpod .      (contexto = raíz del repositorio)
#   Usar:       docker run -d -p 8080:80 -v kutpod-storage:/var/www/html/storage \
#                                        -v kutpod-media:/var/www/html/media  ghcr.io/ernestoacostame/kutpod:latest
#
# Variables:  PUID / PGID (usuario con el que corre; Unraid: 99 / 100) · TZ (zona horaria)
# Volúmenes:  /var/www/html/storage (BD y ajustes) · /var/www/html/media (audios e imágenes)
# Puerto:     80
#   --build-arg WITH_WHISPER=1  añade faster-whisper (solo para el plugin Estudio, que viene desactivado)
# ============================================================================
FROM docker.io/library/php:8.3-apache

ARG WITH_WHISPER=0

RUN apt-get update && apt-get install -y --no-install-recommends \
        ffmpeg fontconfig fonts-dejavu-core ca-certificates curl util-linux tzdata \
        libzip-dev libpng-dev libjpeg-dev libwebp-dev libfreetype6-dev \
    && docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype \
    && docker-php-ext-install -j"$(nproc)" gd zip \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

RUN if [ "$WITH_WHISPER" = "1" ]; then \
        apt-get update && apt-get install -y --no-install-recommends python3 python3-venv \
        && python3 -m venv /opt/fw \
        && /opt/fw/bin/pip install --no-cache-dir faster-whisper \
        && rm -rf /var/lib/apt/lists/*; \
    fi

COPY installer/docker/kutpod.ini /usr/local/etc/php/conf.d/kutpod.ini
COPY installer/docker/apache.conf /etc/apache2/conf-available/kutpod.conf
COPY installer/docker/entrypoint.sh /usr/local/bin/kutpod-entrypoint
RUN a2enconf kutpod && chmod +x /usr/local/bin/kutpod-entrypoint

# Código de la aplicación. Los datos (BD, ajustes, medios) van en volúmenes: storage/ y media/
COPY . /var/www/html
RUN rm -rf /var/www/html/installer /var/www/html/dist /var/www/html/.git /var/www/html/.github \
    && mkdir -p /var/www/html/media /var/www/html/storage/studio/inbox /cache/huggingface \
    && cp -a /var/www/html/storage /opt/kutpod-storage-seed \
    && chown -R www-data:www-data /var/www/html /cache
# (/opt/kutpod-storage-seed: copia de los archivos de storage/ que trae la aplicación —schema.sql, geoip…—. Si el volumen
#  es una carpeta del servidor, Docker no la rellena con lo de la imagen: el arranque repone lo que falte.)

ENV PUID=33 PGID=33 TZ=Europe/Madrid HF_HOME=/cache/huggingface
VOLUME ["/var/www/html/storage", "/var/www/html/media"]
EXPOSE 80
WORKDIR /var/www/html

LABEL org.opencontainers.image.title="KutPod" \
      org.opencontainers.image.description="Alojamiento de podcasts (PHP + SQLite) de la suite KutStudio" \
      org.opencontainers.image.source="https://github.com/ernestoacostame/kutpod" \
      org.opencontainers.image.licenses="GPL-3.0-or-later"

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s CMD curl -fsS -o /dev/null http://localhost/ || exit 1
ENTRYPOINT ["/usr/local/bin/kutpod-entrypoint"]
CMD ["apache2-foreground"]
