FROM php:latest

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libzip-dev \
 && docker-php-ext-install zip \
 && rm -rf /var/lib/apt/lists/*

# Install Composer globally
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Set working directory inside container
WORKDIR /app

# Install AWS SDK for PHP
RUN composer require aws/aws-sdk-php --no-interaction --no-progress

# Copy the rest of the scraper code
COPY scraper/ /app/

# Create non-root user
RUN useradd -m -u 1000 appuser
USER 1000 

# Default command
CMD ["php", "/app/run.php"]

