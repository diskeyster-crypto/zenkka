<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

Auth::requireAuth();

$config    = JsonStore::readConfig();
$appName   = htmlspecialchars($config['app_name'] ?? 'Zenkka CMS', ENT_QUOTES, 'UTF-8');
$username  = htmlspecialchars(Auth::getUsername(), ENT_QUOTES, 'UTF-8');

$result    = null;
$genError  = '';
$submitted = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!Csrf::validate($token)) {
        $genError = 'Invalid CSRF token.';
    } else {
        $submitted = true;
        try {
            $topic       = Validator::str($_POST['topic'] ?? '', 1, 500);
            $count       = Validator::int($_POST['count'] ?? 10, 1, (int)($config['max_variants_per_request'] ?? 1000));
            $language    = Validator::inList($_POST['language'] ?? 'ru', ['ru', 'en', 'de', 'fr', 'es', 'zh']);
            $format      = Validator::inList($_POST['format'] ?? 'title', ['title', 'short', 'extended']);
            $temperature = Validator::float($_POST['temperature'] ?? 0.7, 0.0, 2.0);

            $provider = $config['ai_provider'] ?? 'openrouter';
            if ($provider === 'gemini') {
                $client = new GeminiClient(
                    $config['gemini_api_key'] ?? '',
                    'gemini-1.5-flash'
                );
            } else {
                $client = new OpenRouterClient(
                    $config['openrouter_api_key'] ?? '',
                    $config['openrouter_model'] ?? 'openrouter/auto'
                );
            }

            $service = new GenerationService($client, $config);
            $result  = $service->generate([
                'topic'       => $topic,
                'count'       => $count,
                'language'    => $language,
                'format'      => $format,
                'temperature' => $temperature,
            ]);
        } catch (InvalidArgumentException $e) {
            $genError = 'Validation error: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
        } catch (RuntimeException $e) {
            $genError = 'Generation error: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
            Logger::error('Generation failed in admin', ['error' => $e->getMessage()]);
        }
    }
}

$csrfField  = Csrf::field();
$maxCount   = (int)($config['max_variants_per_request'] ?? 1000);
$currentPage = 'index';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Генерация — <?= $appName ?></title>
<?php include __DIR__ . '/partials/admin_styles.php'; ?>
</head>
<body>
<?php include __DIR__ . '/partials/sidebar.php'; ?>

<main class="main-content">
    <div class="page-header">
        <h1>✨ Генерация контента</h1>
        <p class="text-muted">Создание наборов данных с помощью ИИ</p>
    </div>

    <?php if ($genError !== ''): ?>
    <div class="alert alert-error"><?= $genError ?></div>
    <?php endif; ?>

    <?php if ($result !== null): ?>
    <div class="alert alert-success">
        ✅ Генерация завершена! Получено <strong><?= (int)$result['received_count'] ?></strong> из <strong><?= (int)$result['requested_count'] ?></strong> элементов.
        <div style="margin-top:.75rem;display:flex;gap:.75rem;flex-wrap:wrap">
            <a href="/admin/view.php?id=<?= htmlspecialchars($result['id'], ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-outline">👁 Просмотр</a>
            <a href="/admin/download.php?id=<?= htmlspecialchars($result['id'], ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-outline">⬇ Скачать JSON</a>
            <a href="/admin/index.php" class="btn btn-sm btn-primary">+ Новая генерация</a>
        </div>
    </div>
    <?php endif; ?>

    <div class="card">
        <form method="POST" action="/admin/index.php" id="genForm">
            <?= $csrfField ?>

            <div class="form-row">
                <div class="form-group" style="flex:1">
                    <label for="topic">Тема</label>
                    <input type="text" id="topic" name="topic" placeholder="Например: рецепты пиццы" required maxlength="500">
                    <p class="hint">Опишите тему для генерации.</p>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="count">Количество элементов</label>
                    <input type="number" id="count" name="count" value="10" min="1" max="<?= $maxCount ?>" required>
                    <p class="hint">Макс: <?= $maxCount ?></p>
                </div>
                <div class="form-group">
                    <label for="language">Язык</label>
                    <select id="language" name="language">
                        <option value="ru">Русский</option>
                        <option value="en">English</option>
                        <option value="de">Deutsch</option>
                        <option value="fr">Français</option>
                        <option value="es">Español</option>
                        <option value="zh">中文</option>
                    </select>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="format">Формат</label>
                    <select id="format" name="format">
                        <option value="title">Только заголовок</option>
                        <option value="short">Заголовок + краткое описание</option>
                        <option value="extended">Заголовок + описание + текст</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="temperature">Температура: <span id="tempVal">0.7</span></label>
                    <input type="range" id="temperature" name="temperature" min="0" max="2" step="0.1" value="0.7"
                           oninput="document.getElementById('tempVal').textContent=this.value">
                    <p class="hint">0 = точный, 2 = творческий</p>
                </div>
            </div>

            <button type="submit" class="btn btn-primary" id="submitBtn">
                <span id="btnText">🚀 Сгенерировать</span>
                <span id="btnSpinner" style="display:none">⏳ Генерация...</span>
            </button>
        </form>
    </div>
</main>

<script>
document.getElementById('genForm').addEventListener('submit', function() {
    document.getElementById('btnText').style.display    = 'none';
    document.getElementById('btnSpinner').style.display = 'inline';
    document.getElementById('submitBtn').disabled       = true;
});
</script>
</body>
</html>
