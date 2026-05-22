FROM php:8.1-apache

RUN apt-get update && apt-get install -y --no-install-recommends \
    git \
    unzip \
    libpng-dev \
    libjpeg-dev \
    libzip-dev \
    libxml2-dev \
    libonig-dev \
    libicu-dev \
    zlib1g-dev \
    libsodium-dev \
    default-mysql-client \
  && docker-php-ext-install \
    mysqli \
    pdo_mysql \
    gd \
    intl \
    mbstring \
    opcache \
    soap \
    zip \
    sodium \
  && a2dismod mpm_event mpm_worker 2>/dev/null || true \
  && a2enmod mpm_prefork rewrite \
  && rm -rf /var/lib/apt/lists/*

COPY docker/php/php.ini /usr/local/etc/php/conf.d/moodle.ini
COPY docker/php/moodle.conf /etc/apache2/sites-available/000-default.conf

# Clone IOMAD (Moodle 4.2 branch) into the image
RUN git clone --depth=1 --branch IOMAD_402_STABLE \
    https://github.com/iomad/iomad.git /var/www/html \
  && chown -R www-data:www-data /var/www/html

RUN mkdir -p /var/moodledata && chown www-data:www-data /var/moodledata

# Bake the Claude plugin into the image
COPY plugin/block_iomad_claude /var/www/html/blocks/iomad_claude
RUN chown -R www-data:www-data /var/www/html/blocks/iomad_claude

COPY scripts/entrypoint.sh /scripts/entrypoint.sh
COPY scripts/install-iomad.sh /scripts/install-iomad.sh
RUN chmod +x /scripts/entrypoint.sh /scripts/install-iomad.sh

WORKDIR /var/www/html

EXPOSE 80

ENTRYPOINT ["/bin/bash", "/scripts/entrypoint.sh"]
