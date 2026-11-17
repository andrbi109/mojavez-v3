<?php
/** @var \App\Modules\Core\Domain\Applicant $applicant */
/** @var array $statusLog */
use App\Modules\Core\Domain\Applicant;
?>
<h1>پروندهٔ <?= htmlspecialchars($applicant->trackingCode, ENT_QUOTES, 'UTF-8') ?></h1>
<p>نام: <?= htmlspecialchars($applicant->fullName(), ENT_QUOTES, 'UTF-8') ?></p>
<p>کد ملی: <?= htmlspecialchars($applicant->nationalCode, ENT_QUOTES, 'UTF-8') ?></p>
<p>موبایل: <?= htmlspecialchars($applicant->mobile, ENT_QUOTES, 'UTF-8') ?></p>
<p>وضعیت: <?= htmlspecialchars($applicant->currentStatus->value, ENT_QUOTES, 'UTF-8') ?></p>
<p>وضعیت مجوز: <?= htmlspecialchars($applicant->licenseState->value, ENT_QUOTES, 'UTF-8') ?></p>

<?php if ($applicant->isMatchedToNational()): ?>
    <p>مجوز ملی: <?= htmlspecialchars((string)$applicant->nationalLicenseRequestNumber, ENT_QUOTES, 'UTF-8') ?>
       (اطمینان: <?= htmlspecialchars((string)$applicant->matchConfidence, ENT_QUOTES, 'UTF-8') ?>)</p>
<?php endif; ?>

<h2>تاریخچهٔ وضعیت</h2>
<table>
    <thead><tr><th>وضعیت</th><th>توسط</th><th>تاریخ</th><th>توضیح</th></tr></thead>
    <tbody>
    <?php foreach ($statusLog as $entry): ?>
        <tr>
            <td><?= htmlspecialchars($entry->statusName, ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= htmlspecialchars($entry->triggeredBy, ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= htmlspecialchars($entry->createdAt, ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= htmlspecialchars((string)($entry->description ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
