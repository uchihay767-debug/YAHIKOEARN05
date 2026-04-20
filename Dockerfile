FROM php:8.2-apache

# Install dependencies
RUN apt-get update && apt-get install -y \
    libzip-dev \
    unzip \
    && docker-php-ext-install zip pdo_mysql

# Enable Apache mod_rewrite
RUN a2enmod rewrite

# Set working directory
WORKDIR /var/www/html

# Copy files
COPY . .

# Set proper permissions
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && chmod 666 users.json error.log 2>/dev/null || true

# Expose port 10000 (Render default)
EXPOSE 10000

# Apache config
COPY 000-default.conf /etc/apache2/sites-available/000-default.conf
RUN a2dissite 000-default.conf.sample

CMD ["apache2-foreground"]