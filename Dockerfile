FROM php:8-apache
RUN a2enmod rewrite
RUN mkdir /data
RUN chown -R www-data:www-data /data
COPY src /var/www/html
WORKDIR /var/www/html
VOLUME /data
