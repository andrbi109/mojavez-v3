<?php
/** @var \App\Modules\Core\Domain\Applicant $applicant */
/** @var array $statusLog */
/** @var ?\App\Modules\Core\Domain\JobDetail $jobDetail */
?>
<h1>پیگیری <?= htmlspecialchars($applicant->trackingCode, ENT_QUOTES, 'UTF-8') ?></h1>
<p>نام: <?= htmlspecialchars($applicant->fullName(), ENT_QUOTES, 'UTF-8') ?></p>
<p>وضعیت: <?= htmlspecialchars($applicant->currentStatus->value, ENT_QUOTES, 'UTF-8') ?></p>
<p>وضعیت مجوز: <?= htmlspecialchars($applicant->licenseState->value, ENT_QUOTES, 'UTF-8') ?></p>

<?php if ($jobDetail !== null): ?>
    <h2>اطلاعات شغلی</h2>
    <p>رشته: <?= htmlspecialchars($jobDetail->licenseTitle, ENT_QUOTES, 'UTF-8') ?></p>
    <p>سابقه: <?= htmlspecialchars($jobDetail->experienceLabel(), ENT_QUOTES, 'UTF-8') ?></p>
<?php endif; ?>

<h2>تاریخچهٔ وضعیت</h2>
<table>
    <thead><tr><th>وضعیت</th><th>تاریخ</th></tr></thead>
    <tbody>
    <?php foreach ($statusLog as $entry): ?>
        <tr><td><?= htmlspecialchars($entry->statusName, ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= htmlspecialchars($entry->createdAt, ENT_QUOTES, 'UTF-8') ?></td></tr>
    <?php endforeach; ?>
    </tbody>
</table>
<p><a href="/tracking/print/<?= htmlspecialchars($applicant->trackingCode, ENT_QUOTES, 'UTF-8') ?>">نسخهٔ چاپی</a></p>
