# Customized SolidInvoice for NMP Mobiles - PHP 8.4 + Apache
FROM php:8.4-apache

# System deps for PHP extensions
RUN apt-get update && apt-get install -y --no-install-recommends \
        libicu-dev \
        libpng-dev \
        libjpeg-dev \
        libfreetype-dev \
        libxml2-dev \
        libxslt1-dev \
        libzip-dev \
        libonig-dev \
        default-mysql-client \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        intl \
        gd \
        soap \
        xsl \
        pdo_mysql \
        zip \
        mbstring \
        bcmath \
        opcache \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Apache: point docroot at public/, enable rewrite
RUN a2enmod rewrite
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf \
    && printf '<Directory /var/www/html/public>\n    AllowOverride All\n    Require all granted\n</Directory>\n' > /etc/apache2/conf-available/solidinvoice.conf \
    && a2enconf solidinvoice

# PHP production config
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && printf 'memory_limit=512M\nupload_max_filesize=32M\npost_max_size=32M\ndate.timezone=Asia/Dubai\n' > "$PHP_INI_DIR/conf.d/zz-solidinvoice.ini"

# App source (already contains vendor + built assets)
COPY . /var/www/html/
WORKDIR /var/www/html

ENV SOLIDINVOICE_ENV=prod \
    SOLIDINVOICE_DEBUG=0 \
    SOLIDINVOICE_LOCALE=en

RUN mkdir -p var/cache var/log config/env \
    && chown -R www-data:www-data var config \
    && rm -rf var/cache/prod

COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 80
ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]
CMD ["apache2-foreground"]
