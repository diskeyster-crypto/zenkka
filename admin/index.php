<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

Auth::requireAuth();

$config    = JsonStore::readConfig();
$appName   = htmlspecialchars($config['app_name'] ?? 'Zenkka CMS', ENT_QUOTES, 'UTF-8');

$result    = null;
$genError  = '';

$defaultTemplate = $config['default_prompt_template'] ?? PromptBuilder::DEFAULT_TEMPLATE;

// ── Full language list ────────────────────────────────────────────────────────
$allLanguages = [
    'ru' => 'Русский',            'en' => 'English',
    'de' => 'Deutsch',            'fr' => 'Français',
    'es' => 'Español',            'it' => 'Italiano',
    'pt' => 'Português',          'pl' => 'Polski',
    'uk' => 'Українська',         'be' => 'Беларуская',
    'kk' => 'Қазақша',            'tr' => 'Türkçe',
    'ar' => 'العربية',             'he' => 'עברית',
    'fa' => 'فارسی',              'hi' => 'हिन्दी',
    'zh' => '中文',               'ja' => '日本語',
    'ko' => '한국어',              'nl' => 'Nederlands',
    'sv' => 'Svenska',            'no' => 'Norsk',
    'da' => 'Dansk',              'fi' => 'Suomi',
    'cs' => 'Čeština',            'sk' => 'Slovenčina',
    'ro' => 'Română',             'bg' => 'Български',
    'sr' => 'Српски',             'hr' => 'Hrvatski',
    'sl' => 'Slovenščina',        'el' => 'Ελληνικά',
    'hu' => 'Magyar',             'lt' => 'Lietuvių',
    'lv' => 'Latviešu',           'et' => 'Eesti',
    'vi' => 'Tiếng Việt',         'th' => 'ไทย',
    'id' => 'Bahasa Indonesia',   'ms' => 'Bahasa Melayu',
    'custom' => '— Другой язык (ввести вручную) —',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!Csrf::validate($token)) {
        $genError = 'Invalid CSRF token.';
    } else {
        try {
            $topic       = Validator::str($_POST['topic'] ?? '', 1, 500);
            $count       = Validator::int($_POST['count'] ?? 10, 1, (int)($config['max_variants_per_request'] ?? 200));
            $temperature = Validator::float($_POST['temperature'] ?? 0.7, 0.0, 2.0);

            // Language
            $langCode = trim($_POST['language'] ?? 'ru');
            if ($langCode === 'custom') {
                $langName = Validator::str($_POST['custom_language'] ?? '', 1, 100);
                $langCode = 'custom';
            } elseif (array_key_exists($langCode, $allLanguages)) {
                $langName = $allLanguages[$langCode];
            } else {
                throw new InvalidArgumentException('Недопустимый язык.');
            }

            $operatorRules  = trim($_POST['operator_rules'] ?? '');
            $promptTemplate = trim($_POST['prompt_template'] ?? '');
            if ($promptTemplate === '') {
                $promptTemplate = $defaultTemplate;
            }

            // Length settings
            $lengthSettings = [];
            foreach (['title', 'short_description', 'description'] as $field) {
                $mode = Validator::inList($_POST["length_{$field}_mode"] ?? 'range', ['exact', 'range']);
                if ($mode === 'exact') {
                    $lengthSettings[$field] = [
                        'mode'  => 'exact',
                        'exact' => Validator::int($_POST["length_{$field}_exact"] ?? 0, 0, 10000),
                    ];
                } else {
                    $minVal = Validator::int($_POST["length_{$field}_min"] ?? 0, 0, 10000);
                    $maxVal = Validator::int($_POST["length_{$field}_max"] ?? 0, 0, 10000);
                    if ($maxVal > 0 && $minVal > $maxVal) {
                        throw new InvalidArgumentException("Для поля {$field}: min не может быть больше max.");
                    }
                    $lengthSettings[$field] = ['mode' => 'range', 'min' => $minVal, 'max' => $maxVal];
                }
            }

            // Export settings
            $generationName    = trim($_POST['generation_name']    ?? '');
            $destinationFolder = trim($_POST['destination_folder'] ?? '');
            $outputFormat      = Validator::inList($_POST['output_format'] ?? 'json', ['json', 'txt', 'html', 'md', 'csv']);
            $outputMode        = Validator::inList($_POST['output_mode']   ?? 'single_file', ['single_file', 'file_per_item', 'both']);
            $filenameTemplate  = trim($_POST['filename_template']  ?? '');
            if ($filenameTemplate === '') {
                $filenameTemplate = '{generation_slug}_{num}';
            }

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
                'topic'              => $topic,
                'count'              => $count,
                'language'           => $langCode,
                'language_name'      => $langName,
                'temperature'        => $temperature,
                'length_settings'    => $lengthSettings,
                'operator_rules'     => $operatorRules,
                'prompt_template'    => $promptTemplate,
                'generation_name'    => $generationName,
                'destination_folder' => $destinationFolder,
                'output_format'      => $outputFormat,
                'output_mode'        => $outputMode,
                'filename_template'  => $filenameTemplate,
            ]);
        } catch (InvalidArgumentException $e) {
            $genError = 'Validation error: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
        } catch (RuntimeException $e) {
            $genError = 'Generation error: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
            Logger::error('Generation failed in admin', ['error' => $e->getMessage()]);
        }
    }
}

$csrfField   = Csrf::field();
$maxCount    = (int)($config['max_variants_per_request'] ?? 200);
$currentPage = 'index';

$exampleRules = <<<'RULES'
Напиши информативный текст на обиходном языке.

Задача:
Текст должен выглядеть естественно, как написанный человеком: живой, немного неровный, без ощущения рекламы или шаблона.

Содержание:
Кратко и понятно раскрыть:
- что это такое
- когда и зачем используется
- как применяется в общих чертах
- возможные ограничения, нюансы или побочные эффекты

Нативные упоминания:
Лёгко и ненавязчиво упомянуть, что товар может быть доступен в магазинах или онлайн.

Стиль:
- разговорный, но информативный
- без рекламных клише
- без агрессивных призывов к покупке
- без перегруженности терминами

Естественность:
- варьировать длину предложений
- не начинать все тексты одинаково
- избегать повторяющихся структур
- каждый текст должен отличаться по тону и структуре
- можно начинать с вопроса, ситуации, наблюдения или факта
RULES;
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Генерация — <?= $appName ?></title>
<?php include __DIR__ . '/partials/admin_styles.php'; ?>
<style>
.length-block{background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:1rem 1.25rem;margin-bottom:1rem}
.length-block h3{font-size:.85rem;font-weight:700;color:#374151;text-transform:uppercase;letter-spacing:.04em;margin-bottom:.75rem}
.length-row{display:flex;gap:.75rem;align-items:flex-start;flex-wrap:wrap}
.length-row .form-group{margin-bottom:0;flex:1;min-width:100px}
.exact-group,.range-group{display:flex;gap:.75rem;flex-wrap:wrap}
.mode-toggle select{font-size:.85rem;padding:.4rem .6rem}
.rules-block{background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:1rem 1.25rem;margin-top:1rem}
.rules-block h3{font-size:.85rem;font-weight:700;color:#92400e;text-transform:uppercase;letter-spacing:.04em;margin-bottom:.75rem}
textarea.rules-ta{width:100%;min-height:200px;font-size:.85rem;font-family:monospace;line-height:1.5;border:1px solid #d1d5db;border-radius:6px;padding:.6rem .8rem;resize:vertical}
textarea.rules-ta:focus{outline:none;border-color:#667eea;box-shadow:0 0 0 3px rgba(102,126,234,.2)}
.template-block{background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:1rem 1.25rem;margin-top:1rem}
.template-block h3{font-size:.85rem;font-weight:700;color:#166534;text-transform:uppercase;letter-spacing:.04em;margin-bottom:.75rem}
.template-vars{font-size:.75rem;color:#6b7280;background:#fff;border:1px solid #e5e7eb;border-radius:4px;padding:.5rem .75rem;margin-bottom:.75rem;line-height:1.8}
textarea.tpl-ta{width:100%;min-height:300px;font-size:.82rem;font-family:monospace;line-height:1.5;border:1px solid #d1d5db;border-radius:6px;padding:.6rem .8rem;resize:vertical}
textarea.tpl-ta:focus{outline:none;border-color:#22c55e;box-shadow:0 0 0 3px rgba(34,197,94,.2)}
.section-title{font-size:1rem;font-weight:700;color:#1e293b;margin:1.5rem 0 .75rem;padding-bottom:.4rem;border-bottom:2px solid #e2e8f0}
.export-block{background:#f0f4ff;border:1px solid #c7d2fe;border-radius:8px;padding:1rem 1.25rem;margin-top:1rem}
.export-block h3{font-size:.85rem;font-weight:700;color:#3730a3;text-transform:uppercase;letter-spacing:.04em;margin-bottom:.75rem}
.hint-vars{font-size:.73rem;color:#6b7280;background:#fff;border:1px dashed #c7d2fe;border-radius:4px;padding:.4rem .7rem;margin-top:.3rem;line-height:1.8}
.file-list{list-style:none;padding:0;margin:.5rem 0 0}
.file-list li{font-size:.82rem;padding:.25rem 0;border-bottom:1px solid #f1f5f9}
.file-list li:last-child{border-bottom:none}
.file-list code{color:#4f46e5;font-size:.8rem}
</style>
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
    <?php
        $summary      = $result['validation_summary'] ?? [];
        $invalid      = (int)($summary['invalid'] ?? 0);
        $exportFiles  = $result['files'] ?? [];
        $exportError  = $result['export_error'] ?? '';
        $genNameDisp  = htmlspecialchars($result['generation_name'] ?? '', ENT_QUOTES, 'UTF-8');
        $destDisp     = htmlspecialchars($result['destination_folder'] ?? '', ENT_QUOTES, 'UTF-8');
        $fmtDisp      = htmlspecialchars($result['output_format'] ?? '', ENT_QUOTES, 'UTF-8');
        $modeDisp     = htmlspecialchars($result['output_mode'] ?? '', ENT_QUOTES, 'UTF-8');
    ?>
    <div class="alert alert-success">
        ✅ Генерация завершена!
        Имя: <strong><?= $genNameDisp ?></strong> |
        Получено: <strong><?= (int)$result['received_count'] ?></strong> / <strong><?= (int)$result['requested_count'] ?></strong>
        <?php if ($invalid > 0): ?>
        <br><span style="color:#b45309">⚠️ Элементов с ошибками длины: <strong><?= $invalid ?></strong></span>
        <?php endif; ?>

        <?php if ($exportError !== ''): ?>
        <br><span style="color:#b91c1c">⛔ Ошибка экспорта: <?= htmlspecialchars($exportError, ENT_QUOTES, 'UTF-8') ?></span>
        <?php elseif (!empty($exportFiles)): ?>
        <br>
        <strong>📁 Папка:</strong> <code>storage/exports/<?= $destDisp ?></code> |
        <strong>Формат:</strong> <?= $fmtDisp ?> |
        <strong>Режим:</strong> <?= $modeDisp ?> |
        <strong>Файлов создано:</strong> <?= count($exportFiles) ?>
        <ul class="file-list">
            <?php foreach (array_slice($exportFiles, 0, 10) as $f): ?>
            <li>📄 <code><?= htmlspecialchars($f['path'] ?? $f['filename'] ?? '', ENT_QUOTES, 'UTF-8') ?></code></li>
            <?php endforeach; ?>
            <?php if (count($exportFiles) > 10): ?>
            <li style="color:#6b7280">… и ещё <?= count($exportFiles) - 10 ?> файлов</li>
            <?php endif; ?>
        </ul>
        <?php endif; ?>

        <div style="margin-top:.75rem;display:flex;gap:.75rem;flex-wrap:wrap">
            <a href="/admin/view.php?id=<?= htmlspecialchars($result['id'], ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-outline">👁 Просмотр</a>
            <a href="/admin/download.php?id=<?= htmlspecialchars($result['id'], ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-outline">⬇ Скачать JSON</a>
            <?php if (!empty($exportFiles)): ?>
            <a href="/admin/download_zip.php?id=<?= htmlspecialchars($result['id'], ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-outline">📦 Скачать ZIP</a>
            <?php endif; ?>
            <a href="/admin/index.php" class="btn btn-sm btn-primary">+ Новая генерация</a>
        </div>
    </div>
    <?php endif; ?>

    <div class="card">
        <form method="POST" action="/admin/index.php" id="genForm">
            <?= $csrfField ?>

            <!-- ── 1. Тема и количество ────────────────────────────── -->
            <div class="section-title">📌 Тема и количество</div>

            <div class="form-row">
                <div class="form-group" style="flex:2">
                    <label for="topic">Тема генерации</label>
                    <input type="text" id="topic" name="topic"
                           placeholder="Например: рецепты пиццы" required maxlength="500"
                           value="<?= htmlspecialchars($_POST['topic'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="form-group">
                    <label for="count">Количество вариантов</label>
                    <input type="number" id="count" name="count"
                           value="<?= (int)($_POST['count'] ?? 10) ?>"
                           min="1" max="<?= $maxCount ?>" required>
                    <p class="hint">Макс: <?= $maxCount ?></p>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="language">Язык</label>
                    <select id="language" name="language" onchange="toggleCustomLang(this.value)">
                        <?php
                        $selLang = $_POST['language'] ?? 'ru';
                        foreach ($allLanguages as $code => $label):
                        ?>
                        <option value="<?= htmlspecialchars($code, ENT_QUOTES, 'UTF-8') ?>"
                            <?= $selLang === $code ? 'selected' : '' ?>>
                            <?= htmlspecialchars("{$code} — {$label}", ENT_QUOTES, 'UTF-8') ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" id="custom_lang_group" style="<?= ($selLang !== 'custom') ? 'display:none' : '' ?>">
                    <label for="custom_language">Свой язык</label>
                    <input type="text" id="custom_language" name="custom_language"
                           placeholder="Например: Georgian, Armenian…"
                           value="<?= htmlspecialchars($_POST['custom_language'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="form-group">
                    <label for="temperature">Температура: <span id="tempVal"><?= htmlspecialchars($_POST['temperature'] ?? '0.7', ENT_QUOTES, 'UTF-8') ?></span></label>
                    <input type="range" id="temperature" name="temperature"
                           min="0" max="2" step="0.1"
                           value="<?= htmlspecialchars($_POST['temperature'] ?? '0.7', ENT_QUOTES, 'UTF-8') ?>"
                           oninput="document.getElementById('tempVal').textContent=this.value">
                    <p class="hint">0 = точный, 2 = творческий</p>
                </div>
            </div>

            <!-- ── 2. Настройки длины ─────────────────────────────── -->
            <div class="section-title">📏 Настройки длины текста</div>

            <?php
            $lengthDefaults = [
                'title'             => ['mode' => 'range', 'exact' => 55,  'min' => 40,  'max' => 70],
                'short_description' => ['mode' => 'range', 'exact' => 150, 'min' => 120, 'max' => 180],
                'description'       => ['mode' => 'range', 'exact' => 250, 'min' => 200, 'max' => 300],
            ];
            $fieldLabels = ['title' => 'Title', 'short_description' => 'Short Description', 'description' => 'Description'];
            foreach (['title', 'short_description', 'description'] as $field):
                $def  = $lengthDefaults[$field];
                $mode = $_POST["length_{$field}_mode"] ?? $def['mode'];
            ?>
            <div class="length-block">
                <h3><?= $fieldLabels[$field] ?></h3>
                <div class="length-row">
                    <div class="form-group mode-toggle">
                        <label>Режим</label>
                        <select name="length_<?= $field ?>_mode" id="mode_<?= $field ?>"
                                onchange="toggleLengthMode('<?= $field ?>', this.value)">
                            <option value="range" <?= $mode === 'range' ? 'selected' : '' ?>>Диапазон от/до</option>
                            <option value="exact" <?= $mode === 'exact' ? 'selected' : '' ?>>Точное количество знаков</option>
                        </select>
                    </div>
                    <div id="exact_<?= $field ?>" class="exact-group" style="<?= $mode !== 'exact' ? 'display:none' : '' ?>">
                        <div class="form-group">
                            <label>Ровно знаков</label>
                            <input type="number" name="length_<?= $field ?>_exact"
                                   value="<?= (int)($_POST["length_{$field}_exact"] ?? $def['exact']) ?>"
                                   min="0" max="10000" style="width:100px">
                        </div>
                    </div>
                    <div id="range_<?= $field ?>" class="range-group" style="<?= $mode === 'exact' ? 'display:none' : '' ?>">
                        <div class="form-group">
                            <label>Минимум знаков</label>
                            <input type="number" name="length_<?= $field ?>_min"
                                   value="<?= (int)($_POST["length_{$field}_min"] ?? $def['min']) ?>"
                                   min="0" max="10000" style="width:100px">
                        </div>
                        <div class="form-group">
                            <label>Максимум знаков</label>
                            <input type="number" name="length_<?= $field ?>_max"
                                   value="<?= (int)($_POST["length_{$field}_max"] ?? $def['max']) ?>"
                                   min="0" max="10000" style="width:100px">
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>

            <!-- ── 3. Rules ───────────────────────────────────────── -->
            <div class="rules-block">
                <h3>📝 Правила генерации (operator rules)</h3>
                <div style="display:flex;gap:.5rem;margin-bottom:.5rem">
                    <button type="button" class="btn btn-sm btn-outline" onclick="insertExampleRules()">📋 Вставить пример</button>
                    <button type="button" class="btn btn-sm btn-outline" onclick="document.getElementById('operator_rules').value=''">🗑 Очистить</button>
                </div>
                <textarea id="operator_rules" name="operator_rules" class="rules-ta"
                          placeholder="Требования к стилю, тону, структуре, SEO и т.д."><?= htmlspecialchars($_POST['operator_rules'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
            </div>

            <!-- ── 4. Prompt template ─────────────────────────────── -->
            <div class="template-block">
                <h3>🔧 Шаблон промта</h3>
                <div class="template-vars">
                    Доступные переменные:
                    <code>[topic]</code> <code>[count]</code> <code>[language]</code>
                    <code>[title_length_rules]</code> <code>[short_description_length_rules]</code>
                    <code>[description_length_rules]</code> <code>[operator_rules]</code> <code>[output_schema]</code>
                </div>
                <textarea id="prompt_template" name="prompt_template" class="tpl-ta"><?= htmlspecialchars($_POST['prompt_template'] ?? $defaultTemplate, ENT_QUOTES, 'UTF-8') ?></textarea>
                <p class="hint">Оставьте без изменений, чтобы использовать шаблон по умолчанию.</p>
            </div>

            <!-- ── 5. Файлы и экспорт ──────────────────────────────── -->
            <div class="section-title">📁 Файлы и экспорт</div>

            <div class="export-block">
                <h3>🗂 Настройки экспорта</h3>

                <div class="form-row">
                    <div class="form-group" style="flex:2">
                        <label for="generation_name">Имя генерации</label>
                        <input type="text" id="generation_name" name="generation_name" maxlength="200"
                               placeholder="Например: VPN статьи апрель"
                               value="<?= htmlspecialchars($_POST['generation_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                        <p class="hint">Произвольное название. Если пусто — будет автоимя.</p>
                    </div>
                    <div class="form-group" style="flex:2">
                        <label for="destination_folder">Папка назначения</label>
                        <input type="text" id="destination_folder" name="destination_folder" maxlength="200"
                               placeholder="Например: exports/vpn или blog/articles"
                               value="<?= htmlspecialchars($_POST['destination_folder'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                        <p class="hint">Относительно <code>storage/exports/</code>. Запрещены ../ и абс. пути.</p>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="output_format">Формат выходного файла</label>
                        <select id="output_format" name="output_format">
                            <?php
                            $formats   = ['json' => 'JSON', 'txt' => 'TXT (текст)', 'html' => 'HTML', 'md' => 'Markdown (MD)', 'csv' => 'CSV'];
                            $selFormat = $_POST['output_format'] ?? 'json';
                            foreach ($formats as $fVal => $fLabel):
                            ?>
                            <option value="<?= $fVal ?>" <?= $selFormat === $fVal ? 'selected' : '' ?>><?= $fLabel ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="output_mode">Режим сохранения</label>
                        <select id="output_mode" name="output_mode">
                            <?php
                            $modes   = ['single_file' => 'Один общий файл', 'file_per_item' => '1 файл = 1 текст', 'both' => 'Оба варианта'];
                            $selMode = $_POST['output_mode'] ?? 'single_file';
                            foreach ($modes as $mVal => $mLabel):
                            ?>
                            <option value="<?= $mVal ?>" <?= $selMode === $mVal ? 'selected' : '' ?>><?= $mLabel ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label for="filename_template">Шаблон имени файла</label>
                    <input type="text" id="filename_template" name="filename_template" maxlength="200"
                           placeholder="Например: запрос_{num} или {topic_slug}_{date}_{num}"
                           value="<?= htmlspecialchars($_POST['filename_template'] ?? '{generation_slug}_{num}', ENT_QUOTES, 'UTF-8') ?>">
                    <div class="hint-vars">
                        Переменные:
                        <code>{generation_name}</code> <code>{generation_slug}</code>
                        <code>{topic}</code> <code>{topic_slug}</code>
                        <code>{id}</code> <code>{num}</code>
                        <code>{date}</code> <code>{time}</code> <code>{datetime}</code>
                        <code>{format}</code> <code>{lang}</code>
                        <br>Расширение добавляется автоматически по выбранному формату.
                    </div>
                </div>
            </div>

            <!-- ── Submit ─────────────────────────────────────────── -->
            <div style="margin-top:1.5rem">
                <button type="submit" class="btn btn-primary" id="submitBtn">
                    <span id="btnText">🚀 Сгенерировать</span>
                    <span id="btnSpinner" style="display:none">⏳ Генерация...</span>
                </button>
            </div>
        </form>
    </div>
</main>

<script>
function toggleLengthMode(field, mode) {
    document.getElementById('exact_' + field).style.display = mode === 'exact' ? '' : 'none';
    document.getElementById('range_' + field).style.display = mode === 'range' ? '' : 'none';
}

function toggleCustomLang(val) {
    document.getElementById('custom_lang_group').style.display = val === 'custom' ? '' : 'none';
}

var exampleRulesText = <?= json_encode($exampleRules, JSON_UNESCAPED_UNICODE) ?>;
function insertExampleRules() {
    document.getElementById('operator_rules').value = exampleRulesText;
}

document.getElementById('genForm').addEventListener('submit', function() {
    document.getElementById('btnText').style.display    = 'none';
    document.getElementById('btnSpinner').style.display = 'inline';
    document.getElementById('submitBtn').disabled       = true;
});
</script>
</body>
</html>
