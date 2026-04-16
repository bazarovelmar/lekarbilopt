FROM php:8.3-fpm

RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev libjpeg62-turbo-dev libpng-dev zip unzip nodejs npm \
    && docker-php-ext-configure gd --with-jpeg \
    && docker-php-ext-install pdo_pgsql gd \
    && rm -rf /var/lib/apt/lists/*

RUN npm install -g playwright \
    && PLAYWRIGHT_BROWSERS_PATH=/ms-playwright npx playwright install --with-deps chromium

WORKDIR /var/www/html

COPY . .

RUN chown -R www-data:www-data storage bootstrap/cache

CMD ["php-fpm"]
