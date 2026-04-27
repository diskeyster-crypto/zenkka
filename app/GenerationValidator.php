<?php
declare(strict_types=1);

class GenerationValidator
{
    /**
     * Validate a single item against length settings.
     * Returns the item with an added 'validation' sub-array.
     */
    public function validateItem(array $item, array $lengthSettings): array
    {
        $allowTolerance    = (bool)($lengthSettings['allow_length_tolerance'] ?? false);
        $tolerancePct      = (int)($lengthSettings['tolerance_percent'] ?? 10);

        $fields = ['title', 'short_description', 'description'];
        $errors = [];
        $charCounts = [];

        foreach ($fields as $field) {
            $text = isset($item[$field]) ? (string)$item[$field] : '';
            $charCounts[$field . '_chars'] = $this->countChars($text);

            if (!isset($lengthSettings[$field])) {
                continue;
            }

            $rule   = $lengthSettings[$field];
            $result = $this->validateLength($text, $rule, $allowTolerance, $tolerancePct);
            if (!$result['valid']) {
                $errors[] = $result['error'];
            }
        }

        // Check empty strings
        foreach ($fields as $field) {
            if (isset($item[$field]) && trim((string)$item[$field]) === '') {
                $errors[] = "Поле '{$field}' пустое.";
            }
        }

        $validation                 = $charCounts;
        $validation['valid_length'] = empty($errors);
        $validation['errors']       = $errors;

        $item['validation'] = $validation;
        return $item;
    }

    /**
     * Validate a single field's text against a length rule.
     * Returns ['valid' => bool, 'error' => string].
     */
    public function validateLength(
        string $text,
        array $rule,
        bool $allowTolerance = false,
        int $tolerancePct = 10
    ): array {
        $len  = $this->countChars($text);
        $mode = $rule['mode'] ?? 'range';

        if ($mode === 'exact') {
            $exact = (int)($rule['exact'] ?? $rule['exact_chars'] ?? 0);
            if ($exact === 0) {
                return ['valid' => true, 'error' => ''];
            }

            if ($allowTolerance) {
                $delta = (int)ceil($exact * $tolerancePct / 100);
                $valid = ($len >= $exact - $delta && $len <= $exact + $delta);
            } else {
                $valid = ($len === $exact);
            }

            if (!$valid) {
                return [
                    'valid' => false,
                    'error' => "Длина {$len} символов, ожидалось {$exact}.",
                ];
            }
        } else {
            // range
            $min = (int)($rule['min'] ?? $rule['min_chars'] ?? 0);
            $max = (int)($rule['max'] ?? $rule['max_chars'] ?? 0);

            if ($min === 0 && $max === 0) {
                return ['valid' => true, 'error' => ''];
            }

            if ($allowTolerance && $tolerancePct > 0) {
                $delta = (int)ceil(max($min, $max) * $tolerancePct / 100);
                $effectiveMin = max(0, $min - $delta);
                $effectiveMax = $max + $delta;
            } else {
                $effectiveMin = $min;
                $effectiveMax = $max;
            }

            if ($max > 0 && ($len < $effectiveMin || $len > $effectiveMax)) {
                return [
                    'valid' => false,
                    'error' => "Длина {$len} символов, ожидалось {$min}–{$max}.",
                ];
            }

            if ($max === 0 && $min > 0 && $len < $effectiveMin) {
                return [
                    'valid' => false,
                    'error' => "Длина {$len} символов, минимум {$min}.",
                ];
            }
        }

        return ['valid' => true, 'error' => ''];
    }

    /**
     * Count characters using mb_strlen (UTF-8 safe).
     */
    public function countChars(string $text): int
    {
        return mb_strlen($text, 'UTF-8');
    }

    /**
     * Check whether any two items have identical values for $field.
     * Returns true if at least one duplicate pair is found.
     */
    public function isDuplicate(array $items, string $field): bool
    {
        $seen = [];
        foreach ($items as $item) {
            if (!is_array($item) || !isset($item[$field])) {
                continue;
            }
            $val = trim((string)$item[$field]);
            if ($val === '') {
                continue;
            }
            if (in_array($val, $seen, true)) {
                return true;
            }
            $seen[] = $val;
        }
        return false;
    }

    /**
     * Check if a specific item's field value already exists in previously collected items.
     */
    public function isFieldDuplicateOf(string $value, array $existingValues): bool
    {
        $trimmed = trim($value);
        return $trimmed !== '' && in_array($trimmed, $existingValues, true);
    }
}
