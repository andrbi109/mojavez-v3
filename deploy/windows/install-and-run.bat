@echo off
REM mojavez-v3 — Windows install (XAMPP/Laragon).
REM Per docs/00-MASTER.md §16.4.
REM
REM Usage: deploy\windows\install-and-run.bat
REM Assumes XAMPP or Laragon is installed at C:\xampp or C:\laragon

setlocal enabledelayedexpansion

set "ROOT=%~dp0..\.."
cd /d "%ROOT%"

echo ============================================================
echo   mojavez-v3 Windows installer (XAMPP/Laragon)
echo ============================================================

REM Locate PHP.
set "PHP="
if exist "C:\xampp\php\php.exe" set "PHP=C:\xampp\php\php.exe"
if exist "C:\laragon\bin\php\php-8.2\php.exe" set "PHP=C:\laragon\bin\php\php-8.2\php.exe"
if "%PHP%"=="" (
    echo ERROR: PHP not found. Install XAMPP or Laragon, or set PHP= path.
    pause
    exit /b 1
)
echo Using PHP: %PHP%

REM Verify required extensions.
"%PHP%" -m | findstr /i "pdo_mysql mbstring fileinfo gd curl" >nul || (
    echo WARNING: not all required PHP extensions are loaded. Check php.ini.
)

REM Create writable dirs.
if not exist "storage\logs" mkdir "storage\logs"
if not exist "storage\cache" mkdir "storage\cache"
if not exist "storage\uploads" mkdir "storage\uploads"
if not exist "uploads" mkdir "uploads"

REM .env from example if missing.
if not exist ".env" (
    copy ".env.example" ".env" >nul
    echo Created .env from .env.example — edit it with your DB credentials.
)

REM Run migrations.
echo.
echo Running migrations...
"%PHP%" scripts\migrate.php --all
if errorlevel 1 (
    echo Migration failed. Check your .env DB_* settings and that MySQL is running.
    pause
    exit /b 1
)

echo.
echo ============================================================
echo   Install complete.
echo   Start the dev server with:
echo     %PHP% -S localhost:8000 -t public public\router.php
echo   Then open http://localhost:8000/
echo ============================================================
pause
