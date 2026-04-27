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
$dateRaw  = substr($id, 4, 8);
$dateDir  = substr($dateRaw, 0, 4) . '-' . substr($dateRaw, 4, 2) . '-' . substr($dateRaw, 6, 2);
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
$provider   = htmlspecialchars($gen['provider'] ?? '', ENT_QUOTES, 'UTF-8');
$model      = htmlspecialchars($gen['model'] ?? '', ENT_QUOTES, 'UTF-8');
$createdAt  = htmlspecialchars($gen['created_at'] ?? '', ENT_QUOTES, 'UTF-8');
$items      = is_array($gen['items'] ?? null) ? $gen['items'] : [];
$lengthSets = $gen['length_settings'] ?? [];
$opRules    = $gen['operator_rules'] ?? '';
$summary    = $gen['validation_summary'] ?? [];
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Просмотр <?= $genId ?> — <?= $appName ?></title>
<?php include __DIR__ . '/partials/admin_styles.php'; ?>
<style>
.meta-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:1rem;margin-bottom:1.5rem}
.meta-item .label{font-size:.72rem;color:#6b7280;text-transform:uppercase;letter-spacing:.04em}
.meta-item .value{font-size:.95rem;font-weight:600;color:#1e293b;margin-top:.2rem}
.item-card{background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:1rem;margin-bottom:.75rem}
.item-card.invalid{border-color:#fca5a5;background:#fff5f5}
.item-card h4{font-size:.82rem;font-weight:700;color:#374151;margin-bottom:.5rem}
.item-field{margin-bottom:.4rem;font-size:.83rem}
.item-field .field-label{color:#6b7280;font-weight:600;min-width:130px;display:inline-block}
.item-field .field-val{color:#1e293b}
.val-badge{font-size:.7rem;padding:.15rem .45rem;border-radius:20px;font-weight:700;margin-left:.3rem}
.val-ok{background:#dcfce7;color:#166534}
.val-err{background:#fee2e2;color:#991b1b}
.char-count{font-size:.72rem;color:#9ca3af}
.length-table td,.length-table th{font-size:.82rem;padding:.3rem .6rem}
pre.rules-pre{background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:.75rem 1rem;font-size:.8rem;white-space:pre-wrap;word-break:break-word;max-height:250px;overflow-y:auto}
.tabs{display:flex;gap:0;border-bottom:2px solid #e2e8f0;margin-bottom:1rem}
.tab-btn{padding:.5rem 1rem;border:none;background:none;cursor:pointer;font-size:.875rem;color:#6b7280;border-bottom:3px solid transparent;margin-bottom:-2px;font-weight:600;transition:color .2s}
.tab-btn.active{color:#667eea;border-bottom-color:#667eea}
.tab-panel{display:none}.tab-panel.active{display:block}
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

    <!-- Metadata card -->
    <div class="card">
        <h2>Метаданные</h2>
        <div class="meta-grid">
            <div class="meta-item"><div class="label">Тема</div><div class="value"><?= $topic ?></div></div>
            <div class="meta-item"><div class="label">Запрошено</div><div class="value"><?= $reqCount ?></div></div>
            <div class="meta-item"><div class="label">Получено</div><div class="value"><?= $recCount ?></div></div>
            <div class="meta-item"><div class="label">Язык</div><div class="value"><?= strtoupper($language) ?></div></div>
            <div class="meta-item"><div class="label">Провайдер</div><div class="value"><?= $provider ?></div></div>
            <div class="meta-item"><div class="label">Модель</div><div class="value" style="font-size:.8rem"><?= $model ?></div></div>
            <div class="meta-item"><div class="label">Создано</div><div class="value"><?= $createdAt ?></div></div>
            <?php if (!empty($summary)): ?>
            <div class="meta-item">
                <div class="label">Валидация</div>
                <div class="value">
                    <span class="val-badge val-ok"><?= (int)($summary['valid'] ?? 0) ?> ✓</span>
                    <?php if (($summary['invalid'] ?? 0) > 0): ?>
                    <span class="val-badge val-err"><?= (int)$summary['invalid'] ?> ✗</span>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Tabs -->
    <div class="card" style="padding-top:1rem">
        <div class="tabs">
            <button class="tab-btn active" onclick="showTab('items')">📄 Элементы (<?= count($items) ?>)</button>
            <button class="tab-btn" onclick="showTab('length')">📏 Настройки длины</button>
            <button class="tab-btn" onclick="showTab('rules')">📝 Правила</button>
        </div>

        <!-- Items tab -->
        <div id="tab-items" class="tab-panel active">
        <?php if (empty($items)): ?>
            <p style="color:#64748b">Нет элементов.</p>
        <?php else: ?>
            <?php foreach ($items as $idx => $item): ?>
            <?php if (!is_array($item)) continue; ?>
            <?php
                $val     = $item['validation'] ?? [];
                $isValid = $val['valid_length'] ?? true;
                $errors  = $val['errors'] ?? [];
            ?>
            <div class="item-card <?= $isValid ? '' : 'invalid' ?>">
                <h4>
                    #<?= (int)($item['id'] ?? ($idx + 1)) ?>
                    <?php if ($isValid): ?>
                        <span class="val-badge val-ok">✓ OK</span>
                    <?php else: ?>
                        <span class="val-badge val-err">✗ Ошибка длины</span>
                    <?php endif; ?>
                </h4>

                <?php foreach (['title', 'short_description', 'description'] as $f): ?>
                <?php if (!isset($item[$f])) continue; ?>
                <?php
                    $fVal   = (string)$item[$f];
                    $fChars = mb_strlen($fVal, 'UTF-8');
                    $fKey   = $f . '_chars';
                ?>
                <div class="item-field">
                    <span class="field-label"><?= htmlspecialchars($f, ENT_QUOTES, 'UTF-8') ?></span>
                    <span class="char-count">(<?= $fChars ?> зн.)</span><br>
                    <span class="field-val"><?= htmlspecialchars($fVal, ENT_QUOTES, 'UTF-8') ?></span>
                </div>
                <?php endforeach; ?>

                <?php if (isset($item['tags']) && is_array($item['tags'])): ?>
                <div class="item-field">
                    <span class="field-label">tags</span>
                    <span class="field-val"><?= htmlspecialchars(implode(', ', $item['tags']), ENT_QUOTES, 'UTF-8') ?></span>
                </div>
                <?php endif; ?>

                <?php if (!empty($errors)): ?>
                <div style="margin-top:.5rem">
                    <?php foreach ($errors as $err): ?>
                    <div style="font-size:.75rem;color:#b91c1c;background:#fef2f2;border-radius:4px;padding:.2rem .5rem;margin-bottom:.2rem">
                        ⚠ <?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
        </div>

        <!-- Length settings tab -->
        <div id="tab-length" class="tab-panel">
        <?php if (empty($lengthSets)): ?>
            <p style="color:#64748b">Настройки длины не указаны.</p>
        <?php else: ?>
            <table class="length-table">
                <thead>
                    <tr>
                        <th>Поле</th>
                        <th>Режим</th>
                        <th>Точно</th>
                        <th>Мин</th>
                        <th>Макс</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach (['title', 'short_description', 'description'] as $f): ?>
                <?php if (!isset($lengthSets[$f])) continue; ?>
                <?php $r = $lengthSets[$f]; ?>
                <tr>
                    <td><strong><?= htmlspecialchars($f, ENT_QUOTES, 'UTF-8') ?></strong></td>
                    <td><?= htmlspecialchars($r['mode'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= isset($r['exact']) ? (int)$r['exact'] : '—' ?></td>
                    <td><?= isset($r['min']) ? (int)$r['min'] : '—' ?></td>
                    <td><?= isset($r['max']) ? (int)$r['max'] : '—' ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php if (isset($lengthSets['allow_length_tolerance'])): ?>
            <p style="margin-top:.75rem;font-size:.83rem;color:#4b5563">
                Допуск (tolerance):
                <strong><?= $lengthSets['allow_length_tolerance'] ? 'Да' : 'Нет' ?></strong>
                <?php if (!empty($lengthSets['tolerance_percent'])): ?>
                (<?= (int)$lengthSets['tolerance_percent'] ?>%)
                <?php endif; ?>
            </p>
            <?php endif; ?>
        <?php endif; ?>
        </div>

        <!-- Rules tab -->
        <div id="tab-rules" class="tab-panel">
        <?php if ($opRules !== ''): ?>
            <h3 style="font-size:.85rem;font-weight:700;color:#374151;margin-bottom:.5rem">Правила оператора</h3>
            <pre class="rules-pre"><?= htmlspecialchars($opRules, ENT_QUOTES, 'UTF-8') ?></pre>
        <?php else: ?>
            <p style="color:#64748b">Правила оператора не указаны.</p>
        <?php endif; ?>
        </div>
    </div>
</main>

<script>
function showTab(name) {
    document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.getElementById('tab-' + name).classList.add('active');
    event.target.classList.add('active');
}
</script>
</body>
</html>
