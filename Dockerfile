FROM php:8.3-apache

RUN a2enmod rewrite headers \
 && mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/portal.ini

WORKDIR /var/www/portal
COPY composer.json ./
RUN composer install --no-dev --no-interaction --no-progress --optimize-autoloader --no-scripts
COPY . .
RUN composer dump-autoload --no-dev --optimize \
 && mkdir -p storage \
 && chown -R www-data:www-data storage

EXPOSE 80
