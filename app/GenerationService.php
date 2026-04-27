<?php
declare(strict_types=1);

class GenerationService
{
    private AiClientInterface    $client;
    private array                $config;
    private PromptBuilder        $promptBuilder;
    private GenerationValidator  $validator;
    private ExportService        $exportService;
    private FileNameBuilder      $fileNameBuilder;

    public function __construct(AiClientInterface $client, array $config)
    {
        $this->client          = $client;
        $this->config          = $config;
        $this->promptBuilder   = new PromptBuilder();
        $this->validator       = new GenerationValidator();
        $this->exportService   = new ExportService();
        $this->fileNameBuilder = new FileNameBuilder();
    }

    /**
     * Generate content items, then export them to files.
     *
     * $params keys:
     *   topic, count, language, language_name, temperature,
     *   length_settings  (array with title/short_description/description sub-arrays),
     *   operator_rules   (string),
     *   prompt_template  (string, optional),
     *   generation_name  (string),
     *   destination_folder (string),
     *   output_format    (json|txt|html|md|csv),
     *   output_mode      (single_file|file_per_item|both),
     *   filename_template (string)
     */
    public function generate(array $params): array
    {
        $topic          = $params['topic'];
        $count          = (int)$params['count'];
        $language       = $params['language'];
        $languageName   = $params['language_name'] ?? $language;
        $temperature    = (float)($params['temperature'] ?? 0.7);
        $lengthSettings = $params['length_settings'] ?? [];
        $operatorRules  = $params['operator_rules']  ?? '';
        $promptTemplate = $params['prompt_template']
            ?? $this->config['default_prompt_template']
            ?? PromptBuilder::DEFAULT_TEMPLATE;

        // Export / file settings
        $generationName   = trim($params['generation_name'] ?? '');
        $destinationFolder = trim($params['destination_folder'] ?? '');
        $outputFormat     = $params['output_format']      ?? 'json';
        $outputMode       = $params['output_mode']        ?? 'single_file';
        $filenameTemplate = $params['filename_template']  ?? '{generation_slug}_{num}';
        $transliterate    = (bool)($this->config['transliterate_filenames'] ?? false);

        // Generate slug from name
        if ($generationName === '') {
            $generationName = 'generation_' . date('Ymd_His');
        }
        $generationSlug = $this->fileNameBuilder->slugify($generationName, true);

        // Inject global tolerance settings into lengthSettings
        $lengthSettings['allow_length_tolerance'] = (bool)($this->config['allow_length_tolerance'] ?? false);
        $lengthSettings['tolerance_percent']       = (int)($this->config['tolerance_percent'] ?? 10);

        // Auto-append medical safety rules if topic is health-related
        $medicalRules = $this->promptBuilder->buildMedicalSafetyRules($topic);
        if ($medicalRules !== '' && mb_strpos($operatorRules, '[АВТОМАТИЧЕСКИЕ ПРАВИЛА БЕЗОПАСНОСТИ') === false) {
            $operatorRules = $operatorRules !== ''
                ? $operatorRules . "\n\n" . $medicalRules
                : $medicalRules;
        }

        // Build the language string for the prompt
        $languageForPrompt = $languageName !== $language
            ? "Пиши строго на языке: {$languageName}. Код языка: {$language}."
            : $language;

        $id          = 'gen_' . date('Ymd') . '_' . bin2hex(random_bytes(4));
        $chunkSize   = (int)($this->config['chunk_size'] ?? 10);
        if ($chunkSize < 1) {
            $chunkSize = 10;
        }
        $totalChunks = (int)ceil($count / $chunkSize);

        $systemPrompt = 'Ты генератор JSON. Всегда возвращай только валидный JSON без markdown, без пояснений, без ```.';
        $allItems     = [];

        $autoRegenerate = (bool)($this->config['auto_regenerate_invalid_items'] ?? false);

        $dateDir  = date('Y-m-d');
        $savePath = STORAGE_PATH . '/generations/' . $dateDir . '/' . $id . '.json';

        $seenTitles = [];

        for ($chunk = 0; $chunk < $totalChunks; $chunk++) {
            $startId = $chunk * $chunkSize + 1;
            $endId   = min(($chunk + 1) * $chunkSize, $count);

            $userPrompt = $this->promptBuilder->buildPrompt([
                'topic'           => $topic,
                'count'           => $endId - $startId + 1,
                'language'        => $languageForPrompt,
                'length_settings' => $lengthSettings,
                'operator_rules'  => $operatorRules,
                'prompt_template' => $promptTemplate,
                'start_id'        => $startId,
                'end_id'          => $endId,
            ]);

            try {
                $raw    = $this->client->complete($systemPrompt, $userPrompt, $temperature);
                $parsed = $this->tryParseJson($raw);

                if ($parsed === null) {
                    Logger::error('JSON parse failed, attempting repair', [
                        'chunk' => $chunk,
                        'raw'   => mb_substr($raw, 0, 500),
                    ]);
                    $parsed = $this->repairJson($raw);
                }

                if ($parsed === null) {
                    Logger::error('JSON repair failed, skipping chunk', ['chunk' => $chunk]);
                    continue;
                }

                $chunkItems     = $this->extractItems($parsed);
                $validatedItems = [];
                $invalidItems   = [];

                foreach ($chunkItems as $item) {
                    if (!is_array($item)) {
                        continue;
                    }

                    $titleVal = trim((string)($item['title'] ?? ''));
                    if ($titleVal !== '' && in_array($titleVal, $seenTitles, true)) {
                        $item['validation'] = [
                            'title_chars'             => $this->validator->countChars($titleVal),
                            'short_description_chars' => $this->validator->countChars((string)($item['short_description'] ?? '')),
                            'description_chars'       => $this->validator->countChars((string)($item['description'] ?? '')),
                            'valid_length'            => false,
                            'errors'                  => ['Дубликат title.'],
                        ];
                        $invalidItems[] = $item;
                        continue;
                    }

                    $validated = $this->validator->validateItem($item, $lengthSettings);
                    if ($validated['validation']['valid_length']) {
                        if ($titleVal !== '') {
                            $seenTitles[] = $titleVal;
                        }
                        $validatedItems[] = $validated;
                    } else {
                        $invalidItems[] = $validated;
                    }
                }

                if ($autoRegenerate && !empty($invalidItems)) {
                    $regenItems = $this->regenerateInvalidItems(
                        $invalidItems, $systemPrompt, $topic, $languageForPrompt,
                        $temperature, $lengthSettings, $operatorRules, $promptTemplate, $seenTitles
                    );
                    foreach ($regenItems as $ri) {
                        if (isset($ri['validation']['valid_length']) && $ri['validation']['valid_length']) {
                            $tVal = trim((string)($ri['title'] ?? ''));
                            if ($tVal !== '') {
                                $seenTitles[] = $tVal;
                            }
                            $validatedItems[] = $ri;
                        } else {
                            $validatedItems[] = $ri;
                        }
                    }
                } else {
                    $validatedItems = array_merge($validatedItems, $invalidItems);
                }

                $allItems = array_merge($allItems, $validatedItems);

            } catch (RuntimeException $e) {
                Logger::error('Generation chunk failed', ['chunk' => $chunk, 'error' => $e->getMessage()]);
                continue;
            }

            // Save partial result after each chunk
            $partial = $this->buildResult(
                $id, $topic, $count, $language, $allItems,
                $lengthSettings, $operatorRules, $promptTemplate,
                $generationName, $generationSlug, $destinationFolder,
                $outputFormat, $outputMode, $filenameTemplate, []
            );
            JsonStore::write($savePath, $partial);
        }

        // ── Export files ─────────────────────────────────────────────────────
        $exportSettings = [
            'output_format'     => $outputFormat,
            'output_mode'       => $outputMode,
            'destination_folder' => $destinationFolder,
            'filename_template' => $filenameTemplate,
            'transliterate'     => $transliterate,
        ];

        $files = [];
        $exportError = '';
        try {
            // Build generation array for export (without final files key to avoid circular)
            $genForExport = $this->buildResult(
                $id, $topic, $count, $language, $allItems,
                $lengthSettings, $operatorRules, $promptTemplate,
                $generationName, $generationSlug, $destinationFolder,
                $outputFormat, $outputMode, $filenameTemplate, []
            );
            $files = $this->exportService->export($genForExport, $exportSettings);

            // Write metadata.json to the export folder
            $this->writeExportMetadata($genForExport, $exportSettings, $files);
        } catch (RuntimeException $e) {
            $exportError = $e->getMessage();
            Logger::error('Export failed', ['error' => $exportError]);
        }

        $final = $this->buildResult(
            $id, $topic, $count, $language, $allItems,
            $lengthSettings, $operatorRules, $promptTemplate,
            $generationName, $generationSlug, $destinationFolder,
            $outputFormat, $outputMode, $filenameTemplate, $files
        );

        if ($exportError !== '') {
            $final['export_error'] = $exportError;
        }

        JsonStore::write($savePath, $final);
        Logger::info('Generation completed', ['id' => $id, 'received' => count($allItems)]);

        return $final;
    }

    // ── Private helpers ────────────────────────────────────────────────────────

    private function buildResult(
        string $id,
        string $topic,
        int $count,
        string $language,
        array $items,
        array $lengthSettings,
        string $operatorRules,
        string $promptTemplate,
        string $generationName,
        string $generationSlug,
        string $destinationFolder,
        string $outputFormat,
        string $outputMode,
        string $filenameTemplate,
        array $files
    ): array {
        $model = method_exists($this->client, 'getModel') ? $this->client->getModel() : 'unknown';

        $invalidCount = 0;
        foreach ($items as $item) {
            if (isset($item['validation']['valid_length']) && !$item['validation']['valid_length']) {
                $invalidCount++;
            }
        }

        return [
            'id'                 => $id,
            'generation_name'    => $generationName,
            'generation_slug'    => $generationSlug,
            'topic'              => $topic,
            'requested_count'    => $count,
            'received_count'     => count($items),
            'language'           => $language,
            'provider'           => $this->client->getName(),
            'model'              => $model,
            'created_at'         => date('c'),
            'destination_folder' => $destinationFolder,
            'output_format'      => $outputFormat,
            'output_mode'        => $outputMode,
            'filename_template'  => $filenameTemplate,
            'length_settings'    => $lengthSettings,
            'operator_rules'     => $operatorRules,
            'prompt_template'    => $promptTemplate,
            'validation_summary' => [
                'total'   => count($items),
                'valid'   => count($items) - $invalidCount,
                'invalid' => $invalidCount,
            ],
            'files'              => $files,
            'items'              => $items,
        ];
    }

    private function writeExportMetadata(array $generation, array $settings, array $files): void
    {
        $destFolder = $settings['destination_folder'] ?? '';
        $pathGuard  = new PathGuard();
        $absDir     = $pathGuard->resolveExportPath($destFolder);

        $metadata = [
            'id'                 => $generation['id'],
            'generation_name'    => $generation['generation_name'] ?? '',
            'generation_slug'    => $generation['generation_slug'] ?? '',
            'topic'              => $generation['topic']           ?? '',
            'destination_folder' => $destFolder,
            'output_format'      => $settings['output_format']  ?? 'json',
            'output_mode'        => $settings['output_mode']    ?? 'single_file',
            'filename_template'  => $settings['filename_template'] ?? '',
            'language'           => $generation['language']       ?? '',
            'requested_count'    => $generation['requested_count'] ?? 0,
            'received_count'     => $generation['received_count']  ?? 0,
            'created_at'         => $generation['created_at']      ?? date('c'),
            'files'              => $files,
        ];

        $metaPath = $absDir . '/metadata.json';
        JsonStore::write($metaPath, $metadata);
    }

    private function extractItems(array $parsed): array
    {
        if (isset($parsed['items']) && is_array($parsed['items'])) {
            return $parsed['items'];
        }
        if (array_is_list($parsed)) {
            return $parsed;
        }
        return [$parsed];
    }

    private function tryParseJson(string $text): ?array
    {
        $cleaned = preg_replace('/^```(?:json)?\s*/i', '', trim($text));
        $cleaned = preg_replace('/\s*```$/', '', (string)$cleaned);
        $cleaned = trim((string)$cleaned);

        $data = json_decode($cleaned, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
            return $data;
        }
        return null;
    }

    private function repairJson(string $broken): ?array
    {
        $systemPrompt = 'Ты эксперт по JSON. Верни исправленный валидный JSON без пояснений, без markdown.';
        $userPrompt   = "Исправь следующий невалидный JSON и верни только валидный JSON:\n\n" . $broken;

        try {
            $fixed = $this->client->complete($systemPrompt, $userPrompt, 0.0);
            return $this->tryParseJson($fixed);
        } catch (RuntimeException $e) {
            Logger::error('JSON repair request failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function regenerateInvalidItems(
        array  $invalidItems,
        string $systemPrompt,
        string $topic,
        string $language,
        float  $temperature,
        array  $lengthSettings,
        string $operatorRules,
        string $promptTemplate,
        array  $seenTitles
    ): array {
        if (empty($invalidItems)) {
            return [];
        }

        $ids = array_filter(array_map(fn($i) => isset($i['id']) ? (int)$i['id'] : null, $invalidItems));
        if (empty($ids)) {
            return [];
        }

        $startId = min($ids);
        $endId   = max($ids);

        $userPrompt = $this->promptBuilder->buildPrompt([
            'topic'           => $topic,
            'count'           => count($ids),
            'language'        => $language,
            'length_settings' => $lengthSettings,
            'operator_rules'  => $operatorRules . "\n\nВажно: перегенерируй только элементы с id: " . implode(', ', $ids) . ". Строго соблюдай требования к длине.",
            'prompt_template' => $promptTemplate,
            'start_id'        => $startId,
            'end_id'          => $endId,
        ]);

        try {
            $raw    = $this->client->complete($systemPrompt, $userPrompt, $temperature);
            $parsed = $this->tryParseJson($raw);
            if ($parsed === null) {
                $parsed = $this->repairJson($raw);
            }
            if ($parsed === null) {
                Logger::error('Regen JSON parse failed');
                return $invalidItems;
            }

            $regenItems = $this->extractItems($parsed);
            $result     = [];

            foreach ($regenItems as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $tVal = trim((string)($item['title'] ?? ''));
                if ($tVal !== '' && in_array($tVal, $seenTitles, true)) {
                    $item['validation'] = ['valid_length' => false, 'errors' => ['Дубликат title после перегенерации.']];
                    $result[] = $item;
                    continue;
                }
                $result[] = $this->validator->validateItem($item, $lengthSettings);
            }

            return $result;
        } catch (RuntimeException $e) {
            Logger::error('Regen chunk failed', ['error' => $e->getMessage()]);
            return $invalidItems;
        }
    }
}
