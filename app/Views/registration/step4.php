<?php
/**
 * registration/step4.php — wizard step 4: documents + review + submit.
 *
 * Phase 14 (v1.5.0) — pays D-7-3. The final step: applicant uploads
 * required documents (national-ID scan, business-license proof,
 * photo) and reviews a summary before final submission. On submit,
 * the (future) RegistrationController creates the applicants row + a
 * status_log row + redirects to /register/success/{trackingCode}.
 *
 * Expected $data:
 *   $requiredDocs  array<int, array{doc_type, label, is_required}> —
 *                   document_types rows the wizard expects.
 *   $summary       array<string,string> — read-only summary of steps
 *                   1..3 (applicant name, address, job) for review.
 *   $baseUrl, $csrfField.
 */
/** @var array<int, array<string,mixed>> $requiredDocs */
/** @var array<string,string> $summary */
/** @var string $baseUrl */
/** @var string $csrfField */

$requiredDocs ??= [];
$summary      ??= [];
$baseUrl      ??= '';
$csrfField    ??= '';
$currentStep = 4;
$totalSteps  = 4;
require __DIR__ . '/_progress.php';
?>
<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="card shadow-sm">
            <div class="card-header bg-white">
                <h1 class="h5 mb-0"><i class="bi bi-file-earmark-text text-brand"></i>
                    مرحلهٔ ۴ — مدارک و بازبینی
                </h1>
            </div>
            <div class="card-body p-4">
                <form method="post" action="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>register/step/4"
                    enctype="multipart/form-data">
                    <?= $csrfField ?>

                    <h2 class="h6 mb-3"><i class="bi bi-upload"></i> مدارک</h2>
                    <?php foreach ($requiredDocs as $doc):
                        $docType = (string)($doc['doc_type'] ?? '');
                        $label = (string)($doc['label'] ?? '');
                        $req = !empty($doc['is_required']);
                    ?>
                        <div class="mb-3">
                            <label for="doc_<?= htmlspecialchars($docType, ENT_QUOTES) ?>" class="form-label">
                                <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
                                <?php if ($req): ?><span class="text-danger">*</span><?php endif; ?>
                            </label>
                            <input type="file" class="form-control" id="doc_<?= htmlspecialchars($docType, ENT_QUOTES) ?>"
                                name="docs[<?= htmlspecialchars($docType, ENT_QUOTES) ?>]"
                                accept="image/jpeg,image/png,application/pdf"
                                <?= $req ? 'required' : '' ?>>
                        </div>
                    <?php endforeach; ?>

                    <hr class="my-4">
                    <h2 class="h6 mb-3"><i class="bi bi-clipboard-check"></i> بازبینی اطلاعات</h2>
                    <dl class="row mb-0 small">
                        <?php foreach ($summary as $k => $v):
                            $fa = match ($k) {
                                'first_name'       => 'نام',
                                'last_name'        => 'نام خانوادگی',
                                'national_code'    => 'کد ملی',
                                'father_name'      => 'نام پدر',
                                'mobile'           => 'موبایل',
                                'province'         => 'استان',
                                'township'         => 'شهرستان',
                                'postal_code'      => 'کد پستی',
                                'address'          => 'نشانی',
                                'license_title'    => 'عنوان مجوز',
                                'business_description' => 'توضیح فعالیت',
                                default            => htmlspecialchars($k, ENT_QUOTES, 'UTF-8'),
                            };
                        ?>
                            <dt class="col-sm-4 text-muted"><?= $fa ?></dt>
                            <dd class="col-sm-8"><?= htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8') ?></dd>
                        <?php endforeach; ?>
                    </dl>

                    <div class="form-check mb-3">
                        <input type="checkbox" class="form-check-input" id="consent" name="consent" value="1" required>
                        <label class="form-check-label" for="consent">
                            صحت اطلاعات را تأیید می‌کنم و با اشتراک‌گذاری داده‌ها با درگاه ملی موافقم.
                        </label>
                    </div>

                    <div class="mt-4 d-flex justify-content-between">
                        <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES) ?>register/step/3"
                            class="btn btn-outline-secondary">
                            <i class="bi bi-arrow-return-right"></i> بازگشت
                        </a>
                        <button type="submit" class="btn btn-success">
                            <i class="bi bi-check2-circle"></i> ثبت نهایی
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
