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
 * FormFieldController — admin CRUD for the `form_fields` table.
 *
 * Replaces the Phase-10 `AdminController::formFields` stub (D-10-1) with
 * full CRUD on the wizard-form-field definitions. Each row controls one
 * input on the public registration wizard: field_key + label + type +
 * options (JSON for select/radio/checkbox) + validation_rules (JSON) +
 * required/system/active flags + sort_order.
 *
 * Per docs/00-MASTER.md §11 — only role='admin' (superadmin) can manage
 * form fields. Other admin roles (operator, sync_operator) get 403.
 *
 * Operations (7 routes registered in Core/Module.php):
 *   - index():   GET  /admin/form-fields             list all fields (joined with service_type + wizard_step)
 *   - create():  GET  /admin/form-fields/new          show the edit form with an empty record
 *   - store():   POST /admin/form-fields              validate + insert
 *   - edit():    GET  /admin/form-fields/{id}/edit    show the edit form for an existing record
 *   - update():  POST /admin/form-fields/{id}         validate + update
 *   - destroy(): POST /admin/form-fields/{id}/delete  delete
 *   - move():    POST /admin/form-fields/move         reorder (swap sort_order with adjacent row)
 *
 * Phase 11 (v1.2.0): pays down D-7-1 + D-10-1 + D-2-2.
 */
final class FormFieldController extends Controller
{
    /** @var array<int,string> Allowed field_type values. */
    private const ALLOWED_FIELD_TYPES = [
        'text', 'textarea', 'select', 'radio', 'checkbox',
        'number', 'date', 'tel', 'file',
    ];

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
            'SELECT ff.*,
                    st.title AS service_type_title,
                    ws.title AS wizard_step_title
               FROM form_fields ff
               LEFT JOIN service_types st ON st.id = ff.service_type_id
               LEFT JOIN wizard_steps  ws ON ws.id = ff.wizard_step_id
              ORDER BY ff.sort_order, ff.id'
        );
        return $this->render('admin/form_fields/index', [
            'title' => 'فیلدهای فرم',
            'rows'  => $rows,
        ]);
    }

    public function create(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);
        $serviceTypes = $this->conn->all('SELECT id, title FROM service_types ORDER BY sort_order, id');
        $wizardSteps  = $this->conn->all('SELECT id, title FROM wizard_steps ORDER BY sort_order, id');
        return $this->render('admin/form_fields/edit', [
            'title'            => 'فیلد جدید',
            'field'            => $this->blankField(),
            'serviceTypes'     => $serviceTypes,
            'wizardSteps'      => $wizardSteps,
            'errors'           => [],
            'allowedTypes'     => self::ALLOWED_FIELD_TYPES,
        ]);
    }

    public function store(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);
        $body = $request->body();
        $errors = $this->validate($body, isNew: true);
        $field = $this->hydrateFromPost($body);

        if ($errors !== []) {
            $serviceTypes = $this->conn->all('SELECT id, title FROM service_types ORDER BY sort_order, id');
            $wizardSteps  = $this->conn->all('SELECT id, title FROM wizard_steps ORDER BY sort_order, id');
            return $this->render('admin/form_fields/edit', [
                'title'            => 'فیلد جدید',
                'field'            => $field,
                'serviceTypes'     => $serviceTypes,
                'wizardSteps'      => $wizardSteps,
                'errors'           => $errors,
                'allowedTypes'     => self::ALLOWED_FIELD_TYPES,
            ]);
        }

        $this->conn->execute(
            'INSERT INTO form_fields
                (wizard_step_id, service_type_id, field_key, field_label, field_type,
                 field_options, validation_rules, is_required, is_system, is_active, sort_order)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $field['wizard_step_id'] ?: null,
                $field['service_type_id'] ?: null,
                $field['field_key'],
                $field['field_label'],
                $field['field_type'],
                $field['field_options'] !== '' ? $field['field_options'] : null,
                $field['validation_rules'] !== '' ? $field['validation_rules'] : null,
                $field['is_required'] ? 1 : 0,
                $field['is_system'] ? 1 : 0,
                $field['is_active'] ? 1 : 0,
                (int)$field['sort_order'],
            ]
        );
        return Response::redirect('/admin/form-fields');
    }

    public function edit(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);
        $id = (int)($params['id'] ?? 0);
        $row = $this->conn->one('SELECT * FROM form_fields WHERE id = ?', [$id]);
        if ($row === null) {
            return Response::html('<!doctype html><meta charset="utf-8"><h1>۴۰۴</h1><p>فیلد یافت نشد.</p>', 404);
        }
        $serviceTypes = $this->conn->all('SELECT id, title FROM service_types ORDER BY sort_order, id');
        $wizardSteps  = $this->conn->all('SELECT id, title FROM wizard_steps ORDER BY sort_order, id');
        return $this->render('admin/form_fields/edit', [
            'title'        => 'ویرایش فیلد',
            'field'        => $row,
            'serviceTypes' => $serviceTypes,
            'wizardSteps'  => $wizardSteps,
            'errors'       => [],
            'allowedTypes' => self::ALLOWED_FIELD_TYPES,
        ]);
    }

    public function update(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);
        $id = (int)($params['id'] ?? 0);
        $existing = $this->conn->one('SELECT id FROM form_fields WHERE id = ?', [$id]);
        if ($existing === null) {
            return Response::html('<!doctype html><meta charset="utf-8"><h1>۴۰۴</h1><p>فیلد یافت نشد.</p>', 404);
        }

        $body = $request->body();
        $errors = $this->validate($body, isNew: false, currentId: $id);
        $field = $this->hydrateFromPost($body);

        if ($errors !== []) {
            $serviceTypes = $this->conn->all('SELECT id, title FROM service_types ORDER BY sort_order, id');
            $wizardSteps  = $this->conn->all('SELECT id, title FROM wizard_steps ORDER BY sort_order, id');
            $row = $this->hydrateFromPost($body) + ['id' => $id];
            return $this->render('admin/form_fields/edit', [
                'title'        => 'ویرایش فیلد',
                'field'        => $row,
                'serviceTypes' => $serviceTypes,
                'wizardSteps'  => $wizardSteps,
                'errors'       => $errors,
                'allowedTypes' => self::ALLOWED_FIELD_TYPES,
            ]);
        }

        $this->conn->execute(
            'UPDATE form_fields SET
                wizard_step_id = ?, service_type_id = ?, field_key = ?, field_label = ?,
                field_type = ?, field_options = ?, validation_rules = ?,
                is_required = ?, is_system = ?, is_active = ?, sort_order = ?
             WHERE id = ?',
            [
                $field['wizard_step_id'] ?: null,
                $field['service_type_id'] ?: null,
                $field['field_key'],
                $field['field_label'],
                $field['field_type'],
                $field['field_options'] !== '' ? $field['field_options'] : null,
                $field['validation_rules'] !== '' ? $field['validation_rules'] : null,
                $field['is_required'] ? 1 : 0,
                $field['is_system'] ? 1 : 0,
                $field['is_active'] ? 1 : 0,
                (int)$field['sort_order'],
                $id,
            ]
        );
        return Response::redirect('/admin/form-fields');
    }

    public function destroy(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);
        $id = (int)($params['id'] ?? 0);
        $existing = $this->conn->one('SELECT id FROM form_fields WHERE id = ?', [$id]);
        if ($existing === null) {
            return Response::html('<!doctype html><meta charset="utf-8"><h1>۴۰۴</h1><p>فیلد یافت نشد.</p>', 404);
        }
        $this->conn->execute('DELETE FROM form_fields WHERE id = ?', [$id]);
        return Response::redirect('/admin/form-fields');
    }

    /**
     * POST /admin/form-fields/move — reorder one field up or down.
     *
     * Body: id=<int>, direction=up|down
     * Swaps sort_order with the adjacent row in the requested direction,
     * then redirects to the index. Falls through silently if the field
     * is already at the boundary (no row to swap with).
     *
     * Phase 13 (v1.4.0): pays D-11-2. The neighbour-selection SQL is
     * deterministic under sort_order ties: when multiple rows share the
     * same `sort_order`, the tie-break is by `id` (ASC for "down", DESC
     * for "up"). The WHERE clause uses `sort_order < ? OR (sort_order = ?
     * AND id < ?)` (and the symmetrical `> ?` form for "down") so that
     * the swap target is unambiguous even when several rows have the
     * same sort_order. The `ORDER BY sort_order <dir>, id <dir> LIMIT 1`
     * then selects the unique nearest neighbour.
     *
     * Without this tie-break, two rows with the same sort_order would
     * produce an ambiguous neighbour selection — the swap could land
     * on either row, leading to non-deterministic ordering. This rule
     * is documented here (was implicit before Phase 13).
     */
    public function move(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);
        $body = $request->body();
        $id = (int)($body['id'] ?? 0);
        $direction = (string)($body['direction'] ?? '');

        $current = $this->conn->one('SELECT id, sort_order FROM form_fields WHERE id = ?', [$id]);
        if ($current === null) {
            return Response::html('<!doctype html><meta charset="utf-8"><h1>۴۰۴</h1><p>فیلد یافت نشد.</p>', 404);
        }

        $currentSort = (int)$current['sort_order'];
        if ($direction === 'up') {
            $neighbour = $this->conn->one(
                'SELECT id, sort_order FROM form_fields
                  WHERE sort_order < ? OR (sort_order = ? AND id < ?)
                  ORDER BY sort_order DESC, id DESC LIMIT 1',
                [$currentSort, $currentSort, $id]
            );
        } elseif ($direction === 'down') {
            $neighbour = $this->conn->one(
                'SELECT id, sort_order FROM form_fields
                  WHERE sort_order > ? OR (sort_order = ? AND id > ?)
                  ORDER BY sort_order ASC, id ASC LIMIT 1',
                [$currentSort, $currentSort, $id]
            );
        } else {
            $neighbour = null;
        }

        if ($neighbour !== null) {
            $this->conn->transactional(function () use ($current, $neighbour): void {
                $this->conn->execute(
                    'UPDATE form_fields SET sort_order = ? WHERE id = ?',
                    [(int)$neighbour['sort_order'], (int)$current['id']]
                );
                $this->conn->execute(
                    'UPDATE form_fields SET sort_order = ? WHERE id = ?',
                    [(int)$current['sort_order'], (int)$neighbour['id']]
                );
            });
        }
        return Response::redirect('/admin/form-fields');
    }

    /**
     * Build a blank field record for the create form.
     *
     * @return array<string,mixed>
     */
    private function blankField(): array
    {
        return [
            'id'               => null,
            'wizard_step_id'   => null,
            'service_type_id'  => null,
            'field_key'        => '',
            'field_label'      => '',
            'field_type'        => 'text',
            'field_options'    => '',
            'validation_rules' => '',
            'is_required'      => 0,
            'is_system'        => 0,
            'is_active'        => 1,
            'sort_order'       => 0,
        ];
    }

    /**
     * Pull submitted POST body into a normalized field array.
     *
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    private function hydrateFromPost(array $body): array
    {
        return [
            'id'               => isset($body['id']) ? (int)$body['id'] : null,
            'wizard_step_id'   => isset($body['wizard_step_id']) && $body['wizard_step_id'] !== '' ? (int)$body['wizard_step_id'] : null,
            'service_type_id'  => isset($body['service_type_id']) && $body['service_type_id'] !== '' ? (int)$body['service_type_id'] : null,
            'field_key'        => trim((string)($body['field_key'] ?? '')),
            'field_label'      => trim((string)($body['field_label'] ?? '')),
            'field_type'       => trim((string)($body['field_type'] ?? 'text')),
            'field_options'    => trim((string)($body['field_options'] ?? '')),
            'validation_rules' => trim((string)($body['validation_rules'] ?? '')),
            'is_required'      => !empty($body['is_required']) ? 1 : 0,
            'is_system'        => !empty($body['is_system']) ? 1 : 0,
            'is_active'        => !empty($body['is_active']) ? 1 : 0,
            'sort_order'       => (int)($body['sort_order'] ?? 0),
        ];
    }

    /**
     * Validate the submitted field. Returns a list of Persian error messages.
     *
     * @param array<string,mixed> $body
     * @return array<int,string>
     */
    private function validate(array $body, bool $isNew, ?int $currentId = null): array
    {
        $errors = [];
        $fieldKey = trim((string)($body['field_key'] ?? ''));
        $fieldLabel = trim((string)($body['field_label'] ?? ''));
        $fieldType = trim((string)($body['field_type'] ?? 'text'));

        if ($fieldKey === '') {
            $errors[] = 'کلید فیلد الزامی است.';
        } elseif (!preg_match('/^[a-z][a-z0-9_]{0,63}$/i', $fieldKey)) {
            $errors[] = 'کلید فیلد باید با حرف انگلیسی شروع شود و فقط شامل حرف، عدد و زیرخط باشد (حداکثر ۶۴ نویسه).';
        } else {
            // Uniqueness check on field_key.
            $existing = $this->conn->one('SELECT id FROM form_fields WHERE field_key = ?', [$fieldKey]);
            if ($isNew) {
                if ($existing !== null) {
                    $errors[] = 'این کلید فیلد قبلاً استفاده شده.';
                }
            } elseif ($existing !== null && (int)$existing['id'] !== (int)$currentId) {
                $errors[] = 'این کلید فیلد قبلاً استفاده شده.';
            }
        }

        if ($fieldLabel === '') {
            $errors[] = 'برچسب فیلد الزامی است.';
        } elseif (mb_strlen($fieldLabel) > 150) {
            $errors[] = 'برچسب فیلد حداکثر ۱۵۰ نویسه.';
        }

        if (!in_array($fieldType, self::ALLOWED_FIELD_TYPES, true)) {
            $errors[] = 'نوع فیلد نامعتبر است.';
        }

        $options = trim((string)($body['field_options'] ?? ''));
        if ($options !== '') {
            $decoded = json_decode($options, true);
            if (!is_array($decoded)) {
                $errors[] = 'گزینه‌ها باید JSON معتبر باشد (آرایه).';
            }
        }
        $rules = trim((string)($body['validation_rules'] ?? ''));
        if ($rules !== '') {
            $decoded = json_decode($rules, true);
            if (!is_array($decoded)) {
                $errors[] = 'قواعد اعتبارسنجی باید JSON معتبر باشد (آرایه).';
            }
        }

        return $errors;
    }
}
