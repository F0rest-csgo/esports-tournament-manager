FROM php:8.4-fpm
RUN apt-get update && apt-get install -y --no-install-recommends libonig-dev libzip-dev \
    && docker-php-ext-install pdo_mysql mbstring zip && rm -rf /var/lib/apt/lists/*
WORKDIR /var/www/tournament
COPY . .
RUN mkdir -p public/uploads var && chown -R www-data:www-data public/uploads var
RUN printf 'display_errors=Off\nlog_errors=On\nexpose_php=Off\nupload_max_filesize=10M\npost_max_size=12M\nsession.use_strict_mode=1\n' > /usr/local/etc/php/conf.d/tournament.ini
