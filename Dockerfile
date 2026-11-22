FROM php:8.3-apache

# ────────────────────────────────────────────────
# 1. Paquets système + nettoyage
# ────────────────────────────────────────────────
RUN apt-get update && apt-get install -y --no-install-recommends \
    git \
    zip \
    unzip \
    p7zip-full \
    sqlite3 \
    curl \
    ca-certificates \
    python3 \
    python3-venv \
    supervisor \
    libzip-dev \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libicu-dev \
    libcurl4-openssl-dev \
    libonig-dev \
    ffmpeg \
    redis-server \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/*

# ────────────────────────────────────────────────
# 2. Permissions www-data → UID/GID 1000 (souvent utilisé localement)
# ────────────────────────────────────────────────
RUN usermod -u 1000 www-data && groupmod -g 1000 www-data

# ────────────────────────────────────────────────
# 3. Extensions PHP
# ────────────────────────────────────────────────
RUN pecl install redis \
    && docker-php-ext-enable redis

RUN docker-php-ext-configure gd --with-jpeg --with-freetype \
    && docker-php-ext-install -j$(nproc) \
    pdo_mysql \
    zip \
    gd \
    intl \
    curl \
    mbstring

# PWA Web Push relies on openssl, curl, mbstring and json.
# openssl and json are enabled in the official php:8.3-apache image.

# ────────────────────────────────────────────────
# 4. Composer
# ────────────────────────────────────────────────
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# ────────────────────────────────────────────────
# 5. Répertoire de travail
# ────────────────────────────────────────────────
WORKDIR /var/www/html

# ────────────────────────────────────────────────
# 6. Configuration Apache + Symfony
# ────────────────────────────────────────────────
RUN sed -i 's!/var/www/html!/var/www/html/public!g' /etc/apache2/sites-available/000-default.conf \
    && a2enmod rewrite

# Port 8000 au lieu de 80
RUN sed -i 's/Listen 80/Listen 8000/' /etc/apache2/ports.conf \
    && sed -i 's/<VirtualHost \*:80>/<VirtualHost *:8000>/g' /etc/apache2/sites-available/000-default.conf \
    && sed -i 's/<VirtualHost \*:80>/<VirtualHost *:8000>/g' /etc/apache2/sites-available/default-ssl.conf 2>/dev/null || true

# Optimisations KeepAlive / sessions Redis
RUN { \
    echo 'KeepAlive On'; \
    echo 'KeepAliveTimeout 300'; \
    echo 'MaxKeepAliveRequests 200'; \
    echo 'Timeout 300'; \
    } >> /etc/apache2/apache2.conf

RUN { \
    echo 'session.save_handler = redis'; \
    echo 'session.save_path = "tcp://127.0.0.1:6379?persistent=1"'; \
    echo 'redis.session.locking_enabled = 1'; \
    echo 'redis.session.lock_retries = -1'; \
    echo 'redis.session.lock_wait_time = 10000'; \
    } > /usr/local/etc/php/conf.d/zz-redis-sessions.ini

# Raise the web SAPI execution limit (CLI workers already run without a time limit).
RUN echo 'max_execution_time = 300' > /usr/local/etc/php/conf.d/zz-app.ini

# MPM prefork tuning
RUN echo '' >> /etc/apache2/mods-enabled/mpm_prefork.conf \
    && echo '    KeepAlive On' >> /etc/apache2/mods-enabled/mpm_prefork.conf \
    && echo '    KeepAliveTimeout 300' >> /etc/apache2/mods-enabled/mpm_prefork.conf \
    && echo '    MaxKeepAliveRequests 200' >> /etc/apache2/mods-enabled/mpm_prefork.conf \
    && echo '    Timeout 300' >> /etc/apache2/mods-enabled/mpm_prefork.conf

# ────────────────────────────────────────────────
# 7. Compilation de librespot-auth (Rust, version épinglée)
# ────────────────────────────────────────────────
ARG LIBRESPOT_AUTH_REF=f16fa8ad7159aff6af4933fd782707c8e876fc8c
RUN mkdir -p /var/www/html/var/librespot-auth \
    && cd /var/www/html/var/librespot-auth \
    && git clone https://github.com/Gautardos/librespot-auth.git . \
    && git checkout "$LIBRESPOT_AUTH_REF" \
    && curl --proto '=https' --tlsv1.2 -sSf https://sh.rustup.rs | sh -s -- -y --no-modify-path \
    && . "$HOME/.cargo/env" \
    && cargo build --release \
    && chmod +x target/release/librespot-auth \
    && rm -rf /root/.cargo /root/.rustup

# ────────────────────────────────────────────────
# 8. Environnement Python
# ────────────────────────────────────────────────
COPY cli/requirements.txt /tmp/requirements.txt
ARG PYTHON_DEPS_CACHE_BUST=1
RUN python3 -m venv /opt/venv \
    && /opt/venv/bin/pip install --no-cache-dir --upgrade pip \
    && /opt/venv/bin/pip install --no-cache-dir -r /tmp/requirements.txt \
    && rm -f /tmp/requirements.txt

# ────────────────────────────────────────────────
# 9. Dépendances Composer (vendor seul, sans le source)
# ────────────────────────────────────────────────
COPY --chown=www-data:www-data .env composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-plugins --no-autoloader --no-interaction

# ────────────────────────────────────────────────
# 10. Source applicatif
# ────────────────────────────────────────────────
COPY --chown=www-data:www-data src/            src/
COPY --chown=www-data:www-data public/         public/
COPY --chown=www-data:www-data config/         config/
COPY --chown=www-data:www-data templates/      templates/
COPY --chown=www-data:www-data cli/            cli/
COPY --chown=www-data:www-data bin/            bin/

# ────────────────────────────────────────────────
# 11. Composer final + scripts Symfony
# ────────────────────────────────────────────────
RUN composer install --no-dev --optimize-autoloader --classmap-authoritative \
    --no-interaction --no-scripts \
    && (composer run-script auto-scripts || (php bin/console cache:clear && php bin/console assets:install public)) \
    && { chown -R www-data:www-data var/cache public/bundles 2>/dev/null || true; }

# ────────────────────────────────────────────────
# 12. Dossiers de runtime (après le Composer final)
# ────────────────────────────────────────────────
RUN mkdir -p var/storage/logs var/sessions var/cache var/log var/composer_home var/db var/home \
    && chown -R www-data:www-data var/storage var/sessions var/cache var/log var/composer_home var/db var/home \
    && chmod -R 775 var/storage var/sessions var/cache var/log var/composer_home var/db var/home

# ────────────────────────────────────────────────
# 13. Fichiers de configuration finale
# ────────────────────────────────────────────────
COPY supervisord.conf /etc/supervisord.conf
COPY docker-entrypoint.sh /var/www/html/docker-entrypoint.sh
RUN chmod +x /var/www/html/docker-entrypoint.sh

# ────────────────────────────────────────────────
# Ports & démarrage
# ────────────────────────────────────────────────
EXPOSE 8000

ENTRYPOINT ["/var/www/html/docker-entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisord.conf"]
