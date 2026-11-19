# mojavez-v3 — Deployment

Per `docs/00-MASTER.md §16` — choose your deployment target:

| Directory | Target | How |
|---|---|---|
| `docker/` | Docker (production) | `cd docker && ./generate-secrets.sh > .env && docker compose up -d --build` |
| `apache/` | Linux Apache vhost | `sudo cp apache/mojavez.conf /etc/apache2/sites-available/ && sudo a2ensite mojavez` |
| `nginx/` | Linux Nginx server block | `sudo cp nginx/mojavez.conf /etc/nginx/sites-available/` |
| `linux/` | Linux (apt/dnf install script + systemd timers) | `sudo bash linux/install.sh && sudo bash linux/install-service.sh` |
| `windows/` | Windows XAMPP/Laragon | `windows\install-and-run.bat` |
| `shared-hosting/` | cPanel/DirectAdmin/Nginx+Apache | upload files → visit `check.php` → visit `install.php` |

## Docker (production)

1. `cd deploy/docker`
2. Generate the `.env` with random secrets via the helper script:

   ```bash
   bash generate-secrets.sh > .env
   ```

   The script runs `openssl rand -hex 32` for each secret (`CRON_SECRET`,
   `SYNC_SECRET`, `GATEWAY_API_KEY`) and `openssl rand -hex 16` for the
   MariaDB root + app passwords, then prints a complete `.env` to stdout.
   Inspect the result before committing to a deploy (`./generate-secrets.sh | less`).

   If you don't have `openssl` available, copy `.env.example` to `.env`
   and fill in the secrets manually (`openssl rand -hex 32` per secret).

3. Review the generated `.env` — adjust `MOJAVEZ_PORT`, `ADMIN_USERNAME`,
   `APP_URL`, and the `SMS_*` keys if you have a Kavenegar-compatible
   SMS gateway.
4. `docker compose up -d --build`
5. `docker compose logs -f app` — watch the migration / admin-user creation.
6. Open `http://localhost:${MOJAVEZ_PORT:-8080}/` and log in with the
   `ADMIN_USERNAME` set in the `.env` and the admin password shown in
   the app service logs (the entrypoint prints it once on first boot).

## Shared hosting (most common)

1. Upload the entire `mojavez-v3/` directory to `public_html/mojavez-v3/`.
2. Copy `deploy/shared-hosting/htaccess.txt` → `public/mojavez-v3/public/.htaccess`.
3. Copy `deploy/shared-hosting/root-htaccess.htaccess` → `public_html/.htaccess` (rename to `.htaccess`).
4. Copy `deploy/shared-hosting/check.php` and `install.php` → `public_html/mojavez-v3/public/`.
5. Visit `https://your-domain/mojavez-v3/check.php` — verify all checks pass.
6. Visit `https://your-domain/mojavez-v3/install.php` — fill the DB form, click install.
7. The installer generates `.env` with secrets, runs migrations, creates the admin user, then **self-deletes** and writes a `storage/.installed` lock marker. The marker is the PRIMARY guard against re-install — even if the self-delete silently fails on a permission-restricted host, the marker file blocks the next run.
8. Log in at `https://your-domain/mojavez-v3/admin/login` with the credentials shown.

To re-install (e.g. after a manual DB wipe): delete `storage/.installed`
and `.env`, OR visit `install.php?force=1&CRON_SECRET=<secret>` where
`<secret>` is the `CRON_SECRET` from the existing `.env`.

## Cron jobs (if available)

```
* * * * * /usr/local/bin/php /home/USERNAME/mojavez-v3/scripts/run_worker.php --max-jobs=5 >> /home/USERNAME/mojavez-v3/storage/logs/worker.log 2>&1
0 * * * * /usr/local/bin/php /home/USERNAME/mojavez-v3/scripts/run_sync.php >> /home/USERNAME/mojavez-v3/storage/logs/sync.log 2>&1
```

If cron is NOT available: the poor man's cron (admin page visit triggers `run_worker.php`) keeps the queue draining — see `docs/00-MASTER.md §16.5`.

## Geofence note

`qr.mojavez.ir` (upstream national gateway) is **only reachable from inside Iran**. Deploy the app on a host inside Iran for live scraping. Outside Iran, the scraper returns `timeout` — use `seed_lookups.php --from-gateway` for local testing and the cached `national_licenses` for development.
