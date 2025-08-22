# Use the official PHP 8.4 CLI image as the base image
FROM php:8.4-cli AS build

# Install necessary PHP extensions and Composer in one step to minimize layers
RUN apt-get update -y && \
    apt-get install -y --no-install-suggests --no-install-recommends \
        libicu-dev libonig-dev libzip-dev gettext libyaml-dev && \
    docker-php-ext-install intl zip gettext calendar apcu && \
    pecl install yaml && \
    docker-php-ext-enable intl zip gettext calendar yaml && \
    curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer && \
    rm -rf /var/lib/apt/lists/*

# Set the working directory
WORKDIR /var/www/html

# Copy composer files first for caching purposes
COPY composer.json composer.lock ./

# Run composer install to install dependencies
RUN composer install --no-interaction --prefer-dist --optimize-autoloader --no-dev

# Copy the rest of the application code (.dockerignore not working when building from docker compose and remote repo)
COPY ./src ./src
COPY ./i18n ./i18n
COPY ./jsondata ./jsondata
COPY LitCalTestServer.php index.php ./public/

# Stage 2: final build
FROM php:8.4-cli AS main

# Set the working directory
WORKDIR /var/www/html

# Install runtime dependencies (not the -dev packages)
RUN apt-get update -y && \
    apt-get install -y --no-install-suggests --no-install-recommends \
    libyaml-0-2 libzip4 locales-all && \
    rm -rf /var/lib/apt/lists/*

# Copy the compiled PHP extensions from the build stage
COPY --from=build /usr/local/lib/php/extensions /usr/local/lib/php/extensions
COPY --from=build /usr/local/etc/php/conf.d /usr/local/etc/php/conf.d
COPY --from=build /usr/local/bin/composer /usr/local/bin/composer
COPY --from=build /var/www/html /var/www/html

# Set the environment variable
ENV PHP_CLI_SERVER_WORKERS=6

# Expose port 8000 to the host
EXPOSE 8000

# Command to run PHP's built-in server
CMD ["php", "-S", "0.0.0.0:8000", "-t", "/var/www/html/public"]
