<?php
declare(strict_types=1);

class ExportService
{
    private PathGuard       $pathGuard;
    private FileNameBuilder $fileNameBuilder;

    public function __construct()
    {
        $this->pathGuard       = new PathGuard();
        $this->fileNameBuilder = new FileNameBuilder();
    }

    /**
     * Export a generation to files according to $settings.
     *
     * $settings keys: output_mode, output_format, destination_folder,
     *                 filename_template, transliterate
     *
     * Returns array of file records.
     */
    public function export(array $generation, array $settings): array
    {
        $mode = $settings['output_mode'] ?? 'single_file';

        $files = match ($mode) {
            'file_per_item' => $this->exportFilePerItem($generation, $settings),
            'both'          => array_merge(
                $this->exportSingleFile($generation, $settings),
                $this->exportFilePerItem($generation, $settings)
            ),
            default         => $this->exportSingleFile($generation, $settings),
        };

        return $files;
    }

    /**
     * Export all items into one combined file.
     */
    public function exportSingleFile(array $generation, array $settings): array
    {
        $format        = $settings['output_format']    ?? 'json';
        $destFolder    = $settings['destination_folder'] ?? '';
        $transliterate = (bool)($settings['transliterate'] ?? false);
        $genSlug       = $generation['generation_slug'] ?? ($generation['id'] ?? 'gen');
        $date          = date('Ymd');

        $filename = $this->fileNameBuilder->ensureExtension($genSlug . '_' . $date, $format);
        $filename = $this->fileNameBuilder->sanitizeFilename($filename, $transliterate);

        $absDir = $this->pathGuard->resolveExportPath($destFolder);
        if (!$this->pathGuard->createFolderIfNotExists($absDir)) {
            throw new RuntimeException("Не удалось создать папку: {$destFolder}");
        }

        $absPath = $this->deduplicatePath($absDir . '/' . $filename);
        $context = [
            'language' => $generation['language'] ?? 'ru',
            'topic'    => $generation['topic']    ?? '',
        ];

        $content = $this->renderSingleFile($generation, $format, $context);

        if (file_put_contents($absPath, $content, LOCK_EX) === false) {
            throw new RuntimeException("Не удалось записать файл: " . basename($absPath));
        }

        $relPath = ($destFolder !== '' ? rtrim($destFolder, '/') . '/' : '') . basename($absPath);

        return [[
            'item_id'  => null,
            'filename' => basename($absPath),
            'path'     => $relPath,
            'format'   => $format,
            'type'     => 'single_file',
        ]];
    }

    /**
     * Export each item to its own separate file.
     */
    public function exportFilePerItem(array $generation, array $settings): array
    {
        $format           = $settings['output_format']     ?? 'json';
        $destFolder       = $settings['destination_folder']  ?? '';
        $filenameTemplate = $settings['filename_template'] ?? '{generation_slug}_{num}';
        $transliterate    = (bool)($settings['transliterate'] ?? false);
        $items            = $generation['items']           ?? [];

        $absDir = $this->pathGuard->resolveExportPath($destFolder);
        if (!$this->pathGuard->createFolderIfNotExists($absDir)) {
            throw new RuntimeException("Не удалось создать папку: {$destFolder}");
        }

        $genSlug  = $generation['generation_slug'] ?? 'gen';
        $genName  = $generation['generation_name'] ?? $genSlug;
        $topic    = $generation['topic']           ?? '';
        $topicSlug = $this->fileNameBuilder->slugify($topic, true);
        $lang     = $generation['language']        ?? 'ru';
        $date     = date('Ymd');
        $time     = date('His');
        $datetime = date('Ymd_His');

        $files         = [];
        $usedFilenames = [];

        foreach ($items as $num => $item) {
            if (!is_array($item)) {
                continue;
            }

            $itemId = (int)($item['id'] ?? ($num + 1));

            $variables = [
                'generation_name' => $genName,
                'generation_slug' => $genSlug,
                'topic'           => $topic,
                'topic_slug'      => $topicSlug,
                'id'              => $itemId,
                'num'             => $num + 1,
                'date'            => $date,
                'time'            => $time,
                'datetime'        => $datetime,
                'format'          => $format,
                'lang'            => $lang,
            ];

            $filename = $this->fileNameBuilder->buildFilename(array_merge($variables, [
                'template'     => $filenameTemplate,
                'format'       => $format,
                'transliterate'=> $transliterate,
            ]));

            $filename = $this->deduplicateFilename($filename, $usedFilenames, $absDir);
            $usedFilenames[] = $filename;

            $context = ['language' => $lang, 'topic' => $topic];
            $content = $this->renderItem($item, $format, $context);

            $absPath = $absDir . '/' . $filename;
            if (file_put_contents($absPath, $content, LOCK_EX) === false) {
                Logger::error('ExportService: failed to write file', ['path' => $absPath]);
                continue;
            }

            $relPath = ($destFolder !== '' ? rtrim($destFolder, '/') . '/' : '') . $filename;

            $files[] = [
                'item_id'  => $itemId,
                'filename' => $filename,
                'path'     => $relPath,
                'format'   => $format,
                'type'     => 'file_per_item',
            ];
        }

        return $files;
    }

    /**
     * Render a single item to the requested format.
     */
    public function renderItem(array $item, string $format, array $context): string
    {
        $title     = $item['title']             ?? '';
        $shortDesc = $item['short_description'] ?? '';
        $desc      = $item['description']       ?? '';
        $tags      = is_array($item['tags'] ?? null) ? $item['tags'] : [];
        $tagsStr   = implode(', ', $tags);
        $lang      = $context['language'] ?? 'ru';
        $topic     = $context['topic']    ?? '';

        switch ($format) {
            case 'txt':
                $parts = array_filter([$title, $shortDesc, $desc,
                    $tagsStr !== '' ? 'Tags: ' . $tagsStr : '']);
                return implode("\n\n", $parts) . "\n";

            case 'html': {
                $dir  = in_array($lang, ['ar', 'he', 'fa'], true) ? 'rtl' : 'ltr';
                $esc  = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
                $tagsHtml = '';
                if (!empty($tags)) {
                    $tagsHtml = '<p class="tags">' . implode(', ', array_map($esc, $tags)) . '</p>';
                }
                return '<!DOCTYPE html>' . "\n"
                    . '<html lang="' . $esc($lang) . '" dir="' . $dir . '">' . "\n"
                    . '<head><meta charset="UTF-8">'
                    . '<meta name="viewport" content="width=device-width,initial-scale=1">'
                    . '<title>' . $esc($title) . '</title></head>' . "\n"
                    . '<body>' . "\n"
                    . '<h1>' . $esc($title) . '</h1>' . "\n"
                    . '<p class="short-description">' . $esc($shortDesc) . '</p>' . "\n"
                    . '<div class="description">' . $esc($desc) . '</div>' . "\n"
                    . $tagsHtml . "\n"
                    . '</body></html>' . "\n";
            }

            case 'md': {
                $lines = ["# {$title}", '', $shortDesc, '', $desc];
                if ($tagsStr !== '') {
                    $lines[] = '';
                    $lines[] = 'Tags: ' . $tagsStr;
                }
                return implode("\n", $lines) . "\n";
            }

            case 'csv': {
                $handle = fopen('php://memory', 'w');
                if ($handle === false) {
                    return '';
                }
                fputcsv($handle, ['id', 'title', 'short_description', 'description', 'tags']);
                fputcsv($handle, [$item['id'] ?? '', $title, $shortDesc, $desc, $tagsStr]);
                rewind($handle);
                $content = stream_get_contents($handle);
                fclose($handle);
                return (string)$content;
            }

            case 'json':
            default:
                return json_encode([
                    'id'                => $item['id'] ?? null,
                    'title'             => $title,
                    'short_description' => $shortDesc,
                    'description'       => $desc,
                    'tags'              => $tags,
                    'language'          => $lang,
                    'topic'             => $topic,
                ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
        }
    }

    /**
     * Render all items into a single combined file.
     */
    public function renderSingleFile(array $generation, string $format, array $context): string
    {
        $items = $generation['items'] ?? [];
        $lang  = $context['language'] ?? $generation['language'] ?? 'ru';

        switch ($format) {
            case 'json': {
                $meta = $generation;
                unset($meta['items']);
                return json_encode(
                    ['metadata' => $meta, 'items' => $items],
                    JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
                ) . "\n";
            }

            case 'csv': {
                $handle = fopen('php://memory', 'w');
                if ($handle === false) {
                    return '';
                }
                fputcsv($handle, ['id', 'title', 'short_description', 'description', 'tags']);
                foreach ($items as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $tags = is_array($item['tags'] ?? null) ? implode(', ', $item['tags']) : '';
                    fputcsv($handle, [
                        $item['id'] ?? '',
                        $item['title'] ?? '',
                        $item['short_description'] ?? '',
                        $item['description'] ?? '',
                        $tags,
                    ]);
                }
                rewind($handle);
                $content = stream_get_contents($handle);
                fclose($handle);
                return (string)$content;
            }

            case 'html': {
                $dir = in_array($lang, ['ar', 'he', 'fa'], true) ? 'rtl' : 'ltr';
                $esc = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
                $topicEsc = $esc($generation['topic'] ?? '');
                $html  = '<!DOCTYPE html>' . "\n"
                    . '<html lang="' . $esc($lang) . '" dir="' . $dir . '">' . "\n"
                    . '<head><meta charset="UTF-8">'
                    . '<meta name="viewport" content="width=device-width,initial-scale=1">'
                    . "<title>{$topicEsc}</title></head>\n<body>\n";
                foreach ($items as $idx => $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $tags = is_array($item['tags'] ?? null) ? implode(', ', $item['tags']) : '';
                    $html .= '<article>' . "\n"
                        . '<h2>' . $esc($item['title'] ?? '') . '</h2>' . "\n"
                        . '<p>' . $esc($item['short_description'] ?? '') . '</p>' . "\n"
                        . '<div>' . $esc($item['description'] ?? '') . '</div>' . "\n"
                        . ($tags !== '' ? '<p class="tags">' . $esc($tags) . '</p>' . "\n" : '')
                        . '</article>' . "\n\n";
                }
                $html .= '</body></html>' . "\n";
                return $html;
            }

            case 'md': {
                $lines = ['# ' . ($generation['topic'] ?? ''), ''];
                foreach ($items as $idx => $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $num  = (int)($item['id'] ?? ($idx + 1));
                    $tags = is_array($item['tags'] ?? null) ? implode(', ', $item['tags']) : '';
                    $lines[] = '---';
                    $lines[] = '';
                    $lines[] = "## {$num}. " . ($item['title'] ?? '');
                    $lines[] = '';
                    if (!empty($item['short_description'])) {
                        $lines[] = $item['short_description'];
                        $lines[] = '';
                    }
                    if (!empty($item['description'])) {
                        $lines[] = $item['description'];
                        $lines[] = '';
                    }
                    if ($tags !== '') {
                        $lines[] = 'Tags: ' . $tags;
                        $lines[] = '';
                    }
                }
                return implode("\n", $lines) . "\n";
            }

            case 'txt':
            default: {
                $parts = [];
                foreach ($items as $idx => $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $num   = (int)($item['id'] ?? ($idx + 1));
                    $tags  = is_array($item['tags'] ?? null) ? implode(', ', $item['tags']) : '';
                    $sep   = str_repeat('=', 30);
                    $block = $sep . "\nItem {$num}\n" . $sep . "\n\n"
                        . "Title:\n" . ($item['title'] ?? '') . "\n\n"
                        . "Short description:\n" . ($item['short_description'] ?? '') . "\n\n"
                        . "Description:\n" . ($item['description'] ?? '') . "\n\n";
                    if ($tags !== '') {
                        $block .= "Tags:\n{$tags}\n\n";
                    }
                    $parts[] = $block;
                }
                return implode("\n", $parts);
            }
        }
    }

    // ── Private helpers ────────────────────────────────────────────────────────

    private function deduplicatePath(string $absPath): string
    {
        if (!file_exists($absPath)) {
            return $absPath;
        }
        $ext  = pathinfo($absPath, PATHINFO_EXTENSION);
        $base = $ext !== '' ? substr($absPath, 0, -(mb_strlen($ext, 'UTF-8') + 1)) : $absPath;
        $i    = 2;
        do {
            $candidate = $base . '_' . $i . ($ext !== '' ? '.' . $ext : '');
            $i++;
        } while (file_exists($candidate));
        return $candidate;
    }

    private function deduplicateFilename(string $filename, array $used, string $absDir): string
    {
        if (!in_array($filename, $used, true) && !file_exists($absDir . '/' . $filename)) {
            return $filename;
        }
        $ext  = pathinfo($filename, PATHINFO_EXTENSION);
        $base = $ext !== '' ? pathinfo($filename, PATHINFO_FILENAME) : $filename;
        $i    = 2;
        do {
            $candidate = $base . '_' . $i . ($ext !== '' ? '.' . $ext : '');
            $i++;
        } while (in_array($candidate, $used, true) || file_exists($absDir . '/' . $candidate));
        return $candidate;
    }
}
