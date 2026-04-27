<?php
declare(strict_types=1);

// Standalone installer — does NOT require bootstrap.php
if (session_status() === PHP_SESSION_NONE) {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
               || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

define('BASE_PATH',    __DIR__);
define('STORAGE_PATH', BASE_PATH . '/storage');

// ── CSRF helpers (inline, no dependency) ───────────────────────────────────
function install_csrf_token(): string
{
    if (empty($_SESSION['install_csrf'])) {
        $_SESSION['install_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['install_csrf'];
}

function install_csrf_valid(string $token): bool
{
    $stored = $_SESSION['install_csrf'] ?? '';
    return $stored !== '' && hash_equals($stored, $token);
}

// ── Guard: already installed ────────────────────────────────────────────────
if (file_exists(STORAGE_PATH . '/config.json')) {
    $msg = htmlspecialchars('Already installed. Please delete install.php before going to production.', ENT_QUOTES, 'UTF-8');
    echo <<<HTML
    <!DOCTYPE html><html><head><meta charset="UTF-8"><title>Installed</title>
    <style>body{font-family:sans-serif;display:flex;align-items:center;justify-content:center;height:100vh;background:#f0f2f5;}
    .card{background:#fff;padding:2rem 3rem;border-radius:8px;box-shadow:0 2px 12px rgba(0,0,0,.15);text-align:center;}
    h2{color:#e74c3c;}</style></head><body>
    <div class="card"><h2>⚠️ Already Installed</h2><p>{$msg}</p></div></body></html>
    HTML;
    exit;
}

// ── PHP version check ───────────────────────────────────────────────────────
if (PHP_VERSION_ID < 80100) {
    $v = htmlspecialchars(PHP_VERSION, ENT_QUOTES, 'UTF-8');
    echo <<<HTML
    <!DOCTYPE html><html><head><meta charset="UTF-8"><title>Unsupported PHP</title>
    <style>body{font-family:sans-serif;display:flex;align-items:center;justify-content:center;height:100vh;background:#f0f2f5;}
    .card{background:#fff;padding:2rem 3rem;border-radius:8px;box-shadow:0 2px 12px rgba(0,0,0,.15);text-align:center;}
    h2{color:#e74c3c;}</style></head><body>
    <div class="card"><h2>⚠️ Unsupported PHP Version</h2>
    <p>PHP 8.1+ is required. Current version: <strong>{$v}</strong></p></div></body></html>
    HTML;
    exit;
}

$errors  = [];
$success = false;

// ── POST handler ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!install_csrf_valid($token)) {
        $errors[] = 'Invalid CSRF token. Please reload and try again.';
    } else {
        // Collect & validate
        $appName          = trim($_POST['app_name'] ?? '');
        $adminUser        = trim($_POST['admin_username'] ?? '');
        $adminPass        = $_POST['admin_password'] ?? '';
        $confirmPass      = $_POST['confirm_password'] ?? '';
        $orApiKey         = trim($_POST['openrouter_api_key'] ?? '');
        $orModel          = trim($_POST['openrouter_model'] ?? 'openrouter/auto');
        $aiProvider       = trim($_POST['ai_provider'] ?? 'openrouter');
        $geminiKey        = trim($_POST['gemini_api_key'] ?? '');
        $maxVariants      = (int)($_POST['max_variants_per_request'] ?? 200);
        $chunkSize        = (int)($_POST['chunk_size'] ?? 10);

        if ($appName === '') {
            $errors[] = 'App name is required.';
        } elseif (mb_strlen($appName) > 100) {
            $errors[] = 'App name too long (max 100 chars).';
        }

        if ($adminUser === '' || !preg_match('/^[a-zA-Z0-9_]{3,50}$/', $adminUser)) {
            $errors[] = 'Admin username must be 3-50 alphanumeric/underscore characters.';
        }

        if (strlen($adminPass) < 8) {
            $errors[] = 'Admin password must be at least 8 characters.';
        }

        if ($adminPass !== $confirmPass) {
            $errors[] = 'Passwords do not match.';
        }

        if (!in_array($aiProvider, ['openrouter', 'gemini'], true)) {
            $errors[] = 'Invalid AI provider.';
        }

        if ($aiProvider === 'openrouter' && $orApiKey === '') {
            $errors[] = 'OpenRouter API key is required when using OpenRouter.';
        }

        if ($aiProvider === 'gemini' && $geminiKey === '') {
            $errors[] = 'Gemini API key is required when using Gemini.';
        }

        if ($maxVariants < 1 || $maxVariants > 10000) {
            $errors[] = 'Max variants must be between 1 and 10000.';
        }

        if ($chunkSize < 1 || $chunkSize > 100) {
            $errors[] = 'Chunk size must be between 1 and 100.';
        }

        if (empty($errors)) {
            // Create directories
            foreach ([
                STORAGE_PATH,
                STORAGE_PATH . '/generations',
                STORAGE_PATH . '/logs',
            ] as $dir) {
                if (!is_dir($dir) && !mkdir($dir, 0750, true)) {
                    $errors[] = "Could not create directory: {$dir}";
                }
            }

            // Write storage/.htaccess
            if (empty($errors)) {
                file_put_contents(STORAGE_PATH . '/.htaccess', "Order deny,allow\nDeny from all\n", LOCK_EX);
            }

            if (empty($errors)) {
                // Write config.json
                $config = [
                    'app_name'                 => $appName,
                    'ai_provider'              => $aiProvider,
                    'openrouter_api_key'       => $orApiKey,
                    'openrouter_model'         => $orModel !== '' ? $orModel : 'openrouter/auto',
                    'gemini_api_key'           => $geminiKey,
                    'max_variants_per_request' => $maxVariants,
                    'chunk_size'               => $chunkSize,
                    'installed_at'             => date('Y-m-d H:i:s'),
                ];
                $configJson = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                file_put_contents(STORAGE_PATH . '/config.json', $configJson, LOCK_EX);

                // Write users.json
                $users = [
                    [
                        'username' => $adminUser,
                        'password' => password_hash($adminPass, PASSWORD_BCRYPT, ['cost' => 12]),
                        'role'     => 'admin',
                    ],
                ];
                $usersJson = json_encode($users, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                file_put_contents(STORAGE_PATH . '/users.json', $usersJson, LOCK_EX);

                $success = true;
                // Regenerate CSRF so form can't be resubmitted
                unset($_SESSION['install_csrf']);
            }
        }
    }
}

$csrfToken = install_csrf_token();

// ── Helpers for re-populating form ──────────────────────────────────────────
function old(string $key, string $default = ''): string
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        return htmlspecialchars(trim($_POST[$key] ?? $default), ENT_QUOTES, 'UTF-8');
    }
    return htmlspecialchars($default, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Zenkka CMS — Installer</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:#f0f2f5;min-height:100vh;display:flex;align-items:flex-start;justify-content:center;padding:2rem 1rem}
.card{background:#fff;border-radius:10px;box-shadow:0 4px 24px rgba(0,0,0,.12);width:100%;max-width:560px;padding:2.5rem}
h1{font-size:1.6rem;margin-bottom:.25rem;color:#1a202c}
.subtitle{color:#718096;font-size:.9rem;margin-bottom:2rem}
h2{font-size:1rem;color:#4a5568;margin:1.5rem 0 .75rem;text-transform:uppercase;letter-spacing:.05em}
.form-group{margin-bottom:1.2rem}
label{display:block;font-size:.875rem;font-weight:600;color:#4a5568;margin-bottom:.4rem}
input[type=text],input[type=password],input[type=number],select{width:100%;padding:.6rem .8rem;border:1px solid #d1d5db;border-radius:6px;font-size:.95rem;transition:border-color .2s}
input:focus,select:focus{outline:none;border-color:#667eea;box-shadow:0 0 0 3px rgba(102,126,234,.2)}
.hint{font-size:.78rem;color:#718096;margin-top:.3rem}
.btn{display:block;width:100%;padding:.85rem;background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;border:none;border-radius:6px;font-size:1rem;font-weight:600;cursor:pointer;margin-top:1.5rem;transition:opacity .2s}
.btn:hover{opacity:.9}
.errors{background:#fff5f5;border:1px solid #fed7d7;border-radius:6px;padding:1rem;margin-bottom:1.5rem}
.errors p{color:#c53030;font-size:.875rem;margin-bottom:.25rem}
.errors p:last-child{margin-bottom:0}
.success{background:#f0fff4;border:1px solid #9ae6b4;border-radius:6px;padding:1.5rem;text-align:center}
.success h2{color:#276749;text-transform:none;letter-spacing:0;margin-top:0}
.success p{color:#2f855a;font-size:.9rem;margin-top:.5rem}
.success a{display:inline-block;margin-top:1rem;padding:.6rem 1.5rem;background:#38a169;color:#fff;border-radius:6px;text-decoration:none;font-weight:600}
.warning{background:#fffbeb;border:1px solid #fbd38d;border-radius:6px;padding:.75rem;margin-top:1rem;font-size:.83rem;color:#744210}
</style>
</head>
<body>
<div class="card">
    <h1>🚀 Zenkka CMS</h1>
    <p class="subtitle">One-time installer — fill in the form below to set up your CMS.</p>

    <?php if ($success): ?>
    <div class="success">
        <h2>✅ Installation Successful!</h2>
        <p>Your CMS has been installed. You can now log in.</p>
        <div class="warning">⚠️ <strong>Security:</strong> Delete <code>install.php</code> from your server before going to production!</div>
        <a href="/login.php">Go to Login →</a>
    </div>
    <?php else: ?>

    <?php if (!empty($errors)): ?>
    <div class="errors">
        <?php foreach ($errors as $err): ?>
        <p>• <?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></p>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <form method="POST" action="install.php">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

        <h2>Application</h2>
        <div class="form-group">
            <label for="app_name">App Name</label>
            <input type="text" id="app_name" name="app_name" value="<?= old('app_name', 'Zenkka CMS') ?>" required>
        </div>

        <h2>Admin Account</h2>
        <div class="form-group">
            <label for="admin_username">Username</label>
            <input type="text" id="admin_username" name="admin_username" value="<?= old('admin_username', 'admin') ?>" required>
        </div>
        <div class="form-group">
            <label for="admin_password">Password</label>
            <input type="password" id="admin_password" name="admin_password" required>
            <p class="hint">Minimum 8 characters.</p>
        </div>
        <div class="form-group">
            <label for="confirm_password">Confirm Password</label>
            <input type="password" id="confirm_password" name="confirm_password" required>
        </div>

        <h2>AI Provider</h2>
        <div class="form-group">
            <label for="ai_provider">Provider</label>
            <select id="ai_provider" name="ai_provider" onchange="toggleProvider(this.value)">
                <option value="openrouter" <?= old('ai_provider', 'openrouter') === 'openrouter' ? 'selected' : '' ?>>OpenRouter</option>
                <option value="gemini" <?= old('ai_provider') === 'gemini' ? 'selected' : '' ?>>Google Gemini</option>
            </select>
        </div>
        <div id="openrouter_section">
            <div class="form-group">
                <label for="openrouter_api_key">OpenRouter API Key</label>
                <input type="password" id="openrouter_api_key" name="openrouter_api_key" value="<?= old('openrouter_api_key') ?>">
            </div>
            <div class="form-group">
                <label for="openrouter_model">OpenRouter Model</label>
                <input type="text" id="openrouter_model" name="openrouter_model" value="<?= old('openrouter_model', 'openrouter/auto') ?>">
            </div>
        </div>
        <div id="gemini_section" style="display:none">
            <div class="form-group">
                <label for="gemini_api_key">Gemini API Key</label>
                <input type="password" id="gemini_api_key" name="gemini_api_key" value="<?= old('gemini_api_key') ?>">
            </div>
        </div>

        <h2>Generation Settings</h2>
        <div class="form-group">
            <label for="max_variants_per_request">Max Variants per Request</label>
            <input type="number" id="max_variants_per_request" name="max_variants_per_request" value="<?= old('max_variants_per_request', '200') ?>" min="1" max="10000">
        </div>
        <div class="form-group">
            <label for="chunk_size">Chunk Size</label>
            <input type="number" id="chunk_size" name="chunk_size" value="<?= old('chunk_size', '10') ?>" min="1" max="100">
            <p class="hint">Number of items to request per AI call.</p>
        </div>

        <button type="submit" class="btn">Install Zenkka CMS</button>
    </form>
    <?php endif; ?>
</div>

<script>
function toggleProvider(val) {
    document.getElementById('openrouter_section').style.display = val === 'openrouter' ? '' : 'none';
    document.getElementById('gemini_section').style.display     = val === 'gemini'      ? '' : 'none';
}
// Init on load
toggleProvider(document.getElementById('ai_provider').value);
</script>
</body>
</html>
