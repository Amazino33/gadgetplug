#!/bin/bash
# Run this on the hosting server, from the app root:
#   ./deploy.sh
set -e

# The shared host stops ("Killed") a command that uses too much at once, most
# often straight after composer. A killed step is tried again after a pause
# instead of ending the deploy half way.
retry() {
    for attempt in 1 2 3; do
        if "$@"; then
            return 0
        fi
        echo "    '$*' did not finish (attempt $attempt). Trying again in 15 seconds..."
        sleep 15
    done
    echo "    '$*' failed 3 times. Run it again by hand."
    return 1
}

echo "==> Pulling latest changes from origin/main..."
LOCK_BEFORE=$(git rev-parse HEAD:composer.lock 2>/dev/null || echo none)
git pull origin main
LOCK_AFTER=$(git rev-parse HEAD:composer.lock 2>/dev/null || echo none)

# composer install is the heaviest step, and the one the host kills. Only when
# the PHP packages actually changed (or were never installed).
if [ "$LOCK_BEFORE" != "$LOCK_AFTER" ] || [ ! -f vendor/autoload.php ]; then
    echo "==> PHP packages changed: installing..."
    retry composer install --no-dev --optimize-autoloader
    sleep 5
else
    echo "==> PHP packages unchanged: skipping composer install."
fi

# Frontend assets are built locally and committed to public/build — this
# server has no npm/node available, and that's fine: git pull already
# brought the compiled CSS/JS. Don't try to build here.

echo "==> Running migrations..."
retry php artisan migrate --force

echo "==> Rebuilding caches..."
# Clear first, then build, a step at a time with a short pause between, so
# no one moment asks too much of the host. Config, routes, events and views
# are all cached: every request then skips reading them again.
retry php artisan optimize:clear
sleep 2
retry php artisan config:cache
sleep 2
retry php artisan route:cache
sleep 2
retry php artisan event:cache
sleep 2
retry php artisan view:cache

echo "==> Deploy complete. Now at:"
git log --oneline -1
