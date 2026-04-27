<?php
declare(strict_types=1);

class JsonStore
{
    public static function read(string $path): array
    {
        if (!file_exists($path)) {
            return [];
        }
        $content = file_get_contents($path);
        if ($content === false || $content === '') {
            return [];
        }
        $data = json_decode($content, true);
        return is_array($data) ? $data : [];
    }

    public static function write(string $path, array $data): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        file_put_contents($path, $json, LOCK_EX);
    }

    public static function readConfig(): array
    {
        return self::read(STORAGE_PATH . '/config.json');
    }

    public static function readUsers(): array
    {
        return self::read(STORAGE_PATH . '/users.json');
    }
}
