# Production deploy

## Что входит в прод-контур

- `docker-compose.prod.yml` поднимает `app`, `worker`, `nginx`, `postgres`, `redis`.
- `Dockerfile.prod` собирает отдельные prod-образы:
  - PHP-FPM с `vendor`, собранными Vite-ассетами и Playwright/Chromium.
  - Nginx со статикой из `public` и `public/build`.
- Сертификаты Let's Encrypt хранятся в docker volume `letsencrypt`.
- Nginx автоматически работает в двух режимах:
  - если сертификатов нет, стартует HTTP-конфиг на `:80`;
  - после выпуска сертификата и рестарта nginx включает HTTPS на `:443`.

## Перед деплоем

1. Укажите DNS-запись:
   - `A genlekopt.lekarbil.ru -> IP_вашего_сервера`
2. Откройте порты `80` и `443` на сервере.
3. Установите Docker Engine и Docker Compose plugin.

## Подготовка переменных

1. Скопируйте шаблон:

```bash
cp .env.prod.example .env.prod
```

2. Обязательно заполните в `.env.prod`:
   - `APP_KEY`
   - `DB_PASSWORD`
   - `POSTGRES_PASSWORD`
   - `OPENAI_API_KEY`
   - `TELEGRAM_BOT_TOKEN`
   - `AIDENTIKA_API_KEY`
   - `AIDENTIKA_WEBHOOK_SECRET` при использовании подписи webhook

3. Сгенерируйте `APP_KEY`:

```bash
docker compose -f docker-compose.prod.yml build app
docker compose -f docker-compose.prod.yml run --rm app php artisan key:generate --show
```

Скопируйте сгенерированное значение в `APP_KEY=` внутри `.env.prod`.

## Первый запуск

1. Соберите и поднимите сервисы:

```bash
docker compose -f docker-compose.prod.yml up -d --build
```

2. Прогоните миграции:

```bash
docker compose -f docker-compose.prod.yml exec app php artisan migrate --force
```

3. Проверьте, что HTTP уже отвечает:

```bash
curl -I http://genlekopt.lekarbil.ru
```

## Выпуск сертификата Let's Encrypt

Сертификат выпускается через `certbot` по `webroot`, не останавливая nginx.

```bash
docker compose --profile ops -f docker-compose.prod.yml run --rm certbot \
  certonly --webroot -w /var/www/certbot \
  -d genlekopt.lekarbil.ru \
  --email you@example.com \
  --agree-tos \
  --no-eff-email
```

После успешного выпуска перезапустите nginx:

```bash
docker compose -f docker-compose.prod.yml restart nginx
```

После рестарта nginx увидит файлы в `/etc/letsencrypt/live/genlekopt.lekarbil.ru/` и автоматически включит HTTPS.

## Обновление webhook-ов

После включения HTTPS выполните:

```bash
docker compose -f docker-compose.prod.yml exec app php artisan telegram:webhook:set
docker compose -f docker-compose.prod.yml exec app php artisan aidentika:webhook:set
```

## Продление сертификатов

Раз в сутки запускайте renew через cron:

```cron
17 3 * * * cd /opt/LekarBilOptom && docker compose --profile ops -f docker-compose.prod.yml run --rm certbot renew --webroot -w /var/www/certbot && docker compose -f docker-compose.prod.yml exec nginx nginx -s reload >/dev/null 2>&1
```

Путь `/opt/LekarBilOptom` замените на реальный путь проекта на сервере.

## Обновление приложения

```bash
git pull
docker compose -f docker-compose.prod.yml up -d --build
docker compose -f docker-compose.prod.yml exec app php artisan migrate --force
```

## Что важно учесть

- `storage` вынесен в docker volume `app_storage`, поэтому логи и файлы сохраняются между пересборками.
- В проекте есть queue worker, поэтому сервис `worker` обязателен.
- Laravel route cache сейчас лучше не включать: в `routes/web.php` есть closure-маршрут `/`.
