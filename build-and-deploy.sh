#!/bin/bash
set -e

PLUGIN_DIR="$(cd "$(dirname "$0")" && pwd)"
ADMIN_DIR="$PLUGIN_DIR/src/Resources/app/administration"
CONTAINER="shop-sw6"
PLUGIN_DEST="/var/www/html/custom/plugins/Mond1SW6"

echo "==> Installing npm dependencies..."
cd "$ADMIN_DIR"
npm install --prefer-offline --no-audit

echo "==> Building admin JS..."
npm run build

echo "==> Syncing plugin PHP/config sources to Docker..."
docker cp "$PLUGIN_DIR/src/." "$CONTAINER:$PLUGIN_DEST/src/"
docker cp "$PLUGIN_DIR/composer.json" "$CONTAINER:$PLUGIN_DEST/composer.json"

echo "==> Syncing built admin assets to Docker..."
docker cp "$PLUGIN_DIR/src/Resources/public/administration/." \
    "$CONTAINER:$PLUGIN_DEST/src/Resources/public/administration/"

echo "==> Running assets:install in container..."
docker exec -u www-data "$CONTAINER" bash -c \
    "cd /var/www/html && php bin/console assets:install 2>&1"

echo "==> Clearing cache..."
docker exec -u www-data "$CONTAINER" bash -c \
    "cd /var/www/html && php bin/console cache:clear 2>&1"

echo ""
echo "✅ Done! Plugin built and deployed to $CONTAINER."