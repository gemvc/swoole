FROM nginx:alpine

# Install PHP-FPM and required extensions
RUN apk add --no-cache \
    php81-fpm \
    php81-pdo \
    php81-pdo_mysql \
    php81-mbstring \
    php81-json \
    php81-openssl \
    php81-tokenizer \
    php81-xml \
    php81-ctype \
    php81-fileinfo \
    php81-dom \
    php81-simplexml \
    php81-xmlreader \
    php81-xmlwriter \
    php81-zip \
    php81-curl \
    php81-gd \
    php81-intl \
    php81-bcmath \
    php81-opcache \
    composer

# Set working directory
WORKDIR /var/www/html

# Copy application files
COPY . .

# Copy Nginx configuration
COPY nginx/nginx.conf /etc/nginx/conf.d/default.conf

# Configure PHP-FPM
RUN sed -i 's/listen = 127.0.0.1:9000/listen = 0.0.0.0:9000/' /etc/php81/php-fpm.d/www.conf \
    && sed -i 's/user = nobody/user = nginx/' /etc/php81/php-fpm.d/www.conf \
    && sed -i 's/group = nobody/group = nginx/' /etc/php81/php-fpm.d/www.conf

# Configure PHP
RUN sed -i 's/upload_max_filesize = 2M/upload_max_filesize = 64M/' /etc/php81/php.ini \
    && sed -i 's/post_max_size = 8M/post_max_size = 64M/' /etc/php81/php.ini \
    && sed -i 's/memory_limit = 128M/memory_limit = 256M/' /etc/php81/php.ini

# Set proper permissions
RUN chown -R nginx:nginx /var/www/html \
    && chmod -R 755 /var/www/html

# Install Composer dependencies
RUN composer install --no-dev --optimize-autoloader

# Create startup script
RUN echo '#!/bin/sh' > /start.sh \
    && echo 'php-fpm81 -D' >> /start.sh \
    && echo 'nginx -g "daemon off;"' >> /start.sh \
    && chmod +x /start.sh

# Expose port
EXPOSE 80

# Start both PHP-FPM and Nginx
CMD ["/start.sh"]
