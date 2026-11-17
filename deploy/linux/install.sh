#!/usr/bin/env bash
# mojavez-v3 — Linux install script (Apache + MariaDB + PHP-FPM + systemd).
# Per docs/00-MASTER.md §16.3.
#
# Usage (as root or with sudo):
#   sudo bash deploy/linux/install.sh
#
# Idempotent: re-running skips already-done steps.

set -e

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
APP_DIR="/opt/mojavez-v3"
PHP_BIN="${PHP:-$(command -v php || echo php)}"

echo "═══ mojavez-v3 Linux installer ═══"

# 1. Install system packages.
if command -v apt-get >/dev/null 2>&1; then
    echo "[1/6] Installing apt packages..."
    apt-get update -qq
    apt-get install -y -qq apache2 mariadb-server php8.2 php8.2-mysql php8.2-mbstring \
        php8.2-gd php8.2-curl php8.2-zip php8.2-fpm
elif command -v dnf >/dev/null 2>&1; then
    echo "[1/6] Installing dnf packages..."
    dnf install -y httpd mariadb-server php php-mysqlnd php-mbstring php-gd php-curl php-zip php-fpm
else
    echo "ERROR: neither apt-get nor dnf found — install PHP 8.2, MariaDB, Apache manually."
    exit 1
fi

# 2. Copy app to /opt/mojavez-v3.
echo "[2/6] Copying app to $APP_DIR..."
mkdir -p "$APP_DIR"
rsync -a --delete --exclude storage/logs --exclude .env "$ROOT/" "$APP_DIR/"

# 3. Writable storage.
echo "[3/6] Setting permissions..."
mkdir -p "$APP_DIR/storage/logs" "$APP_DIR/storage/cache" "$APP_DIR/storage/uploads" "$APP_DIR/uploads"
chown -R www-data:www-data "$APP_DIR/storage" "$APP_DIR/uploads" 2>/dev/null \
    || chown -R apache:apache "$APP_DIR/storage" "$APP_DIR/uploads" 2>/dev/null \
    || true
chmod 0775 "$APP_DIR/storage" "$APP_DIR/uploads"

# 4. Apache vhost.
echo "[4/6] Installing Apache vhost..."
if [ -d /etc/apache2/sites-available ]; then
    cp "$ROOT/deploy/apache/mojavez.conf" /etc/apache2/sites-available/mojavez.conf
    a2enmod rewrite headers
    a2ensite mojavez
    a2dissite 000-default 2>/dev/null || true
    systemctl reload apache2
elif [ -d /etc/httpd/conf.d ]; then
    cp "$ROOT/deploy/apache/mojavez.conf" /etc/httpd/conf.d/mojavez.conf
    systemctl reload httpd
fi

# 5. Database (idempotent — only create if missing).
echo "[5/6] Database..."
if ! mysql -e "USE mojavez_v3" 2>/dev/null; then
    mysql <<'SQL'
CREATE DATABASE IF NOT EXISTS mojavez_v3 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'mojavez'@'localhost' IDENTIFIED BY 'mojavez_pass';
GRANT ALL PRIVILEGES ON mojavez_v3.* TO 'mojavez'@'localhost';
FLUSH PRIVILEGES;
SQL
    echo "  database created (mojavez/mojavez_pass). Change the password!"
else
    echo "  database mojavez_v3 already exists — skipping."
fi

# 6. Migrations.
echo "[6/6] Running migrations..."
cd "$APP_DIR"
"$PHP_BIN" scripts/migrate.php --all

echo ""
echo "═══ Install complete ═══"
echo "  App:        $APP_DIR"
echo "  Web root:   $APP_DIR/public"
echo "  Next steps: cp .env.example .env && edit secrets, then systemctl restart apache2"
echo "              visit /check.php to verify prerequisites"
