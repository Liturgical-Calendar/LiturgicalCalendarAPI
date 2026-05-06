# Use the official PHP 8.4 CLI image as the base image
FROM php:8.4-cli AS build

# Install necessary PHP extensions and Composer in one step to minimize layers
RUN --mount=type=cache,target=/var/cache/apt \
    --mount=type=cache,target=/var/lib/apt \
    apt-get update -y && \
    apt-get install -y --no-install-suggests --no-install-recommends \
        libicu-dev libonig-dev libzip-dev libpq-dev gettext libyaml-dev && \
    docker-php-ext-install intl zip calendar gettext pdo pdo_pgsql && \
    pecl install apcu yaml && \
    docker-php-ext-enable intl zip calendar apcu yaml gettext && \
    curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer && \
    rm -rf /var/lib/apt/lists/*

# Set the working directory
WORKDIR /var/www/html

# Copy composer files first for caching purposes
COPY composer.json composer.lock ./

# Run composer install to install dependencies
RUN --mount=type=cache,target=/tmp/composer \
    composer install --no-interaction --prefer-dist \
    --optimize-autoloader --no-dev

# Copy the rest of the application code (.dockerignore not working when building from docker compose and remote repo)
COPY ./src ./src
COPY ./i18n ./i18n
COPY ./jsondata ./jsondata
COPY ./public/LitCalTestServer.php ./public/index.php ./public/
COPY ./.env.example ./.env.local

# Include scripts directory (setup scripts, init-db.sql, openfga model)
# so consumers (e.g., frontend docker-compose) can extract them.
COPY ./scripts ./scripts

# Stage 2: final build
FROM php:8.4-cli AS main

# Set the working directory
WORKDIR /var/www/html

# Install runtime dependencies (not the -dev packages).
# jq is included so consumers of this image (e.g., the frontend's openfga-setup
# service) can run setup-openfga.sh / setup-zitadel.sh without an apt-get install
# at container start. curl is already provided by the php:8.4-cli base image.
RUN apt-get update -y && \
    apt-get install -y --no-install-suggests --no-install-recommends \
    libyaml-0-2 libicu76 libzip5 libpq5 locales-all jq && \
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
