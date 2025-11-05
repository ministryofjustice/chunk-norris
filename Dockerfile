FROM php:latest

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libzip-dev \
    curl \
 && docker-php-ext-install zip \
 && rm -rf /var/lib/apt/lists/*

# Install Composer globally
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Set working directory inside container
WORKDIR /app

# Copy composer.json first for better layer caching
COPY composer.json composer.lock* ./

# Install PHP dependencies (AWS SDK)
RUN composer install --no-dev --no-interaction --no-progress --optimize-autoloader

# Copy the rest of the scraper code
COPY scraper/ /app/

# Create non-root user
RUN useradd -m -u 1000 appuser && \
    chown -R appuser:appuser /app

# Switch to non-root user
USER 1000

# Default command
CMD ["php", "/app/run.php"]
