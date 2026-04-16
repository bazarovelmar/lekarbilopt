# Playwright Proxy Server Setup (VPS)

Ниже пошаговая инструкция, как мы развернули отдельный сервис Playwright‑API на VPS с доменом и HTTPS.

## 0) Предусловия
- VPS с Ubuntu 22.04 (root‑доступ).
- Домен (например `playwrite1.lekarbil.ru`) указывает на IP сервера (A‑запись).
- Проект `wb-playwright-proxy` уже в git или будет загружен через `scp`.

Проверка DNS:
```bash
dig +short playwrite1.lekarbil.ru
```
Должен вернуть IP VPS.

---

## 1) Подготовка сервера
```bash
apt update && apt upgrade -y
apt install -y git curl
curl -fsSL https://deb.nodesource.com/setup_20.x | bash -
apt install -y nodejs
```

---

## 2) Загрузка проекта

### Вариант A: из git
```bash
git clone <ТВОЙ_GIT_URL> wb-playwright-proxy
cd wb-playwright-proxy
```

### Вариант B: scp
```bash
scp -r wb-playwright-proxy root@<IP_сервера>:/root/
cd /root/wb-playwright-proxy
```

---

## 3) Установка зависимостей
```bash
npm install
```

## 4) Установка браузера Playwright
```bash
PLAYWRIGHT_BROWSERS_PATH=/ms-playwright npx playwright install --with-deps chromium
```

---

## 5) Проверка запуска вручную
```bash
PORT=3000 node src/server.js
```
В другом окне:
```bash
curl http://127.0.0.1:3000/health
```
Должно вернуть `{"ok":true}`. Остановить `Ctrl+C`.

---

## 6) Автозапуск через systemd

### Создание сервиса (через printf, без зависания)
```bash
printf '%s\n' \
'[Unit]' \
'Description=WB Playwright Proxy' \
'After=network.target' \
'' \
'[Service]' \
'Type=simple' \
'WorkingDirectory=/root/wb-playwright-proxy' \
'ExecStart=/usr/bin/node /root/wb-playwright-proxy/src/server.js' \
'Restart=always' \
'Environment=PORT=3000' \
'Environment=NODE_ENV=production' \
'' \
'[Install]' \
'WantedBy=multi-user.target' \
| tee /etc/systemd/system/wb-playwright.service > /dev/null
```

```bash
systemctl daemon-reload
systemctl enable wb-playwright
systemctl start wb-playwright
systemctl status wb-playwright
```

Проверка:
```bash
curl http://127.0.0.1:3000/health
```

---

## 7) Nginx + HTTPS (Let’s Encrypt)

### Установка nginx
```bash
apt install -y nginx
```

### Конфиг для домена (HTTP + HTTPS)
```bash
printf '%s\n' \
'server {' \
'    listen 80;' \
'    server_name playwrite1.lekarbil.ru;' \
'    return 301 https://$host$request_uri;' \
'}' \
'' \
'server {' \
'    listen 443 ssl;' \
'    server_name playwrite1.lekarbil.ru;' \
'' \
'    ssl_certificate /etc/letsencrypt/live/playwrite1.lekarbil.ru/fullchain.pem;' \
'    ssl_certificate_key /etc/letsencrypt/live/playwrite1.lekarbil.ru/privkey.pem;' \
'' \
'    location / {' \
'        proxy_pass http://127.0.0.1:3000;' \
'        proxy_set_header Host $host;' \
'        proxy_set_header X-Real-IP $remote_addr;' \
'    }' \
'}' | tee /etc/nginx/sites-available/wb-playwright > /dev/null
```

### Включить сайт и перезапустить nginx
```bash
rm -f /etc/nginx/sites-enabled/default
ln -s /etc/nginx/sites-available/wb-playwright /etc/nginx/sites-enabled/
nginx -t
systemctl restart nginx
```

### Установка certbot и сертификата
```bash
apt install -y certbot python3-certbot-nginx
certbot --nginx -d playwrite1.lekarbil.ru
```

---

## 8) Проверка
```bash
curl -L https://playwrite1.lekarbil.ru/health
```
Должно вернуть `{"ok":true}`.

---

## 9) Ошибки и решения

### 404 на HTTPS
- SSL был поставлен в `default` конфиг, а не в `wb-playwright`.
- Удалить `default` и использовать конфиг `wb-playwright` с SSL.

### 502 Bad Gateway
- Сервис не запущен: `systemctl status wb-playwright`
- Проверить локально `curl http://127.0.0.1:3000/health`

---

## 10) Использование как удалённого узла

В основном проекте:
```
WB_PLAYWRIGHT_REMOTE_NODES=https://playwrite1.lekarbil.ru
```

После перезапуска приложение будет ходить на удалённый узел.
