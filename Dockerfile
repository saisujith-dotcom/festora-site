FROM php:8.4-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends ca-certificates libonig-dev \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql mbstring \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html
ENV PORT=10000

COPY docker/apache-vhost.conf.template /etc/apache2/sites-available/festora.conf.template
RUN a2dissite 000-default

COPY config.php event.php events.php index.php login.php logout.php my-registrations.php register.php signup.php /var/www/html/
COPY admin/ /var/www/html/admin/
COPY api/ /var/www/html/api/
COPY includes/ /var/www/html/includes/
COPY public/ /var/www/html/public/
COPY database/migrations/ /var/www/html/database/migrations/
COPY scripts/ /var/www/html/scripts/

COPY docker/entrypoint.sh /usr/local/bin/festora-entrypoint
RUN chmod 0755 /usr/local/bin/festora-entrypoint

ENTRYPOINT ["festora-entrypoint"]
CMD ["apache2-foreground"]
