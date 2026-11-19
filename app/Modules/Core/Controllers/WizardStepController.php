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
use App\Shared\Support\Validator;

/**
 * WizardStepController — admin CRUD for the `wizard_steps` table.
 *
 * Phase 17 (v1.8.0): ported from v2's WizardStepController, adapted to
 * v3's modular architecture and the v3 `wizard_steps` schema (which uses
 * `view_key` VARCHAR + `icon` VARCHAR; v2 also had `step_number` and
 * `description` columns that v3 doesn't have — we adapt accordingly).
 *
 * Each row controls one registration-wizard step. System steps (view_key
 * step1..step4) are seeded by migration 004 and cannot be deleted (the
 * `is_system` flag is honoured on delete). Custom steps can be added,
 * toggled, re-ordered, and deleted.
 *
 * Per docs/00-MASTER.md §11 — only role='admin' (super-admin) can
 * manage wizard steps.
 *
 * Routes (5 — registered in Core/Module.php):
 *   GET  /admin/wizard-steps                  → index (list ordered by sort_order)
 *   POST /admin/wizard-steps/save             → save (insert or update by id)
 *   POST /admin/wizard-steps/toggle/{id}      → toggle is_active
 *   POST /admin/wizard-steps/delete           → delete (refuses system rows)
 *   POST /admin/wizard-steps/move             → reorder (swap sort_order with neighbour)
 */
final class WizardStepController extends Controller
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
     * GET /admin/wizard-steps — list all wizard steps joined with
     * service_types, ordered by service_type + sort_order, with field
     * counts per step (for the delete-protection hint).
     */
    public function index(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);
        $rows = $this->conn->all(
            'SELECT ws.*,
                    st.title AS service_type_title,
                    (SELECT COUNT(*) FROM form_fields ff WHERE ff.wizard_step_id = ws.id) AS field_count
               FROM wizard_steps ws
               LEFT JOIN service_types st ON st.id = ws.service_type_id
              ORDER BY ws.service_type_id, ws.sort_order, ws.id'
        );
        $serviceTypes = $this->conn->all('SELECT id, title FROM service_types ORDER BY sort_order, id');
        $editId = (int)$request->queryParam('edit', 0);
        $editing = $editId > 0 ? $this->conn->one('SELECT * FROM wizard_steps WHERE id = ?', [$editId]) : null;
        return $this->render('admin/wizard_steps', [
            'title'        => 'مراحل ویزارد',
            'rows'         => $rows,
            'serviceTypes' => $serviceTypes,
            'editing'      => $editing,
            'errors'       => [],
        ]);
    }

    /**
     * POST /admin/wizard-steps/save — insert or update a wizard step.
     *
     * Body: id (optional), service_type_id (optional), view_key (unique),
     * title, icon (optional), is_active, sort_order.
     */
    public function save(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);
        $body = $request->body();
        $id = (int)($body['id'] ?? 0);
        $title = Validator::nonEmptyString($body['title'] ?? '', 150);
        $viewKey = Validator::sanitizeText($body['view_key'] ?? '', 64);
        $icon = Validator::sanitizeText($body['icon'] ?? '', 50);
        $isActive = !empty($body['is_active']) ? 1 : 0;
        $sortOrder = (int)($body['sort_order'] ?? 0);
        $serviceTypeId = Validator::positiveIntOrNull($body['service_type_id'] ?? '');

        $errors = [];
        if ($title === null) {
            $errors[] = 'عنوان مرحله الزامی است.';
        }
        if ($viewKey === '' ) {
            $errors[] = 'کلید نمایش (view_key) الزامی است.';
        } elseif (!preg_match('/^[a-z][a-z0-9_]{0,63}$/i', $viewKey)) {
            $errors[] = 'کلید نمایش باید با حرف انگلیسی شروع شود و فقط شامل حرف، عدد و زیرخط باشد.';
        } else {
            $sql = 'SELECT id FROM wizard_steps WHERE view_key = ?';
            $args = [$viewKey];
            if ($id > 0) {
                $sql .= ' AND id <> ?';
                $args[] = $id;
            }
            $existing = $this->conn->one($sql, $args);
            if ($existing !== null) {
                $errors[] = 'این کلید نمایش قبلاً ثبت شده.';
            }
        }

        if ($errors !== []) {
            $rows = $this->conn->all(
                'SELECT ws.*, st.title AS service_type_title,
                        (SELECT COUNT(*) FROM form_fields ff WHERE ff.wizard_step_id = ws.id) AS field_count
                   FROM wizard_steps ws
                   LEFT JOIN service_types st ON st.id = ws.service_type_id
                  ORDER BY ws.service_type_id, ws.sort_order, ws.id'
            );
            $serviceTypes = $this->conn->all('SELECT id, title FROM service_types ORDER BY sort_order, id');
            return $this->render('admin/wizard_steps', [
                'title'        => 'مراحل ویزارد',
                'rows'         => $rows,
                'serviceTypes' => $serviceTypes,
                'editing'      => ['id' => $id, 'title' => $title ?? '', 'view_key' => $viewKey, 'icon' => $icon, 'is_active' => $isActive, 'sort_order' => $sortOrder, 'service_type_id' => $serviceTypeId],
                'errors'       => $errors,
            ]);
        }

        $isSystem = 0;
        if ($id > 0) {
            // Preserve is_system — never overwrite on update.
            $current = $this->conn->one('SELECT is_system FROM wizard_steps WHERE id = ?', [$id]);
            $isSystem = $current !== null ? (int)$current['is_system'] : 0;
            $this->conn->execute(
                'UPDATE wizard_steps
                    SET view_key = ?, title = ?, service_type_id = ?, icon = ?, is_active = ?, sort_order = ?
                  WHERE id = ?',
                [$viewKey, $title, $serviceTypeId, $icon !== '' ? $icon : null, $isActive, $sortOrder, $id]
            );
        } else {
            $this->conn->execute(
                'INSERT INTO wizard_steps (view_key, title, service_type_id, is_system, is_active, sort_order, icon)
                 VALUES (?, ?, ?, 0, ?, ?, ?)',
                [$viewKey, $title, $serviceTypeId, $isActive, $sortOrder, $icon !== '' ? $icon : null]
            );
        }
        $this->logger->info('Wizard step saved', [
            '_module' => 'core', 'id' => $id, 'view_key' => $viewKey,
        ]);
        return Response::redirect('/admin/wizard-steps');
    }

    /**
     * POST /admin/wizard-steps/toggle/{id} — flip the is_active flag.
     *
     * System steps can be toggled off (they disappear from the wizard)
     * but never deleted — they have hard-coded controllers + views.
     */
    public function toggle(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);
        $id = (int)($params['id'] ?? 0);
        $row = $this->conn->one('SELECT id, is_active, title FROM wizard_steps WHERE id = ?', [$id]);
        if ($row === null) {
            return Response::html('<!doctype html><meta charset="utf-8"><h1>۴۰۴</h1><p>مرحله یافت نشد.</p>', 404);
        }
        $next = (int)$row['is_active'] === 1 ? 0 : 1;
        $this->conn->execute('UPDATE wizard_steps SET is_active = ? WHERE id = ?', [$next, $id]);
        $this->logger->info('Wizard step toggled', [
            '_module' => 'core', 'id' => $id, 'is_active' => $next,
        ]);
        return Response::redirect('/admin/wizard-steps');
    }

    /**
     * POST /admin/wizard-steps/delete — delete a wizard step.
     *
     * Refuses to delete system steps (is_system = 1). Refuses to delete
     * a step that still has form_fields (the FK would SET NULL those
     * fields' wizard_step_id — they'd become orphaned). Returns a 400
     * page in either case; otherwise hard-deletes the row (form_fields
     * FK is ON DELETE SET NULL — safe).
     */
    public function delete(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);
        $id = (int)$request->input('id', 0);
        $row = $this->conn->one('SELECT id, is_system, title FROM wizard_steps WHERE id = ?', [$id]);
        if ($row === null) {
            return Response::html('<!doctype html><meta charset="utf-8"><h1>۴۰۴</h1><p>مرحله یافت نشد.</p>', 404);
        }
        if ((int)$row['is_system'] === 1) {
            return Response::html(
                '<!doctype html><meta charset="utf-8"><h1>۴۰۰</h1>'
                . '<p>مراحل سیستمی قابل حذف نیستند؛ در صورت نیاز غیرفعالشان کنید (toggle).</p>',
                400
            );
        }
        $fieldCount = (int)$this->conn->scalar(
            'SELECT COUNT(*) FROM form_fields WHERE wizard_step_id = ?',
            [$id]
        );
        if ($fieldCount > 0) {
            return Response::html(
                '<!doctype html><meta charset="utf-8"><h1>۴۰۰</h1>'
                . '<p>این مرحله هنوز ' . $fieldCount . ' فیلد دارد؛ ابتدا فیلدهایش را از «فیلدهای فرم» حذف کنید.</p>',
                400
            );
        }
        $this->conn->execute('DELETE FROM wizard_steps WHERE id = ?', [$id]);
        $this->logger->info('Wizard step deleted', [
            '_module' => 'core', 'id' => $id, 'title' => $row['title'],
        ]);
        return Response::redirect('/admin/wizard-steps');
    }

    /**
     * POST /admin/wizard-steps/move — reorder one step up or down.
     *
     * Body: id, direction ∈ {up, down}.
     * Swaps sort_order with the adjacent row in the requested direction,
     * scoped to the same service_type_id (NULL treated as a single group).
     * Falls through silently if the step is already at the boundary.
     */
    public function move(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);
        $body = $request->body();
        $id = (int)($body['id'] ?? 0);
        $direction = (string)($body['direction'] ?? '');

        $current = $this->conn->one('SELECT id, sort_order, service_type_id FROM wizard_steps WHERE id = ?', [$id]);
        if ($current === null) {
            return Response::html('<!doctype html><meta charset="utf-8"><h1>۴۰۴</h1><p>مرحله یافت نشد.</p>', 404);
        }
        $currentSort = (int)$current['sort_order'];
        $serviceTypeId = $current['service_type_id'];

        // Build the neighbour query — same service_type_id group, with
        // NULL-safe equality.
        $serviceFilter = $serviceTypeId === null
            ? 'service_type_id IS NULL'
            : 'service_type_id = ?';
        $args = $serviceTypeId === null ? [] : [$serviceTypeId];

        if ($direction === 'up') {
            $sql = "SELECT id, sort_order FROM wizard_steps
                    WHERE {$serviceFilter}
                      AND (sort_order < ? OR (sort_order = ? AND id < ?))
                    ORDER BY sort_order DESC, id DESC LIMIT 1";
            $neighbour = $this->conn->one($sql, array_merge($args, [$currentSort, $currentSort, $id]));
        } elseif ($direction === 'down') {
            $sql = "SELECT id, sort_order FROM wizard_steps
                    WHERE {$serviceFilter}
                      AND (sort_order > ? OR (sort_order = ? AND id > ?))
                    ORDER BY sort_order ASC, id ASC LIMIT 1";
            $neighbour = $this->conn->one($sql, array_merge($args, [$currentSort, $currentSort, $id]));
        } else {
            $neighbour = null;
        }

        if ($neighbour !== null) {
            $this->conn->transactional(function () use ($current, $neighbour): void {
                $this->conn->execute(
                    'UPDATE wizard_steps SET sort_order = ? WHERE id = ?',
                    [(int)$neighbour['sort_order'], (int)$current['id']]
                );
                $this->conn->execute(
                    'UPDATE wizard_steps SET sort_order = ? WHERE id = ?',
                    [(int)$current['sort_order'], (int)$neighbour['id']]
                );
            });
        }
        return Response::redirect('/admin/wizard-steps');
    }
}
