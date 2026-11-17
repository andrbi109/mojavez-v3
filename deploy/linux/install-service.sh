#!/usr/bin/env bash
# mojavez-v3 — systemd timer + service for the worker (poor man's cron → real cron).
# Per docs/00-MASTER.md §16.3 + §16.5.
#
# Installs two systemd units:
#   - mojavez-worker.service  (runs scripts/run_worker.php --max-jobs=5 every minute)
#   - mojavez-sync.service    (runs scripts/run_sync.php every hour)
# Plus timers that trigger them.
#
# Usage: sudo bash deploy/linux/install-service.sh

set -e

APP_DIR="${APP_DIR:-/opt/mojavez-v3}"
PHP_BIN="${PHP:-$(command -v php || echo /usr/bin/php)}"

echo "═══ mojavez-v3 systemd service installer ═══"

# Worker service (runs run_worker.php).
cat >/etc/systemd/system/mojavez-worker.service <<EOF
[Unit]
Description=mojavez-v3 job queue worker
After=network.target mariadb.service

[Service]
Type=oneshot
ExecStart=$PHP_BIN $APP_DIR/scripts/run_worker.php --max-jobs=5
User=www-data
Group=www-data
WorkingDirectory=$APP_DIR
EOF

# Worker timer (every minute).
cat >/etc/systemd/system/mojavez-worker.timer <<EOF
[Unit]
Description=Run mojavez-v3 worker every minute

[Timer]
OnBootSec=30
OnUnitActiveSec=60
Unit=mojavez-worker.service

[Install]
WantedBy=timers.target
EOF

# Sync service (runs run_sync.php).
cat >/etc/systemd/system/mojavez-sync.service <<EOF
[Unit]
Description=mojavez-v3 national-gateway sync cycle
After=network.target mariadb.service

[Service]
Type=oneshot
ExecStart=$PHP_BIN $APP_DIR/scripts/run_sync.php
User=www-data
Group=www-data
WorkingDirectory=$APP_DIR
EOF

# Sync timer (every hour).
cat >/etc/systemd/system/mojavez-sync.timer <<EOF
[Unit]
Description=Run mojavez-v3 sync every hour

[Timer]
OnBootSec=60
OnUnitActiveSec=3600
Unit=mojavez-sync.service

[Install]
WantedBy=timers.target
EOF

systemctl daemon-reload
systemctl enable --now mojavez-worker.timer mojavez-sync.timer

echo ""
echo "═══ Installed ═══"
echo "  Worker timer: every 60s → scripts/run_worker.php --max-jobs=5"
echo "  Sync timer:   every 3600s → scripts/run_sync.php"
echo ""
echo "  Check: systemctl list-timers mojavez-*"
echo "  Logs:  journalctl -u mojavez-worker -u mojavez-sync -f"
