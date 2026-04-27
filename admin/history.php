<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

Auth::requireAuth();

$config   = JsonStore::readConfig();
$appName  = htmlspecialchars($config['app_name'] ?? 'Zenkka CMS', ENT_QUOTES, 'UTF-8');
$currentPage = 'history';

// ── Scan generations directory ───────────────────────────────────────────────
$generationsDir = STORAGE_PATH . '/generations';
$files = [];

if (is_dir($generationsDir)) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($generationsDir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'json') {
            $files[] = $file->getPathname();
        }
    }
}

// Sort newest first (by mtime), handling filemtime() returning false
usort($files, function ($a, $b) {
    $mtimeA = @filemtime($a);
    $mtimeB = @filemtime($b);
    return ($mtimeB !== false ? $mtimeB : 0) <=> ($mtimeA !== false ? $mtimeA : 0);
});

// Load metadata for each
$generations = [];
foreach ($files as $path) {
    $data = JsonStore::read($path);
    if (!empty($data['id'])) {
        $generations[] = $data;
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>История — <?= $appName ?></title>
<?php include __DIR__ . '/partials/admin_styles.php'; ?>
</head>
<body>
<?php include __DIR__ . '/partials/sidebar.php'; ?>

<main class="main-content">
    <div class="page-header">
        <h1>📋 История генераций</h1>
        <p class="text-muted">Все созданные наборы данных</p>
    </div>

    <?php if (empty($generations)): ?>
    <div class="card" style="text-align:center;color:#64748b;padding:3rem">
        <p style="font-size:2rem">📭</p>
        <p style="margin-top:.75rem">Генераций ещё нет.</p>
        <a href="/admin/index.php" class="btn btn-primary" style="margin-top:1rem">Создать первую →</a>
    </div>
    <?php else: ?>
    <div class="stats-row">
        <div class="stat-card">
            <div class="label">Всего генераций</div>
            <div class="value"><?= count($generations) ?></div>
        </div>
        <div class="stat-card">
            <div class="label">Всего элементов</div>
            <div class="value"><?= array_sum(array_column($generations, 'received_count')) ?></div>
        </div>
    </div>

    <div class="card">
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Имя генерации</th>
                            <th>Тема</th>
                            <th>Язык</th>
                            <th>Формат</th>
                            <th>Режим</th>
                            <th>Папка</th>
                            <th>Файлов</th>
                            <th>Получено</th>
                            <th>Дата</th>
                            <th>Действия</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($generations as $gen): ?>
                        <?php
                            $id       = htmlspecialchars($gen['id'] ?? '', ENT_QUOTES, 'UTF-8');
                            $genName  = htmlspecialchars(mb_strimwidth($gen['generation_name'] ?? $gen['id'] ?? '', 0, 40, '…'), ENT_QUOTES, 'UTF-8');
                            $topic    = htmlspecialchars(mb_strimwidth($gen['topic'] ?? '', 0, 50, '…'), ENT_QUOTES, 'UTF-8');
                            $req      = (int)($gen['requested_count'] ?? 0);
                            $rec      = (int)($gen['received_count'] ?? 0);
                            $lang     = htmlspecialchars(strtoupper($gen['language'] ?? ''), ENT_QUOTES, 'UTF-8');
                            $fmt      = htmlspecialchars($gen['output_format'] ?? '—', ENT_QUOTES, 'UTF-8');
                            $mode     = htmlspecialchars($gen['output_mode'] ?? '—', ENT_QUOTES, 'UTF-8');
                            $dest     = htmlspecialchars(mb_strimwidth($gen['destination_folder'] ?? '—', 0, 30, '…'), ENT_QUOTES, 'UTF-8');
                            $fileCnt  = is_array($gen['files'] ?? null) ? count($gen['files']) : '—';
                            $created  = htmlspecialchars($gen['created_at'] ?? '', ENT_QUOTES, 'UTF-8');
                            $pct      = $req > 0 ? round($rec / $req * 100) : 0;
                            $badgeCls = $pct >= 90 ? 'badge-green' : ($pct >= 50 ? 'badge-blue' : 'badge-gray');
                        ?>
                        <tr>
                            <td><code style="font-size:.72rem;color:#6b7280"><?= $id ?></code></td>
                            <td><?= $genName ?></td>
                            <td><?= $topic ?></td>
                            <td><?= $lang ?></td>
                            <td><span class="badge badge-blue"><?= $fmt ?></span></td>
                            <td style="font-size:.8rem;color:#64748b"><?= $mode ?></td>
                            <td style="font-size:.78rem;color:#64748b"><code><?= $dest ?></code></td>
                            <td><?= $fileCnt ?></td>
                            <td><span class="badge <?= $badgeCls ?>"><?= $rec ?> (<?= $pct ?>%)</span></td>
                            <td style="white-space:nowrap;font-size:.8rem"><?= $created ?></td>
                            <td style="white-space:nowrap">
                                <a href="/admin/view.php?id=<?= $id ?>" class="btn btn-sm btn-outline">👁</a>
                                <a href="/admin/download.php?id=<?= $id ?>" class="btn btn-sm btn-outline">⬇ JSON</a>
                                <?php if (is_array($gen['files'] ?? null) && count($gen['files']) > 0): ?>
                                <a href="/admin/download_zip.php?id=<?= $id ?>" class="btn btn-sm btn-outline">📦 ZIP</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
    </div>
    <?php endif; ?>
</main>
</body>
</html>
