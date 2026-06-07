FROM php:8.4-apache

# 1. Instalar dependências do sistema
RUN apt-get update && apt-get install -y \
    libpq-dev \
    libzip-dev \
    zip \
    unzip \
    && docker-php-ext-install pdo pdo_pgsql zip

# 2. Instalar Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# 3. Configurar diretório de trabalho
WORKDIR /var/www/html
COPY . .

# 4. Configurações do Apache (Porta e DocumentRoot)
ENV PORT=8080
RUN echo "Listen ${PORT}" > /etc/apache2/ports.conf && \
    sed -i "s/:80/:${PORT}/g" /etc/apache2/sites-available/000-default.conf && \
    sed -i 's|/var/www/html|/var/www/html/public|g' /etc/apache2/sites-available/000-default.conf && \
    a2enmod rewrite

# 5. Instalar dependências PHP e ajustar permissões
RUN composer install --no-dev --optimize-autoloader && \
    chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

# 6. Comando final
EXPOSE 8080
CMD ["sh", "-c", "php artisan migrate --force && apache2-foreground"]
