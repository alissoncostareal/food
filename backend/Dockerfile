FROM php:8.4-apache

# Instalar extensões do sistema e PHP necessárias para Laravel
RUN apt-get update && apt-get install -y \
    libpq-dev \
    libzip-dev \
    zip \
    unzip \
    && docker-php-ext-install pdo pdo_pgsql zip

# Instalar Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Configurar Apache
COPY . /var/www/html
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

# Habilitar mod_rewrite do Apache
RUN a2enmod rewrite

# Expor a porta 8080 (o Render usa a porta 8080 ou 10000)
ENV PORT=8080
RUN sed -i 's/80/8080/g' /etc/apache2/ports.conf /etc/apache2/sites-available/000-default.conf

WORKDIR /var/www/html
RUN composer install --no-dev --optimize-autoloader

# Ajustar o DocumentRoot do Apache para a pasta public
RUN sed -i 's|/var/www/html|/var/www/html/public|g' /etc/apache2/sites-available/000-default.conf

# Garantir permissões nas pastas do Laravel
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

# Ajustar permissões para que o artisan consiga escrever no storage
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

# Habilitar mod_rewrite do Apache
RUN a2enmod rewrite

# Mudar o DocumentRoot para a pasta public
RUN sed -i 's|/var/www/html|/var/www/html/public|g' /etc/apache2/sites-available/000-default.conf

# Adicione estas linhas para rodar a migração durante o Build
# Nota: Isso exige que as variáveis de ambiente (DB_URL) estejam disponíveis no build
# Se falhar no build, moveremos para o Start Command abaixo
RUN php artisan migrate --force

# Expor a porta 8080
ENV PORT=8080
RUN sed -i 's/80/8080/g' /etc/apache2/ports.conf /etc/apache2/sites-available/000-default.conf

WORKDIR /var/www/html
CMD ["apache2-foreground"]
