FROM php:8.3-apache

# ────────────────────────────────────────────────
# 1. Paquets système + nettoyage
# ────────────────────────────────────────────────
RUN apt-get update && apt-get install -y --no-install-recommends \
    git \
    zip \
    unzip \
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

# ────────────────────────────────────────────────
# 4. Composer
# ────────────────────────────────────────────────
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# ────────────────────────────────────────────────
# 5. Répertoire de travail
# ────────────────────────────────────────────────
WORKDIR /var/www/html

# ────────────────────────────────────────────────
# 6. Copie sélective → on n'inclut PAS var/
# ────────────────────────────────────────────────
COPY .env composer.json composer.lock ./
COPY src/            src/
COPY public/         public/
COPY config/         config/
COPY templates/      templates/
COPY cli/            cli/
COPY bin/            bin/

# Installation des dépendances PHP (sans dev)
RUN composer install --no-dev --optimize-autoloader --classmap-authoritative --no-interaction

# ────────────────────────────────────────────────
# 7. Environnement Python
# ────────────────────────────────────────────────
RUN python3 -m venv /opt/venv
RUN /opt/venv/bin/pip install --no-cache-dir --upgrade pip \
    && /opt/venv/bin/pip install --no-cache-dir -r cli/requirements.txt

# ────────────────────────────────────────────────
# 8. Compilation de librespot-auth (Rust)
# ────────────────────────────────────────────────
RUN mkdir -p /var/www/html/var/librespot-auth \
    && cd /var/www/html/var/librespot-auth \
    && git clone https://github.com/Gautardos/librespot-auth.git . \
    && curl --proto '=https' --tlsv1.2 -sSf https://sh.rustup.rs | sh -s -- -y --no-modify-path \
    && . "$HOME/.cargo/env" \
    && cargo build --release \
    && rm -rf /root/.cargo /root/.rustup   # on nettoie le plus possible

# ────────────────────────────────────────────────
# 9. Configuration Apache + Symfony
# ────────────────────────────────────────────────
RUN sed -i 's!/var/www/html!/var/www/html/public!g' /etc/apache2/sites-available/000-default.conf \
    && a2enmod rewrite

# Port 8000 au lieu de 80
RUN sed -i 's/Listen 80/Listen 8000/' /etc/apache2/ports.conf \
    && sed -i 's/<VirtualHost \*:80>/<VirtualHost *:8000>/g' /etc/apache2/sites-available/000-default.conf \
    && sed -i 's/<VirtualHost \*:80>/<VirtualHost *:8000>/g' /etc/apache2/sites-available/default-ssl.conf 2>/dev/null || true

# ────────────────────────────────────────────────
# 10. Optimisations KeepAlive / sessions Redis
# ────────────────────────────────────────────────
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

# MPM prefork tuning
RUN echo '' >> /etc/apache2/mods-enabled/mpm_prefork.conf \
    && echo '    KeepAlive On' >> /etc/apache2/mods-enabled/mpm_prefork.conf \
    && echo '    KeepAliveTimeout 300' >> /etc/apache2/mods-enabled/mpm_prefork.conf \
    && echo '    MaxKeepAliveRequests 200' >> /etc/apache2/mods-enabled/mpm_prefork.conf \
    && echo '    Timeout 300' >> /etc/apache2/mods-enabled/mpm_prefork.conf

# ────────────────────────────────────────────────
# 11. Dossiers de runtime (créés vides dans l’image)
# ────────────────────────────────────────────────
RUN mkdir -p var/storage var/sessions var/cache var/log var/composer_home \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 775 var vendor \
    && find /var/www/html/var/librespot-auth -type f -name "librespot-auth" -exec chmod +x {} +

# ────────────────────────────────────────────────
# 12. Fichiers de configuration finale
# ────────────────────────────────────────────────
COPY supervisord.conf /etc/supervisord.conf

# ────────────────────────────────────────────────
# Ports & démarrage
# ────────────────────────────────────────────────
EXPOSE 8000

CMD ["/usr/bin/supervisord", "-c", "/etc/supervisord.conf"]