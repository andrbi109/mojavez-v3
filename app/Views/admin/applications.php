<?php
/** @var array $rows */
/** @var int $total */
/** @var int $page */
/** @var int $pageSize */
?>
<h1>درخواست‌ها</h1>
<table class="table">
    <thead><tr><th>کد رهگیری</th><th>نام</th><th>کد ملی</th><th>وضعیت</th><th>وضعیت مجوز</th><th>تاریخ ثبت</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $row): ?>
        <tr>
            <td><?= htmlspecialchars((string)$row['tracking_code'], ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= htmlspecialchars((string)$row['first_name'] . ' ' . (string)$row['last_name'], ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= htmlspecialchars((string)$row['national_code'], ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= htmlspecialchars((string)$row['current_status'], ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= htmlspecialchars((string)$row['license_state'], ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= htmlspecialchars((string)$row['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
            <td><a href="/admin/view/<?= (int)$row['id'] ?>">مشاهده</a></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<p>صفحه <?= htmlspecialchars((string)$page, ENT_QUOTES, 'UTF-8') ?> — <?= htmlspecialchars((string)$total, ENT_QUOTES, 'UTF-8') ?> رکورد</p>
