<?php
declare(strict_types=1);

class GeminiClient implements AiClientInterface
{
    private string $apiKey;
    private string $model;

    public function __construct(string $apiKey, string $model = 'gemini-1.5-flash')
    {
        $this->apiKey = $apiKey;
        $this->model  = $model;
    }

    public function complete(string $systemPrompt, string $userPrompt, float $temperature): string
    {
        $url = sprintf(
            'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent?key=%s',
            rawurlencode($this->model),
            rawurlencode($this->apiKey)
        );

        $payload = json_encode([
            'system_instruction' => [
                'parts' => [['text' => $systemPrompt]],
            ],
            'contents' => [
                [
                    'role'  => 'user',
                    'parts' => [['text' => $userPrompt]],
                ],
            ],
            'generationConfig' => [
                'temperature' => $temperature,
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
            ],
        ]);

        $response  = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($curlError !== '') {
            Logger::error('Gemini curl error', ['error' => $curlError]);
            throw new RuntimeException('Gemini request failed: ' . $curlError);
        }

        $data = json_decode((string)$response, true);
        if ($httpCode !== 200 || !isset($data['candidates'][0]['content']['parts'][0]['text'])) {
            $errMsg = $data['error']['message'] ?? ('HTTP ' . $httpCode);
            Logger::error('Gemini API error', ['http_code' => $httpCode, 'response' => $response]);
            throw new RuntimeException('Gemini API error: ' . $errMsg);
        }

        return $data['candidates'][0]['content']['parts'][0]['text'];
    }

    public function getName(): string
    {
        return 'gemini';
    }

    public function getModel(): string
    {
        return $this->model;
    }
}
