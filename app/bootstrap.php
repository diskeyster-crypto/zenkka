<?php
declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
define('STORAGE_PATH', BASE_PATH . '/storage');
define('APP_PATH', BASE_PATH . '/app');

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => false,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

require_once APP_PATH . '/Auth.php';
require_once APP_PATH . '/Csrf.php';
require_once APP_PATH . '/JsonStore.php';
require_once APP_PATH . '/Validator.php';
require_once APP_PATH . '/Logger.php';
require_once APP_PATH . '/GenerationService.php';
require_once APP_PATH . '/Ai/AiClientInterface.php';
require_once APP_PATH . '/Ai/OpenRouterClient.php';
require_once APP_PATH . '/Ai/GeminiClient.php';
