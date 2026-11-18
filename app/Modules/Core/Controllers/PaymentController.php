<?php
declare(strict_types=1);

namespace App\Modules\Core\Controllers;

use App\Container;
use App\Http\Csrf;
use App\Http\Request;
use App\Http\Response;
use App\Http\Session;
use App\Shared\Persistence\Connection;
use App\Shared\Support\Environment;
use App\Shared\Support\Hash;
use App\Shared\Support\PersianDate;
use App\Shared\Support\StructuredLogger;

/**
 * PaymentController — payment receipts review + payment channels CRUD.
 *
 * Phase 18 (v1.9.0): ported from v2's PaymentController, adapted to v3's
 * modular architecture (DI Container + Connection instead of v2's
 * Database::getInstance() + procedural models). The admin "financial"
 * section: list payments joined with applicants, approve/reject pending
 * receipts (writes a `payment_audit_log` row), CSV export (with BOM +
 * formula-injection protection), and management of `payment_channels`.
 *
 * Phase 49 (v2.1.5) — smarter payments page:
 *   - The summary stat cards at the top now show: total amount (sum of all
 *     payments), approved count + approved total, pending count, rejected
 *     count. Before, the cards only had approved-month-sum + approved-total-
 *     sum + pending-count + rejected-count (missing the count for approved
 *     and the grand-total amount).
 *   - Date-range filter (from / to) added next to the existing status +
 *     purpose + free-text filters. The from/to are validated as strict
 *     Y-m-d and applied as `p.created_at >= ?` / `p.created_at < ? + 1 day`
 *     so the `to` date is inclusive. The CSV export URL also includes the
 *     from/to so the export respects the same window.
 *
 * Per docs/00-MASTER.md §11 — only role='admin' (super-admin) can access
 * any of these actions. Operators get 403.
 *
 * Routes (5 — registered in Core/Module.php):
 *   GET  /admin/payments                  → index
 *   POST /admin/payments/review           → review
 *   GET  /admin/payments/csv              → csv
 *   POST /admin/payment-channels/save    → saveChannel
 *   POST /admin/payment-channels/delete  → deleteChannel
 *
 * Schema notes (the v3 `payment_channels` table has only `id, title,
 * channel_type, is_active, sort_order` — no `account_info` like v2).
 */
final class PaymentController extends Controller
{
    /** Statuses accepted by the review() decision (the form sends these). */
    private const REVIEW_DECISIONS = ['approved' => 'تأیید شده', 'rejected' => 'رد شده'];

    /** Pending-review status — only payments in this state may be reviewed. */
    private const STATUS_PENDING = 'در انتظار رسید';

    /**
     * Whitelisted payment status values accepted by the index()/csv()
     * `status` filter. Phase 50 (v2.1.6) added `'در انتظار'` — a new
     * status used by AdminController::requestPayment for rows it
     * creates on the admin's behalf (the user hasn't paid yet). The
     * previously-existing `'در انتظار رسید'` is a different status
     * (the user has uploaded a receipt and is awaiting admin review).
     *
     * @var array<int,string>
     */
    private const STATUS_FILTERS = ['', 'در انتظار', 'در انتظار رسید', 'تأیید شده', 'رد شده'];

    /**
     * Phase 50 — PersianDate helper, lazy-loaded from the Container on
     * first use (so the constructor signature doesn't change for existing
     * callers/tests). Used by parseDateRange() to convert Jalali
     * YYYY/MM/DD input strings to Gregorian Y-m-d for the DB query.
     */
    private ?PersianDate $persianDate = null;

    public function __construct(
        Container $container,
        Session $session,
        Csrf $csrf,
        Environment $environment,
        Hash $hash,
        StructuredLogger $logger,
        private readonly Connection $conn,
    ) {
        parent::__construct($container, $session, $csrf, $environment, $hash, $logger);
    }

    /**
     * GET /admin/payments — list payments joined with applicants
     * (paginated 20/page) + payment_channels sidebar + revenue stats.
     *
     * Phase 49 (v2.1.5) — added `from` / `to` query params for a date-range
     * filter on `payments.created_at`. Both are validated as strict Y-m-d
     * (a regex check before any DB call) and applied via bound parameters.
     * The filters are also forwarded to the CSV export link so the admin
     * can download exactly the window they're viewing.
     *
     * Phase 50 (v2.1.6) — the date inputs are now Jalali (YYYY/MM/DD);
     * parseDateRange() converts them to Gregorian internally. The
     * `filters` array passed to the view still contains the original
     * Jalali strings (so the inputs re-populate with what the admin
     * typed, not the Gregorian equivalent). The status filter now
     * also accepts 'در انتظار' (the new admin-requested status).
     */
    public function index(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);

        $page = max(1, (int)$request->queryParam('page', 1));
        $pageSize = 20;

        // Whitelisted filters — anything not in the allowed set becomes ''.
        // Phase 50 (v2.1.6) — the status whitelist now includes 'در انتظار'
        // (admin-requested, awaiting user payment) on top of the existing
        // 'در انتظار رسید' (awaiting admin receipt review).
        $status = (string)$request->queryParam('status', '');
        if (!in_array($status, self::STATUS_FILTERS, true)) {
            $status = '';
        }
        $purpose = (string)$request->queryParam('purpose', '');
        if (!in_array($purpose, ['', 'هزینهٔ ثبت‌نام', 'هزینهٔ دریافت مجدد تصویر مجوز'], true)) {
            $purpose = '';
        }
        $q = trim((string)$request->queryParam('q', ''));
        // Phase 50 (v2.1.6) — the from/to inputs in the admin/payments.php
        // view are now Jalali (YYYY/MM/DD); the controller converts each
        // to Gregorian via PersianDate::jalaliStringToGregorian before
        // applying the filter, so the DB query still uses bound Y-m-d.
        // Keep the original Jalali strings so the input values re-populate.
        $fromJalali = (string)$request->queryParam('from', '');
        $toJalali = (string)$request->queryParam('to', '');
        [$from, $to] = $this->parseDateRange($fromJalali, $toJalali);

        // Build the WHERE clause + params array. The LIMIT/OFFSET are
        // appended as the last two params so the WHERE args stay in
        // order (MySQL with ATTR_EMULATE_PREPARES=false accepts LIMIT ?
        // OFFSET ? as integer placeholders — no string-concat needed).
        [$where, $args] = $this->buildWhereClause($status, $purpose, $q, $from, $to);

        $total = (int)$this->conn->scalar(
            "SELECT COUNT(*) FROM payments p INNER JOIN applicants a ON a.id = p.applicant_id {$where}",
            $args
        );
        $totalPages = max(1, (int)ceil($total / $pageSize));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $pageSize;
        $limitArgs = array_merge($args, [$pageSize, $offset]);

        // Phase 50 (v2.1.6) — also fetch `p.description` (added by migration
        // 20261118_001) so the admin/payments.php view can show the note
        // the admin attached when creating the payment request.
        $payments = $this->conn->all(
            "SELECT p.id, p.applicant_id, a.tracking_code, a.first_name, a.last_name,
                    a.national_code, p.purpose, p.amount, p.reference_code, p.paid_at,
                    p.status, p.created_at, p.reviewed_at, p.reject_reason, p.description,
                    pc.title AS channel_title,
                    ru.display_name AS reviewer_name
               FROM payments p
               INNER JOIN applicants a ON a.id = p.applicant_id
               LEFT  JOIN payment_channels pc ON pc.id = p.payment_channel_id
               LEFT  JOIN users ru ON ru.id = p.reviewed_by
              {$where}
              ORDER BY p.created_at DESC, p.id DESC
              LIMIT ? OFFSET ?",
            $limitArgs
        );

        $stats = $this->computeStats();
        $byChannel = $this->conn->all(
            "SELECT COALESCE(pc.title, '—') AS channel, COUNT(*) AS cnt, COALESCE(SUM(p.amount), 0) AS total
               FROM payments p
               LEFT JOIN payment_channels pc ON pc.id = p.payment_channel_id
              WHERE p.status = 'تأیید شده'
              GROUP BY pc.title
              ORDER BY total DESC"
        );
        $channels = $this->conn->all(
            'SELECT id, title, channel_type, is_active, sort_order FROM payment_channels ORDER BY sort_order, id'
        );

        return $this->render('admin/payments', [
            'title'       => 'درآمد و پرداخت‌ها',
            'payments'     => $payments,
            'filters'      => [
                'status'  => $status,
                'purpose' => $purpose,
                'q'       => $q,
                // Phase 50 — pass the original Jalali strings to the view so
                // the date inputs re-populate with what the admin typed.
                'from'    => $fromJalali,
                'to'      => $toJalali,
            ],
            'page'         => $page,
            'totalPages'   => $totalPages,
            'total'        => $total,
            'stats'        => $stats,
            'byChannel'    => $byChannel,
            'channels'     => $channels,
            'message'      => $this->session->get('payment_message'),
        ]);
    }

    /**
     * POST /admin/payments/review — approve or reject a pending payment.
     *
     * Body: payment_id (int), decision (approved|rejected), note (string).
     * Writes a `payment_audit_log` row + updates `payments.status`. Returns
     * JSON (success or error) — the view redirects on success.
     */
    public function review(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);
        $body = $request->body();
        $id = (int)($body['payment_id'] ?? 0);
        $decision = (string)($body['decision'] ?? '');
        $note = trim((string)($body['note'] ?? ''));

        if (!array_key_exists($decision, self::REVIEW_DECISIONS)) {
            $this->jsonError(422, 'invalid_decision', 'تصمیم نامعتبر (approved/rejected).');
        }
        $newStatus = self::REVIEW_DECISIONS[$decision];

        $payment = $this->conn->one('SELECT id, status FROM payments WHERE id = ?', [$id]);
        if ($payment === null) {
            $this->jsonError(404, 'not_found', 'پرداخت یافت نشد.');
        }
        if ($payment['status'] !== self::STATUS_PENDING) {
            $this->jsonError(409, 'already_reviewed', 'این رسید پیش‌تر بررسی شده است.');
        }

        $adminId = (int)$this->session->get('admin_id', 0);
        $this->conn->transactional(function () use ($id, $newStatus, $adminId, $note): void {
            $this->conn->execute(
                'UPDATE payments SET status = ?, reviewed_by = ?, reviewed_at = NOW(),
                    reject_reason = ? WHERE id = ?',
                [$newStatus, $adminId > 0 ? $adminId : null, $newStatus === 'رد شده' && $note !== '' ? $note : null, $id]
            );
            $this->conn->insert(
                'INSERT INTO payment_audit_log (payment_id, user_id, action, field_changed, old_value, new_value, note)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
                [
                    $id,
                    $adminId,
                    $newStatus === 'تأیید شده' ? 'approve' : 'reject',
                    'status',
                    self::STATUS_PENDING,
                    $newStatus,
                    $note !== '' ? mb_substr($note, 0, 500) : null,
                ]
            );
        });

        $this->logger->info('Payment reviewed', [
            '_module'  => 'core',
            'payment_id' => $id,
            'decision'  => $decision,
            'admin_id'  => $adminId,
        ]);

        return $this->jsonSuccess(['payment_id' => $id, 'status' => $newStatus]);
    }

    /**
     * GET /admin/payments/csv — download all payments (with current
     * filters) as a CSV file. UTF-8 BOM + formula-injection protection.
     *
     * Phase 49 (v2.1.5) — also forwards the `from` / `to` date range so
     * the export matches the page filter window exactly.
     *
     * Phase 50 (v2.1.6) — the `from` / `to` params are now Jalali
     * (matching the admin/payments.php date inputs). parseDateRange()
     * converts them to Gregorian. The status whitelist now also accepts
     * 'در انتظار'. The CSV header includes a new "توضیحات" column for
     * the admin-entered description.
     */
    public function csv(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);

        $status = (string)$request->queryParam('status', '');
        if (!in_array($status, self::STATUS_FILTERS, true)) {
            $status = '';
        }
        $purpose = (string)$request->queryParam('purpose', '');
        if (!in_array($purpose, ['', 'هزینهٔ ثبت‌نام', 'هزینهٔ دریافت مجدد تصویر مجوز'], true)) {
            $purpose = '';
        }
        $q = trim((string)$request->queryParam('q', ''));
        [$from, $to] = $this->parseDateRange(
            (string)$request->queryParam('from', ''),
            (string)$request->queryParam('to', '')
        );
        [$where, $args] = $this->buildWhereClause($status, $purpose, $q, $from, $to);

        $rows = $this->conn->all(
            "SELECT p.id, a.tracking_code, a.first_name, a.last_name, a.national_code,
                    p.purpose, p.amount, pc.title AS channel_title, p.reference_code,
                    p.paid_at, p.status, ru.display_name AS reviewer_name, p.reviewed_at,
                    p.description, p.created_at
               FROM payments p
               INNER JOIN applicants a ON a.id = p.applicant_id
               LEFT  JOIN payment_channels pc ON pc.id = p.payment_channel_id
               LEFT  JOIN users ru ON ru.id = p.reviewed_by
              {$where}
              ORDER BY p.created_at DESC, p.id DESC",
            $args
        );

        $headers = ['شناسه', 'کد رهگیری', 'متقاضی', 'کد ملی', 'هدف پرداخت', 'مبلغ (تومان)',
            'کانال', 'کد پیگیری', 'تاریخ واریز', 'وضعیت', 'بررسی‌کننده', 'تاریخ بررسی',
            'تاریخ ثبت', 'توضیحات'];
        $out = [];
        foreach ($rows as $p) {
            $out[] = [
                (string)$p['id'],
                (string)$p['tracking_code'],
                (string)$p['first_name'] . ' ' . (string)$p['last_name'],
                (string)$p['national_code'],
                (string)$p['purpose'],
                (string)($p['amount'] ?? '0'),
                (string)($p['channel_title'] ?? '—'),
                (string)($p['reference_code'] ?? ''),
                (string)($p['paid_at'] ?? ''),
                (string)$p['status'],
                (string)($p['reviewer_name'] ?? ''),
                (string)($p['reviewed_at'] ?? ''),
                (string)($p['created_at'] ?? ''),
                (string)($p['description'] ?? ''),
            ];
        }
        $filename = 'payments-' . date('Ymd') . '.csv';
        return $this->csvResponse($filename, $headers, $out);
    }

    /**
     * POST /admin/payment-channels/save — insert or update a payment channel.
     *
     * Body: id (optional, for update), title, channel_type, sort_order, is_active.
     * The v3 `payment_channels` table has columns: id, title, channel_type,
     * is_active, sort_order.
     */
    public function saveChannel(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);
        $body = $request->body();
        $id = (int)($body['id'] ?? 0);
        $title = trim((string)($body['title'] ?? ''));
        $channelType = trim((string)($body['channel_type'] ?? ''));
        $sortOrder = (int)($body['sort_order'] ?? 100);
        $isActive = !empty($body['is_active']) ? 1 : 0;

        if ($title === '' || mb_strlen($title) > 100) {
            $this->session->set('payment_message', 'عنوان کانال الزامی است (حداکثر ۱۰۰ نویسه).');
            return $this->redirect('/admin/payments');
        }
        if ($channelType === '' || mb_strlen($channelType) > 50) {
            $this->session->set('payment_message', 'نوع کانال الزامی است (حداکثر ۵۰ نویسه).');
            return $this->redirect('/admin/payments');
        }

        if ($id > 0) {
            $this->conn->execute(
                'UPDATE payment_channels SET title = ?, channel_type = ?, is_active = ?, sort_order = ? WHERE id = ?',
                [$title, $channelType, $isActive, $sortOrder, $id]
            );
            $this->session->set('payment_message', 'کانال «' . $title . '» ویرایش شد.');
        } else {
            $this->conn->execute(
                'INSERT INTO payment_channels (title, channel_type, is_active, sort_order) VALUES (?, ?, ?, ?)',
                [$title, $channelType, $isActive, $sortOrder]
            );
            $this->session->set('payment_message', 'کانال «' . $title . '» افزوده شد.');
        }
        $this->logger->info('Payment channel saved', [
            '_module' => 'core', 'id' => $id, 'title' => $title,
        ]);
        return $this->redirect('/admin/payments');
    }

    /**
     * POST /admin/payment-channels/delete — delete a payment channel.
     *
     * If any payment still references the channel (payment_channel_id),
     * we refuse the hard-delete and instead just deactivate it (the FK
     * would SET NULL on delete, but losing the human-readable title is
     * worse than keeping an inactive row).
     */
    public function deleteChannel(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);
        $id = (int)$request->input('id', 0);
        $existing = $this->conn->one('SELECT id, title FROM payment_channels WHERE id = ?', [$id]);
        if ($existing === null) {
            $this->session->set('payment_message', 'کانال یافت نشد.');
            return $this->redirect('/admin/payments');
        }
        $usage = (int)$this->conn->scalar(
            'SELECT COUNT(*) FROM payments WHERE payment_channel_id = ?',
            [$id]
        );
        if ($usage > 0) {
            $this->conn->execute('UPDATE payment_channels SET is_active = 0 WHERE id = ?', [$id]);
            $this->session->set(
                'payment_message',
                'کانال به‌دلیل وجود تراکنش حذف نشد؛ غیرفعال گردید.'
            );
        } else {
            $this->conn->execute('DELETE FROM payment_channels WHERE id = ?', [$id]);
            $this->session->set('payment_message', 'کانال حذف شد.');
        }
        $this->logger->info('Payment channel delete', [
            '_module' => 'core', 'id' => $id, 'usage' => $usage,
        ]);
        return $this->redirect('/admin/payments');
    }

    // ----------------------- private helpers -----------------------

    /**
     * Build the WHERE clause + parameter array for the payments JOIN
     * query, based on the (already-whitelisted) filters.
     *
     * Phase 49 (v2.1.5) — $from / $to are added as `p.created_at >= ?`
     * and `p.created_at < ? + 1 day` respectively, so the `to` date is
     * inclusive of the entire day (a user picking 2026-12-31 expects
     * rows created at 23:59:59 to be included).
     *
     * @return array{0:string,1:array<int,mixed>}
     */
    private function buildWhereClause(string $status, string $purpose, string $q, string $from = '', string $to = ''): array
    {
        $clauses = [];
        $args = [];
        if ($status !== '') {
            $clauses[] = 'p.status = ?';
            $args[] = $status;
        }
        if ($purpose !== '') {
            $clauses[] = 'p.purpose = ?';
            $args[] = $purpose;
        }
        if ($from !== '') {
            $clauses[] = 'p.created_at >= ?';
            $args[] = $from . ' 00:00:00';
        }
        if ($to !== '') {
            $clauses[] = 'p.created_at < ?';
            // +1 day so the end date is inclusive of the full day.
            $args[] = date('Y-m-d', strtotime($to . ' +1 day')) . ' 00:00:00';
        }
        if ($q !== '') {
            $clauses[] = '(a.tracking_code LIKE ? OR a.national_code LIKE ? OR a.first_name LIKE ? OR a.last_name LIKE ? OR p.reference_code LIKE ?)';
            $like = '%' . $this->conn->escapeLike($q) . '%';
            array_push($args, $like, $like, $like, $like, $like);
        }
        $where = $clauses === [] ? '' : 'WHERE ' . implode(' AND ', $clauses);
        return [$where, $args];
    }

    /**
     * Validate + normalize the `from` / `to` date-range query params.
     *
     * Phase 49 (v2.1.5): both params were strict Gregorian `Y-m-d`.
     * Phase 50 (v2.1.6): the admin/payments.php view now uses Jalali
     * text inputs (`YYYY/MM/DD` with a pattern attribute + JS
     * validation), so this method now accepts Jalali strings and
     * converts them to Gregorian via PersianDate::jalaliStringToGregorian
     * before returning. Both are optional; if either is missing or
     * fails the strict `YYYY/MM/DD` regex + PersianDate round-trip, it
     * is treated as empty (no filter applied). Swapping is done if `to`
     * comes before `from` (lexicographic Y-m-d compare) so the admin
     * doesn't get an empty result by accident.
     *
     * @return array{0:string,1:string}  normalized [from, to] (Gregorian Y-m-d, or '' if unset)
     */
    private function parseDateRange(string $fromRaw, string $toRaw): array
    {
        $from = '';
        $to = '';
        // Strict Jalali YYYY/MM/DD regex (with 1-2 digit month/day so
        // users can type "1405/8/9" or "1405/08/09"). PersianDate then
        // validates the round-trip (rejects 1405/13/40 etc.).
        $jalaliPattern = '#^\d{4}/\d{1,2}/\d{1,2}$#';
        if (preg_match($jalaliPattern, $fromRaw) === 1) {
            $g = $this->persianDate()->jalaliStringToGregorian($fromRaw);
            if ($g !== null) {
                $from = $g;
            }
        }
        if (preg_match($jalaliPattern, $toRaw) === 1) {
            $g = $this->persianDate()->jalaliStringToGregorian($toRaw);
            if ($g !== null) {
                $to = $g;
            }
        }
        // Swap if `to` < `from` (lexicographic Y-m-d compare).
        if ($from !== '' && $to !== '' && $to < $from) {
            [$from, $to] = [$to, $from];
        }
        return [$from, $to];
    }

    /**
     * Lazy-resolved PersianDate helper (builds the instance from the
     * DI Container on first call, then memoizes — the Container always
     * returns the same instance for a stateless class).
     */
    private function persianDate(): PersianDate
    {
        if ($this->persianDate === null) {
            $this->persianDate = $this->container->make(PersianDate::class);
        }
        return $this->persianDate;
    }

    /**
     * Compute the summary stats shown at the top of the payments page.
     *
     * Phase 49 (v2.1.5) — added `totalCount` (all payments), `approvedCount`
     * (count of approved — the cards used to show only the *sum* of approved),
     * and `totalAmount` (sum of all amounts, regardless of status, so the
     * admin sees the gross flow through the system, not just the approved
     * slice). The 30-day approved sum is kept for backward compatibility.
     *
     * Phase 50 (v2.1.6) — added `requestedCount` + `requestedSum` for the
     * new `'در انتظار'` status (admin-requested, awaiting user payment).
     * The pendingCount/pendingSum (for `'در انتظار رسید'`) remain
     * unchanged since they're a distinct status.
     *
     * @return array{
     *   approvedMonth:int, approvedTotal:int, approvedCount:int,
     *   pendingCount:int, pendingSum:int,
     *   rejectedCount:int, rejectedSum:int,
     *   totalCount:int, totalAmount:int,
     *   requestedCount:int, requestedSum:int
     * }
     */
    private function computeStats(): array
    {
        return [
            'approvedMonth'  => (int)$this->conn->scalar(
                "SELECT COALESCE(SUM(amount), 0) FROM payments
                 WHERE status = 'تأیید شده' AND reviewed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
            ),
            'approvedTotal'  => (int)$this->conn->scalar(
                "SELECT COALESCE(SUM(amount), 0) FROM payments WHERE status = 'تأیید شده'"
            ),
            'approvedCount'  => (int)$this->conn->scalar(
                "SELECT COUNT(*) FROM payments WHERE status = 'تأیید شده'"
            ),
            'pendingCount'   => (int)$this->conn->scalar(
                "SELECT COUNT(*) FROM payments WHERE status = 'در انتظار رسید'"
            ),
            'pendingSum'     => (int)$this->conn->scalar(
                "SELECT COALESCE(SUM(amount), 0) FROM payments WHERE status = 'در انتظار رسید'"
            ),
            'rejectedCount'  => (int)$this->conn->scalar(
                "SELECT COUNT(*) FROM payments WHERE status = 'رد شده'"
            ),
            'rejectedSum'    => (int)$this->conn->scalar(
                "SELECT COALESCE(SUM(amount), 0) FROM payments WHERE status = 'رد شده'"
            ),
            'totalCount'     => (int)$this->conn->scalar(
                'SELECT COUNT(*) FROM payments'
            ),
            'totalAmount'    => (int)$this->conn->scalar(
                'SELECT COALESCE(SUM(amount), 0) FROM payments'
            ),
            'requestedCount' => (int)$this->conn->scalar(
                "SELECT COUNT(*) FROM payments WHERE status = 'در انتظار'"
            ),
            'requestedSum'   => (int)$this->conn->scalar(
                "SELECT COALESCE(SUM(amount), 0) FROM payments WHERE status = 'در انتظار'"
            ),
        ];
    }

    /**
     * Build a CSV Response with UTF-8 BOM + formula-injection protection.
     *
     * Cells starting with one of `=`, `+`, `-`, `@` are prefixed with a
     * single quote so Excel/LibreOffice don't interpret them as formulas.
     *
     * @param array<int,string> $headers
     * @param array<int,array<int,string>> $rows
     */
    private function csvResponse(string $filename, array $headers, array $rows): Response
    {
        $bom = "\xEF\xBB\xBF";  // UTF-8 BOM for Excel
        $lines = [];
        $lines[] = $this->csvLine($headers);
        foreach ($rows as $row) {
            $lines[] = $this->csvLine($row);
        }
        $body = $bom . implode("\r\n", $lines) . "\r\n";
        return (new Response($body, 200, [
            'Content-Type'        => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]));
    }

    /**
     * Convert one row (array of strings) to a CSV line, applying
     * formula-injection protection to every cell.
     *
     * @param array<int,string> $cells
     */
    private function csvLine(array $cells): string
    {
        $out = [];
        foreach ($cells as $cell) {
            $cell = (string)$cell;
            // Formula-injection protection: prefix =, +, -, @ with a
            // single quote so the spreadsheet doesn't evaluate the cell.
            if ($cell !== '' && in_array($cell[0], ['=', '+', '-', '@'], true)) {
                $cell = "'" . $cell;
            }
            // Enclose in quotes + escape embedded quotes by doubling.
            $out[] = '"' . str_replace('"', '""', $cell) . '"';
        }
        return implode(',', $out);
    }
}
