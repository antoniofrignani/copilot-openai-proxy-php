<?php

namespace App\Utils;

class SseStream
{
    public static function forward($response)
    {
        $body = $response->getBody();
        $buffer = '';

        while (!$body->eof()) {
            $chunk = $body->read(1024);
            if ($chunk === '') {
                continue;
            }

            $buffer .= $chunk;
            while (($lineEnd = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $lineEnd + 1);
                $buffer = substr($buffer, $lineEnd + 1);
                echo self::normalizeSseLine($line);
            }

            ob_flush();
            flush();
        }

        if ($buffer !== '') {
            echo self::normalizeSseLine($buffer);
            ob_flush();
            flush();
        }
    }

    private static function normalizeSseLine(string $line): string
    {
        if (!str_starts_with($line, 'data: ')) {
            return $line;
        }

        $payload = trim(substr($line, 6));
        if ($payload === '[DONE]') {
            return $line;
        }

        $decoded = json_decode($payload, true);
        if (!is_array($decoded) || !isset($decoded['choices']) || !is_array($decoded['choices'])) {
            return $line;
        }

        foreach ($decoded['choices'] as &$choice) {
            if (!is_array($choice) || !isset($choice['finish_reason']) || !is_string($choice['finish_reason'])) {
                continue;
            }

            $allowed = ['stop', 'length', 'tool_calls', 'content_filter', 'function_call'];
            if (!in_array($choice['finish_reason'], $allowed, true)) {
                $choice['finish_reason'] = 'stop';
            }
        }

        return 'data: ' . json_encode($decoded) . "\n";
    }

    public static function forwardAsResponses($response): void
    {
        $body = $response->getBody();
        $buffer = '';
        $responseId = 'resp_' . bin2hex(random_bytes(12));
        $messageId = 'msg_' . bin2hex(random_bytes(12));
        $createdAt = time();
        $model = 'unknown';
        $outputText = '';
        $usage = null;
        $createdSent = false;

        while (!$body->eof()) {
            $chunk = $body->read(1024);
            if ($chunk === '') {
                continue;
            }

            $buffer .= $chunk;
            while (($lineEnd = strpos($buffer, "\n")) !== false) {
                $line = trim(substr($buffer, 0, $lineEnd));
                $buffer = substr($buffer, $lineEnd + 1);

                if (!str_starts_with($line, 'data: ')) {
                    continue;
                }

                $payload = trim(substr($line, 6));
                if ($payload === '' || $payload === '[DONE]') {
                    continue;
                }

                $decoded = json_decode($payload, true);
                if (!is_array($decoded)) {
                    continue;
                }

                if (isset($decoded['model']) && is_string($decoded['model']) && $decoded['model'] !== '') {
                    $model = $decoded['model'];
                }

                if (!$createdSent) {
                    self::emitResponsesEvent([
                        'type' => 'response.created',
                        'response' => [
                            'id' => $responseId,
                            'object' => 'response',
                            'created_at' => $createdAt,
                            'status' => 'in_progress',
                            'model' => $model,
                        ],
                    ]);
                    $createdSent = true;
                }

                if (isset($decoded['usage']) && is_array($decoded['usage'])) {
                    $usage = $decoded['usage'];
                }

                if (!isset($decoded['choices']) || !is_array($decoded['choices'])) {
                    continue;
                }

                foreach ($decoded['choices'] as $choice) {
                    if (!is_array($choice)) {
                        continue;
                    }

                    $delta = $choice['delta']['content'] ?? null;
                    if (is_string($delta) && $delta !== '') {
                        $outputText .= $delta;
                        self::emitResponsesEvent([
                            'type' => 'response.output_text.delta',
                            'response_id' => $responseId,
                            'item_id' => $messageId,
                            'output_index' => 0,
                            'content_index' => 0,
                            'delta' => $delta,
                        ]);
                    }
                }
            }
        }

        if (!$createdSent) {
            self::emitResponsesEvent([
                'type' => 'response.created',
                'response' => [
                    'id' => $responseId,
                    'object' => 'response',
                    'created_at' => $createdAt,
                    'status' => 'in_progress',
                    'model' => $model,
                ],
            ]);
        }

        self::emitResponsesEvent([
            'type' => 'response.output_text.done',
            'response_id' => $responseId,
            'item_id' => $messageId,
            'output_index' => 0,
            'content_index' => 0,
            'text' => $outputText,
        ]);

        self::emitResponsesEvent([
            'type' => 'response.completed',
            'response' => [
                'id' => $responseId,
                'object' => 'response',
                'created_at' => $createdAt,
                'status' => 'completed',
                'model' => $model,
                'output' => [[
                    'id' => $messageId,
                    'type' => 'message',
                    'status' => 'completed',
                    'role' => 'assistant',
                    'content' => [[
                        'type' => 'output_text',
                        'text' => $outputText,
                        'annotations' => [],
                    ]],
                ]],
                'output_text' => $outputText,
                'usage' => [
                    'input_tokens' => (int) ($usage['prompt_tokens'] ?? 0),
                    'output_tokens' => (int) ($usage['completion_tokens'] ?? 0),
                    'total_tokens' => (int) ($usage['total_tokens'] ?? 0),
                ],
            ],
        ]);

        self::emitRawLine('data: [DONE]' . "\n\n");
    }

    private static function emitResponsesEvent(array $event): void
    {
        self::emitRawLine('data: ' . json_encode($event) . "\n\n");
    }

    private static function emitRawLine(string $line): void
    {
        echo $line;
        ob_flush();
        flush();
    }
}
