# Use the official PHP 8.3 CLI image as the base image
FROM php:8.4-cli AS build

# Install necessary PHP extensions and Composer in one step to minimize layers
RUN apt update -y && \
    apt upgrade -y && \
    apt install -y --no-install-suggests --no-install-recommends libicu-dev libonig-dev libzip-dev gettext libyaml-dev && \
    docker-php-ext-install intl zip gettext calendar && \
    pecl install yaml && \
    docker-php-ext-enable intl zip gettext calendar yaml && \
    curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer && \
    apt-get clean && \
    rm -rf /var/lib/apt/lists/*

# Set the working directory
WORKDIR /var/www/html

COPY composer.json composer.lock ./

# Run composer install to install dependencies
RUN composer install --no-interaction --prefer-dist --optimize-autoloader --no-dev

# Copy the rest of the application code
COPY . .

# Stage 2: final build
FROM php:8.4-cli AS main

# Set the working directory
WORKDIR /var/www/html

# Copy the compiled PHP extensions from the build stage
COPY --from=build /usr/lib/x86_64-linux-gnu/libyaml* /usr/lib/x86_64-linux-gnu
COPY --from=build /usr/lib/x86_64-linux-gnu/libzip* /usr/lib/x86_64-linux-gnu
COPY --from=build /usr/local/lib/php/extensions /usr/local/lib/php/extensions
COPY --from=build /usr/local/etc/php/conf.d /usr/local/etc/php/conf.d
COPY --from=build /usr/local/bin/composer /usr/local/bin/composer
COPY --from=build /var/www/html /var/www/html

RUN apt update -y && \
    apt upgrade -y && \
    apt install -y --no-install-suggests --no-install-recommends locales-all && \
    apt-get clean && \
    rm -rf /var/lib/apt/lists/*

# Expose port 8000 to the host
EXPOSE 8000

# Set the environment variable
ENV PHP_CLI_SERVER_WORKERS=6

# Command to run PHP's built-in server
CMD ["php", "-S", "0.0.0.0:8000", "-t", "/var/www/html"]
