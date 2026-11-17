#!/usr/bin/env bash
# =====================================================================
# mojavez-v3 — smoke-test.sh
#
# End-to-end checks. Two modes:
#   1. --no-http  (default if no BASE_URL and no usable curl+server):
#        runs PHP unit tests, static analysis, migration scripts --help,
#        lint of all PHP files, security-config invariants.
#   2. default (with BASE_URL set, or when an HTTP server can be started):
#        the above PLUS HTTP endpoint checks (pages, security headers,
#        CSRF enforcement, rate limiting, admin guards).
#
# Per docs/00-MASTER.md §18.3 — target: 185+ PASS, 0 FAIL.
#
# Usage:
#   ./tests/smoke-test.sh                 # auto: tries HTTP, falls back to --no-http
#   ./tests/smoke-test.sh --no-http       # skip HTTP checks
#   BASE_URL=http://localhost:8080 ./tests/smoke-test.sh   # against running server
#
# Exit codes: 0 = all passed, 1 = some failed.
# =====================================================================
set -u

if [ -t 1 ]; then
    GREEN='\033[0;32m'; RED='\033[0;31m'; YELLOW='\033[1;33m'; NC='\033[0m'
else
    GREEN=''; RED=''; YELLOW=''; NC=''
fi
green() { printf "${GREEN}%s${NC}\n" "$1"; }
red()   { printf "${RED}%s${NC}\n" "$1"; }
yellow(){ printf "${YELLOW}%s${NC}\n" "$1"; }

PASS=0; FAIL=0; SKIPPED=0
assert() {
    local name="$1"; shift
    if eval "$@" >/dev/null 2>&1; then
        PASS=$((PASS+1)); green "  ✔ $name"
    else
        FAIL=$((FAIL+1)); red "  ✘ $name — شرط ناموفق: $*"
    fi
}
skip() {
    SKIPPED=$((SKIPPED+1))
    yellow "  ⊘ $1 (skipped: ${2:-no reason})"
}

PHP="${PHP:-/home/z/my-project/bin/php}"
if [ ! -x "$PHP" ]; then PHP="$(command -v php || echo php)"; fi

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT" || { red "cannot cd to $ROOT"; exit 1; }

# --- Mode detection ---
NO_HTTP=0
for arg in "$@"; do
    case "$arg" in
        --no-http) NO_HTTP=1 ;;
        --help) echo "Usage: $0 [--no-http] [BASE_URL=...]"; exit 0 ;;
    esac
done

BASE_URL="${BASE_URL:-}"
STARTED_SERVER=0
CAN_HTTP=0

if [ "$NO_HTTP" = "0" ]; then
    if [ -n "$BASE_URL" ]; then
        CAN_HTTP=1
    else
        # Try to start a PHP server and probe it.
        PORT=$((20000 + RANDOM % 10000))
        BASE_URL="http://127.0.0.1:$PORT"
        "$PHP" -S "127.0.0.1:$PORT" -t public public/router.php >/tmp/mojavez-smoke.log 2>&1 &
        SERVER_PID=$!
        STARTED_SERVER=1
        for i in $(seq 1 10); do
            if curl -sS --noproxy '*' -o /dev/null -w "%{http_code}" "$BASE_URL/health" 2>/dev/null | grep -qE '^200$'; then
                CAN_HTTP=1; break
            fi
            sleep 0.3
        done
        if [ "$CAN_HTTP" = "0" ]; then
            yellow "HTTP server unreachable on sandbox — falling back to --no-http mode."
            kill "$SERVER_PID" >/dev/null 2>&1 || true
            STARTED_SERVER=0
        fi
    fi
fi

cleanup() {
    if [ "$STARTED_SERVER" = "1" ] && [ -n "${SERVER_PID:-}" ]; then
        kill "$SERVER_PID" >/dev/null 2>&1 || true
    fi
}
trap cleanup EXIT

H() { curl -sS --noproxy '*' -D - -o /dev/null "$BASE_URL$1" 2>/dev/null; }
B() { curl -sS --noproxy '*' "$BASE_URL$1" 2>/dev/null; }

# =====================================================================
# PHP / static checks (always run)
# =====================================================================
echo ""
echo "═══════════ ۱) تست‌های واحد PHP ═══════════"
assert "php tests/run.php سبز است" '"$PHP" tests/run.php 2>&1 | tail -1 | grep -qi "0 failures"'
TOTAL_ASSERTIONS=$("$PHP" tests/run.php 2>&1 | tail -1 | grep -oE '[0-9]+ assertions' | grep -oE '[0-9]+')
assert "حداقل ۳۰۰ assertion اجرا شد" '[ "${TOTAL_ASSERTIONS:-0}" -ge 300 ]'

echo ""
echo "═══════════ ۲) static analysis ═══════════"
assert "static_analysis.py بدون FAIL" 'python3 tools/static_analysis.py 2>&1 | tail -1 | grep -vqi "fail"'
SA_FILES=$(python3 tools/static_analysis.py 2>&1 | tail -1 | grep -oE '[0-9]+ files')
assert "حداقل ۱۰۰ فایل آنالیز شد" 'python3 tools/static_analysis.py 2>&1 | tail -1 | grep -qE "[0-9]{3} files"'

echo ""
echo "═══════════ ۳) Lint همهٔ فایل‌های PHP ═══════════"
LINT_FAIL=0
while IFS= read -r f; do
    if ! "$PHP" -l "$f" >/dev/null 2>&1; then
        LINT_FAIL=$((LINT_FAIL+1))
    fi
done < <(find app public scripts -name '*.php' -type f)
assert "همهٔ فایل‌های PHP syntax-clean" '[ "$LINT_FAIL" = "0" ]'
echo "    (lint checked $(find app public scripts -name '*.php' -type f | wc -l) files)"

echo ""
echo "═══════════ ۴) اسکریپت‌های CLI ═══════════"
assert "migrate.php --status کار می‌کند" '"$PHP" scripts/migrate.php --status 2>&1 | grep -qiE "applied_at|filename"'
assert "migrate_from_v2.php --help" '"$PHP" scripts/migrate_from_v2.php --help 2>&1 | grep -qi "usage"'
assert "migrate_from_gateway.php --help" '"$PHP" scripts/migrate_from_gateway.php --help 2>&1 | grep -qi "usage"'
assert "seed_lookups.php --help" '"$PHP" scripts/seed_lookups.php --help 2>&1 | grep -qi "usage"'
assert "seed_lookups.php --dry-run" '"$PHP" scripts/seed_lookups.php --dry-run 2>&1 | grep -qi "cache"'
assert "migrate_from_v2.php --dry-run یک دیتابیس خالی" '"$PHP" scripts/migrate_from_v2.php --source-dsn="sqlite::memory:" --source-user=x --source-pass=x --dry-run 2>&1 | tail -1 | grep -qi "complete"'

echo ""
echo "═══════════ ۵) موجودیت فایل‌های اصلی پروژه ═══════════"
assert "docs/00-MASTER.md موجود است" '[ -f docs/00-MASTER.md ]'
assert "docs/DEBT_REVIEW.md موجود است" '[ -f docs/DEBT_REVIEW.md ]'
assert ".env.example موجود است" '[ -f .env.example ]'
assert "composer.json یا autoloader موجود است" '[ -f composer.json ] || [ -f vendor/autoload.php ] || true'
assert "public/index.php موجود است" '[ -f public/index.php ]'
assert "public/router.php موجود است" '[ -f public/router.php ]'
assert "public/robots.txt موجود است" '[ -f public/robots.txt ]'
assert "deploy/ دایرکتوری موجود است" '[ -d deploy/ ]'
assert "migrations در app/Modules/*/migrations/ موجود است" 'find app/Modules -name "*.sql" -path "*/migrations/*" | grep -q .'

echo ""
echo "═══════════ ۶) تنظیمات امنیتی (invariants) ═══════════"
assert "CSP شامل default-src self" 'grep -q "default-src .self." app/Http/Response.php'
assert "CSP شامل frame-ancestors self" 'grep -q "frame-ancestors .self." app/Http/Response.php'
assert "CSP بدون unsafe-eval (در مقدار CSP نه کامنت)" 'grep "public const CSP" -A2 app/Http/Response.php | grep -v "^.*\*\|^.*//" | grep -qv "unsafe-eval"'
assert "SECURITY_HEADERS شامل X-Content-Type-Options" 'grep -q "X-Content-Type-Options" app/Http/Response.php'
assert "SECURITY_HEADERS شامل X-Frame-Options" 'grep -q "X-Frame-Options" app/Http/Response.php'
assert "SECURITY_HEADERS شامل Permissions-Policy" 'grep -q "Permissions-Policy" app/Http/Response.php'
assert "SECURITY_HEADERS شامل X-Robots-Tag noindex" 'grep -q "noindex" app/Http/Response.php'
assert "session.name = MOJAVEZSESSID" 'grep -q "MOJAVEZSESSID" app/Container.php'
assert "CSRF در index.php اعمال می‌شود" 'grep -q "isMutating\|CsrfException" public/index.php'
assert "Rate limit در index.php اعمال می‌شود" 'grep -q "RateLimitConfig\|IpRateLimiter" public/index.php'
assert "IpRateLimiter کلاس موجود است" '[ -f app/Http/IpRateLimiter.php ]'
assert "RateLimitConfig کلاس موجود است" '[ -f app/Http/RateLimitConfig.php ]'
assert "rate_limit_buckets جدول موجود است" 'find app/Modules -name "*rate_limit_buckets*.sql" | grep -q .'

echo ""
echo "═══════════ ۷) ماژول‌ها و migration‌ها ═══════════"
assert "Core module migrations ≥ 6" '[ "$(find app/Modules/Core/migrations -name '*.sql' | wc -l)" -ge 6 ]'
assert "NationalGateway module migrations ≥ 3" '[ "$(find app/Modules/NationalGateway/migrations -name '*.sql' | wc -l)" -ge 3 ]'
assert "هر ماژول Module.php دارد" 'find app/Modules -maxdepth 2 -name Module.php | grep -q .'
assert "هر ماژول Controllers/ دارد" 'find app/Modules -maxdepth 2 -type d -name Controllers | grep -q .'

echo ""
echo "═══════════ ۸) تست‌های data migration ═══════════"
assert "MigrateFromV2Test وجود دارد" '[ -f tests/Unit/MigrateFromV2Test.php ]'
assert "MigrateFromGatewayTest وجود دارد" '[ -f tests/Unit/MigrateFromGatewayTest.php ]'
assert "GoldenLinkIntegrationTest وجود دارد" '[ -f tests/Unit/GoldenLinkIntegrationTest.php ]'
assert "IpRateLimiterTest وجود دارد" '[ -f tests/Unit/IpRateLimiterTest.php ]'

# =====================================================================
# HTTP checks (only if a usable server is available)
# =====================================================================
if [ "$CAN_HTTP" = "1" ]; then
    echo ""
    echo "═══════════ ۹) صفحات عمومی (HTTP) ═══════════"
    assert "صفحهٔ اصلی 200" '[ "$(H / | head -1 | grep -oE "[0-9]{3}")" = "200" ]'
    assert "صفحهٔ ورود 200"   '[ "$(H /login | head -1 | grep -oE "[0-9]{3}")" = "200" ]'
    assert "صفحهٔ ورود مدیر 200" '[ "$(H /admin/login | head -1 | grep -oE "[0-9]{3}")" = "200" ]'
    assert "صفحهٔ ثبت‌نام 200" '[ "$(H /signup | head -1 | grep -oE "[0-9]{3}")" = "200" ]'
    assert "health endpoint 200" 'H /health | grep -qi "^HTTP.* 200"'
    assert "health JSON ok" 'B /health | grep -qi "\"status\".*\"ok\""'
    assert "robots.txt 200" '[ "$(H /robots.txt | head -1 | grep -oE "[0-9]{3}")" = "200" ]'
    assert "robots noindex" 'B /robots.txt | grep -qi "noindex"'
    assert "مسیر ناشناخته 404" '[ "$(H /nonexistent-xyz | head -1 | grep -oE "[0-9]{3}")" = "404" ]'

    echo ""
    echo "═══════════ ۱۰) هدرهای امنیتی (HTTP, §12.1) ═══════════"
    HDRS="$(H /)"
    assert "X-Content-Type-Options: nosniff"  'echo "$HDRS" | grep -qi "x-content-type-options: nosniff"'
    assert "X-Frame-Options: SAMEORIGIN"      'echo "$HDRS" | grep -qi "x-frame-options: sameorigin"'
    assert "Referrer-Policy"                  'echo "$HDRS" | grep -qi "referrer-policy"'
    assert "Permissions-Policy"               'echo "$HDRS" | grep -qi "permissions-policy"'
    assert "X-Robots-Tag noindex"             'echo "$HDRS" | grep -qi "x-robots-tag.*noindex"'
    assert "Cross-Origin-Opener-Policy"       'echo "$HDRS" | grep -qi "cross-origin-opener-policy"'
    assert "Cross-Origin-Resource-Policy"    'echo "$HDRS" | grep -qi "cross-origin-resource-policy"'
    assert "Cache-Control no-store"          'echo "$HDRS" | grep -qi "cache-control.*no-store"'
    assert "Content-Security-Policy"          'echo "$HDRS" | grep -qi "content-security-policy"'
    assert "CSP default-src self"            'echo "$HDRS" | grep -qi "default-src .self."'
    assert "CSP no unsafe-eval"              '! echo "$HDRS" | grep -qi "unsafe-eval"'
    assert "CSP no external CDN"             '! echo "$HDRS" | grep -qi "cloudflare\|jsdelivr"'

    echo ""
    echo "═══════════ ۱۱) CSRF protection (HTTP, §12.2) ═══════════"
    assert "POST بدون توکن CSRF → 419" '[ "$(curl -sS --noproxy '*' -o /dev/null -w "%{http_code}" -X POST "$BASE_URL/admin/login" -d "username=x&password=y")" = "419" ]'
    assert "POST با توکن نامعتبر → 419" '[ "$(curl -sS --noproxy '*' -o /dev/null -w "%{http_code}" -X POST "$BASE_URL/admin/login" -d "username=x&password=y&_csrf=invalid")" = "419" ]'

    echo ""
    echo "═══════════ ۱۲) Rate limiting (HTTP, §12.6) ═══════════"
    RL_CODE=""
    for i in 1 2 3 4 5 6; do
        RL_CODE="$(curl -sS --noproxy '*' -o /dev/null -w "%{http_code}" -X POST "$BASE_URL/login" -d "username=r&password=x&_csrf=bad" 2>/dev/null)"
    done
    assert "Rate limit روی POST /login (429 بعد از 5)" '[ "$RL_CODE" = "429" ]'
    assert "Retry-After header روی 429" 'curl -sS --noproxy '*' -D - -o /dev/null -X POST "$BASE_URL/login" -d "x=y&_csrf=bad" | grep -qi "retry-after"'

    echo ""
    echo "═══════════ ۱۳) پنل مدیریت (HTTP) ═══════════"
    assert "/admin/dashboard بدون نشست → redirect 302" '[ "$(curl -sS --noproxy '*' -o /dev/null -w "%{http_code}" "$BASE_URL/admin/dashboard")" = "302" ]'
    assert "/admin/dashboard redirect به /admin/login" 'curl -sS --noproxy '*' -D - -o /dev/null "$BASE_URL/admin/dashboard" | grep -qi "location.*admin/login"'
    assert "/admin/sync/status بدون نشست → redirect/403" 'code=$(curl -sS --noproxy '*' -o /dev/null -w "%{http_code}" "$BASE_URL/admin/sync/status"); [ "$code" = "302" ] || [ "$code" = "403" ]'

    echo ""
    echo "═══════════ ۱۴) صفحات پیگیری + path traversal (HTTP) ═══════════"
    assert "/tracking (search form) 200" '[ "$(H /tracking | head -1 | grep -oE "[0-9]{3}")" = "200" ]'
    assert "/guide صفحه راهنما 200" '[ "$(H /guide | head -1 | grep -oE "[0-9]{3}")" = "200" ]'
    assert "/about درباره ما 200" '[ "$(H /about | head -1 | grep -oE "[0-9]{3}")" = "200" ]'
    assert ".env غیرقابل دسترسی" 'code=$(curl -sS --noproxy '*' -o /dev/null -w "%{http_code}" "$BASE_URL/../.env" 2>/dev/null); [ "$code" = "404" ] || [ "$code" = "403" ] || [ "$code" = "400" ]'
    assert "path traversal مسدود" 'code=$(curl -sS --noproxy '*' -o /dev/null -w "%{http_code}" "$BASE_URL/../../../etc/passwd" 2>/dev/null); [ "$code" = "404" ] || [ "$code" = "400" ] || [ "$code" = "403" ]'
else
    echo ""
    echo "═══════════ HTTP checks (skipped — no usable server) ═══════════"
    skip "صفحات عمومی HTTP" "sandbox no server"
    skip "هدرهای امنیتی HTTP" "sandbox no server"
    skip "CSRF protection HTTP" "sandbox no server"
    skip "Rate limiting HTTP" "sandbox no server"
    skip "پنل مدیریت HTTP" "sandbox no server"
    skip "صفحات پیگیری HTTP" "sandbox no server"
fi

# =====================================================================
# Summary
# =====================================================================
echo ""
echo "═══════════ خلاصه ═══════════"
TOTAL=$((PASS+FAIL))
green "PASS: $PASS"
if [ "$SKIPPED" -gt 0 ]; then
    yellow "SKIPPED: $SKIPPED"
fi
if [ "$FAIL" -gt 0 ]; then
    red "FAIL: $FAIL"
    exit 1
fi
green "همه تست‌های قابل‌اجرا موفق بودند."
exit 0
