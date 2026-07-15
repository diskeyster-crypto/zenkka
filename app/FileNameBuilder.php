<?php
declare(strict_types=1);

class FileNameBuilder
{
    private const ALLOWED_EXTENSIONS = ['json', 'txt', 'html', 'md', 'csv'];
    private const MAX_FILENAME_LEN   = 120;

    /** Cyrillic → Latin transliteration table */
    private const TRANSLIT = [
        'а'=>'a',  'б'=>'b',  'в'=>'v',  'г'=>'g',  'д'=>'d',  'е'=>'e',  'ё'=>'yo', 'ж'=>'zh',
        'з'=>'z',  'и'=>'i',  'й'=>'y',  'к'=>'k',  'л'=>'l',  'м'=>'m',  'н'=>'n',  'о'=>'o',
        'п'=>'p',  'р'=>'r',  'с'=>'s',  'т'=>'t',  'у'=>'u',  'ф'=>'f',  'х'=>'kh', 'ц'=>'ts',
        'ч'=>'ch', 'ш'=>'sh', 'щ'=>'shch','ъ'=>'',  'ы'=>'y',  'ь'=>'',   'э'=>'e',  'ю'=>'yu',
        'я'=>'ya',
        'А'=>'A',  'Б'=>'B',  'В'=>'V',  'Г'=>'G',  'Д'=>'D',  'Е'=>'E',  'Ё'=>'Yo', 'Ж'=>'Zh',
        'З'=>'Z',  'И'=>'I',  'Й'=>'Y',  'К'=>'K',  'Л'=>'L',  'М'=>'M',  'Н'=>'N',  'О'=>'O',
        'П'=>'P',  'Р'=>'R',  'С'=>'S',  'Т'=>'T',  'У'=>'U',  'Ф'=>'F',  'Х'=>'Kh', 'Ц'=>'Ts',
        'Ч'=>'Ch', 'Ш'=>'Sh', 'Щ'=>'Shch','Ъ'=>'',  'Ы'=>'Y',  'Ь'=>'',   'Э'=>'E',  'Ю'=>'Yu',
        'Я'=>'Ya',
    ];

    /**
     * Build a filename for a single item using the given template and data.
     *
     * $data keys:
     *   template, format, transliterate,
     *   generation_name, generation_slug, topic, topic_slug,
     *   id, num, date, time, datetime, lang
     */
    public function buildFilename(array $data): string
    {
        $template      = $data['template']      ?? '{generation_slug}_{num}';
        $format        = $data['format']        ?? 'txt';
        $transliterate = (bool)($data['transliterate'] ?? false);

        $filename = $this->replaceFilenameVariables($template, $data);
        $filename = $this->ensureExtension($filename, $format);
        return $this->sanitizeFilename($filename, $transliterate);
    }

    /**
     * Replace {variable} placeholders.
     */
    public function replaceFilenameVariables(string $template, array $variables): string
    {
        $map = [];
        foreach ($variables as $key => $value) {
            $map['{' . $key . '}'] = (string)$value;
        }
        return str_replace(array_keys($map), array_values($map), $template);
    }

    /**
     * Ensure the filename has the correct allowed extension.
     * If a different (or missing) extension is present, it is replaced/added.
     */
    public function ensureExtension(string $filename, string $format): string
    {
        $ext = strtolower($format);
        if (!in_array($ext, self::ALLOWED_EXTENSIONS, true)) {
            $ext = 'txt';
        }

        $current = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (in_array($current, self::ALLOWED_EXTENSIONS, true)) {
            return pathinfo($filename, PATHINFO_FILENAME) . '.' . $ext;
        }

        return $filename . '.' . $ext;
    }

    /**
     * Sanitize a filename: strip dangerous characters, enforce length/extension.
     */
    public function sanitizeFilename(string $filename, bool $transliterate = false): string
    {
        $filename = str_replace("\0", '', $filename);

        if ($transliterate) {
            $filename = $this->transliterate($filename);
        }

        $ext  = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $name = pathinfo($filename, PATHINFO_FILENAME);

        // Enforce allowed extension
        if (!in_array($ext, self::ALLOWED_EXTENSIONS, true)) {
            $ext = 'txt';
        }

        // Strip path separators and dangerous chars
        $name = str_replace(['/', '\\', "\0"], '_', $name);
        $name = (string)preg_replace('/[*?"<>|:]/', '_', $name);

        // Prevent path traversal in name
        $name = str_replace(['..', './', '/.'], '_', $name);

        // Replace spaces with underscore
        $name = str_replace(' ', '_', $name);

        // Trim leading/trailing dots and underscores
        $name = trim($name, '._');

        if ($name === '') {
            $name = 'file_' . date('Ymd_His');
        }

        if (mb_strlen($name, 'UTF-8') > self::MAX_FILENAME_LEN) {
            $name = mb_substr($name, 0, self::MAX_FILENAME_LEN, 'UTF-8');
        }

        return $name . '.' . $ext;
    }

    /**
     * Transliterate Cyrillic characters to Latin equivalents.
     */
    public function transliterate(string $text): string
    {
        return strtr($text, self::TRANSLIT);
    }

    /**
     * Create a URL/filename-safe slug from any string.
     * Optionally transliterates Cyrillic first.
     */
    public function slugify(string $text, bool $transliterate = true): string
    {
        if ($transliterate) {
            $text = $this->transliterate($text);
        }

        $text = mb_strtolower($text, 'UTF-8');
        $text = (string)preg_replace('/[\s\-]+/', '_', $text);
        $text = (string)preg_replace('/[^\p{L}\p{N}_]/u', '', $text);
        $text = trim($text, '_');

        if ($text === '') {
            $text = 'gen_' . date('Ymd_His');
        }

        if (mb_strlen($text, 'UTF-8') > 80) {
            $text = mb_substr($text, 0, 80, 'UTF-8');
        }

        return $text;
    }
}
