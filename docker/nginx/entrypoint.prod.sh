#!/bin/sh
set -eu

DOMAIN="${APP_DOMAIN:?APP_DOMAIN is required}"
SERVER_NAME="${NGINX_SERVER_NAME:-$DOMAIN}"
TLS_CERT="/etc/letsencrypt/live/${DOMAIN}/fullchain.pem"
TLS_KEY="/etc/letsencrypt/live/${DOMAIN}/privkey.pem"

if [ -f "$TLS_CERT" ] && [ -f "$TLS_KEY" ]; then
  export SERVER_NAME TLS_CERT TLS_KEY
  envsubst '${SERVER_NAME} ${TLS_CERT} ${TLS_KEY}' \
    < /etc/nginx/templates/app-https.conf.template \
    > /etc/nginx/conf.d/default.conf
else
  export SERVER_NAME
  envsubst '${SERVER_NAME}' \
    < /etc/nginx/templates/app-http.conf.template \
    > /etc/nginx/conf.d/default.conf
fi

exec "$@"
