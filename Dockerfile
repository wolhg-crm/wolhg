FROM php:8.5-apache

RUN apt-get update && apt-get install -y libcurl4-openssl-dev libonig-dev unzip && rm -rf /var/lib/apt/lists/*
RUN docker-php-ext-install curl mbstring pdo_mysql
RUN a2enmod rewrite headers
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY apache.conf /etc/apache2/sites-available/000-default.conf
COPY docker-entrypoint.sh /usr/local/bin/church-care-entrypoint
RUN chmod +x /usr/local/bin/church-care-entrypoint

WORKDIR /var/www/html
ENTRYPOINT ["church-care-entrypoint"]
CMD ["apache2-foreground"]
