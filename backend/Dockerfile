FROM php:8.4-apache

# 1. Instalar dependências do sistema
RUN apt-get update && apt-get install -y \
    libpq-dev \
    libzip-dev \
    zip \
    unzip \
    && docker-php-ext-install pdo pdo_pgsql zip \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# 2. Instalar Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# 3. Configurar diretório de trabalho
WORKDIR /var/www/html
COPY . .

# 4. Configurações do Apache
ENV PORT=8080

RUN echo "Listen ${PORT}" > /etc/apache2/ports.conf && \
    sed -i "s/:80/:${PORT}/g" /etc/apache2/sites-available/000-default.conf && \
    sed -i 's|/var/www/html|/var/www/html/public|g' /etc/apache2/sites-available/000-default.conf && \
    echo "ServerName localhost" >> /etc/apache2/apache2.conf && \
    a2enmod rewrite headers

# 5. Instalar dependências PHP e ajustar permissões
RUN composer install --no-dev --optimize-autoloader --no-interaction && \
    chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache && \
    chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache && \
    chmod +x /var/www/html/docker/start.sh /var/www/html/docker/worker.sh

# 6. Expor porta
EXPOSE 8080

# 7. Comando final de produção
CMD ["/var/www/html/docker/start.sh"]
