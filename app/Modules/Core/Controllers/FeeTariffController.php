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
}
