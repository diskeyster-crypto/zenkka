<?php
declare(strict_types=1);

class OpenRouterClient implements AiClientInterface
{
    private string $apiKey;
    private string $model;

    public function __construct(string $apiKey, string $model = 'openrouter/auto')
    {
        $this->apiKey = $apiKey;
        $this->model  = $model;
    }

    public function complete(string $systemPrompt, string $userPrompt, float $temperature): string
    {
        $payload = json_encode([
            'model'       => $this->model,
            'temperature' => $temperature,
            'messages'    => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user',   'content' => $userPrompt],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $ch = curl_init('https://openrouter.ai/api/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json',
                'HTTP-Referer: https://zenkka.app',
                'X-Title: Zenkka CMS',
            ],
        ]);

        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($curlError !== '') {
            Logger::error('OpenRouter curl error', ['error' => $curlError]);
            throw new RuntimeException('OpenRouter request failed: ' . $curlError);
        }

        $data = json_decode((string)$response, true);
        if ($httpCode !== 200 || !isset($data['choices'][0]['message']['content'])) {
            $errMsg = $data['error']['message'] ?? ('HTTP ' . $httpCode);
            Logger::error('OpenRouter API error', ['http_code' => $httpCode, 'response' => $response]);
            throw new RuntimeException('OpenRouter API error: ' . $errMsg);
        }

        return $data['choices'][0]['message']['content'];
    }

    public function getName(): string
    {
        return 'openrouter';
    }

    public function getModel(): string
    {
        return $this->model;
    }
}
