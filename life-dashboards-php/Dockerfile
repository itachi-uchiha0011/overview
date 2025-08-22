FROM php:8.2-apache
RUN a2enmod rewrite && docker-php-ext-install pdo pdo_sqlite
WORKDIR /var/www/html
COPY public/ /var/www/html/
RUN mkdir -p /var/www/html/uploads/files /var/www/html/uploads/avatars
COPY --chown=www-data:www-data public/ /var/www/html/
COPY --chown=www-data:www-data data/ /var/www/html/data/
EXPOSE 80
CMD ["apache2-foreground"]
