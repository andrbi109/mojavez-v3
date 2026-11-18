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

    /**
     * GET /admin/fee-tariff — list all tariffs (joined with service_type title)
     * plus the decile-based fee_groups + their fee_group_items.
     *
     * Phase 48 (v2.1.4): the index now also loads every fee_group with its
     * fee_group_items pre-grouped, so the view can render a per-group card
     * showing the items, their amounts and the sum total per group (which
     * is what `FeeCalculatorService` charges for an applicant whose income
     * decile falls in the group's range). The view also exposes an inline
     * "add item" form for each group that posts to itemsSave() (the
     * existing bulk-upsert handler accepts a single-key items map).
     *
     * The original fee_tariffs list (the per-service-type decile tariff
     * table that pre-dates fee_groups) is still rendered above the groups
     * section so the existing CRUD flows keep working.
     */
    public function index(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);
        $rows = $this->conn->all(
            'SELECT ft.*, st.title AS service_type_title
               FROM fee_tariffs ft
               LEFT JOIN service_types st ON st.id = ft.service_type_id
              ORDER BY ft.service_type_id, ft.decile_min, ft.id'
        );

        // Phase 48: load fee_groups + their items + the per-group sum.
        $groups = $this->conn->all(
            'SELECT g.*,
                    (SELECT COALESCE(SUM(amount), 0) FROM fee_group_items i WHERE i.fee_group_id = g.id) AS items_total
               FROM fee_groups g
              ORDER BY g.sort_order, g.id'
        );
        $items = $this->conn->all(
            'SELECT i.*
               FROM fee_group_items i
              ORDER BY i.fee_group_id, i.sort_order, i.id'
        );

        // Group items by fee_group_id so the view can iterate one group at a time.
        $itemsByGroup = [];
        foreach ($items as $it) {
            $gid = (int)$it['fee_group_id'];
            if (!isset($itemsByGroup[$gid])) {
                $itemsByGroup[$gid] = [];
            }
            $itemsByGroup[$gid][] = $it;
        }

        // Phase 54 (v2.2.0) — build the master list of services for the
        // "تعرفهٔ پایه" matrix section. The matrix shows every unique
        // item_key across all fee_groups, with one row per service and
        // a per-group checkbox + amount input for each of the 4 groups.
        //
        // Services are keyed by item_key (the admin's stable identifier
        // — "new_request", "reissue", etc.). For each service we collect:
        //   - the canonical item_label (the most recent non-empty label)
        //   - sort_order + notes (canonicalized similarly)
        //   - per-group state: enabled (bool) + amount (int) + row id
        //     for each fee_group_id (so the view can render a checkbox
        //     + an amount input, and the saveMatrix() handler can
        //     upsert/delete exactly the right rows).
        $services = $this->buildServicesMatrix($groups, $items);

        return $this->render('admin/fee_tariff/index', [
            'title'         => 'تعرفهٔ هزینه',
            'rows'          => $rows,
            'groups'        => $groups,
            'itemsByGroup'  => $itemsByGroup,
            'services'      => $services,
        ]);
    }

    /**
     * Phase 54 (v2.2.0) — build the master list of services with
     * per-group state, used by the "تعرفهٔ پایه" matrix section.
     *
     * Returns a list (preserving discovery order) of associative arrays:
     *
     *   [
     *     'item_key'   => 'new_request',
     *     'item_label' => 'ثبت درخواست',
     *     'sort_order' => 10,
     *     'notes'      => 'note text or empty string',
     *     'groups'     => [
     *       3 => ['enabled' => true,  'amount' => 50000, 'row_id' => 12],
     *       5 => ['enabled' => false, 'amount' => 0,     'row_id' => null],
     *       ...
     *     ],
     *   ]
     *
     * The view iterates `groups` (the fee_groups list, in sort_order) to
     * render one checkbox + amount input per group; the controller keys
     * by fee_group_id (int) so the view doesn't need to look up the id
     * again.
     *
     * @param array<int,array<string,mixed>> $groups  fee_groups rows
     * @param array<int,array<string,mixed>> $items   fee_group_items rows
     * @return array<int,array<string,mixed>>
     */
    private function buildServicesMatrix(array $groups, array $items): array
    {
        // Index group IDs in sort_order so the matrix renders groups
        // in the same order as the per-group cards below.
        $groupIds = [];
        foreach ($groups as $g) {
            $groupIds[] = (int)$g['id'];
        }

        $services = [];
        $index = [];  // item_key → index in $services
        foreach ($items as $it) {
            $key   = (string)$it['item_key'];
            $gid   = (int)$it['fee_group_id'];
            $label = (string)$it['item_label'];
            $sort  = (int)$it['sort_order'];
            $amt   = (int)$it['amount'];
            $rid   = (int)$it['id'];
            $nts   = (string)($it['notes'] ?? '');

            if (!isset($index[$key])) {
                $svc = [
                    'item_key'   => $key,
                    'item_label' => $label,
                    'sort_order' => $sort,
                    'notes'      => $nts,
                    'groups'     => [],
                ];
                foreach ($groupIds as $gid0) {
                    $svc['groups'][$gid0] = [
                        'enabled' => false,
                        'amount'  => 0,
                        'row_id'  => null,
                    ];
                }
                $services[] = $svc;
                $index[$key] = count($services) - 1;
            } else {
                // Merge canonical label/sort/notes — most recent non-empty wins.
                $svc = &$services[$index[$key]];
                if ($label !== '' && $svc['item_label'] === '') {
                    $svc['item_label'] = $label;
                }
                if ($sort > $svc['sort_order']) {
                    $svc['sort_order'] = $sort;
                }
                if ($nts !== '' && $svc['notes'] === '') {
                    $svc['notes'] = $nts;
                }
            }
            // Mark this service as enabled in this group + record amount + row id.
            $services[$index[$key]]['groups'][$gid] = [
                'enabled' => true,
                'amount'  => $amt,
                'row_id'  => $rid,
            ];
            unset($svc);
        }

        // Sort services by sort_order, then item_key for stability.
        usort($services, static function (array $a, array $b): int {
            if ($a['sort_order'] !== $b['sort_order']) {
                return $a['sort_order'] <=> $b['sort_order'];
            }
            return strcmp($a['item_key'], $b['item_key']);
        });

        return $services;
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
     * POST /admin/fee-tariff/services/matrix-save — bulk upsert + delete
     * of fee_group_items based on the per-service × per-group matrix in
     * the "تعرفهٔ پایه" section of /admin/fee-tariff.
     *
     * Phase 54 (v2.2.0) — the view renders a checkbox + amount input for
     * every (service, group) pair. This handler accepts the submitted
     * form and:
     *   - For each service × group where the checkbox is checked → upsert
     *     the fee_group_items row (insert if missing, update label /
     *     amount / sort / notes if existing).
     *   - For each service × group where the checkbox is unchecked AND
     *     a row exists → DELETE the row.
     *
     * Expected body shape (PHP parses nested form-encoded array keys
     * into a nested array):
     *
     *   services[<item_key>][label]      = "ثبت درخواست"
     *   services[<item_key>][sort]       = 10
     *   services[<item_key>][notes]      = "note text"
     *   services[<item_key>][groups][<gid>][enabled] = "1"  (present iff checked)
     *   services[<item_key>][groups][<gid>][amount]  = 50000
     *
     * Validation:
     *   - item_key matches /^[A-Za-z][A-Za-z0-9_]{0,49}$/ (same rule as
     *     the inline add-item form). Invalid keys are silently skipped.
     *   - amount is clamped to [0, PHP_INT_MAX].
     *   - label is trimmed; empty label is silently skipped (the row
     *     is left as-is to avoid wiping out an existing label).
     *
     * Auth: requireRole(['admin']).
     * Returns: 302 redirect to /admin/fee-tariff#services-matrix.
     */
    public function servicesMatrixSave(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);
        $body = $request->body();

        // Load the current set of fee_group_ids so we only ever touch
        // rows for groups that actually exist (defense in depth — the
        // view only renders checkboxes for real groups, but a malicious
        // POST could try to write into a non-existent group_id).
        $groupRows = $this->conn->all('SELECT id FROM fee_groups ORDER BY sort_order, id');
        $groupIds = array_map(static fn (array $r): int => (int)$r['id'], $groupRows);

        $services = $body['services'] ?? [];
        if (!is_array($services)) {
            $services = [];
        }

        foreach ($services as $rawKey => $data) {
            $key = trim((string)$rawKey);
            if ($key === '' || preg_match('/^[A-Za-z][A-Za-z0-9_]{0,49}$/', $key) !== 1) {
                continue;
            }
            $label = trim((string)($data['label'] ?? ''));
            $sort  = (int)($data['sort'] ?? 0);
            $notes = trim((string)($data['notes'] ?? ''));
            $groupStates = is_array($data['groups'] ?? null) ? $data['groups'] : [];

            foreach ($groupIds as $gid) {
                $state     = $groupStates[$gid] ?? [];
                $isEnabled = !empty($state['enabled']);
                $amount    = max(0, (int)($state['amount'] ?? 0));

                $existing = $this->conn->one(
                    'SELECT id FROM fee_group_items WHERE fee_group_id = ? AND item_key = ?',
                    [$gid, $key]
                );

                if (!$isEnabled) {
                    // Checkbox unchecked → delete the row if it exists.
                    if ($existing !== null) {
                        $this->conn->execute(
                            'DELETE FROM fee_group_items WHERE id = ?',
                            [(int)$existing['id']]
                        );
                    }
                    continue;
                }

                // Checkbox checked → upsert. Skip if label is empty (would
                // violate NOT NULL on item_label); leave the existing row
                // alone so the admin doesn't accidentally wipe a label.
                if ($label === '') {
                    continue;
                }

                if ($existing !== null) {
                    $this->conn->execute(
                        'UPDATE fee_group_items
                            SET item_label = ?, amount = ?, sort_order = ?, notes = ?
                          WHERE id = ?',
                        [$label, $amount, $sort, $notes !== '' ? $notes : null, (int)$existing['id']]
                    );
                } else {
                    $this->conn->execute(
                        'INSERT INTO fee_group_items
                            (fee_group_id, item_key, item_label, amount, sort_order, notes)
                         VALUES (?, ?, ?, ?, ?, ?)',
                        [$gid, $key, $label, $amount, $sort, $notes !== '' ? $notes : null]
                    );
                }
            }
        }
        return Response::redirect('/admin/fee-tariff#services-matrix');
    }

    /**
     * POST /admin/fee-tariff/services/add — add a NEW service to ALL
     * fee_groups at once (Phase 54 / v2.2.0 matrix requirement: "When a
     * new service is added, it should be auto-added to all 4 groups").
     *
     * The handler iterates every fee_group_id and inserts a
     * fee_group_items row with the same item_key, item_label, amount,
     * sort_order, notes for each group. If a row already exists for a
     * (group, key) pair (e.g. the admin clicked "add" twice by mistake,
     * or the service already existed in some groups), the existing row
     * is updated instead of duplicating.
     *
     * Validation:
     *   - item_key matches /^[A-Za-z][A-Za-z0-9_]{0,49}$/ (same as
     *     itemsSave()).
     *   - item_label is non-empty after trim (NOT NULL constraint).
     *   - amount clamped to [0, PHP_INT_MAX].
     * On validation failure: silent redirect (PRG), no error banner —
     * the admin sees the row didn't get added and tries again.
     *
     * Auth: requireRole(['admin']).
     * Returns: 302 redirect to /admin/fee-tariff#services-matrix.
     */
    public function serviceAddAll(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);
        $body = $request->body();
        $key   = trim((string)($body['item_key'] ?? ''));
        $label = trim((string)($body['item_label'] ?? ''));
        if ($key === '' || $label === '') {
            return Response::redirect('/admin/fee-tariff#services-matrix');
        }
        if (preg_match('/^[A-Za-z][A-Za-z0-9_]{0,49}$/', $key) !== 1) {
            return Response::redirect('/admin/fee-tariff#services-matrix');
        }
        $amount = max(0, (int)($body['amount'] ?? 0));
        $sort   = (int)($body['sort_order'] ?? 0);
        $notes  = trim((string)($body['notes'] ?? ''));

        $groupRows = $this->conn->all('SELECT id FROM fee_groups ORDER BY sort_order, id');
        foreach ($groupRows as $g) {
            $gid = (int)$g['id'];
            $existing = $this->conn->one(
                'SELECT id FROM fee_group_items WHERE fee_group_id = ? AND item_key = ?',
                [$gid, $key]
            );
            if ($existing !== null) {
                $this->conn->execute(
                    'UPDATE fee_group_items
                        SET item_label = ?, amount = ?, sort_order = ?, notes = ?
                      WHERE id = ?',
                    [$label, $amount, $sort, $notes !== '' ? $notes : null, (int)$existing['id']]
                );
            } else {
                $this->conn->execute(
                    'INSERT INTO fee_group_items
                        (fee_group_id, item_key, item_label, amount, sort_order, notes)
                     VALUES (?, ?, ?, ?, ?, ?)',
                    [$gid, $key, $label, $amount, $sort, $notes !== '' ? $notes : null]
                );
            }
        }
        return Response::redirect('/admin/fee-tariff#services-matrix');
    }

    /**
     * POST /admin/fee-tariff/services/delete — permanently delete a
     * service (every fee_group_items row with the given item_key,
     * across all fee_groups).
     *
     * Phase 54 (v2.2.0) — paired with serviceAddAll() so the admin can
     * both add and remove a service as a whole (rather than clicking
     * "delete" four times — once per group card).
     *
     * Defense:
     *   - item_key is validated with the same pattern as serviceAddAll()
     *     so a malicious POST can't do anything via SQL injection (the
     *     DELETE uses a bound placeholder, so injection is already
     *     impossible, but we also reject obviously bad keys).
     *   - The DELETE touches only fee_group_items (no FK to payments
     *     or other tables), so no cascade risk.
     *
     * Auth: requireRole(['admin']).
     * Returns: 302 redirect to /admin/fee-tariff#services-matrix.
     */
    public function serviceDeleteAll(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);
        $body = $request->body();
        $key = trim((string)($body['item_key'] ?? ''));
        if ($key === '' || preg_match('/^[A-Za-z][A-Za-z0-9_]{0,49}$/', $key) !== 1) {
            return Response::redirect('/admin/fee-tariff#services-matrix');
        }
        $this->conn->execute(
            'DELETE FROM fee_group_items WHERE item_key = ?',
            [$key]
        );
        return Response::redirect('/admin/fee-tariff#services-matrix');
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
