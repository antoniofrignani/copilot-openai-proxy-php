<?php

namespace App\Controllers;

use App\Services\AttachmentStore;
use App\Services\CopilotClient;
use App\Utils\SseStream;
use GuzzleHttp\Exception\RequestException;
use Throwable;

class ChatController
{
    public function handle()
    {
        try {
            $rawInput = file_get_contents('php://input');
            $input = json_decode($rawInput, true);
            if (!is_array($input)) {
                $this->sendJsonError(400, 'Invalid JSON payload', json_last_error_msg());
                return;
            }

            $messages = $this->extractMessages($input);
            $stream = $input['stream'] ?? false;
            $isResponsesRequest = $this->isResponsesRequest();
            if ($isResponsesRequest) {
                $messages = $this->injectAttachmentContext($input, $messages);
            }

            $client = new CopilotClient();

            if ($stream) {
                $response = $client->streamChat($messages);

                header('Content-Type: text/event-stream');
                header('Cache-Control: no-cache');

                if ($isResponsesRequest) {
                    SseStream::forwardAsResponses($response);
                } else {
                    SseStream::forward($response);
                }
                return;
            }

            $response = $client->chat($messages);
            $this->normalizeFinishReasons($response);

            if ($isResponsesRequest) {
                $response = $this->toResponsesApiResponse($response);
            }

            echo json_encode($response);
        } catch (RequestException $e) {
            $status = $e->hasResponse() ? $e->getResponse()->getStatusCode() : 502;
            $responseBody = $e->hasResponse() ? (string) $e->getResponse()->getBody() : '';
            error_log(sprintf('Upstream request failed (%d): %s %s', $status, $e->getMessage(), $responseBody));
            $this->sendJsonError($status, 'Upstream request failed', $e->getMessage());
        } catch (Throwable $e) {
            error_log(sprintf('Internal proxy error: %s', $e->getMessage()));
            $this->sendJsonError(500, 'Internal proxy error', $e->getMessage());
        }
    }

    private function extractMessages(array $input): array
    {
        if (isset($input['messages']) && is_array($input['messages'])) {
            return $input['messages'];
        }

        if (!array_key_exists('input', $input)) {
            return [];
        }

        if (is_string($input['input'])) {
            return [[
                'role' => 'user',
                'content' => $input['input']
            ]];
        }

        if (!is_array($input['input'])) {
            return [];
        }

        $messages = [];
        foreach ($input['input'] as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $role = $entry['role'] ?? 'user';
            $content = $entry['content'] ?? '';

            if (is_string($content)) {
                $messages[] = [
                    'role' => $role,
                    'content' => $content
                ];
                continue;
            }

            if (!is_array($content)) {
                continue;
            }

            $textParts = [];
            foreach ($content as $part) {
                if (!is_array($part)) {
                    continue;
                }

                $type = $part['type'] ?? '';
                if (($type === 'input_text' || $type === 'text') && isset($part['text']) && is_string($part['text'])) {
                    $textParts[] = $part['text'];
                }
            }

            if ($textParts !== []) {
                $messages[] = [
                    'role' => $role,
                    'content' => implode("\n", $textParts)
                ];
            }
        }

        return $messages;
    }

    private function normalizeFinishReasons(array &$payload): void
    {
        if (!isset($payload['choices']) || !is_array($payload['choices'])) {
            return;
        }

        foreach ($payload['choices'] as &$choice) {
            if (!is_array($choice) || !isset($choice['finish_reason']) || !is_string($choice['finish_reason'])) {
                continue;
            }

            $allowed = ['stop', 'length', 'tool_calls', 'content_filter', 'function_call'];
            if (!in_array($choice['finish_reason'], $allowed, true)) {
                $choice['finish_reason'] = 'stop';
            }
        }
    }

    private function isResponsesRequest(): bool
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        $path = parse_url($uri, PHP_URL_PATH) ?: $uri;
        return $path === '/v1/responses';
    }

    private function toResponsesApiResponse(array $chatCompletion): array
    {
        $text = '';
        $content = $chatCompletion['choices'][0]['message']['content'] ?? '';
        if (is_string($content)) {
            $text = $content;
        }

        $model = is_string($chatCompletion['model'] ?? null) ? $chatCompletion['model'] : 'unknown';

        return [
            'id' => 'resp_' . bin2hex(random_bytes(12)),
            'object' => 'response',
            'created_at' => time(),
            'status' => 'completed',
            'model' => $model,
            'output' => [[
                'id' => 'msg_' . bin2hex(random_bytes(12)),
                'type' => 'message',
                'status' => 'completed',
                'role' => 'assistant',
                'content' => [[
                    'type' => 'output_text',
                    'text' => $text,
                    'annotations' => [],
                ]],
            ]],
            'output_text' => $text,
            'usage' => [
                'input_tokens' => (int) ($chatCompletion['usage']['prompt_tokens'] ?? 0),
                'output_tokens' => (int) ($chatCompletion['usage']['completion_tokens'] ?? 0),
                'total_tokens' => (int) ($chatCompletion['usage']['total_tokens'] ?? 0),
            ],
        ];
    }

    private function sendJsonError(int $statusCode, string $error, string $details): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        echo json_encode([
            'error' => $error,
            'details' => $details,
        ]);
    }

    private function injectAttachmentContext(array $input, array $messages): array
    {
        $vectorStoreIds = $this->extractVectorStoreIds($input);
        if ($vectorStoreIds === []) {
            return $messages;
        }

        $query = '';
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            $message = $messages[$i] ?? null;
            if (!is_array($message)) {
                continue;
            }
            if (($message['role'] ?? '') !== 'user') {
                continue;
            }
            $content = $message['content'] ?? '';
            if (is_string($content) && $content !== '') {
                $query = $content;
                break;
            }
        }

        $store = new AttachmentStore();
        $context = $store->buildContextForVectorStores($vectorStoreIds, $query);
        if ($context === '') {
            return $messages;
        }

        array_unshift($messages, [
            'role' => 'system',
            'content' => "Use the following retrieved file context to answer when relevant. Cite filenames when possible.\n\n" . $context,
        ]);

        return $messages;
    }

    private function extractVectorStoreIds(array $input): array
    {
        $ids = [];

        $toolResources = $input['tool_resources']['file_search']['vector_store_ids'] ?? null;
        if (is_array($toolResources)) {
            foreach ($toolResources as $id) {
                if (is_string($id) && $id !== '') {
                    $ids[$id] = true;
                }
            }
        }

        $tools = $input['tools'] ?? null;
        if (is_array($tools)) {
            foreach ($tools as $tool) {
                if (!is_array($tool)) {
                    continue;
                }
                if (($tool['type'] ?? '') !== 'file_search') {
                    continue;
                }
                $toolIds = $tool['vector_store_ids'] ?? null;
                if (!is_array($toolIds)) {
                    continue;
                }
                foreach ($toolIds as $id) {
                    if (is_string($id) && $id !== '') {
                        $ids[$id] = true;
                    }
                }
            }
        }

        return array_keys($ids);
    }
}
