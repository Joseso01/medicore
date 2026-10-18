FROM php:8.2-apache

RUN docker-php-ext-install pdo pdo_mysql

RUN a2enmod rewrite headers

RUN { \
    echo "session.use_strict_mode = 1"; \
    echo "session.cookie_httponly = 1"; \
    echo "session.cookie_samesite = Strict"; \
    echo "session.use_only_cookies = 1"; \
    echo "session.gc_maxlifetime = 1200"; \
    echo "expose_php = Off"; \
    echo "date.timezone = America/Mexico_City"; \
} > /usr/local/etc/php/conf.d/medicore-security.ini

RUN chown -R www-data:www-data /var/www/html

