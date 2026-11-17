<?php
/** @var \App\Modules\Core\Domain\Applicant $applicant */
/** @var array $statusLog */
?>
<h1>نسخهٔ چاپی پرونده</h1>
<p>کد رهگیری: <?= htmlspecialchars($applicant->trackingCode, ENT_QUOTES, 'UTF-8') ?></p>
<p>نام: <?= htmlspecialchars($applicant->fullName(), ENT_QUOTES, 'UTF-8') ?></p>
<p>کد ملی: <?= htmlspecialchars($applicant->nationalCode, ENT_QUOTES, 'UTF-8') ?></p>
<p>وضعیت: <?= htmlspecialchars($applicant->currentStatus->value, ENT_QUOTES, 'UTF-8') ?></p>
<h2>تاریخچهٔ وضعیت</h2>
<table>
    <?php foreach ($statusLog as $entry): ?>
        <tr><td><?= htmlspecialchars($entry->statusName, ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= htmlspecialchars($entry->createdAt, ENT_QUOTES, 'UTF-8') ?></td></tr>
    <?php endforeach; ?>
</table>
<script>window.print();</script>
