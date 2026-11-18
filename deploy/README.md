# mojavez-v3 — Deployment

Per `docs/00-MASTER.md §16` — choose your deployment target:

| Directory | Target | How |
|---|---|---|
| `docker/` | Docker (production) | `cd docker && cp .env.example .env && docker compose up -d --build` |
| `apache/` | Linux Apache vhost | `sudo cp apache/mojavez.conf /etc/apache2/sites-available/ && sudo a2ensite mojavez` |
| `nginx/` | Linux Nginx server block | `sudo cp nginx/mojavez.conf /etc/nginx/sites-available/` |
| `linux/` | Linux (apt/dnf install script + systemd timers) | `sudo bash linux/install.sh && sudo bash linux/install-service.sh` |
| `windows/` | Windows XAMPP/Laragon | `windows\install-and-run.bat` |
| `shared-hosting/` | cPanel/DirectAdmin/Nginx+Apache | upload files → visit `check.php` → visit `install.php` |

## Shared hosting (most common)

1. Upload the entire `mojavez-v3/` directory to `public_html/mojavez-v3/`.
2. Copy `deploy/shared-hosting/htaccess.txt` → `public/mojavez-v3/public/.htaccess`.
3. Copy `deploy/shared-hosting/root-htaccess.htaccess` → `public_html/.htaccess` (rename to `.htaccess`).
4. Copy `deploy/shared-hosting/check.php` and `install.php` → `public_html/mojavez-v3/public/`.
5. Visit `https://your-domain/mojavez-v3/check.php` — verify all checks pass.
6. Visit `https://your-domain/mojavez-v3/install.php` — fill the DB form, click install.
7. The installer generates `.env` with secrets, runs migrations, creates the admin user, then **self-deletes**.
8. Log in at `https://your-domain/mojavez-v3/admin/login` with the credentials shown.

## Cron jobs (if available)

```
* * * * * /usr/local/bin/php /home/USERNAME/mojavez-v3/scripts/run_worker.php --max-jobs=5 >> /home/USERNAME/mojavez-v3/storage/logs/worker.log 2>&1
0 * * * * /usr/local/bin/php /home/USERNAME/mojavez-v3/scripts/run_sync.php >> /home/USERNAME/mojavez-v3/storage/logs/sync.log 2>&1
```

If cron is NOT available: the poor man's cron (admin page visit triggers `run_worker.php`) keeps the queue draining — see `docs/00-MASTER.md §16.5`.

## Geofence note

`qr.mojavez.ir` (upstream national gateway) is **only reachable from inside Iran**. Deploy the app on a host inside Iran for live scraping. Outside Iran, the scraper returns `timeout` — use `seed_lookups.php --from-gateway` for local testing and the cached `national_licenses` for development.
