#!/usr/bin/env bash
#
# Puts one commit of Deepakpromonkey/stagingapi live on the staging box.
#
#   deploy.sh <commit sha>
#
# Run by the pipeline as the app user, after provision.sh. If the new release
# does not come up healthy, the previous commit is put back and the run fails.
# Migrations are the exception: they are forward-only and are not undone.
# See docs/staging-deploy.md.

set -euo pipefail

SHA=${1:?usage: deploy.sh <commit sha>}

APP_DIR=/var/www/dollartraq-api
REPO=https://github.com/Deepakpromonkey/stagingapi.git
HOST=staggingapi.dollartraq.com
PHP_FPM=php8.5-fpm

umask 002   # php-fpm runs as www-data and shares the group, not the owner

log() { echo "==> $*"; }

exec 9>/tmp/dollartraq-deploy.lock
flock -n 9 || { echo "Another deploy is running." >&2; exit 1; }

cd "$APP_DIR"

# This checkout used to track the production repository.
[[ "$(git remote get-url origin)" == "$REPO" ]] || git remote set-url origin "$REPO"

if ! git cat-file -e "$SHA^{commit}" 2>/dev/null; then
    log "Fetching $SHA"
    git fetch --quiet --no-tags origin "$SHA"
fi

PREVIOUS=$(git rev-parse HEAD)

# Hand edits on the server would be discarded by the reset below; keep them.
if ! git diff --quiet -- . ':!vendor' ':!storage'; then
    mkdir -p storage/app/deploy-backups
    patch="storage/app/deploy-backups/$(date +%Y%m%d-%H%M%S)-${PREVIOUS:0:8}.patch"
    git diff -- . ':!vendor' ':!storage' > "$patch"
    log "Local edits saved to $patch"
fi

# Files that were committed once and are runtime state now. Out of the index,
# the reset leaves them on disk; a commit that still tracks them re-adds them.
git rm -r -q --cached --ignore-unmatch \
    vendor storage/logs/laravel.log storage/framework/sessions storage/framework/views >/dev/null

# Every step returns on failure by hand: set -e does not apply inside a
# function called as an `if` condition, which is how this one is called.
release() {
    local sha=$1 migrate=$2

    git reset --quiet --hard "$sha" || return 1
    composer install --no-dev --prefer-dist --optimize-autoloader \
        --no-interaction --no-progress --quiet || return 1

    if [[ "$migrate" == yes ]]; then
        php artisan migrate --force --no-interaction || return 1
    fi

    php artisan optimize:clear >/dev/null || return 1
    php artisan optimize >/dev/null || return 1
    sudo systemctl reload "$PHP_FPM" || return 1
    php artisan queue:restart >/dev/null || return 1
}

healthy() {
    local attempt
    for attempt in 1 2 3 4 5; do
        if curl -fsS --max-time 10 --resolve "$HOST:443:127.0.0.1" "https://$HOST/up" >/dev/null \
            && php -r '
                require "vendor/autoload.php";
                $app = require "bootstrap/app.php";
                $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
                $app["cache"]->put("deploy:ping", 1, 30);
                exit($app["cache"]->get("deploy:ping") == 1 ? 0 : 1);
            ' >/dev/null 2>&1
        then
            return 0
        fi
        sleep 3
    done
    return 1
}

log "Releasing ${SHA:0:8} (was ${PREVIOUS:0:8})"

if release "$SHA" yes && healthy; then
    log "Staging is on ${SHA:0:8}"
    exit 0
fi

echo "!! ${SHA:0:8} did not come up healthy; putting ${PREVIOUS:0:8} back" >&2
release "$PREVIOUS" no || true

if healthy; then
    echo "!! Rolled back to ${PREVIOUS:0:8}. Migrations from ${SHA:0:8}, if any, are still applied." >&2
else
    echo "!! Rollback is not healthy either. Check storage/logs/laravel.log." >&2
fi
exit 1
