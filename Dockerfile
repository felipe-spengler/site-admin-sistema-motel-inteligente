FROM php:8.2-apache

# Install mysqli extension and pdo_mysql
RUN docker-php-ext-install mysqli pdo pdo_mysql && docker-php-ext-enable mysqli pdo_mysql

# Install system dependencies (git, unzip, tzdata)
RUN apt-get update && apt-get install -y git unzip tzdata

# Define timezone
ENV TZ=America/Sao_Paulo
RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Copy application files
COPY . /var/www/html/

# Set working directory
WORKDIR /var/www/html

# Composer install
RUN if [ -f "./api/composer.json" ]; then cd api && composer install --no-dev --optimize-autoloader; fi
RUN if [ -f "composer.json" ]; then composer install --no-dev --optimize-autoloader; fi

# Set ownership
RUN chown -R www-data:www-data /var/www/html/

# Enable Apache modules
RUN a2enmod rewrite headers

# Expose port
EXPOSE 80
