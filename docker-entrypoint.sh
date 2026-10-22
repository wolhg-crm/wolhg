#!/usr/bin/env sh
set -e

if [ -f composer.json ] && [ ! -f vendor/autoload.php ]; then
  composer install --no-interaction --prefer-dist
fi

exec "$@"
