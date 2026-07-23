#!/bin/bash
# Run this on the hosting server, from the app root:
#   ./deploy.sh
set -e

echo "==> Pulling latest changes from origin/main..."
git pull origin main

echo "==> Installing dependencies..."
composer install --no-dev --optimize-autoloader

echo "==> Running migrations..."
php artisan migrate --force

echo "==> Clearing caches..."
php artisan view:clear
php artisan config:clear

echo "==> Deploy complete. Now at:"
git log --oneline -1
