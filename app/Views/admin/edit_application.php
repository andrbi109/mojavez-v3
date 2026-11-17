<?php
/**
 * admin/edit_application.php — edit an existing applicant case file.
 *
 * Phase 17 (v1.8.0): rendered by AdminController::editApplication().
 * Shows the applicant's full editable data (identity, service_type,
 * job_detail, property_detail) + a list of uploaded documents with
 * delete buttons + an "add document" form. POSTs to /admin/update.
 *
 * @var \App\Modules\Core\Domain\Applicant $applicant
 * @var array<string,mixed>                  $row  raw applicants row (all columns)
 * @var \App\Modules\Core\Domain\JobDetail|null    $jobDetail
 * @var \App\Modules\Core\Domain\PropertyDetail|null $propertyDetail
 * @var array<int, \App\Modules\Core\Domain\Document> $documents
 * @var array<int, array<string,mixed>>       $serviceTypes
 * @var array<int,string>                     $errors
 * @var string $csrfField
 * @var string $baseUrl
 */
/** @var \App\Modules\Core\Domain\Applicant $applicant */
/** @var array<string,mixed> $row */
/** @var array<int, \App\Modules\Core\Domain\Document> $documents */
/** @var array<int, array<string,mixed>> $serviceTypes */
/** @var array<int,string> $errors */
/** @var string $csrfField */
/** @var string $baseUrl */

use App\Modules\Core\Domain\Document;
use App\Modules\Core\Domain\JobDetail;
use App\Modules\Core\Domain\PropertyDetail;

$row           ??= [];
$jobDetail     ??= null;
$propertyDetail ??= null;
$documents     ??= [];
$serviceTypes  ??= [];
$errors        ??= [];
$csrfField     ??= '';
$baseUrl       ??= '/';

/** @return string */
$val = static function (string $key, ?string $default = '') use ($row, $jobDetail, $propertyDetail): string {
    // Look up by key in $row first; fall back to the domain objects.
    if (array_key_exists($key, $row) && isset($row[$key])) {
        return htmlspecialchars((string)$row[$key], ENT_QUOTES, 'UTF-8');
    }
    if ($jobDetail instanceof JobDetail) {
        $map = [
            'license_title'     => $jobDetail->licenseTitle,
            'experience_months' => (string)$jobDetail->experienceMonths,
            'training_method'   => $jobDetail->trainingMethod,
        ];
        if (isset($map[$key])) {
            return htmlspecialchars($map[$key], ENT_QUOTES, 'UTF-8');
        }
    }
    if ($propertyDetail instanceof PropertyDetail) {
        $map = [
            'ownership_status'   => $propertyDetail->ownershipStatus,
            'province'           => $propertyDetail->province,
            'county'             => $propertyDetail->county,
            'city'               => $propertyDetail->city,
            'district'           => (string)($propertyDetail->district ?? ''),
            'village'            => (string)($propertyDetail->village ?? ''),
            'electricity_bill_id' => $propertyDetail->electricityBillId,
            'address_street'     => (string)($propertyDetail->addressStreet ?? ''),
            'address_alley'      => (string)($propertyDetail->addressAlley ?? ''),
            'address_plaque'     => (string)($propertyDetail->addressPlaque ?? ''),
        ];
        if (isset($map[$key])) {
            return htmlspecialchars($map[$key], ENT_QUOTES, 'UTF-8');
        }
    }
    return htmlspecialchars((string)$default, ENT_QUOTES, 'UTF-8');
};

$applicantId = $applicant->id;
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h2 class="h5 mb-1"><i class="bi bi-pencil-square"></i> ویرایش پروندهٔ متقاضی</h2>
        <span class="moj-tracking-code"><?= htmlspecialchars($applicant->trackingCode, ENT_QUOTES, 'UTF-8') ?></span>
    </div>
    <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/view/<?= $applicantId ?>" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-eye"></i> مشاهدهٔ پرونده
    </a>
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

<form method="post" action="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/update">
    <?= $csrfField ?>
    <input type="hidden" name="applicant_id" value="<?= $applicantId ?>">

    <div class="card mb-3">
        <div class="card-header"><i class="bi bi-person-vcard"></i> اطلاعات هویتی</div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">نام</label>
                    <input type="text" class="form-control" name="first_name"
                           value="<?= $val('first_name') ?>" maxlength="50" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label">نام خانوادگی</label>
                    <input type="text" class="form-control" name="last_name"
                           value="<?= $val('last_name') ?>" maxlength="50" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label">کد ملی <span class="text-muted small">(غیرقابل ویرایش)</span></label>
                    <input type="text" class="form-control" value="<?= $val('national_code') ?>" disabled dir="ltr">
                </div>
                <div class="col-md-6">
                    <label class="form-label">موبایل</label>
                    <input type="text" class="form-control" name="mobile"
                           value="<?= $val('mobile') ?>" maxlength="11" pattern="09\d{9}" required dir="ltr">
                </div>
                <div class="col-md-6">
                    <label class="form-label">نام پدر</label>
                    <input type="text" class="form-control" name="father_name"
                           value="<?= $val('father_name') ?>" maxlength="50">
                </div>
                <div class="col-md-6">
                    <label class="form-label">کد پستی</label>
                    <input type="text" class="form-control" name="postal_code"
                           value="<?= $val('postal_code') ?>" maxlength="10" pattern="\d{10}" required dir="ltr">
                </div>
                <div class="col-md-6">
                    <label class="form-label">نوع خدمت</label>
                    <select class="form-select" name="service_type_id">
                        <option value="">—</option>
                        <?php foreach ($serviceTypes as $st): ?>
                            <?php $sid = (int)$st['id']; ?>
                            <option value="<?= $sid ?>"
                                <?= ((int)($row['service_type_id'] ?? 0) === $sid) ? 'selected' : '' ?>>
                                <?= htmlspecialchars((string)$st['title'], ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header"><i class="bi bi-briefcase"></i> اطلاعات شغلی</div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">عنوان مجوز</label>
                    <input type="text" class="form-control" name="license_title"
                           value="<?= $val('license_title') ?>" maxlength="100">
                </div>
                <div class="col-md-3">
                    <label class="form-label">سابقه (ماه)</label>
                    <input type="number" class="form-control" name="experience_months"
                           value="<?= $val('experience_months', '0') ?>" min="0">
                </div>
                <div class="col-md-3">
                    <label class="form-label">نحوه آموزش</label>
                    <select class="form-select" name="training_method">
                        <?php foreach (['در خانه', 'استاد شاگردی', 'کارگاهی'] as $tm): ?>
                            <option value="<?= htmlspecialchars($tm, ENT_QUOTES, 'UTF-8') ?>"
                                <?= ($val('training_method') === $tm) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($tm, ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header"><i class="bi bi-house"></i> اطلاعات ملک</div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">وضعیت مالکیت</label>
                    <select class="form-select" name="ownership_status">
                        <?php foreach (['شخص', 'مستأجر', 'سرپرست'] as $os): ?>
                            <option value="<?= htmlspecialchars($os, ENT_QUOTES, 'UTF-8') ?>"
                                <?= ($val('ownership_status') === $os) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($os, ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">استان</label>
                    <input type="text" class="form-control" name="province"
                           value="<?= $val('province') ?>" maxlength="50">
                </div>
                <div class="col-md-4">
                    <label class="form-label">شهرستان</label>
                    <input type="text" class="form-control" name="county"
                           value="<?= $val('county') ?>" maxlength="50">
                </div>
                <div class="col-md-4">
                    <label class="form-label">شهر</label>
                    <input type="text" class="form-control" name="city"
                           value="<?= $val('city') ?>" maxlength="50">
                </div>
                <div class="col-md-4">
                    <label class="form-label">شناسه قبض برق</label>
                    <input type="text" class="form-control" name="electricity_bill_id"
                           value="<?= $val('electricity_bill_id') ?>" maxlength="20">
                </div>
                <div class="col-md-6">
                    <label class="form-label">خیابان</label>
                    <input type="text" class="form-control" name="address_street"
                           value="<?= $val('address_street') ?>" maxlength="100">
                </div>
                <div class="col-md-3">
                    <label class="form-label">کوچه</label>
                    <input type="text" class="form-control" name="address_alley"
                           value="<?= $val('address_alley') ?>" maxlength="100">
                </div>
                <div class="col-md-3">
                    <label class="form-label">پلاک</label>
                    <input type="text" class="form-control" name="address_plaque"
                           value="<?= $val('address_plaque') ?>" maxlength="20">
                </div>
            </div>
        </div>
    </div>

    <div class="d-flex justify-content-end gap-2 mb-3">
        <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/view/<?= $applicantId ?>" class="btn btn-outline-secondary">انصراف</a>
        <button type="submit" class="btn btn-primary">
            <i class="bi bi-check-lg"></i> ذخیره تغییرات
        </button>
    </div>
</form>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-file-earmark"></i> مدارک</span>
        <button type="button" class="btn btn-outline-primary btn-sm" data-bs-toggle="collapse"
                data-bs-target="#addDocForm" aria-expanded="false" aria-controls="addDocForm">
            <i class="bi bi-plus-lg"></i> افزودن مدرک
        </button>
    </div>
    <div class="card-body">
        <div class="collapse mb-3" id="addDocForm">
            <form method="post" action="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/add-document"
                  enctype="multipart/form-data">
                <?= $csrfField ?>
                <input type="hidden" name="applicant_id" value="<?= $applicantId ?>">
                <div class="row g-2 align-items-end">
                    <div class="col-md-4">
                        <label class="form-label">نوع مدرک</label>
                        <input type="text" class="form-control" name="doc_type"
                               placeholder="مثلاً national_id, personal_photo" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">فایل</label>
                        <input type="file" class="form-control" name="document_file" required
                               accept="image/jpeg,image/png,application/pdf">
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="bi bi-upload"></i> بارگذاری
                        </button>
                    </div>
                </div>
                <div class="form-text small text-muted mt-2">
                    پسوندهای مجاز: jpg، jpeg، png، pdf. حداکثر حجم: ۲ مگابایت.
                </div>
            </form>
        </div>

        <?php if ($documents === []): ?>
            <div class="text-muted text-center py-4">هیچ مدرکی ثبت نشده است.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>نوع</th>
                            <th>نام فایل</th>
                            <th>تاریخ بارگذاری</th>
                            <th class="text-end">عملیات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($documents as $doc): ?>
                            <?php \assert($doc instanceof Document); ?>
                            <tr>
                                <td>
                                    <span class="badge text-bg-secondary">
                                        <?= htmlspecialchars($doc->docType, ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                </td>
                                <td><?= htmlspecialchars($doc->originalName, ENT_QUOTES, 'UTF-8') ?></td>
                                <td class="text-muted small" dir="ltr">
                                    <?= htmlspecialchars($doc->uploadedAt, ENT_QUOTES, 'UTF-8') ?>
                                </td>
                                <td class="text-end">
                                    <form method="post" action="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>admin/delete-document"
                                          class="d-inline"
                                          onsubmit="return confirm('حذف این مدرک؟ این عمل قابل بازگشت نیست.')">
                                        <?= $csrfField ?>
                                        <input type="hidden" name="document_id" value="<?= $doc->id ?>">
                                        <input type="hidden" name="applicant_id" value="<?= $applicantId ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger">
                                            <i class="bi bi-trash"></i> حذف
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
