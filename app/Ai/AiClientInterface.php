<?php
declare(strict_types=1);

interface AiClientInterface
{
    public function complete(string $systemPrompt, string $userPrompt, float $temperature): string;
    public function getName(): string;
}
