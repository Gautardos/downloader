FROM php:8.3-apache

# Installer toutes les dépendances système (ajout libonig-dev pour mbstring)
RUN apt-get update && apt-get install -y \
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
    && rm -rf /var/lib/apt/lists/*

# Configurer et installer les extensions PHP
RUN docker-php-ext-configure gd --with-jpeg --with-freetype \
    && docker-php-ext-install \
    pdo \
    pdo_mysql \
    zip \
    gd \
    intl \
    curl \
    mbstring

# Installer Composer
RUN php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');" && \
    php composer-setup.php --install-dir=/usr/local/bin --filename=composer && \
    rm composer-setup.php

WORKDIR /var/www/html

COPY . .

RUN composer install --no-dev --optimize-autoloader

# Python venv
RUN python3 -m venv /opt/venv
RUN /opt/venv/bin/pip install --upgrade pip && \
    /opt/venv/bin/pip install -r cli/requirements.txt

# Config Apache + Symfony public/
RUN sed -i 's!/var/www/html!/var/www/html/public!g' /etc/apache2/sites-available/000-default.conf \
    && a2enmod rewrite

RUN { \
    echo 'KeepAlive On'; \
    echo 'KeepAliveTimeout 300'; \
    echo 'MaxKeepAliveRequests 200'; \
    echo 'Timeout 300'; \
    } >> /etc/apache2/apache2.conf

# Ajout des directives KeepAlive dans le bloc MPM_prefork (prioritaire)
RUN echo '' >> /etc/apache2/mods-enabled/mpm_prefork.conf \
    && echo '    KeepAlive On' >> /etc/apache2/mods-enabled/mpm_prefork.conf \
    && echo '    KeepAliveTimeout 300' >> /etc/apache2/mods-enabled/mpm_prefork.conf \
    && echo '    MaxKeepAliveRequests 200' >> /etc/apache2/mods-enabled/mpm_prefork.conf \
    && echo '    Timeout 300' >> /etc/apache2/mods-enabled/mpm_prefork.conf

# Permissions var/
RUN chown -R www-data:www-data var \
    && chmod -R 775 var

# Config session PHP
RUN mkdir -p /var/www/html/var/sessions \
    && chown -R www-data:www-data /var/www/html/var/sessions \
    && chmod -R 770 /var/www/html/var/sessions \
    && echo "session.save_path = /var/www/html/var/sessions" >> /usr/local/etc/php/conf.d/session.ini \
    && echo "session.gc_probability = 0" >> /usr/local/etc/php/conf.d/session.ini \
    && echo "session.gc_maxlifetime = 1440" >> /usr/local/etc/php/conf.d/session.ini \
    && echo "session.cookie_lifetime = 0" >> /usr/local/etc/php/conf.d/session.ini

COPY supervisord.conf /etc/supervisord.conf

EXPOSE 80

CMD ["/usr/bin/supervisord"]