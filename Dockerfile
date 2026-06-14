FROM wordpress:6.4-apache

# Install system dependencies, PostgreSQL development libraries, and supervisor
RUN apt-get update && apt-get install -y \
    libpq-dev \
    git \
    unzip \
    mariadb-client \
    postgresql-client \
    supervisor \
    && docker-php-ext-install pdo_pgsql pgsql pdo_mysql \
    && mkdir -p /var/log/supervisor \
    && chown -R www-data:www-data /var/log/supervisor \
    && rm -rf /var/lib/apt/lists/*

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Install WP-CLI
RUN curl -O https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar \
    && chmod +x wp-cli.phar \
    && mv wp-cli.phar /usr/local/bin/wp

# Copy Supervisor configuration
COPY supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Adjust permissions for Apache and CLI usage
RUN chown -R www-data:www-data /var/www

# Enable rewrite module (standard for WordPress permalinks)
RUN a2enmod rewrite

# Workdir is set to standard wordpress dir
WORKDIR /var/www/html
