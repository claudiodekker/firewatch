#!/usr/bin/env bash
# root ignores file modes, so the suite runs as an unprivileged user.
set -euo pipefail

checkout=$(cd "$1" && pwd)
image=$2
role=$3

work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT
tar -C "$checkout" --exclude=./vendor --exclude=./.git --exclude=./storage -cf - . | tar -C "$work" -xf -
docker run --rm --entrypoint cat composer:2 /usr/bin/composer > "$work/composer.phar"
chmod -R a+rwX "$work"

docker run --rm -v "$work:/app" -w /app -e COMPOSER_NO_INTERACTION=1 "$image" sh -ec '
  trap "chmod -R a+rwX /app" EXIT
  if command -v apk >/dev/null; then
    apk add --no-cache git unzip shadow >/dev/null
  else
    apt-get update -qq >/dev/null && apt-get install -y -qq git unzip >/dev/null
  fi
  useradd -m ci
  su ci -s /bin/sh -c "
    set -e
    export HOME=/home/ci COMPOSER_HOME=/home/ci/.composer
    git config --global --add safe.directory /app
    php composer.phar update --prefer-dist --no-progress --with laravel/framework:13.* --with laravel/nightwatch:~1.30.2
    php .github/scripts/sqlite-guard.php '"$role"'
    php -d memory_limit=2G vendor/bin/pest
  "
'
