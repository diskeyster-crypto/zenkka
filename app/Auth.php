<?php
declare(strict_types=1);

class Auth
{
    private const MAX_ATTEMPTS  = 5;
    private const LOCKOUT_SECS  = 600; // 10 minutes

    public static function requireAuth(): void
    {
        if (!self::isLoggedIn()) {
            header('Location: /login.php');
            exit;
        }
    }

    public static function login(string $username, string $password): bool
    {
        // Check if currently locked out
        if (isset($_SESSION['login_locked_until']) && time() < $_SESSION['login_locked_until']) {
            return false;
        }

        // Reset lockout if window has passed
        if (isset($_SESSION['login_locked_until']) && time() >= $_SESSION['login_locked_until']) {
            unset($_SESSION['login_locked_until'], $_SESSION['login_attempts']);
        }

        $users = JsonStore::readUsers();
        foreach ($users as $user) {
            if (isset($user['username'], $user['password'])
                && $user['username'] === $username
                && password_verify($password, $user['password'])
            ) {
                // Success: reset counters and set session
                unset($_SESSION['login_attempts'], $_SESSION['login_locked_until']);
                $_SESSION['admin_logged_in'] = true;
                $_SESSION['admin_username']  = $username;
                session_regenerate_id(true);
                Logger::info('Login successful', ['username' => $username]);
                return true;
            }
        }

        // Failed attempt
        $_SESSION['login_attempts'] = ($_SESSION['login_attempts'] ?? 0) + 1;
        if ($_SESSION['login_attempts'] >= self::MAX_ATTEMPTS) {
            $_SESSION['login_locked_until'] = time() + self::LOCKOUT_SECS;
        }
        Logger::info('Login failed', ['username' => $username, 'attempts' => $_SESSION['login_attempts']]);
        return false;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }
        session_destroy();
        header('Location: /login.php');
        exit;
    }

    public static function isLoggedIn(): bool
    {
        return !empty($_SESSION['admin_logged_in']);
    }

    public static function getUsername(): string
    {
        return $_SESSION['admin_username'] ?? '';
    }

    public static function getLockoutRemainingSeconds(): int
    {
        if (isset($_SESSION['login_locked_until'])) {
            $remaining = $_SESSION['login_locked_until'] - time();
            return $remaining > 0 ? $remaining : 0;
        }
        return 0;
    }
}
