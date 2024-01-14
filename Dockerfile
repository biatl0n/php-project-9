FROM php:8.1-cli


RUN apt-get update && apt-get install -y libzip-dev libpq-dev
RUN docker-php-ext-install zip pdo pdo_pgsql

RUN php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');" \
    && php composer-setup.php --install-dir=/usr/local/bin --filename=composer \
    && php -r "unlink('composer-setup.php');"

WORKDIR /app

COPY . .

RUN composer install

ENV DATABASE_URL=postgres://pageadmin:k2geMneAkUhLdVLBRrLzyQ3D25mZ3NRK@dpg-cm43rja1hbls73ab647g-a.oregon-postgres.render.com:5432/pageanalyzer_7sas

CMD ["bash", "-c", "make start"]
