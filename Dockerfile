FROM php:8.2-fpm

# Installer les dépendances système pour Laravel et Nginx
RUN apt-get update && apt-get install -y \
    nginx \
    libzip-dev \
    libonig-dev \
    libxml2-dev \
    unzip \
    git \
    curl \
    && docker-php-ext-install pdo_mysql zip mbstring xml \
    && rm -rf /var/lib/apt/lists/*

# Installer Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Répertoire de travail
WORKDIR /var/www/html

# Copier le code Laravel
COPY . .

# Installer les dépendances Laravel
RUN composer install --optimize-autoloader --no-dev --no-interaction

# Configuration Nginx et PHP-FPM
COPY nginx-internal.conf /etc/nginx/nginx.conf
COPY php-fpm.conf /usr/local/etc/php-fpm.d/www.conf

# Permissions
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache \
    && chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

# Script de démarrage
RUN echo '#!/bin/sh' > /start.sh && \
    echo 'php-fpm -D' >> /start.sh && \
    echo 'nginx -g "daemon off;"' >> /start.sh && \
    chmod +x /start.sh

# Exposer le port HTTP
EXPOSE 80

# Démarrer Nginx et PHP-FPM
CMD ["/start.sh"]
