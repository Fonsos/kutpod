# ============================================================================
# KutPod · imagen autónoma para un servidor con Docker (el código va dentro de la imagen)
#   docker compose -f installer/docker/docker-compose.yml up -d --build
# El contexto de construcción es la raíz del repositorio (ver docker-compose.yml).
#   WITH_WHISPER=1  añade faster-whisper (transcripción local en CPU; solo para el plugin Studio)
# ============================================================================
FROM docker.io/library/php:8.3-apache

ARG WITH_WHISPER=0

RUN apt-get update && apt-get install -y --no-install-recommends \
        ffmpeg fontconfig fonts-dejavu-core ca-certificates curl util-linux \
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

COPY installer/container/kutpod.ini /usr/local/etc/php/conf.d/kutpod.ini
COPY installer/container/apache.conf /etc/apache2/conf-available/kutpod.conf
COPY installer/container/entrypoint.sh /usr/local/bin/kutpod-entrypoint
RUN a2enconf kutpod && chmod +x /usr/local/bin/kutpod-entrypoint

# Código de la aplicación. Los datos (BD, ajustes, medios) van en volúmenes: storage/ y media/
COPY . /var/www/html
RUN rm -rf /var/www/html/installer /var/www/html/dist \
    && mkdir -p /var/www/html/media /var/www/html/storage/studio/inbox /cache/huggingface \
    && chown -R www-data:www-data /var/www/html /cache

ENV HF_HOME=/cache/huggingface
WORKDIR /var/www/html
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s CMD curl -fsS -o /dev/null http://localhost/ || exit 1
ENTRYPOINT ["/usr/local/bin/kutpod-entrypoint"]
CMD ["apache2-foreground"]
