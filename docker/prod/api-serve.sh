#!/bin/sh
set -e
# Démarrage du service web api sur Render : nginx (HTTP sur $PORT) + php-fpm.
# Les caches Laravel sont déjà (re)générés par api-entrypoint avant ce script.
: "${PORT:=10000}"
export PORT

# Génère le bloc serveur avec le bon port (seul ${PORT} est substitué).
envsubst '${PORT}' < /etc/nginx/nginx-render.conf.template > /tmp/nginx-default.conf

# php-fpm en arrière-plan, nginx au premier plan (PID 1 du conteneur).
php-fpm -D
exec nginx -c /etc/nginx/nginx-render-main.conf -g 'daemon off;'
