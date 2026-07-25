#!/bin/sh
set -e
# Les variables d'environnement sont injectées au runtime → on (re)génère les caches ici,
# jamais au build (sinon la config est figée avec des valeurs vides).
php artisan config:cache >/dev/null 2>&1 || true
php artisan route:cache >/dev/null 2>&1 || true
php artisan event:cache >/dev/null 2>&1 || true
exec "$@"
