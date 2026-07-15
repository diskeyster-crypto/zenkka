<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

if (Auth::isLoggedIn()) {
    header('Location: /admin/index.php');
    exit;
}

$error    = '';
$locked   = false;
$lockMins = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!Csrf::validate($token)) {
        $error = 'Invalid CSRF token. Please try again.';
    } else {
        // Check lockout before attempting login
        $remaining = Auth::getLockoutRemainingSeconds();
        if ($remaining > 0) {
            $locked   = true;
            $lockMins = (int)ceil($remaining / 60);
        } else {
            $username = trim($_POST['username'] ?? '');
            $password = $_POST['password'] ?? '';

            if (Auth::login($username, $password)) {
                header('Location: /admin/index.php');
                exit;
            }

            $remaining = Auth::getLockoutRemainingSeconds();
            if ($remaining > 0) {
                $locked   = true;
                $lockMins = (int)ceil($remaining / 60);
            } else {
                $error = 'Invalid username or password.';
            }
        }
    }
}

$csrfField = Csrf::field();
$config    = JsonStore::readConfig();
$appName   = htmlspecialchars($config['app_name'] ?? 'Zenkka CMS', ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Login — <?= $appName ?></title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);min-height:100vh;display:flex;align-items:center;justify-content:center;padding:1rem}
.card{background:#fff;border-radius:12px;box-shadow:0 8px 32px rgba(0,0,0,.2);width:100%;max-width:400px;padding:2.5rem}
.logo{text-align:center;margin-bottom:2rem}
.logo h1{font-size:1.8rem;color:#1a202c;margin-top:.5rem}
.logo p{color:#718096;font-size:.9rem}
.form-group{margin-bottom:1.25rem}
label{display:block;font-size:.875rem;font-weight:600;color:#4a5568;margin-bottom:.4rem}
input[type=text],input[type=password]{width:100%;padding:.7rem 1rem;border:1.5px solid #d1d5db;border-radius:8px;font-size:.95rem;transition:border-color .2s,box-shadow .2s}
input:focus{outline:none;border-color:#667eea;box-shadow:0 0 0 3px rgba(102,126,234,.2)}
.btn{display:block;width:100%;padding:.85rem;background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;border:none;border-radius:8px;font-size:1rem;font-weight:600;cursor:pointer;transition:opacity .2s;margin-top:.5rem}
.btn:hover{opacity:.9}
.alert{border-radius:8px;padding:.85rem 1rem;margin-bottom:1.25rem;font-size:.875rem}
.alert-error{background:#fff5f5;border:1px solid #fed7d7;color:#c53030}
.alert-warning{background:#fffbeb;border:1px solid #fbd38d;color:#744210}
</style>
</head>
<body>
<div class="card">
    <div class="logo">
        <div style="font-size:2.5rem">🔐</div>
        <h1><?= $appName ?></h1>
        <p>Admin Panel</p>
    </div>

    <?php if ($locked): ?>
    <div class="alert alert-warning">
        ⏳ Too many failed attempts. Try again in <strong><?= $lockMins ?> minute<?= $lockMins !== 1 ? 's' : '' ?></strong>.
    </div>
    <?php elseif ($error !== ''): ?>
    <div class="alert alert-error">
        ❌ <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
    </div>
    <?php endif; ?>

    <?php if (!$locked): ?>
    <form method="POST" action="/login.php">
        <?= $csrfField ?>
        <div class="form-group">
            <label for="username">Username</label>
            <input type="text" id="username" name="username" autocomplete="username" required autofocus>
        </div>
        <div class="form-group">
            <label for="password">Password</label>
            <input type="password" id="password" name="password" autocomplete="current-password" required>
        </div>
        <button type="submit" class="btn">Sign In →</button>
    </form>
    <?php endif; ?>
</div>
</body>
</html>
