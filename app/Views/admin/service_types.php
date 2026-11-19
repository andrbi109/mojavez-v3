<?php
/**
 * admin/service_types.php — list + add/edit form for service_types.
 *
 * Phase 17 (v1.8.0): rendered by ServiceTypeController::index().
 * Shows all service types with their applicant usage counts. The
 * add/edit form at the top posts to /admin/service-types/save; each
 * row has edit + delete buttons.
 *
 * @var array<int, array<string,mixed>> $rows
 * @var array<string,mixed>|null        $editing
 * @var array<int,string>               $errors
 * @var string $csrfField
 * @var string $baseUrl
 */
/** @var array<int, array<string,mixed>> $rows */
/** @var array<string,mixed>|null $editing */
/** @var array<int,string> $errors */
/** @var string $csrfField */
/** @var string $baseUrl */

$rows     ??= [];
$editing ??= null;
$errors   ??= [];
$csrfField ??= '';
$baseUrl  ??= '/';

/** @return string */
$ev = static function (string $key, ?string $default = '') use ($editing): string {
    if ($editing === null) {
        return htmlspecialchars((string)$default, ENT_QUOTES, 'UTF-8');
    }
    return htmlspecialchars((string)($editing[$key] ?? ''), ENT_QUOTES, 'UTF-8');
};
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="h5 mb-0"><i class="bi bi-briefcase"></i> انواع خدمت</h2>
</div>

<?php if ($errors !== []): ?>
<div class="alert alert-danger">
    <ul class="mb-0">
        <?php foreach ($errors as $e): ?>
            <li><?= htmlspecialchars($e, ENT_QUOTES, 'UTF-8') ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<!-- Phase 48 (v2.1.4): explain how services / sub-services are managed.
     Each service type can have many concrete services (e.g. «ثبت درخواست در
     درگاه ملی مجوزها», «بارگذاری مدارک در سامانه تخصصی وزارت میراث فرهنگی»,
     «تغییر کد پستی در سامانه املاک و اسکان»); these are not modeled as a
     separate sub-service table. Instead, each is represented as a
     fee_group_item under a fee_group whose decile range matches the
     applicant's income decile, and is priced in the نرخ‌نامه admin page. -->
<div class="alert alert-info small py-2 mb-3 d-flex align-items-start gap-2">
    <i class="bi bi-info-circle-fill mt-1"></i>
    <div>
        هر نوع خدمت می‌تواند چند «خدمت/زیرخدمت» (مانند «ثبت درخواست در درگاه ملی»،
        «بارگذاری مدارک در سامانه تخصصی وزارت میراث فرهنگی»، «تغییر کد پستی در
        سامانه املاک و اسکان») داشته باشد. در نسخهٔ فعلی، این زیرخدمات به‌صورت
        جدولی جداگانه مدل‌سازی نشده‌اند؛ بلکه هر کدام به‌عنوان یک
        <strong>آیتم نرخ‌نامه</strong> در گروه دهک درآمد متقاضی قیمت‌گذاری می‌شوند.
        برای تعریف یا تغییر قیمت زیرخدمات به
        <a class="alert-link" href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/fee-tariff">بخش «نرخ‌نامه»</a>
        مراجعه کنید.
    </div>
</div>

<div class="card mb-3">
    <div class="card-header">
        <i class="bi <?= $editing === null ? 'bi-plus-lg' : 'bi-pencil' ?>"></i>
        <?= $editing === null ? 'افزودن نوع خدمت' : 'ویرایش نوع خدمت (#' . (int)$editing['id'] . ')' ?>
    </div>
    <div class="card-body">
        <form method="post" action="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/service-types/save">
            <?= $csrfField ?>
            <?php if ($editing !== null): ?>
                <input type="hidden" name="id" value="<?= (int)$editing['id'] ?>">
            <?php endif; ?>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">عنوان <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" name="title"
                           value="<?= $ev('title') ?>" maxlength="120" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label">مرجع صدور</label>
                    <input type="text" class="form-control" name="authority"
                           value="<?= $ev('authority') ?>" maxlength="150">
                </div>
                <div class="col-12">
                    <label class="form-label">توضیحات</label>
                    <textarea class="form-control" name="description" rows="2"><?= $ev('description') ?></textarea>
                </div>
                <div class="col-md-6">
                    <label class="form-label">ترتیب</label>
                    <input type="number" class="form-control" name="sort_order"
                           value="<?= $ev('sort_order', '0') ?>" min="0">
                </div>
                <div class="col-md-6 d-flex align-items-end">
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" name="is_active" value="1"
                               id="st-active" <?= ((int)($editing['is_active'] ?? 1) === 1) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="st-active">فعال</label>
                    </div>
                </div>
            </div>
            <div class="d-flex justify-content-end gap-2 mt-3">
                <?php if ($editing !== null): ?>
                    <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/service-types"
                       class="btn btn-outline-secondary btn-sm">انصراف</a>
                <?php endif; ?>
                <button type="submit" class="btn btn-primary btn-sm">
                    <i class="bi bi-check-lg"></i> ذخیره
                </button>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>عنوان</th>
                        <th>مرجع</th>
                        <th>ترتیب</th>
                        <th>وضعیت</th>
                        <th>پرونده‌ها</th>
                        <th>عناوین مجوز</th>
                        <th>فیلدهای فرم</th>
                        <th>مراحل ویزارد</th>
                        <th class="text-end">عملیات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $r): ?>
                        <?php
                            $sid = (int)$r['id'];
                            $isActive = (int)$r['is_active'] === 1;
                            $applicantCount = (int)($r['applicant_count'] ?? 0);
                            $licenseTitleCount = (int)($r['license_title_count'] ?? 0);
                            $formFieldCount = (int)($r['form_field_count'] ?? 0);
                            $wizardStepCount = (int)($r['wizard_step_count'] ?? 0);
                        ?>
                        <tr>
                            <td><?= $sid ?></td>
                            <td><?= htmlspecialchars((string)$r['title'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td class="text-muted small"><?= htmlspecialchars((string)($r['authority'] ?? '—') ?: '—', ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= (int)$r['sort_order'] ?></td>
                            <td>
                                <?php if ($isActive): ?>
                                    <span class="badge text-bg-success">فعال</span>
                                <?php else: ?>
                                    <span class="badge text-bg-secondary">غیرفعال</span>
                                <?php endif; ?>
                            </td>
                            <td><?= $applicantCount ?></td>
                            <td>
                                <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/license-titles"
                                   class="badge <?= $licenseTitleCount > 0 ? 'text-bg-primary text-decoration-none' : 'text-bg-light text-muted text-decoration-none border' ?>"
                                   title="عناوین مجوز این نوع خدمت">
                                    <?= $licenseTitleCount ?>
                                </a>
                            </td>
                            <td>
                                <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/form-fields"
                                   class="badge <?= $formFieldCount > 0 ? 'text-bg-primary text-decoration-none' : 'text-bg-light text-muted text-decoration-none border' ?>"
                                   title="فیلدهای فرم این نوع خدمت">
                                    <?= $formFieldCount ?>
                                </a>
                            </td>
                            <td>
                                <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/wizard-steps"
                                   class="badge <?= $wizardStepCount > 0 ? 'text-bg-primary text-decoration-none' : 'text-bg-light text-muted text-decoration-none border' ?>"
                                   title="مراحل ویزارد این نوع خدمت">
                                    <?= $wizardStepCount ?>
                                </a>
                            </td>
                            <td class="text-end">
                                <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/service-types?edit=<?= $sid ?>"
                                   class="btn btn-sm btn-outline-primary">
                                    <i class="bi bi-pencil"></i>
                                </a>
                                <form method="post"
                                      action="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/service-types/delete"
                                      class="d-inline"
                                      onsubmit="return confirm('حذف این نوع خدمت؟ در صورت وجود پرونده/فیلد/مرحله وابسته، غیرفعال می‌شود.')">
                                    <?= $csrfField ?>
                                    <input type="hidden" name="id" value="<?= $sid ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($rows === []): ?>
                        <tr><td colspan="10" class="text-center text-muted py-4">هیچ نوع خدمتی ثبت نشده.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
