#!/bin/bash
# Run this on the hosting server, from the app root:
#   ./deploy.sh
set -e

echo "==> Pulling latest changes from origin/main..."
git pull origin main

echo "==> Installing PHP dependencies..."
composer install --no-dev --optimize-autoloader

# Frontend assets are built locally and committed to public/build — this
# server has no npm/node available, and that's fine: git pull already
# brought the compiled CSS/JS. Don't try to build here.

echo "==> Running migrations..."
php artisan migrate --force

echo "==> Rebuilding caches..."
# Clear first, then build. Previously this only cleared, which left the app
# parsing every config file and recompiling every Blade template on each
# request — a large part of the slow first-byte time on this shared host.
php artisan config:clear
php artisan view:clear
php artisan config:cache
php artisan view:cache

# NOTE: `php artisan route:cache` is deliberately not here. routes/web.php
# defines three routes as closures (/nuke-cache and the two /pos entries) and
# closures cannot be serialised, so route caching fails outright until those
# are moved into controllers. Worth doing — it is the next easy win on TTFB.

echo "==> Deploy complete. Now at:"
git log --oneline -1
