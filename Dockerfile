FROM php:8.2-apache

RUN apt-get update && apt-get install -y libzip-dev unzip && docker-php-ext-install zip pdo_mysql
RUN a2enmod rewrite

WORKDIR /var/www/html
COPY . .

# YAHIKOEARN05: Auto-setup files & permissions
RUN touch users.json error.log && echo '{}' > users.json \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && chmod 666 users.json error.log \
    && [ -f 000-default.conf ] && cp 000-default.conf /etc/apache2/sites-available/000-default.conf || echo "Using default Apache config"

EXPOSE 10000
CMD ["apache2-foreground"]
