FROM php:8-apache
RUN echo "expose_php = Off" > /usr/local/etc/php/conf.d/security.ini
RUN a2enmod rewrite
RUN mkdir /data
RUN chown -R www-data:www-data /data
COPY src /var/www
WORKDIR /var/www/html
VOLUME /data
