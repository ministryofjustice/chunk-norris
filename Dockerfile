FROM php:latest

# Install system dependencies and AWS CLI v2
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libzip-dev \
    curl \
    less \
    groff \
 && docker-php-ext-install zip \
 && curl "https://awscli.amazonaws.com/awscli-exe-linux-aarch64.zip" -o "awscliv2.zip" \
 && unzip awscliv2.zip \
 && ./aws/install \
 && rm -rf awscliv2.zip aws/ /var/lib/apt/lists/*

# Install Composer globally
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Set working directory inside container
WORKDIR /app

# Install AWS SDK for PHP directly
RUN composer require aws/aws-sdk-php:^3.0 --no-interaction --no-progress --optimize-autoloader

# Copy the scraper code
COPY scraper/ /app/

# Create non-root user
RUN useradd -m -u 1000 appuser && \
    chown -R appuser:appuser /app

# Switch to non-root user
USER 1000

# Default command
CMD ["php", "/app/run.php"]

