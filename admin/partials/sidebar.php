<?php
// $currentPage and $appName must be defined before including this partial
$navItems = [
    'index'    => ['href' => '/admin/index.php',    'icon' => '✨', 'label' => 'Генерация'],
    'history'  => ['href' => '/admin/history.php',  'icon' => '📋', 'label' => 'История'],
    'settings' => ['href' => '/admin/settings.php', 'icon' => '⚙️', 'label' => 'Настройки'],
];
?>
<nav class="sidebar">
    <div class="sidebar-logo">
        <h2><?= $appName ?? 'Zenkka CMS' ?></h2>
        <p>👤 <?= htmlspecialchars(Auth::getUsername(), ENT_QUOTES, 'UTF-8') ?></p>
    </div>
    <div class="sidebar-nav">
        <?php foreach ($navItems as $key => $item): ?>
        <a href="<?= $item['href'] ?>" class="<?= ($currentPage ?? '') === $key ? 'active' : '' ?>">
            <?= $item['icon'] ?> <?= $item['label'] ?>
        </a>
        <?php endforeach; ?>
        <a href="/logout.php" style="margin-top:auto;color:#f87171">
            🚪 Выход
        </a>
    </div>
    <div class="sidebar-footer">Zenkka CMS</div>
</nav>
