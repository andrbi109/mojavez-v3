<?php
/** @var int $cancelled */
/** @var int $prioritised */
/** @var int $totalApplicants */
/** @var int $pendingReview */
/** @var int $issuedThisMonth */
?>
<h1>داشبورد مدیریت</h1>
<div class="row">
    <div class="col"><h3><?= htmlspecialchars((string)$totalApplicants, ENT_QUOTES, 'UTF-8') ?></h3><p>کل پرونده‌ها</p></div>
    <div class="col"><h3><?= htmlspecialchars((string)$pendingReview, ENT_QUOTES, 'UTF-8') ?></h3><p>در انتظار بررسی</p></div>
    <div class="col"><h3><?= htmlspecialchars((string)$issuedThisMonth, ENT_QUOTES, 'UTF-8') ?></h3><p>صادرشده این ماه</p></div>
</div>
<div class="row">
    <div class="col">لغو خودکار: <?= htmlspecialchars((string)$cancelled, ENT_QUOTES, 'UTF-8') ?></div>
    <div class="col">اولویت‌بندی: <?= htmlspecialchars((string)$prioritised, ENT_QUOTES, 'UTF-8') ?></div>
</div>
<p><a href="/admin/applications">همهٔ درخواست‌ها</a></p>
