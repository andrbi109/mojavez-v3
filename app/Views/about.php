<?php
/** @var string $aboutText */
?>
<h1>دربارهٔ ما</h1>
<div class="about-content">
    <?php if (!empty($aboutText)): ?>
        <?= nl2br(htmlspecialchars((string)$aboutText, ENT_QUOTES, 'UTF-8')) ?>
    <?php else: ?>
        <p>این سامانه برای صدور مجوز صنایع دستی و مشاغل خانگی طراحی شده است.</p>
    <?php endif; ?>
</div>
