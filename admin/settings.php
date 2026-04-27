<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

Auth::requireAuth();

$config      = JsonStore::readConfig();
$appName     = htmlspecialchars($config['app_name'] ?? 'Zenkka CMS', ENT_QUOTES, 'UTF-8');
$currentPage = 'settings';

$success = '';
$errors  = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!Csrf::validate($token)) {
        $errors[] = 'Invalid CSRF token.';
    } else {
        try {
            $aiProvider  = Validator::inList($_POST['ai_provider'] ?? 'openrouter', ['openrouter', 'gemini']);
            $orModel     = trim($_POST['openrouter_model'] ?? 'openrouter/auto');
            $orApiKey    = trim($_POST['openrouter_api_key'] ?? '');
            $geminiKey   = trim($_POST['gemini_api_key'] ?? '');
            $maxVariants = Validator::int($_POST['max_variants_per_request'] ?? 200, 1, 10000);
            $chunkSize   = Validator::int($_POST['chunk_size'] ?? 10, 1, 100);

            // Keep existing keys if fields left blank
            if ($orApiKey === '') {
                $orApiKey = $config['openrouter_api_key'] ?? '';
            }
            if ($geminiKey === '') {
                $geminiKey = $config['gemini_api_key'] ?? '';
            }
            if ($orModel === '') {
                $orModel = 'openrouter/auto';
            }

            $config['ai_provider']              = $aiProvider;
            $config['openrouter_api_key']        = $orApiKey;
            $config['openrouter_model']          = $orModel;
            $config['gemini_api_key']            = $geminiKey;
            $config['max_variants_per_request']  = $maxVariants;
            $config['chunk_size']                = $chunkSize;

            JsonStore::write(STORAGE_PATH . '/config.json', $config);
            Logger::info('Settings updated', ['user' => Auth::getUsername()]);
            $success = 'Настройки сохранены.';
        } catch (InvalidArgumentException $e) {
            $errors[] = htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
        }
    }
}

// Reload config after possible save
$config = JsonStore::readConfig();

// Mask API keys — show last 4 chars only
function maskKey(string $key): string
{
    $len = mb_strlen($key);
    if ($len <= 4) {
        return str_repeat('*', $len);
    }
    return str_repeat('*', $len - 4) . mb_substr($key, -4);
}

$maskedOrKey     = maskKey($config['openrouter_api_key'] ?? '');
$maskedGeminiKey = maskKey($config['gemini_api_key'] ?? '');
$csrfField       = Csrf::field();

$selProvider = htmlspecialchars($config['ai_provider'] ?? 'openrouter', ENT_QUOTES, 'UTF-8');
$orModel     = htmlspecialchars($config['openrouter_model'] ?? 'openrouter/auto', ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Настройки — <?= $appName ?></title>
<?php include __DIR__ . '/partials/admin_styles.php'; ?>
</head>
<body>
<?php include __DIR__ . '/partials/sidebar.php'; ?>

<main class="main-content">
    <div class="page-header">
        <h1>⚙️ Настройки</h1>
        <p class="text-muted">Конфигурация CMS и AI-провайдеров</p>
    </div>

    <?php if ($success !== ''): ?>
    <div class="alert alert-success">✅ <?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
    <div class="alert alert-error">
        <?php foreach ($errors as $err): ?>
        <p>• <?= $err ?></p>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="card">
        <h2>AI-провайдер</h2>
        <form method="POST" action="/admin/settings.php">
            <?= $csrfField ?>

            <div class="form-group">
                <label for="ai_provider">Провайдер</label>
                <select id="ai_provider" name="ai_provider" onchange="toggleProvider(this.value)">
                    <option value="openrouter" <?= $selProvider === 'openrouter' ? 'selected' : '' ?>>OpenRouter</option>
                    <option value="gemini" <?= $selProvider === 'gemini' ? 'selected' : '' ?>>Google Gemini</option>
                </select>
            </div>

            <div id="openrouter_section">
                <div class="form-group">
                    <label for="openrouter_api_key">OpenRouter API Key</label>
                    <input type="password" id="openrouter_api_key" name="openrouter_api_key"
                           placeholder="Текущий ключ: <?= htmlspecialchars($maskedOrKey, ENT_QUOTES, 'UTF-8') ?> (оставьте пустым, чтобы не менять)">
                    <p class="hint">Текущий ключ заканчивается на: <code><?= htmlspecialchars(substr($config['openrouter_api_key'] ?? '', -4) ?: '—', ENT_QUOTES, 'UTF-8') ?></code></p>
                </div>
                <div class="form-group">
                    <label for="openrouter_model">OpenRouter Model</label>
                    <input type="text" id="openrouter_model" name="openrouter_model" value="<?= $orModel ?>">
                </div>
            </div>

            <div id="gemini_section" style="<?= $selProvider !== 'gemini' ? 'display:none' : '' ?>">
                <div class="form-group">
                    <label for="gemini_api_key">Gemini API Key</label>
                    <input type="password" id="gemini_api_key" name="gemini_api_key"
                           placeholder="Текущий ключ: <?= htmlspecialchars($maskedGeminiKey, ENT_QUOTES, 'UTF-8') ?> (оставьте пустым, чтобы не менять)">
                    <p class="hint">Текущий ключ заканчивается на: <code><?= htmlspecialchars(substr($config['gemini_api_key'] ?? '', -4) ?: '—', ENT_QUOTES, 'UTF-8') ?></code></p>
                </div>
            </div>

            <h2 style="margin-top:1.5rem">Параметры генерации</h2>
            <div class="form-row">
                <div class="form-group">
                    <label for="max_variants_per_request">Макс. вариантов на запрос</label>
                    <input type="number" id="max_variants_per_request" name="max_variants_per_request"
                           value="<?= (int)($config['max_variants_per_request'] ?? 200) ?>" min="1" max="10000">
                </div>
                <div class="form-group">
                    <label for="chunk_size">Размер чанка</label>
                    <input type="number" id="chunk_size" name="chunk_size"
                           value="<?= (int)($config['chunk_size'] ?? 10) ?>" min="1" max="100">
                    <p class="hint">Количество элементов за один запрос к AI.</p>
                </div>
            </div>

            <button type="submit" class="btn btn-primary">💾 Сохранить настройки</button>
        </form>
    </div>

    <div class="card">
        <h2>О системе</h2>
        <table>
            <tr><td style="width:200px;color:#6b7280">PHP Version</td><td><?= htmlspecialchars(PHP_VERSION, ENT_QUOTES, 'UTF-8') ?></td></tr>
            <tr><td style="color:#6b7280">Установлено</td><td><?= htmlspecialchars($config['installed_at'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td></tr>
            <tr><td style="color:#6b7280">Хранилище</td><td><code><?= htmlspecialchars(STORAGE_PATH, ENT_QUOTES, 'UTF-8') ?></code></td></tr>
        </table>
    </div>
</main>

<script>
function toggleProvider(val) {
    document.getElementById('openrouter_section').style.display = val === 'openrouter' ? '' : 'none';
    document.getElementById('gemini_section').style.display     = val === 'gemini'      ? '' : 'none';
}
</script>
</body>
</html>
