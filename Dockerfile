# Use the official PHP 8.3 CLI image as the base image
FROM php:8.3-cli

# Set the working directory
WORKDIR /var/www/html

# Install necessary PHP extensions
RUN apt update -y && \
    apt upgrade -y && \
    apt install -y --no-install-recommends libicu-dev libonig-dev libzip-dev locales-all && \
    docker-php-ext-configure intl && \
    docker-php-ext-install intl mbstring zip && \
    docker-php-ext-enable intl mbstring zip

# Install Composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Copy the current directory contents into the container
COPY . ./

# Set the environment variable
ENV PHP_CLI_SERVER_WORKERS=2

# Run composer install to install dependencies
RUN composer install --no-interaction --prefer-dist --optimize-autoloader --no-dev

# Expose port 8000 to the host
EXPOSE 8000

# Command to run PHP's built-in server
CMD ["php", "-S", "0.0.0.0:8000", "-t", "/var/www/html"]
