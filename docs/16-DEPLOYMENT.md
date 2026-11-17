# استقرار mojavez-v3 در محیط‌های مختلف

> این سند راهنماهای استقرار را بر اساس فایل‌های واقعی در `deploy/` تشریح می‌کند. مرجع رسمی `docs/00-MASTER.md §16` و `deploy/README.md` است.

---

## ۱. شش هدف استقرار

| # | محیط | دایرکتوری | ابزار | مناسب برای |
|---|---|---|---|---|
| ۱ | Docker | `deploy/docker/` | Dockerfile + docker-compose.yml + nginx-proxy.conf + entrypoint.sh | production کامل، CI |
| ۲ | Linux Apache | `deploy/apache/mojavez.conf` | Apache vhost + mod_rewrite | سرور لینوکس سنتی |
| ۳ | Linux Nginx | `deploy/nginx/mojavez.conf` | Nginx server block + PHP-FPM | سرور لینوکس مدرن |
| ۴ | Linux systemd | `deploy/linux/install.sh` + `install-service.sh` | apt/dnf + systemd timers | سرور اختصاصی با cron واقعی |
| ۵ | Windows XAMPP/Laragon | `deploy/windows/install-and-run.bat` | XAMPP control panel | توسعهٔ محلی روی ویندوز |
| ۶ | هاست اشتراکی | `deploy/shared-hosting/` | install.php + check.php + .htaccess | cPanel/DirectAdmin بدون shell |

---

## ۲. Docker (تولید)

در `deploy/docker/` سه سرویس در `docker-compose.yml`: (۱) `app` (PHP-FPM 8.2 با Dockerfile سفارشی)، (۲) `db` (MariaDB 10.11)، (۳) `web` (Nginx reverse proxy با `nginx-proxy.conf`). راه‌اندازی:

```
cd deploy/docker
cp .env.example .env
docker compose up -d --build
```

`Dockerfile` بر پایهٔ `php:8.2-fpm` با extension‌های `pdo_mysql`, `mbstring`, `fileinfo`, `gd`, `curl`, `zip` است. `entrypoint.sh` قبل از PHP-FPM اجرا می‌شود و migrationها را اعمال می‌کند (`php scripts/migrate.php --all`)، کاربر مدیر پیش‌فرض را می‌سازد، و اگر `seed_lookups.php` قابل اجرا باشد (geofence داخلی) آن را فراخوانی می‌کند. این همان الگویی است که CI در `.github/workflows/ci.yml` با MariaDB service container استفاده می‌کند.

---

## ۳. Apache vhost و Nginx server block

فایل `deploy/apache/mojavez.conf` یک vhost نمونه است با: `DocumentRoot` به `public/`، `Directory` با `AllowOverride All`، و قانون `mod_rewrite` که همهٔ درخواست‌های غیرفایلی را به `public/index.php` می‌فرستد. نصب:

```
sudo cp deploy/apache/mojavez.conf /etc/apache2/sites-available/
sudo a2ensite mojavez
sudo systemctl reload apache2
```

فایل `deploy/nginx/mojavez.conf` یک server block است با `root` به `public/`، `location /` با `try_files $uri $uri/ /index.php?$query_string`، و `location ~ \.php$` با `fastcgi_pass` به PHP-FPM socket. هر دو پیکربندی امنیت‌های اولیه را شامل می‌شوند: جلوگیری از دسترسی به `.env`، `storage/`, `vendor/`، و `app/`.

---

## ۴. Linux systemd timers

دو اسکریپت در `deploy/linux/`: `install.sh` وابستگی‌ها را نصب می‌کند (PHP 8.2، MariaDB، ext‌ها) و دیتابیس اولیه را می‌سازد. `install-service.sh` دو systemd timer می‌سازد:

```ini
# /etc/systemd/system/mojavez-worker.timer
[Timer]
OnBootSec=1min
OnUnitActiveSec=1min
Unit=mojavez-worker.service

# /etc/systemd/system/mojavez-worker.service
[Service]
Type=oneshot
ExecStart=/usr/bin/php /opt/mojavez-v3/scripts/run_worker.php --max-jobs=5
```

دوم timer برای `run_sync.php` هر ساعت. این برای سرورهای اختصاصی با cron واقعی است (به‌جای Poor Man's Cron که در `§13.3` توضیح داده شده). اگر cron در دسترس نباشد، بازدید مدیر از `/admin/sync/status` یک job از صف را drain می‌کند.

---

## ۵. Windows XAMPP/Laragon

فایل `deploy/windows/install-and-run.bat` به‌صورت خودکار: (۱) بررسی نصب XAMPP (یا راهنمای نصب)، (۲) کپی فایل‌ها به `C:\xampp\htdocs\mojavez-v3\`، (۳) راه‌اندازی Apache + MySQL از `xampp-control.exe`، (۴) اجرای `mysql` برای اعمال schema، (۵) باز کردن `http://localhost/mojavez-v3/`. این محیط برای توسعهٔ محلی روی ویندوز است و با PHP 8.2 (که XAMPP جدید دارد) کار می‌کند. نکتهٔ مهم: `Session.php` ذخیره‌گاه نشست را به `storage/sessions/` تنظیم می‌کند که مشکل مسیر موقت ویندوز (`C:\Windows\Temp`) را حل می‌کند (`§16.4`).

---

## ۶. هاست اشتراکی (install.php + check.php + .htaccess)

مسیر: `deploy/shared-hosting/`. شامل: `check.php` (بررسی نسخهٔ PHP، extension‌ها، قابلیت نوشتن در `storage/`، `uploads/`، دسترسی به MariaDB)، `install.php` (ویزارد تحت وب برای ساختن `.env` با secret‌های CSPRNG، اعمال migrationها، ساخت کاربر مدیر پیش‌فرض، و سپس self-delete)، `htaccess.txt` (کپی به `public/.htaccess` برای mod_rewrite)، `root-htaccess.htaccess` (کپی به `public_html/.htaccess` برای redirect به زیرمسیر).

مراحل طبق `deploy/README.md`:

۱. آپلود `mojavez-v3/` به `public_html/mojavez-v3/`.
۲. کپی `deploy/shared-hosting/htaccess.txt` → `public/mojavez-v3/public/.htaccess`.
۳. کپی `root-htaccess.htaccess` → `public_html/.htaccess`.
۴. کپی `check.php` و `install.php` → `public_html/mojavez-v3/public/`.
۵. بازدید `https://your-domain/mojavez-v3/check.php` و تأیید همهٔ چک‌ها.
۶. بازدید `install.php`، پر کردن فرم DB، کلیک install.
۷. `install.php` خودش را حذف می‌کند و `.env` با secret‌ها می‌سازد.
۸. ورود به `/admin/login` با credential‌های نمایش‌داده‌شده.

Cron jobs اختیاری در `deploy/README.md`:

```
* * * * * /usr/local/bin/php /home/USER/mojavez-v3/scripts/run_worker.php --max-jobs=5
0 * * * * /usr/local/bin/php /home/USER/mojavez-v3/scripts/run_sync.php
```

اگر cron در دسترس نباشد، Poor Man's Cron از بازدید مدیر کافی است (`§13.3`، `§16.5`). محدودیت geofence ایران برای scraping زنده را در `§16.8` ببینید.
