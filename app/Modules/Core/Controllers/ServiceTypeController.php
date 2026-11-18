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
 * ServiceTypeController — admin CRUD for the `service_types` lookup table.
 *
 * Phase 17 (v1.8.0): ported from v2's ServiceTypeController, adapted to
 * v3's modular architecture (DI Container + Connection instead of
 * Database::getInstance()). Each row controls one registration service
 * category (صنایع دستی، مشاقل خانگی مستقل، ...) shown in the wizard's
 * service-selection step + on the public /services page.
 *
 * Per docs/00-MASTER.md §11 — only role='admin' (super-admin) can
 * manage service types. Operators get 403.
 *
 * Routes (3 — registered in Core/Module.php):
 *   GET  /admin/service-types          → index (list all)
 *   POST /admin/service-types/save     → save (insert or update by id)
 *   POST /admin/service-types/delete   → delete (with referential check)
 */
final class ServiceTypeController extends Controller
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
     * GET /admin/service-types — list all service types with their
     * applicant usage counts (for the delete-protection hint).
     *
     * Phase 48 (v2.1.4): the query now also returns three counts
     * per service type — license_title_count, form_field_count and
     * wizard_step_count — so the admin can see at a glance what each
     * service type has wired up. The view links each count to the
     * matching admin page (filtered by service_type_id where the
     * target controller supports it; otherwise to the index page).
     */
    public function index(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);
        $rows = $this->conn->all(
            'SELECT st.*,
                    (SELECT COUNT(*) FROM applicants a WHERE a.service_type_id = st.id) AS applicant_count,
                    (SELECT COUNT(*) FROM license_titles lt WHERE lt.service_type_id = st.id) AS license_title_count,
                    (SELECT COUNT(*) FROM form_fields ff WHERE ff.service_type_id = st.id) AS form_field_count,
                    (SELECT COUNT(*) FROM wizard_steps ws WHERE ws.service_type_id = st.id) AS wizard_step_count
               FROM service_types st
              ORDER BY st.sort_order, st.id'
        );
        $editId = (int)$request->queryParam('edit', 0);
        $editing = $editId > 0 ? $this->conn->one('SELECT * FROM service_types WHERE id = ?', [$editId]) : null;
        return $this->render('admin/service_types', [
            'title'   => 'انواع خدمت',
            'rows'    => $rows,
            'editing' => $editing,
            'errors'  => [],
        ]);
    }

    /**
     * POST /admin/service-types/save — insert or update a service type.
     *
     * Body: id (optional, for update), title, authority, description,
     * is_active (checkbox), sort_order (int).
     */
    public function save(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);
        $body = $request->body();
        $id = (int)($body['id'] ?? 0);
        $title = Validator::nonEmptyString($body['title'] ?? '', 120);
        $authority = Validator::sanitizeText($body['authority'] ?? '', 150);
        $description = Validator::sanitizeText($body['description'] ?? '', 1000);
        $isActive = !empty($body['is_active']) ? 1 : 0;
        $sortOrder = (int)($body['sort_order'] ?? 0);

        $errors = [];
        if ($title === null) {
            $errors[] = 'عنوان خدمت الزامی است (حداکثر ۱۲۰ نویسه).';
        } else {
            // Uniqueness check on title (case-insensitive), excluding
            // the row being updated.
            $sql = 'SELECT id FROM service_types WHERE title = ?';
            $args = [$title];
            if ($id > 0) {
                $sql .= ' AND id <> ?';
                $args[] = $id;
            }
            $existing = $this->conn->one($sql, $args);
            if ($existing !== null) {
                $errors[] = 'این عنوان خدمت قبلاً ثبت شده.';
            }
        }

        if ($errors !== []) {
            $rows = $this->conn->all(
                'SELECT st.*,
                        (SELECT COUNT(*) FROM applicants a WHERE a.service_type_id = st.id) AS applicant_count,
                        (SELECT COUNT(*) FROM license_titles lt WHERE lt.service_type_id = st.id) AS license_title_count,
                        (SELECT COUNT(*) FROM form_fields ff WHERE ff.service_type_id = st.id) AS form_field_count,
                        (SELECT COUNT(*) FROM wizard_steps ws WHERE ws.service_type_id = st.id) AS wizard_step_count
                   FROM service_types st
                  ORDER BY st.sort_order, st.id'
            );
            return $this->render('admin/service_types', [
                'title'   => 'انواع خدمت',
                'rows'    => $rows,
                'editing' => $id > 0 ? ['id' => $id, 'title' => $title, 'authority' => $authority, 'description' => $description, 'is_active' => $isActive, 'sort_order' => $sortOrder] : null,
                'errors'  => $errors,
            ]);
        }

        if ($id > 0) {
            $this->conn->execute(
                'UPDATE service_types SET title = ?, authority = ?, description = ?, is_active = ?, sort_order = ? WHERE id = ?',
                [$title, $authority !== '' ? $authority : null, $description !== '' ? $description : null, $isActive, $sortOrder, $id]
            );
        } else {
            $this->conn->execute(
                'INSERT INTO service_types (title, authority, description, is_active, sort_order) VALUES (?, ?, ?, ?, ?)',
                [$title, $authority !== '' ? $authority : null, $description !== '' ? $description : null, $isActive, $sortOrder]
            );
        }
        $this->logger->info('Service type saved', [
            '_module' => 'core', 'id' => $id, 'title' => $title,
        ]);
        return Response::redirect('/admin/service-types');
    }

    /**
     * POST /admin/service-types/delete — delete a service type.
     *
     * Refuses to delete if any applicant / form_field / wizard_step /
     * license_title / fee_tariff still references it (the FKs would
     * cascade — we want to surface the issue instead). Soft- falls back
     * to no-op with a logged warning.
     */
    public function delete(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);
        $id = (int)$request->input('id', 0);
        $existing = $this->conn->one('SELECT id, title FROM service_types WHERE id = ?', [$id]);
        if ($existing === null) {
            return Response::html('<!doctype html><meta charset="utf-8"><h1>۴۰۴</h1><p>نوع خدمت یافت نشد.</p>', 404);
        }
        $usage = (int)$this->conn->scalar(
            'SELECT (SELECT COUNT(*) FROM applicants WHERE service_type_id = ?)
                  + (SELECT COUNT(*) FROM form_fields WHERE service_type_id = ?)
                  + (SELECT COUNT(*) FROM wizard_steps WHERE service_type_id = ?)
                  + (SELECT COUNT(*) FROM license_titles WHERE service_type_id = ?)
                  + (SELECT COUNT(*) FROM fee_tariffs WHERE service_type_id = ?)',
            [$id, $id, $id, $id, $id]
        );
        if ($usage > 0) {
            $this->logger->warning('Service type delete refused — in use', [
                '_module' => 'core', 'id' => $id, 'usage' => $usage,
            ]);
            // Soft-deactivate instead of delete — keeps the FK rows intact.
            $this->conn->execute(
                'UPDATE service_types SET is_active = 0 WHERE id = ?',
                [$id]
            );
            return Response::redirect('/admin/service-types');
        }
        $this->conn->execute('DELETE FROM service_types WHERE id = ?', [$id]);
        $this->logger->info('Service type deleted', [
            '_module' => 'core', 'id' => $id, 'title' => $existing['title'],
        ]);
        return Response::redirect('/admin/service-types');
    }
}
