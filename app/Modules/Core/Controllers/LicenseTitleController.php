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
 * LicenseTitleController — admin CRUD for the `license_titles` table.
 *
 * Replaces the Phase-10 `AdminController::licenseTitles` stub (D-10-1) with
 * full CRUD on the per-service-type license titles. Each row maps a
 * (service_type_id, title) pair to a category + is_active flag.
 *
 * The `byService` action returns a JSON list filtered by service_type_id —
 * used by the registration wizard's job-detail step to populate the
 * license-title dropdown.
 *
 * Per docs/00-MASTER.md §11 — only role='admin' (superadmin) can manage
 * license titles. The byService endpoint is admin-only too (the public
 * wizard reads via the public service_type_id query in a separate route).
 *
 * Operations (8 routes registered in Core/Module.php):
 *   - index():    GET  /admin/license-titles                    list all titles (joined with service_type)
 *   - create():   GET  /admin/license-titles/new                 show the edit form with an empty record
 *   - store():    POST /admin/license-titles                    validate + insert
 *   - edit():     GET  /admin/license-titles/{id}/edit          show the edit form for an existing record
 *   - update():   POST /admin/license-titles/{id}               validate + update
 *   - destroy():  POST /admin/license-titles/{id}/delete        delete
 *   - toggle():   POST /admin/license-titles/{id}/toggle        flip is_active
 *   - byService():GET  /admin/license-titles/by-service/{id}    JSON list filtered by service_type_id
 *
 * Phase 11 (v1.2.0): pays down D-7-1 + D-10-1 + D-2-2.
 */
final class LicenseTitleController extends Controller
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
            'SELECT lt.*, st.title AS service_type_title
               FROM license_titles lt
               LEFT JOIN service_types st ON st.id = lt.service_type_id
              ORDER BY lt.service_type_id, lt.sort_order, lt.id'
        );
        return $this->render('admin/license_titles/index', [
            'title' => 'عناوین مجوز',
            'rows'  => $rows,
        ]);
    }

    public function create(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);
        $serviceTypes = $this->conn->all('SELECT id, title FROM service_types ORDER BY sort_order, id');
        return $this->render('admin/license_titles/edit', [
            'title'        => 'عنوان مجوز جدید',
            'title_'       => $this->blankTitle(),
            'serviceTypes' => $serviceTypes,
            'errors'       => [],
        ]);
    }

    public function store(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);
        $body = $request->body();
        $row = $this->hydrateFromPost($body);
        $errors = $this->validate($row, isNew: true);

        if ($errors !== []) {
            $serviceTypes = $this->conn->all('SELECT id, title FROM service_types ORDER BY sort_order, id');
            return $this->render('admin/license_titles/edit', [
                'title'        => 'عنوان مجوز جدید',
                'title_'       => $row,
                'serviceTypes' => $serviceTypes,
                'errors'       => $errors,
            ]);
        }

        $this->conn->execute(
            'INSERT INTO license_titles
                (service_type_id, category, title, is_active, sort_order)
             VALUES (?, ?, ?, ?, ?)',
            [
                $row['service_type_id'] ?: null,
                $row['category'] !== '' ? $row['category'] : null,
                $row['title'],
                $row['is_active'] ? 1 : 0,
                (int)$row['sort_order'],
            ]
        );
        return Response::redirect('/admin/license-titles');
    }

    public function edit(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);
        $id = (int)($params['id'] ?? 0);
        $row = $this->conn->one('SELECT * FROM license_titles WHERE id = ?', [$id]);
        if ($row === null) {
            return Response::html('<!doctype html><meta charset="utf-8"><h1>۴۰۴</h1><p>عنوان یافت نشد.</p>', 404);
        }
        $serviceTypes = $this->conn->all('SELECT id, title FROM service_types ORDER BY sort_order, id');
        return $this->render('admin/license_titles/edit', [
            'title'        => 'ویرایش عنوان مجوز',
            'title_'       => $row,
            'serviceTypes' => $serviceTypes,
            'errors'       => [],
        ]);
    }

    public function update(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);
        $id = (int)($params['id'] ?? 0);
        $existing = $this->conn->one('SELECT id FROM license_titles WHERE id = ?', [$id]);
        if ($existing === null) {
            return Response::html('<!doctype html><meta charset="utf-8"><h1>۴۰۴</h1><p>عنوان یافت نشد.</p>', 404);
        }

        $body = $request->body();
        $row = $this->hydrateFromPost($body);
        $errors = $this->validate($row, isNew: false, currentId: $id);

        if ($errors !== []) {
            $serviceTypes = $this->conn->all('SELECT id, title FROM service_types ORDER BY sort_order, id');
            $row['id'] = $id;
            return $this->render('admin/license_titles/edit', [
                'title'        => 'ویرایش عنوان مجوز',
                'title_'       => $row,
                'serviceTypes' => $serviceTypes,
                'errors'       => $errors,
            ]);
        }

        $this->conn->execute(
            'UPDATE license_titles SET
                service_type_id = ?, category = ?, title = ?, is_active = ?, sort_order = ?
             WHERE id = ?',
            [
                $row['service_type_id'] ?: null,
                $row['category'] !== '' ? $row['category'] : null,
                $row['title'],
                $row['is_active'] ? 1 : 0,
                (int)$row['sort_order'],
                $id,
            ]
        );
        return Response::redirect('/admin/license-titles');
    }

    public function destroy(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);
        $id = (int)($params['id'] ?? 0);
        $existing = $this->conn->one('SELECT id FROM license_titles WHERE id = ?', [$id]);
        if ($existing === null) {
            return Response::html('<!doctype html><meta charset="utf-8"><h1>۴۰۴</h1><p>عنوان یافت نشد.</p>', 404);
        }
        $this->conn->execute('DELETE FROM license_titles WHERE id = ?', [$id]);
        return Response::redirect('/admin/license-titles');
    }

    /**
     * POST /admin/license-titles/{id}/toggle — flip is_active for one row.
     */
    public function toggle(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);
        $id = (int)($params['id'] ?? 0);
        $row = $this->conn->one('SELECT id, is_active FROM license_titles WHERE id = ?', [$id]);
        if ($row === null) {
            return Response::html('<!doctype html><meta charset="utf-8"><h1>۴۰۴</h1><p>عنوان یافت نشد.</p>', 404);
        }
        $next = (int)$row['is_active'] === 1 ? 0 : 1;
        $this->conn->execute('UPDATE license_titles SET is_active = ? WHERE id = ?', [$next, $id]);
        return Response::redirect('/admin/license-titles');
    }

    /**
     * GET /admin/license-titles/by-service/{id} — JSON list filtered by service_type_id.
     *
     * Returns: {success:true, data:[{id,title,category,is_active,sort_order}, ...]}
     * Used by the registration wizard to populate the license-title dropdown
     * when the user picks a service_type.
     */
    public function byService(Request $request, array $params = []): Response
    {
        $this->requireRole(['admin']);
        $serviceTypeId = (int)($params['id'] ?? 0);
        $rows = $this->conn->all(
            'SELECT id, title, category, is_active, sort_order
               FROM license_titles
              WHERE service_type_id = ?
                AND is_active = 1
              ORDER BY sort_order, id',
            [$serviceTypeId]
        );
        return $this->jsonSuccess($rows);
    }

    /**
     * @return array<string,mixed>
     */
    private function blankTitle(): array
    {
        return [
            'id'              => null,
            'service_type_id' => null,
            'category'        => '',
            'title'           => '',
            'is_active'       => 1,
            'sort_order'      => 0,
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
            'category'        => trim((string)($body['category'] ?? '')),
            'title'           => trim((string)($body['title'] ?? '')),
            'is_active'       => !empty($body['is_active']) ? 1 : 0,
            'sort_order'       => (int)($body['sort_order'] ?? 0),
        ];
    }

    /**
     * @param array<string,mixed> $t
     * @return array<int,string>
     */
    private function validate(array $t, bool $isNew, ?int $currentId = null): array
    {
        $errors = [];
        $title = (string)$t['title'];
        if ($title === '') {
            $errors[] = 'عنوان مجوز الزامی است.';
        } elseif (mb_strlen($title) > 150) {
            $errors[] = 'عنوان حداکثر ۱۵۰ نویسه.';
        }
        if (mb_strlen((string)$t['category']) > 100) {
            $errors[] = 'طبقه‌بندی حداکثر ۱۰۰ نویسه.';
        }

        // UNIQUE (service_type_id, title) — enforced by schema, but we surface a
        // friendlier Persian message before letting PDO throw a SQLSTATE 23000.
        $serviceTypeId = $t['service_type_id'] ?: null;
        if ($title !== '') {
            if ($serviceTypeId === null) {
                $existing = $this->conn->one(
                    'SELECT id FROM license_titles WHERE title = ? AND service_type_id IS NULL',
                    [$title]
                );
            } else {
                $existing = $this->conn->one(
                    'SELECT id FROM license_titles WHERE service_type_id = ? AND title = ?',
                    [$serviceTypeId, $title]
                );
            }
            if ($existing !== null) {
                if ($isNew || (int)$existing['id'] !== (int)$currentId) {
                    $errors[] = 'این عنوان برای همان نوع خدمت قبلاً ثبت شده.';
                }
            }
        }
        return $errors;
    }
}
