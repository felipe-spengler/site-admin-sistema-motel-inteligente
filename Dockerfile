FROM php:8.2-apache

# Install mysqli extension and pdo_mysql
RUN docker-php-ext-install mysqli pdo pdo_mysql && docker-php-ext-enable mysqli pdo_mysql

# Install system dependencies (git, unzip for composer)
RUN apt-get update && apt-get install -y git unzip

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Copy application files (so we can run composer install)
COPY . /var/www/html/

# Set working directory
WORKDIR /var/www/html

# Run Composer Install (ignore platform reqs to avoid checking local machine php version)
# We run this specifically in the api folder if that's where composer.json is, 
# OR in root if composer.json is in root.
# Looking at your file structure, composer.json seems to be in /api/ based on grep result.
# Let's try installing in both or root, but safer to check.
# Assuming composer.json is in /api/
RUN if [ -f "./api/composer.json" ]; then cd api && composer install --no-dev --optimize-autoloader; fi
# Also check root just in case
RUN if [ -f "composer.json" ]; then composer install --no-dev --optimize-autoloader; fi

# Set ownership
RUN chown -R www-data:www-data /var/www/html/

# Enable rewrite and headers modules
RUN a2enmod rewrite headers

# Expose port 80
EXPOSE 80
