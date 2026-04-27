<?php
declare(strict_types=1);

class Csrf
{
    private const SESSION_KEY = 'csrf_token';

    public static function getToken(): string
    {
        if (empty($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }
        return $_SESSION[self::SESSION_KEY];
    }

    public static function validate(string $token): bool
    {
        $stored = $_SESSION[self::SESSION_KEY] ?? '';
        if ($stored === '') {
            return false;
        }
        return hash_equals($stored, $token);
    }

    public static function field(): string
    {
        $token = htmlspecialchars(self::getToken(), ENT_QUOTES, 'UTF-8');
        return '<input type="hidden" name="csrf_token" value="' . $token . '">';
    }
}
