<?php
declare(strict_types=1);

class Logger
{
    public static function log(string $level, string $message, array $context = []): void
    {
        $logDir = STORAGE_PATH . '/logs';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0750, true);
        }
        $logFile = $logDir . '/app.log';
        $date    = date('Y-m-d H:i:s');
        $ctx     = empty($context) ? '' : ' ' . json_encode($context, JSON_UNESCAPED_UNICODE);
        $line    = "[{$date}] [" . strtoupper($level) . "] {$message}{$ctx}\n";
        file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    }

    public static function error(string $message, array $context = []): void
    {
        self::log('error', $message, $context);
    }

    public static function info(string $message, array $context = []): void
    {
        self::log('info', $message, $context);
    }
}
