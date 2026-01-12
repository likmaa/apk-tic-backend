FROM php:8.2-fpm

# Installer les dépendances système pour Laravel
RUN apt-get update && apt-get install -y \
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

# Copier le code Laravel
WORKDIR /var/www/html
COPY . .

# Installer les dépendances Laravel
RUN composer install --optimize-autoloader --no-dev

# Exposer le port PHP-FPM
EXPOSE 9000

CMD ["php-fpm"]
