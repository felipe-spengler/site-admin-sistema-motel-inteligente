FROM php:8.2-apache

# Install mysqli extension and pdo_mysql
RUN docker-php-ext-install mysqli pdo pdo_mysql && docker-php-ext-enable mysqli pdo_mysql

# Copy application files
COPY . /var/www/html/

# Set ownership
RUN chown -R www-data:www-data /var/www/html/

# Enable rewrite and headers modules
RUN a2enmod rewrite headers

# Expose port 80
EXPOSE 80
