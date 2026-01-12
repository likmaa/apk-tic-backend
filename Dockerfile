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

# Configurer PHP-FPM pour écouter sur un socket Unix (plus fiable en container)
RUN sed -i 's/listen = 0.0.0.0:9000/listen = \/var\/run\/php-fpm.sock/' /usr/local/etc/php-fpm.d/www.conf \
    && sed -i 's/;listen.owner = www-data/listen.owner = www-data/' /usr/local/etc/php-fpm.d/www.conf \
    && sed -i 's/;listen.group = www-data/listen.group = www-data/' /usr/local/etc/php-fpm.d/www.conf \
    && sed -i 's/;listen.mode = 0660/listen.mode = 0660/' /usr/local/etc/php-fpm.d/www.conf

# Préparer les répertoires pour Nginx
RUN mkdir -p /var/run/nginx /var/log/nginx /var/lib/nginx/body /var/lib/nginx/proxy \
    && chown -R www-data:www-data /var/lib/nginx /var/log/nginx /var/run/nginx

# Supprimer la config par défaut de Nginx pour éviter les conflits de port
RUN rm -rf /etc/nginx/sites-enabled/default /etc/nginx/sites-available/default

# Permissions Laravel
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache \
    && chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

# Script de démarrage robuste avec vérification de config
RUN echo '#!/bin/sh' > /start.sh && \
    echo 'echo "Starting PHP-FPM..."' >> /start.sh && \
    echo 'php-fpm -D' >> /start.sh && \
    echo 'echo "Testing Nginx configuration..."' >> /start.sh && \
    echo 'nginx -t && echo "Starting Nginx..." && nginx -g "daemon off;"' >> /start.sh && \
    chmod +x /start.sh

# Exposer le port HTTP
EXPOSE 80

# Démarrer
CMD ["/start.sh"]
