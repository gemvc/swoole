FROM php:8.2-apache

# Install PHP extensions
RUN apt-get update && apt-get install -y \
    libzip-dev \
    zip \
    unzip \
    git \
    && docker-php-ext-install pdo_mysql zip \
    && a2enmod rewrite \
    && a2enmod headers

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www/html

# Copy application files
COPY . /var/www/html

# Set proper permissions
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

# Update Apache configuration to point to public directory
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Install dependencies
RUN composer install --no-dev --optimize-autoloader

# Expose port 80
EXPOSE 80

# Start Apache
CMD ["apache2-foreground"]

