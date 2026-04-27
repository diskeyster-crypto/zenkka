<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

Auth::requireAuth();

$config  = JsonStore::readConfig();
$appName = htmlspecialchars($config['app_name'] ?? 'Zenkka CMS', ENT_QUOTES, 'UTF-8');
$currentPage = 'history';

// ── Resolve generation from ID ──────────────────────────────────────────────
$rawId = $_GET['id'] ?? '';
try {
    $id = Validator::safeId($rawId);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo 'Invalid generation ID.';
    exit;
}

// Parse date from ID: gen_YYYYMMDD_xxxxxxxx → YYYY-MM-DD
$dateRaw = substr($id, 4, 8); // YYYYMMDD
$dateDir = substr($dateRaw, 0, 4) . '-' . substr($dateRaw, 4, 2) . '-' . substr($dateRaw, 6, 2);
$filePath = STORAGE_PATH . '/generations/' . $dateDir . '/' . $id . '.json';

if (!file_exists($filePath)) {
    http_response_code(404);
    echo 'Generation not found.';
    exit;
}

$gen = JsonStore::read($filePath);

$genId      = htmlspecialchars($gen['id'] ?? '', ENT_QUOTES, 'UTF-8');
$topic      = htmlspecialchars($gen['topic'] ?? '', ENT_QUOTES, 'UTF-8');
$reqCount   = (int)($gen['requested_count'] ?? 0);
$recCount   = (int)($gen['received_count'] ?? 0);
$language   = htmlspecialchars($gen['language'] ?? '', ENT_QUOTES, 'UTF-8');
$format     = htmlspecialchars($gen['format'] ?? '', ENT_QUOTES, 'UTF-8');
$provider   = htmlspecialchars($gen['provider'] ?? '', ENT_QUOTES, 'UTF-8');
$model      = htmlspecialchars($gen['model'] ?? '', ENT_QUOTES, 'UTF-8');
$createdAt  = htmlspecialchars($gen['created_at'] ?? '', ENT_QUOTES, 'UTF-8');
$items      = is_array($gen['items'] ?? null) ? $gen['items'] : [];
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Просмотр <?= $genId ?> — <?= $appName ?></title>
<?php include __DIR__ . '/partials/admin_styles.php'; ?>
<style>
.meta-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:1rem;margin-bottom:1.5rem}
.meta-item .label{font-size:.72rem;color:#6b7280;text-transform:uppercase;letter-spacing:.04em}
.meta-item .value{font-size:.95rem;font-weight:600;color:#1e293b;margin-top:.2rem}
.item-table td{font-size:.83rem;max-width:300px;word-break:break-word}
</style>
</head>
<body>
<?php include __DIR__ . '/partials/sidebar.php'; ?>

<main class="main-content">
    <div class="page-header" style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:.75rem">
        <div>
            <h1>👁 Просмотр генерации</h1>
            <p class="text-muted"><code><?= $genId ?></code></p>
        </div>
        <div style="display:flex;gap:.75rem;flex-wrap:wrap">
            <a href="/admin/download.php?id=<?= $genId ?>" class="btn btn-outline">⬇ Скачать JSON</a>
            <a href="/admin/history.php" class="btn btn-outline">← История</a>
        </div>
    </div>

    <div class="card">
        <h2>Метаданные</h2>
        <div class="meta-grid">
            <div class="meta-item"><div class="label">Тема</div><div class="value"><?= $topic ?></div></div>
            <div class="meta-item"><div class="label">Запрошено</div><div class="value"><?= $reqCount ?></div></div>
            <div class="meta-item"><div class="label">Получено</div><div class="value"><?= $recCount ?></div></div>
            <div class="meta-item"><div class="label">Язык</div><div class="value"><?= strtoupper($language) ?></div></div>
            <div class="meta-item"><div class="label">Формат</div><div class="value"><?= $format ?></div></div>
            <div class="meta-item"><div class="label">Провайдер</div><div class="value"><?= $provider ?></div></div>
            <div class="meta-item"><div class="label">Модель</div><div class="value" style="font-size:.8rem"><?= $model ?></div></div>
            <div class="meta-item"><div class="label">Создано</div><div class="value"><?= $createdAt ?></div></div>
        </div>
    </div>

    <div class="card">
        <h2>Элементы (<?= count($items) ?>)</h2>
        <?php if (empty($items)): ?>
        <p style="color:#64748b">Нет элементов.</p>
        <?php else: ?>
        <?php
        // Collect all unique keys from items
        $allKeys = [];
        foreach ($items as $item) {
            if (is_array($item)) {
                foreach (array_keys($item) as $k) {
                    $allKeys[$k] = true;
                }
            }
        }
        $allKeys = array_keys($allKeys);
        ?>
        <div class="table-wrap">
            <table class="item-table">
                <thead>
                    <tr>
                        <?php foreach ($allKeys as $key): ?>
                        <th><?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $item): ?>
                    <tr>
                        <?php if (!is_array($item)): ?>
                        <td colspan="<?= count($allKeys) ?>"><?= htmlspecialchars((string)$item, ENT_QUOTES, 'UTF-8') ?></td>
                        <?php else: ?>
                        <?php foreach ($allKeys as $key): ?>
                        <td><?= htmlspecialchars((string)($item[$key] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</main>
</body>
</html>
