# Stage 1: Build environment
FROM php:8.3-cli-alpine AS builder

# Install necessary packages
RUN apk add --no-cache --virtual .build-deps \
    autoconf \
    g++ \
    make \
    icu-dev \
    oniguruma-dev \
    libzip-dev \
    gettext-dev \
    curl \
    && docker-php-ext-configure intl \
    && docker-php-ext-install intl mbstring zip gettext \
    && rm -rf /var/cache/apk/*

# Install Composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Set the working directory
WORKDIR /var/www/html

# Copy composer files and install dependencies
COPY composer.json composer.lock ./
RUN composer install --no-interaction --prefer-dist --optimize-autoloader --no-dev

# Copy the rest of the application code
COPY . .

# Stage 2: Production environment
FROM php:8.3-cli-alpine

# Install necessary runtime packages
RUN apk add --no-cache \
    icu \
    oniguruma \
    libzip \
    gettext

# Set the working directory
WORKDIR /var/www/html

# Copy the compiled PHP extensions from the builder stage
COPY --from=builder /usr/local/lib/php/extensions /usr/local/lib/php/extensions
COPY --from=builder /usr/local/etc/php/conf.d /usr/local/etc/php/conf.d
COPY --from=builder /usr/local/bin/composer /usr/local/bin/composer

# Copy the application files from the builder stage
COPY --from=builder /var/www/html /var/www/html

# Ensure gettext extension is enabled
RUN docker-php-ext-enable intl mbstring zip gettext

# Set the environment variable
ENV PHP_CLI_SERVER_WORKERS=2

# Expose port 8000 to the host
EXPOSE 8000

# Command to run PHP's built-in server
CMD ["php", "-S", "0.0.0.0:8000", "-t", "/var/www/html"]
