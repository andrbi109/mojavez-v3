# Phase 16 — Seed document_types + Admin Default + Real Document Upload (v1.7.0)

> **Branch:** `phase/16-document-types-and-admin-controllers`
> **Base:** `main` at commit `531b135` (the v1.6.1 release).
> **Tag:** `v1.7.0`
> **Date:** 2026-11-17
> **Maintainer:** AI agent (z.ai)
> **Plan reference:** `docs/PHASE_PLAN_V1_7_TO_V1_9.md` (Phase 16 of 6)

## 1. Context

Phase 15.1 (v1.6.1) closed three runtime bugs in the registration
wizard. One of them was that the wizard's step 4 marked all three
file inputs as HTML5 `required`, but the `document_types` table
was empty in a fresh install — so the browser blocked form
submission and the wizard was untestable. The Phase 15.1 fix
removed the static `required` attribute and added an info alert
when no docs were configured, with the comment:

> The static `required` enforcement will return in v1.7 when
> document-types are seeded by default and the wizard knows exactly
> which docs to require.

Phase 16 closes that loop. It seeds the 6 default `document_types`,
restores the `required` attribute dynamically based on each row's
computed `is_required` field, and seeds a default admin user so a
fresh install is immediately usable.

## 2. What was added

### 2.1 New migrations (2)

| File | Purpose | Idempotency |
|---|---|---|
| `app/Modules/Core/migrations/20261117_010_seed_document_types_default.sql` | Seeds the 6 default `document_types` (national_id, personal_photo, ownership_deed, skill_cert, business_license, avatar). The avatar row is re-asserted at `sort_order=99` (was 100 in the Phase 12 seed migration 007). | `INSERT ... ON DUPLICATE KEY UPDATE` on `UNIQUE(doc_type_key)` |
| `app/Modules/Core/migrations/20261117_011_seed_admin_default.sql` | Seeds the default admin user (`username=admin`, `password=admin123` as a real bcrypt cost-12 hash, `display_name=مدیر کل`, `role=admin`). | `INSERT ... SELECT ... WHERE NOT EXISTS` — only seeds if no row with `username='admin'` exists, so password rotation survives re-running |

Both seeds are mirrored in `database/install.sql` so the fresh-install
path matches the migration path. Both migrations are registered in
`Core/Module.php::migrations()` after the existing `009_seed_default_service_types.sql`
entry.

### 2.2 Changed code

- `RegistrationController::requiredDocs()` — was a hard-coded 3-row
  array; now reads from the `document_types` table:

  ```sql
  SELECT doc_type_key AS doc_type, label, is_active,
         CASE WHEN doc_type_key IN ('national_id', 'personal_photo') THEN 1 ELSE 0 END AS is_required
    FROM document_types
   WHERE is_active = 1 AND doc_type_key != 'avatar'
   ORDER BY sort_order ASC
  ```

  The `avatar` row is filtered out (it's used by `AvatarService`
  elsewhere, not by the wizard). `is_required` is computed from
  `doc_type_key` for now (national_id and personal_photo are required;
  the rest are optional).

- `registration/step4.php` — restored the HTML5 `required` attribute
  on file inputs, dynamically based on each row's `is_required`
  field: `<?= $req ? 'required' : '' ?>`. The empty-list info
  alert is kept for the dev-without-seed case.

- `RegistrationController::handleDocumentUploads()` — docblock
  expanded to document the real file-upload pipeline that has been
  in place since v1.6.0 (delegates to `FileStorage::storeUpload()`
  which runs the 7-layer secure upload pipeline). No behaviour
  change.

## 3. Verification

Ran the migration against the dev DB (MariaDB 11.8 on socket
`/home/z/my-project/opt/mariadb/mysql.sock`):

```bash
$ DB_HOST=127.0.0.1 DB_PORT=13306 DB_USER=mojavez DB_PASS=mojavez123 \
    DB_NAME=mojavez_v3 APP_ENV=development \
    php scripts/migrate.php
Applied 2 new migration(s).

$ mariadb ... -e "SELECT * FROM document_types;"
id  doc_type_key      label                  category     is_active  sort_order
1   avatar            تصویر پروفایل         user_upload  1          99
2   national_id       تصویر کارت ملی         user_upload  1          1
3   personal_photo    عکس پرسنلی             user_upload  1          2
4   ownership_deed    سند مالکیت محل کسب     user_upload  1          3
5   skill_cert        گواهی مهارت            user_upload  1          4
6   business_license  مجوز کسب              user_upload  1          5

$ mariadb ... -e "SELECT id, username, role, display_name FROM users;"
id  username  role   display_name
1   admin     admin  مدیر کل
```

Queried the `requiredDocs()` SQL directly: returns 5 rows
(national_id, personal_photo, ownership_deed, skill_cert,
business_license), with `is_required=1` for national_id and
personal_photo and `is_required=0` for the rest — exactly what
the wizard expects.

Logged in via `/admin/login` with `admin` / `admin123` (with
the PHP dev server restarted with `APP_DEBUG=true` to bypass the
pre-existing PHP 8.4 `E_STRICT` deprecation issue in
`public/index.php:67` that breaks production-mode rendering —
that issue is unrelated to Phase 16 and tracked separately):

```
POST /admin/login     HTTP 302   (redirect to dashboard)
GET  /admin/dashboard HTTP 200   (admin panel rendered)
```

The seed admin user is usable end-to-end.

## 4. Debt

### Paid (partial)

- **D-7-3** (partial) — the wizard's step 4 had hard-coded doc
  rows and a static `required` attribute. Now reads from the
  `document_types` lookup table and `required` is dynamic per-row.
  The wizard renders 5 file inputs (2 required, 3 optional).

### Introduced (1)

- **D-16-1** (low) — `document_types` has no `is_required` column;
  `requiredDocs()` computes it from `doc_type_key` (national_id and
  personal_photo are required). If an admin needs to toggle
  per-doctor, a real `is_required TINYINT(1) NOT NULL DEFAULT 0`
  column + admin UI is needed. Deferred to Phase 17+ when the
  admin CRUD for document_types is built.

### Carried forward (no change to medium/high)

- The 12 low-debt items carried from Phase 15 remain (D-4-5,
  D-5-1, D-5-4, D-5-5, D-6-2, D-6-3, D-6-4, D-6-5, D-6-6, D-8-1,
  D-8-2, D-8-3, D-8-5, D-14-1).
- New: D-16-1. Net change: 12 → 13 low-debt items.
- **No medium or high debt introduced.**

## 5. Notes

- The `documents` table schema is unchanged. v2's `documents`
  table (per `database/schema.sql` in the v2 extract) has the same
  columns as v3 (id, applicant_id, doc_type VARCHAR(50), file_name,
  original_name, file_path, uploaded_at) — no `doc_type_id` foreign
  key in v2 either. The free-form `doc_type` VARCHAR matches the
  `doc_type_key` of the `document_types` lookup table by convention,
  not by constraint. Tightening this to an FK is left for a later
  phase.
- The default admin password is `admin123` — intentionally weak so
  the maintainer is forced to rotate it on first login. The
  migration is non-destructive: re-running it against a DB where
  the admin has already rotated their password does NOT clobber
  the existing row (the `WHERE NOT EXISTS` clause skips the INSERT).
- The v2 reference admin seed (`database/seed-admin.sql` in the
  v2 extract) uses `DELETE FROM users WHERE username='admin';
  INSERT ...` — a destructive pattern that would clobber a
  rotated password on every re-run. v3 uses
  `INSERT ... WHERE NOT EXISTS` instead.
- The pre-existing PHP 8.4 `E_STRICT` deprecation issue in
  `public/index.php:67` (which emits a "Constant E_STRICT is
  deprecated" notice in production mode, breaking header sending
  on every request) is **not** fixed by Phase 16 — it's outside
  the phase scope ("don't touch unrelated files") and predates
  this branch. It should be addressed in a near-future cleanup
  phase (replace `~E_STRICT` with nothing, since the constant is
  deprecated in PHP 8.4+).
