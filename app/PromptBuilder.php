<?php
declare(strict_types=1);

class PromptBuilder
{
    public const DEFAULT_TEMPLATE = <<<'TPL'
Сгенерируй [count] уникальных вариантов текста на языке: [language].

Тема:
[topic]

Требования к длине:
[title_length_rules]
[short_description_length_rules]
[description_length_rules]

Правила оператора:
[operator_rules]

Верни только валидный JSON.
Не используй markdown.
Не добавляй пояснения до или после JSON.

JSON должен строго соответствовать схеме:
[output_schema]
TPL;

    /**
     * Build a human-readable length rule string for one field.
     * e.g. "title: ровно 50 символов" or "title: от 40 до 70 символов"
     */
    public function buildLengthRules(array $lengthSettings): string
    {
        $fields = ['title', 'short_description', 'description'];
        $lines  = [];

        foreach ($fields as $field) {
            if (!isset($lengthSettings[$field])) {
                continue;
            }
            $rule  = $lengthSettings[$field];
            $mode  = $rule['mode'] ?? 'range';
            $label = match ($field) {
                'title'             => 'title',
                'short_description' => 'short_description',
                'description'       => 'description',
                default             => $field,
            };

            if ($mode === 'exact') {
                $chars  = (int)($rule['exact'] ?? $rule['exact_chars'] ?? 0);
                $lines[] = "- {$label}: ровно {$chars} символов (mb_strlen)";
            } else {
                $min    = (int)($rule['min'] ?? $rule['min_chars'] ?? 0);
                $max    = (int)($rule['max'] ?? $rule['max_chars'] ?? 9999);
                $lines[] = "- {$label}: от {$min} до {$max} символов (mb_strlen)";
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Return the expected output JSON schema as a string.
     * IDs will be [startId..endId] — caller inserts that context into operator_rules.
     */
    public function buildOutputSchema(): string
    {
        return <<<'JSON'
{
  "items": [
    {
      "id": <число>,
      "title": "строка",
      "short_description": "строка",
      "description": "строка",
      "tags": ["строка"]
    }
  ]
}
JSON;
    }

    /**
     * Build the final prompt from a template and data.
     *
     * $data keys expected:
     *   topic, count, language, length_settings, operator_rules, start_id, end_id
     *
     * Optional:
     *   prompt_template  — if absent the DEFAULT_TEMPLATE is used
     */
    public function buildPrompt(array $data): string
    {
        $template = $data['prompt_template'] ?? self::DEFAULT_TEMPLATE;

        $lengthSettings        = $data['length_settings'] ?? [];
        $allLengthRules        = $this->buildLengthRules($lengthSettings);

        // Per-field rules for individual variables
        $fields         = ['title', 'short_description', 'description'];
        $fieldRuleLines = [];
        foreach ($fields as $field) {
            $single = $this->buildLengthRules([$field => $lengthSettings[$field] ?? []]);
            $fieldRuleLines[$field] = $single;
        }

        $startId     = (int)($data['start_id'] ?? 1);
        $endId       = (int)($data['end_id']   ?? (int)($data['count'] ?? 1));
        $chunkCount  = $endId - $startId + 1;

        // Merge operator rules + id range instruction
        $operatorRules = trim($data['operator_rules'] ?? '');
        $idInstruction = "Генерируй варианты с id от {$startId} до {$endId}. Количество вариантов: {$chunkCount}.";
        if ($operatorRules !== '') {
            $operatorRules = $idInstruction . "\n\n" . $operatorRules;
        } else {
            $operatorRules = $idInstruction;
        }

        $variables = [
            '[topic]'                           => $data['topic']        ?? '',
            '[count]'                           => (string)$chunkCount,
            '[language]'                        => $data['language']     ?? 'ru',
            '[title_length_rules]'              => $fieldRuleLines['title']             ?? '',
            '[short_description_length_rules]'  => $fieldRuleLines['short_description'] ?? '',
            '[description_length_rules]'        => $fieldRuleLines['description']       ?? '',
            '[operator_rules]'                  => $operatorRules,
            '[output_schema]'                   => $this->buildOutputSchema(),
        ];

        return $this->replaceVariables($template, $variables);
    }

    /**
     * Replace [variable] placeholders in a template string.
     */
    public function replaceVariables(string $template, array $variables): string
    {
        return str_replace(array_keys($variables), array_values($variables), $template);
    }

    /**
     * Detect if a topic is health/medical related and return safety rules string.
     */
    public function buildMedicalSafetyRules(string $topic): string
    {
        $medicalKeywords = [
            'препарат', 'лекарств', 'медикамент', 'таблетк', 'капсул', 'мазь', 'крем',
            'витамин', 'бад ', 'бады', 'биодобавк', 'аптек', 'врач', 'доктор',
            'лечени', 'болезн', 'симптом', 'диагноз', 'дозировк', 'приём', 'терапи',
            'здоровь', 'медицин', 'клиник', 'фармацевт', 'антибиотик', 'обезболивающ',
            'противовоспалительн', 'иммунитет', 'витамин', 'минерал',
            'drug', 'medicine', 'tablet', 'capsule', 'pharmacy', 'doctor', 'medical',
            'treatment', 'disease', 'symptom', 'dosage', 'therapy', 'health',
        ];

        $topicLower = mb_strtolower($topic);
        $isMedical  = false;
        foreach ($medicalKeywords as $kw) {
            if (mb_strpos($topicLower, $kw) !== false) {
                $isMedical = true;
                break;
            }
        }

        if (!$isMedical) {
            return '';
        }

        return <<<'RULES'
[АВТОМАТИЧЕСКИЕ ПРАВИЛА БЕЗОПАСНОСТИ — МЕДИЦИНСКАЯ ТЕМА]
- Текст информационный, не является медицинской рекомендацией.
- Не давать персональные медицинские рекомендации.
- Не назначать дозировки конкретным людям.
- Не обещать конкретный эффект или результат.
- Не утверждать, что препарат точно поможет.
- Не писать "безопасен для всех".
- Не заменять консультацию врача.
- Формулировать осторожно: "может", "обычно", "в некоторых случаях", "по назначению специалиста".
RULES;
    }
}
