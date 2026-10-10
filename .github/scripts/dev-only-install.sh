#!/usr/bin/env bash
set -euo pipefail

checkout=$(cd "$1" && pwd)
scripts=$(cd "$(dirname "$0")" && pwd)

work=$(cd "$(mktemp -d)" && pwd -P)
trap 'rm -rf "$work"' EXIT

fail() {
    echo "::error::$*" >&2
    exit 1
}

probe() {
    php "$scripts/dev-only-probe.php" "$work/$1" | sed "s#$work/$1#<app>#g"
}

composer create-project laravel/laravel "$work/skeleton" --prefer-dist --no-interaction --no-progress --quiet

for app in plain plain-firewatch nightwatch nightwatch-firewatch; do
    cp -R "$work/skeleton" "$work/$app"
done

for app in nightwatch nightwatch-firewatch; do
    composer --working-dir="$work/$app" require laravel/nightwatch --no-interaction --no-progress --quiet
done

repository=$(jq -nc --arg url "$checkout" '{type: "path", url: $url, options: {symlink: true, versions: {"claudiodekker/firewatch": "dev-main"}}}')

for app in plain-firewatch nightwatch-firewatch; do
    composer --working-dir="$work/$app" config repositories.firewatch "$repository"
    composer --working-dir="$work/$app" require --dev claudiodekker/firewatch:dev-main --no-interaction --no-progress --quiet
done

for app in plain nightwatch; do
    composer --working-dir="$work/$app" install --no-dev --no-interaction --no-progress --quiet
done

check() {
    local app=$1 baseline=$2

    probe "$app" > "$work/$app.dev.json"

    jq -e '.firewatchCommands | length > 0' "$work/$app.dev.json" > /dev/null \
        || fail "$app: the probe sees no firewatch:* command with Firewatch installed, so it cannot detect the defect it guards"
    jq -e '.packages | index("claudiodekker/firewatch") != null' "$work/$app.dev.json" > /dev/null \
        || fail "$app: the probe does not see Firewatch in the discovered packages with Firewatch installed, so it cannot detect the defect it guards"

    rm -rf "$work/$app/storage/firewatch"
    composer --working-dir="$work/$app" install --no-dev --no-interaction --no-progress --quiet
    probe "$app" > "$work/$app.prod.json"

    jq -e '.firewatchCommands == []' "$work/$app.prod.json" > /dev/null \
        || fail "$app: firewatch:* commands are registered after composer install --no-dev"
    jq -e '.packages | index("claudiodekker/firewatch") == null' "$work/$app.prod.json" > /dev/null \
        || fail "$app: claudiodekker/firewatch is in bootstrap/cache/packages.php after composer install --no-dev"
    jq -e '.storeFiles == []' "$work/$app.prod.json" > /dev/null \
        || fail "$app: a store file exists after the application booted without Firewatch"
    [ ! -e "$work/$app/vendor/claudiodekker/firewatch" ] \
        || fail "$app: vendor/claudiodekker/firewatch is still installed after composer install --no-dev"

    probe "$baseline" > "$work/$baseline.json"
    diff -u "$work/$baseline.json" "$work/$app.prod.json" \
        || fail "$app: discovered packages, IngestingEvents listeners or config('nightwatch') differ from $baseline, an application that never had Firewatch"

    echo "$app: Firewatch is absent and the application matches $baseline"
}

check plain-firewatch plain
check nightwatch-firewatch nightwatch

jq -e '.nightwatchConfig != null and (.packages | index("laravel/nightwatch") != null)' "$work/nightwatch-firewatch.prod.json" > /dev/null \
    || fail "nightwatch-firewatch: Nightwatch is not discovered and configured after composer install --no-dev"
