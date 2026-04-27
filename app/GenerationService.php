<?php
declare(strict_types=1);

class GenerationService
{
    private AiClientInterface $client;
    private array $config;

    public function __construct(AiClientInterface $client, array $config)
    {
        $this->client = $client;
        $this->config = $config;
    }

    public function generate(array $params): array
    {
        $topic       = $params['topic'];
        $count       = (int)$params['count'];
        $language    = $params['language'];
        $format      = $params['format'];
        $temperature = (float)$params['temperature'];

        $id         = 'gen_' . date('Ymd') . '_' . bin2hex(random_bytes(4));
        $chunkSize  = (int)($this->config['chunk_size'] ?? 10);
        if ($chunkSize < 1) {
            $chunkSize = 10;
        }
        $totalChunks = (int)ceil($count / $chunkSize);

        $systemPrompt = 'Ты генератор JSON. Всегда возвращай только валидный JSON без markdown, без пояснений, без ```.';
        $allItems     = [];

        $dateDir  = date('Y-m-d');
        $savePath = STORAGE_PATH . '/generations/' . $dateDir . '/' . $id . '.json';

        for ($chunk = 0; $chunk < $totalChunks; $chunk++) {
            $startId = $chunk * $chunkSize + 1;
            $endId   = min(($chunk + 1) * $chunkSize, $count);

            $userPrompt = $this->buildUserPrompt($topic, $startId, $endId, $language, $format);

            try {
                $raw    = $this->client->complete($systemPrompt, $userPrompt, $temperature);
                $parsed = $this->tryParseJson($raw);

                if ($parsed === null) {
                    Logger::error('JSON parse failed, attempting repair', ['chunk' => $chunk, 'raw' => mb_substr($raw, 0, 500)]);
                    $parsed = $this->repairJson($raw);
                }

                if ($parsed === null) {
                    Logger::error('JSON repair failed, skipping chunk', ['chunk' => $chunk]);
                    continue;
                }

                // Accept both a direct array of items or an object with an 'items' key
                if (isset($parsed['items']) && is_array($parsed['items'])) {
                    $allItems = array_merge($allItems, $parsed['items']);
                } elseif (array_is_list($parsed)) {
                    $allItems = array_merge($allItems, $parsed);
                } else {
                    // Single object — wrap it
                    $allItems[] = $parsed;
                }
            } catch (RuntimeException $e) {
                Logger::error('Generation chunk failed', ['chunk' => $chunk, 'error' => $e->getMessage()]);
                continue;
            }

            // Save partial result after each chunk
            $partial = $this->buildResult($id, $topic, $count, $language, $allItems);
            JsonStore::write($savePath, $partial);
        }

        $model = method_exists($this->client, 'getModel') ? $this->client->getModel() : 'unknown';

        $final = [
            'id'              => $id,
            'topic'           => $topic,
            'requested_count' => $count,
            'received_count'  => count($allItems),
            'language'        => $language,
            'format'          => $format,
            'provider'        => $this->client->getName(),
            'model'           => $model,
            'created_at'      => date('Y-m-d H:i:s'),
            'items'           => $allItems,
        ];

        JsonStore::write($savePath, $final);
        Logger::info('Generation completed', ['id' => $id, 'received' => count($allItems)]);

        return $final;
    }

    private function buildResult(string $id, string $topic, int $count, string $language, array $items): array
    {
        $model = method_exists($this->client, 'getModel') ? $this->client->getModel() : 'unknown';
        return [
            'id'              => $id,
            'topic'           => $topic,
            'requested_count' => $count,
            'received_count'  => count($items),
            'language'        => $language,
            'provider'        => $this->client->getName(),
            'model'           => $model,
            'created_at'      => date('Y-m-d H:i:s'),
            'items'           => $items,
        ];
    }

    private function buildUserPrompt(string $topic, int $startId, int $endId, string $language, string $format): string
    {
        $itemCount = $endId - $startId + 1;

        $formatDesc = match ($format) {
            'title'    => 'только заголовок (поле "title")',
            'short'    => 'заголовок и краткое описание 1-2 предложения (поля "title", "description")',
            'extended' => 'заголовок, краткое описание и развёрнутый текст 3-5 предложений (поля "title", "description", "body")',
            default    => 'только заголовок (поле "title")',
        };

        return sprintf(
            'Сгенерируй %d элементов (id с %d по %d) на тему "%s" на языке "%s". '
            . 'Каждый элемент должен содержать поле "id" (число) и %s. '
            . 'Верни JSON-массив: [{"id": %d, ...}, ..., {"id": %d, ...}]. '
            . 'Только JSON, без пояснений.',
            $itemCount,
            $startId,
            $endId,
            $topic,
            $language,
            $formatDesc,
            $startId,
            $endId
        );
    }

    private function tryParseJson(string $text): ?array
    {
        // Strip markdown fences if present
        $cleaned = preg_replace('/^```(?:json)?\s*/i', '', trim($text));
        $cleaned = preg_replace('/\s*```$/', '', $cleaned);
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
}
