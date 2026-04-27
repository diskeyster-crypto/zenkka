<?php
declare(strict_types=1);

class GenerationService
{
    private AiClientInterface    $client;
    private array                $config;
    private PromptBuilder        $promptBuilder;
    private GenerationValidator  $validator;

    public function __construct(AiClientInterface $client, array $config)
    {
        $this->client        = $client;
        $this->config        = $config;
        $this->promptBuilder = new PromptBuilder();
        $this->validator     = new GenerationValidator();
    }

    /**
     * Generate content items.
     *
     * $params keys:
     *   topic, count, language, temperature,
     *   length_settings  (array with title/short_description/description sub-arrays),
     *   operator_rules   (string),
     *   prompt_template  (string, optional)
     */
    public function generate(array $params): array
    {
        $topic          = $params['topic'];
        $count          = (int)$params['count'];
        $language       = $params['language'];
        $temperature    = (float)($params['temperature'] ?? 0.7);
        $lengthSettings = $params['length_settings'] ?? [];
        $operatorRules  = $params['operator_rules']  ?? '';
        $promptTemplate = $params['prompt_template']
            ?? $this->config['default_prompt_template']
            ?? PromptBuilder::DEFAULT_TEMPLATE;

        // Inject global tolerance settings into lengthSettings
        $lengthSettings['allow_length_tolerance'] = (bool)($this->config['allow_length_tolerance'] ?? false);
        $lengthSettings['tolerance_percent']       = (int)($this->config['tolerance_percent'] ?? 10);

        // Auto-append medical safety rules if topic is health-related
        $medicalRules  = $this->promptBuilder->buildMedicalSafetyRules($topic);
        if ($medicalRules !== '' && mb_strpos($operatorRules, '[АВТОМАТИЧЕСКИЕ ПРАВИЛА БЕЗОПАСНОСТИ') === false) {
            $operatorRules = $operatorRules !== ''
                ? $operatorRules . "\n\n" . $medicalRules
                : $medicalRules;
        }

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

        // Track seen titles/descriptions to detect duplicates across chunks
        $seenTitles = [];

        for ($chunk = 0; $chunk < $totalChunks; $chunk++) {
            $startId = $chunk * $chunkSize + 1;
            $endId   = min(($chunk + 1) * $chunkSize, $count);

            $userPrompt = $this->promptBuilder->buildPrompt([
                'topic'           => $topic,
                'count'           => $endId - $startId + 1,
                'language'        => $language,
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

                // Normalise: accept {items:[...]} or a plain array
                $chunkItems = $this->extractItems($parsed);

                // Validate each item
                $validatedItems  = [];
                $invalidItems    = [];

                foreach ($chunkItems as $item) {
                    if (!is_array($item)) {
                        continue;
                    }

                    // Check for cross-chunk duplicate titles
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

                // Optionally re-generate invalid items (one retry per chunk)
                if ($autoRegenerate && !empty($invalidItems)) {
                    $regenItems = $this->regenerateInvalidItems(
                        $invalidItems,
                        $systemPrompt,
                        $topic,
                        $language,
                        $temperature,
                        $lengthSettings,
                        $operatorRules,
                        $promptTemplate,
                        $seenTitles
                    );
                    foreach ($regenItems as $ri) {
                        if (isset($ri['validation']['valid_length']) && $ri['validation']['valid_length']) {
                            $tVal = trim((string)($ri['title'] ?? ''));
                            if ($tVal !== '') {
                                $seenTitles[] = $tVal;
                            }
                            $validatedItems[] = $ri;
                        } else {
                            // Still invalid — include with validation errors for transparency
                            $validatedItems[] = $ri;
                        }
                    }
                } else {
                    // Include invalid items with validation errors
                    $validatedItems = array_merge($validatedItems, $invalidItems);
                }

                $allItems = array_merge($allItems, $validatedItems);

            } catch (RuntimeException $e) {
                Logger::error('Generation chunk failed', ['chunk' => $chunk, 'error' => $e->getMessage()]);
                continue;
            }

            // Save partial result after each chunk
            $partial = $this->buildResult(
                $id, $topic, $count, $language,
                $allItems, $lengthSettings, $operatorRules, $promptTemplate
            );
            JsonStore::write($savePath, $partial);
        }

        $final = $this->buildResult(
            $id, $topic, $count, $language,
            $allItems, $lengthSettings, $operatorRules, $promptTemplate
        );

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
        string $promptTemplate
    ): array {
        $model = method_exists($this->client, 'getModel') ? $this->client->getModel() : 'unknown';

        // Build validation summary
        $invalidCount = 0;
        foreach ($items as $item) {
            if (isset($item['validation']['valid_length']) && !$item['validation']['valid_length']) {
                $invalidCount++;
            }
        }

        return [
            'id'              => $id,
            'topic'           => $topic,
            'requested_count' => $count,
            'received_count'  => count($items),
            'language'        => $language,
            'provider'        => $this->client->getName(),
            'model'           => $model,
            'created_at'      => date('c'),  // ISO 8601
            'length_settings' => $lengthSettings,
            'operator_rules'  => $operatorRules,
            'prompt_template' => $promptTemplate,
            'validation_summary' => [
                'total'   => count($items),
                'valid'   => count($items) - $invalidCount,
                'invalid' => $invalidCount,
            ],
            'items'           => $items,
        ];
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
        // Strip markdown fences if present
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

    /**
     * Re-generate only the invalid items by sending a targeted prompt.
     */
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
