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
use App\Shared\Support\StructuredLogger;

/**
 * FeeTariffController — admin CRUD for the `fee_tariffs` table.
 *
 * Replaces the Phase-10 `AdminController::feeTariff` stub (D-10-1) with full
 * CRUD on the decile-based fee tariff. Each row maps (service_type_id,
 * decile_min..decile_max) → base_amount (in toman, integer). Optionally
 * scoped by valid_from / valid_until and notes.
 *
 * Per docs/00-MASTER.md §11 — only role='admin' (superadmin) can manage
 * fee tariffs. Other admin roles (operator, sync_operator) get 403.
 *
 * Operations (6 routes registered in Core/Module.php):
 *   - index():   GET  /admin/fee-tariff             list all tariffs (joined with service_type title)
 *   - create():  GET  /admin/fee-tariff/new          show the edit form with an empty record
 *   - store():   POST /admin/fee-tariff              validate + insert
 *   - edit():    GET  /admin/fee-tariff/{id}/edit    show the edit form for an existing record
 *   - update():  POST /admin/fee-tariff/{id}         validate + update
 *   - destroy(): POST /admin/fee-tariff/{id}/delete  delete
 *
 * Phase 11 (v1.2.0): pays down D-7-1 + D-10-1 + D-2-2.
 */
final class FeeTariffController extends Controller
{
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

    public function index(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);
        $rows = $this->conn->all(
            'SELECT ft.*, st.title AS service_type_title
               FROM fee_tariffs ft
               LEFT JOIN service_types st ON st.id = ft.service_type_id
              ORDER BY ft.service_type_id, ft.decile_min, ft.id'
        );
        return $this->render('admin/fee_tariff/index', [
            'title' => 'تعرفهٔ هزینه',
            'rows'  => $rows,
        ]);
    }

    public function create(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);
        $serviceTypes = $this->conn->all('SELECT id, title FROM service_types ORDER BY sort_order, id');
        return $this->render('admin/fee_tariff/edit', [
            'title'        => 'تعرفهٔ جدید',
            'tariff'       => $this->blankTariff(),
            'serviceTypes' => $serviceTypes,
            'errors'       => [],
        ]);
    }

    public function store(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);
        $body = $request->body();
        $tariff = $this->hydrateFromPost($body);
        $errors = $this->validate($tariff);

        if ($errors !== []) {
            $serviceTypes = $this->conn->all('SELECT id, title FROM service_types ORDER BY sort_order, id');
            return $this->render('admin/fee_tariff/edit', [
                'title'        => 'تعرفهٔ جدید',
                'tariff'       => $tariff,
                'serviceTypes' => $serviceTypes,
                'errors'       => $errors,
            ]);
        }

        $this->conn->execute(
            'INSERT INTO fee_tariffs
                (service_type_id, decile_min, decile_max, base_amount, is_active,
                 valid_from, valid_until, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $tariff['service_type_id'] ?: null,
                (int)$tariff['decile_min'],
                (int)$tariff['decile_max'],
                (int)$tariff['base_amount'],
                $tariff['is_active'] ? 1 : 0,
                $tariff['valid_from'] !== '' ? $tariff['valid_from'] : null,
                $tariff['valid_until'] !== '' ? $tariff['valid_until'] : null,
                $tariff['notes'] !== '' ? $tariff['notes'] : null,
            ]
        );
        return Response::redirect('/admin/fee-tariff');
    }

    public function edit(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);
        $id = (int)($params['id'] ?? 0);
        $row = $this->conn->one('SELECT * FROM fee_tariffs WHERE id = ?', [$id]);
        if ($row === null) {
            return Response::html('<!doctype html><meta charset="utf-8"><h1>۴۰۴</h1><p>تعرفه یافت نشد.</p>', 404);
        }
        $serviceTypes = $this->conn->all('SELECT id, title FROM service_types ORDER BY sort_order, id');
        return $this->render('admin/fee_tariff/edit', [
            'title'        => 'ویرایش تعرفه',
            'tariff'       => $row,
            'serviceTypes' => $serviceTypes,
            'errors'       => [],
        ]);
    }

    public function update(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);
        $id = (int)($params['id'] ?? 0);
        $existing = $this->conn->one('SELECT id FROM fee_tariffs WHERE id = ?', [$id]);
        if ($existing === null) {
            return Response::html('<!doctype html><meta charset="utf-8"><h1>۴۰۴</h1><p>تعرفه یافت نشد.</p>', 404);
        }

        $body = $request->body();
        $tariff = $this->hydrateFromPost($body);
        $errors = $this->validate($tariff);

        if ($errors !== []) {
            $serviceTypes = $this->conn->all('SELECT id, title FROM service_types ORDER BY sort_order, id');
            $tariff['id'] = $id;
            return $this->render('admin/fee_tariff/edit', [
                'title'        => 'ویرایش تعرفه',
                'tariff'       => $tariff,
                'serviceTypes' => $serviceTypes,
                'errors'       => $errors,
            ]);
        }

        $this->conn->execute(
            'UPDATE fee_tariffs SET
                service_type_id = ?, decile_min = ?, decile_max = ?, base_amount = ?,
                is_active = ?, valid_from = ?, valid_until = ?, notes = ?
             WHERE id = ?',
            [
                $tariff['service_type_id'] ?: null,
                (int)$tariff['decile_min'],
                (int)$tariff['decile_max'],
                (int)$tariff['base_amount'],
                $tariff['is_active'] ? 1 : 0,
                $tariff['valid_from'] !== '' ? $tariff['valid_from'] : null,
                $tariff['valid_until'] !== '' ? $tariff['valid_until'] : null,
                $tariff['notes'] !== '' ? $tariff['notes'] : null,
                $id,
            ]
        );
        return Response::redirect('/admin/fee-tariff');
    }

    public function destroy(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);
        $id = (int)($params['id'] ?? 0);
        $existing = $this->conn->one('SELECT id FROM fee_tariffs WHERE id = ?', [$id]);
        if ($existing === null) {
            return Response::html('<!doctype html><meta charset="utf-8"><h1>۴۰۴</h1><p>تعرفه یافت نشد.</p>', 404);
        }
        $this->conn->execute('DELETE FROM fee_tariffs WHERE id = ?', [$id]);
        return Response::redirect('/admin/fee-tariff');
    }

    /**
     * POST /admin/fee-tariff/settings — save the fee-tariff-related
     * global settings (active, valid-until, rural auto-eligible, modal
     * enabled, fallback group code, min amount percent, max discount
     * with/without reason).
     *
     * Phase 20 (v1.9.2) — ports v2's `FeeTariffController::saveSettings`.
     * The settings are stored in the `settings` table under `fee_*`
     * keys. They are read by `FeeCalculatorService` to decide the
     * active tariff group + the rural/auto-eligible branch.
     *
     * Auth: requireRole(['admin']) (superadmin only — per docs/00-MASTER.md §11).
     *
     * Returns: 302 redirect to /admin/fee-tariff (PRG pattern).
     */
    public function saveSettings(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);
        $body = $request->body();
        $set = [
            'fee_tariff_active'              => !empty($body['fee_tariff_active']) ? '1' : '0',
            'fee_tariff_valid_until'         => mb_substr(trim((string)($body['fee_tariff_valid_until'] ?? '')), 0, 20),
            'fee_rural_auto_eligible'         => !empty($body['fee_rural_auto_eligible']) ? '1' : '0',
            'fee_modal_enabled'              => !empty($body['fee_modal_enabled']) ? '1' : '0',
            'fee_fallback_group_code'        => mb_substr(trim((string)($body['fee_fallback_group_code'] ?? 'C')), 0, 8),
            'fee_min_amount_percent'         => (string)max(1, min(50, (int)($body['fee_min_amount_percent'] ?? 10))),
            'fee_max_discount_without_reason' => (string)max(0, min(12, (int)($body['fee_max_discount_without_reason'] ?? 2))),
            'fee_max_discount_with_reason'   => (string)max(10, min(100, (int)($body['fee_max_discount_with_reason'] ?? 50))),
        ];
        foreach ($set as $k => $v) {
            $this->upsertSetting($k, $v);
        }
        return Response::redirect('/admin/fee-tariff');
    }

    /**
     * POST /admin/fee-tariff/group/save — create or update a fee_group.
     *
     * Phase 20 (v1.9.2) — ports v2's `FeeTariffController::groupSave`.
     * Accepts: `id` (0 = insert), `code` (unique), `label` (Persian name),
     * `decile_min`, `decile_max`, `total_amount`, `is_active`, `sort_order`.
     * Backwards-compat: `name` is accepted as an alias for `label` (v2
     * used `name`; v3's `fee_groups` schema uses `label`).
     *
     * Schema: `fee_groups(id, code, label, decile_min, decile_max,
     * total_amount, is_active, sort_order)`.
     *
     * Auth: requireRole(['admin']).
     * Returns: 302 redirect to /admin/fee-tariff.
     */
    public function groupSave(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);
        $body = $request->body();
        $id = (int)($body['id'] ?? 0);
        $code = mb_substr(trim((string)($body['code'] ?? '')), 0, 8);
        $label = trim((string)($body['label'] ?? $body['name'] ?? ''));
        $decileMin = max(1, min(10, (int)($body['decile_min'] ?? 1)));
        $decileMax = max(1, min(10, (int)($body['decile_max'] ?? $decileMin)));
        $totalAmount = max(0, (int)($body['total_amount'] ?? 0));
        $isActive = !empty($body['is_active']) ? 1 : 0;
        $sortOrder = (int)($body['sort_order'] ?? 50);

        if ($code === '' || $label === '') {
            return Response::redirect('/admin/fee-tariff');
        }
        if ($decileMin > $decileMax) {
            return Response::redirect('/admin/fee-tariff');
        }

        if ($id > 0) {
            $existing = $this->conn->one('SELECT id FROM fee_groups WHERE id = ?', [$id]);
            if ($existing === null) {
                return Response::redirect('/admin/fee-tariff');
            }
            $this->conn->execute(
                'UPDATE fee_groups SET code = ?, label = ?, decile_min = ?, decile_max = ?,
                     total_amount = ?, is_active = ?, sort_order = ? WHERE id = ?',
                [$code, $label, $decileMin, $decileMax, $totalAmount, $isActive, $sortOrder, $id]
            );
        } else {
            $dupe = $this->conn->one('SELECT id FROM fee_groups WHERE code = ?', [$code]);
            if ($dupe !== null) {
                return Response::redirect('/admin/fee-tariff');
            }
            $this->conn->execute(
                'INSERT INTO fee_groups (code, label, decile_min, decile_max, total_amount, is_active, sort_order)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$code, $label, $decileMin, $decileMax, $totalAmount, $isActive, $sortOrder]
            );
        }
        return Response::redirect('/admin/fee-tariff');
    }

    /**
     * POST /admin/fee-tariff/group/{id}/delete — delete a fee_group.
     *
     * Phase 20 (v1.9.2) — ports v2's `FeeTariffController::groupDelete`.
     * Refuses to delete if any `payments.fee_group_id` references this
     * group (preserves audit history). On success, cascades to
     * `fee_group_items` via the FK ON DELETE CASCADE.
     *
     * Auth: requireRole(['admin']).
     * Returns: 302 redirect to /admin/fee-tariff.
     */
    public function groupDelete(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);
        $id = (int)($params['id'] ?? 0);
        $existing = $this->conn->one('SELECT id FROM fee_groups WHERE id = ?', [$id]);
        if ($existing === null) {
            return Response::redirect('/admin/fee-tariff');
        }
        $usage = $this->conn->scalar('SELECT COUNT(*) FROM payments WHERE fee_group_id = ?', [$id]);
        if ((int)$usage > 0) {
            return Response::redirect('/admin/fee-tariff');
        }
        $this->conn->execute('DELETE FROM fee_groups WHERE id = ?', [$id]);
        return Response::redirect('/admin/fee-tariff');
    }

    /**
     * POST /admin/fee-tariff/items/save — bulk-upsert fee_group_items
     * for one fee_group.
     *
     * Phase 20 (v1.9.2) — ports v2's `FeeTariffController::itemsSave`.
     * Accepts `group_id` + an `items` array of `{item_key: {label,
     * amount, sort, notes?}}`. Existing items with the same
     * `(fee_group_id, item_key)` are updated; new ones are inserted.
     * Items whose `label` is empty are skipped (defensive — lets the
     * admin submit a partial form with blank trailing rows).
     *
     * Schema: `fee_group_items(id, fee_group_id, item_key, item_label,
     * amount, sort_order, notes)` — UNIQUE(fee_group_id, item_key).
     *
     * Auth: requireRole(['admin']).
     * Returns: 302 redirect to /admin/fee-tariff.
     */
    public function itemsSave(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);
        $body = $request->body();
        $groupId = (int)($body['group_id'] ?? 0);
        $group = $this->conn->one('SELECT id FROM fee_groups WHERE id = ?', [$groupId]);
        if ($group === null) {
            return Response::redirect('/admin/fee-tariff');
        }
        $items = $body['items'] ?? [];
        if (!is_array($items)) {
            $items = [];
        }
        foreach ($items as $key => $data) {
            $key = (string)$key;
            $label = trim((string)($data['label'] ?? ''));
            $amount = max(0, (int)($data['amount'] ?? 0));
            $sort = (int)($data['sort'] ?? 0);
            $notes = trim((string)($data['notes'] ?? ''));
            if ($key === '' || $label === '') {
                continue;
            }
            $existing = $this->conn->one(
                'SELECT id FROM fee_group_items WHERE fee_group_id = ? AND item_key = ?',
                [$groupId, $key]
            );
            if ($existing !== null) {
                $this->conn->execute(
                    'UPDATE fee_group_items SET item_label = ?, amount = ?, sort_order = ?, notes = ?
                     WHERE id = ?',
                    [$label, $amount, $sort, $notes !== '' ? $notes : null, $existing['id']]
                );
            } else {
                $this->conn->execute(
                    'INSERT INTO fee_group_items (fee_group_id, item_key, item_label, amount, sort_order, notes)
                     VALUES (?, ?, ?, ?, ?, ?)',
                    [$groupId, $key, $label, $amount, $sort, $notes !== '' ? $notes : null]
                );
            }
        }
        return Response::redirect('/admin/fee-tariff#group-' . $groupId);
    }

    /**
     * POST /admin/payment/tick — toggle a `payment_service_ticks` row.
     *
     * Phase 20 (v1.9.2) — ports v2's `FeeTariffController::toggleTick`.
     * Accepts `tick_id` + `done` (1/0) + optional `reason`. Toggles
     * `is_done` on the row, stamps `ticked_by` + `ticked_at`, and
     * writes `done_reason` when undoing a tick.
     *
     * Schema: `payment_service_ticks(id, payment_id, item_key,
     * item_label, is_done, done_reason, ticked_by, ticked_at)` —
     * UNIQUE(payment_id, item_key).
     *
     * Auth: requireRole(['admin']). Returns JSON for AJAX callers.
     */
    public function toggleTick(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);
        $tickId = (int)$request->input('tick_id', 0);
        $done = $request->input('done', '1') === '1';
        $reason = mb_substr(trim((string)$request->input('reason', '')), 0, 255);

        if ($tickId <= 0) {
            return Response::json(['success' => false, 'error' => 'invalid_tick_id'], 422);
        }
        $tick = $this->conn->one('SELECT * FROM payment_service_ticks WHERE id = ?', [$tickId]);
        if ($tick === null) {
            return Response::json(['success' => false, 'error' => 'not_found'], 404);
        }
        $adminId = (int)($this->session->get('admin_id') ?? 0);
        $this->conn->execute(
            'UPDATE payment_service_ticks
             SET is_done = ?, done_reason = ?, ticked_by = ?, ticked_at = NOW()
             WHERE id = ?',
            [$done ? 1 : 0, (!$done && $reason !== '') ? $reason : null, $adminId > 0 ? $adminId : null, $tickId]
        );
        return Response::json([
            'success' => true,
            'tick_id' => $tickId,
            'is_done' => $done,
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function blankTariff(): array
    {
        return [
            'id'              => null,
            'service_type_id' => null,
            'decile_min'      => 1,
            'decile_max'      => 10,
            'base_amount'     => 0,
            'is_active'       => 1,
            'valid_from'      => '',
            'valid_until'     => '',
            'notes'           => '',
        ];
    }

    /**
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    private function hydrateFromPost(array $body): array
    {
        return [
            'id'              => isset($body['id']) ? (int)$body['id'] : null,
            'service_type_id' => isset($body['service_type_id']) && $body['service_type_id'] !== '' ? (int)$body['service_type_id'] : null,
            'decile_min'      => (int)($body['decile_min'] ?? 1),
            'decile_max'      => (int)($body['decile_max'] ?? 10),
            'base_amount'     => (int)($body['base_amount'] ?? 0),
            'is_active'       => !empty($body['is_active']) ? 1 : 0,
            'valid_from'      => trim((string)($body['valid_from'] ?? '')),
            'valid_until'     => trim((string)($body['valid_until'] ?? '')),
            'notes'           => trim((string)($body['notes'] ?? '')),
        ];
    }

    /**
     * @param array<string,mixed> $t
     * @return array<int,string>
     */
    private function validate(array $t): array
    {
        $errors = [];
        $min = (int)$t['decile_min'];
        $max = (int)$t['decile_max'];
        if ($min < 1 || $min > 10) {
            $errors[] = 'دهک حداقل باید بین ۱ و ۱۰ باشد.';
        }
        if ($max < 1 || $max > 10) {
            $errors[] = 'دهک حداکثر باید بین ۱ و ۱۰ باشد.';
        }
        if ($min > $max) {
            $errors[] = 'دهک حداقل نمی‌تواند بزرگتر از حداکثر باشد.';
        }
        if ((int)$t['base_amount'] < 0) {
            $errors[] = 'مبلغ پایه نمی‌تواند منفی باشد.';
        }
        $vf = $t['valid_from'];
        $vu = $t['valid_until'];
        if ($vf !== '' && $vu !== '' && $vf > $vu) {
            $errors[] = 'تاریخ شروع باید پیش از تاریخ پایان باشد.';
        }
        if (mb_strlen($t['notes']) > 500) {
            $errors[] = 'یادداشت حداکثر ۵۰۰ نویسه.';
        }
        return $errors;
    }

    /**
     * Upsert a single settings row (key → value). Same pattern as
     * SettingsController::upsertSetting — used by saveSettings() and
     * by the image-upload flow (not used here, but kept for symmetry).
     */
    private function upsertSetting(string $key, string $value): void
    {
        $existing = $this->conn->one('SELECT setting_key FROM settings WHERE setting_key = ?', [$key]);
        if ($existing !== null) {
            $this->conn->execute(
                'UPDATE settings SET setting_value = ? WHERE setting_key = ?',
                [$value, $key]
            );
        } else {
            $this->conn->execute(
                'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)',
                [$key, $value]
            );
        }
    }
}
