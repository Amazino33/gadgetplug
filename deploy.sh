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

echo "==> Clearing caches..."
php artisan view:clear
php artisan config:clear

echo "==> Deploy complete. Now at:"
git log --oneline -1
