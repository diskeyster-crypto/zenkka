<?php
declare(strict_types=1);

class Validator
{
    public static function str(mixed $val, int $min, int $max): string
    {
        $val = trim((string)$val);
        $len = mb_strlen($val);
        if ($len < $min || $len > $max) {
            throw new InvalidArgumentException(
                "Value length {$len} is out of range [{$min}, {$max}]."
            );
        }
        return $val;
    }

    public static function int(mixed $val, int $min, int $max): int
    {
        $int = (int)$val;
        if ($int < $min || $int > $max) {
            throw new InvalidArgumentException(
                "Integer value {$int} is out of range [{$min}, {$max}]."
            );
        }
        return $int;
    }

    public static function float(mixed $val, float $min, float $max): float
    {
        $float = (float)$val;
        if ($float < $min || $float > $max) {
            throw new InvalidArgumentException(
                "Float value {$float} is out of range [{$min}, {$max}]."
            );
        }
        return $float;
    }

    public static function inList(mixed $val, array $allowed): string
    {
        $val = (string)$val;
        if (!in_array($val, $allowed, true)) {
            throw new InvalidArgumentException(
                "Value '" . htmlspecialchars($val, ENT_QUOTES, 'UTF-8') . "' is not in the allowed list."
            );
        }
        return $val;
    }

    public static function safeId(string $id): string
    {
        if (!preg_match('/^gen_\d{8}_[a-f0-9]{8}$/', $id)) {
            throw new InvalidArgumentException('Invalid generation ID format.');
        }
        return $id;
    }
}
