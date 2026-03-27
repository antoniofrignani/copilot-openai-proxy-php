<?php

namespace App\Controllers;

use App\Services\CopilotClient;
use Throwable;

class ModelsController
{
    public function handle(string $path, string $method): void
    {
        if ($method !== 'GET') {
            http_response_code(405);
            header('Content-Type: application/json');
            echo json_encode([
                'error' => [
                    'message' => 'Method not allowed',
                    'type' => 'invalid_request_error',
                ],
            ]);
            return;
        }

        try {
            $client = new CopilotClient();
            $models = $client->models();

            if ($path === '/v1/models') {
                $this->sendModelsList($models);
                return;
            }

            if (preg_match('#^/v1/models/([^/]+)$#', $path, $matches) === 1) {
                $this->sendModel($models, $matches[1]);
                return;
            }

            http_response_code(404);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Not found']);
        } catch (Throwable $e) {
            // Fallback static set so clients can proceed even if upstream listing fails.
            $fallback = $this->fallbackModels();
            if ($path === '/v1/models') {
                $this->sendModelsList($fallback);
                return;
            }

            if (preg_match('#^/v1/models/([^/]+)$#', $path, $matches) === 1) {
                $this->sendModel($fallback, $matches[1]);
                return;
            }

            http_response_code(404);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Not found']);
        }
    }

    private function sendModelsList(array $models): void
    {
        $list = $this->normalizeModelsList($models);
        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode($list);
    }

    private function sendModel(array $models, string $modelId): void
    {
        $list = $this->normalizeModelsList($models);
        foreach ($list['data'] as $model) {
            if (($model['id'] ?? '') === $modelId) {
                http_response_code(200);
                header('Content-Type: application/json');
                echo json_encode($model);
                return;
            }
        }

        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode([
            'error' => [
                'message' => 'Model not found',
                'type' => 'invalid_request_error',
            ],
        ]);
    }

    private function normalizeModelsList(array $models): array
    {
        if (isset($models['data']) && is_array($models['data'])) {
            return [
                'object' => 'list',
                'data' => array_map([$this, 'normalizeModel'], $models['data']),
            ];
        }

        if (array_is_list($models)) {
            return [
                'object' => 'list',
                'data' => array_map([$this, 'normalizeModel'], $models),
            ];
        }

        return [
            'object' => 'list',
            'data' => [],
        ];
    }

    private function normalizeModel($model): array
    {
        if (is_string($model)) {
            return [
                'id' => $model,
                'object' => 'model',
                'created' => 0,
                'owned_by' => 'github-copilot',
            ];
        }

        if (is_array($model)) {
            return [
                'id' => (string) ($model['id'] ?? $model['name'] ?? 'unknown'),
                'object' => 'model',
                'created' => (int) ($model['created'] ?? 0),
                'owned_by' => (string) ($model['owned_by'] ?? 'github-copilot'),
            ];
        }

        return [
            'id' => 'unknown',
            'object' => 'model',
            'created' => 0,
            'owned_by' => 'github-copilot',
        ];
    }

    private function fallbackModels(): array
    {
        return [
            'object' => 'list',
            'data' => [
                ['id' => 'gpt-4o', 'object' => 'model', 'created' => 0, 'owned_by' => 'github-copilot'],
                ['id' => 'gpt-4o-mini', 'object' => 'model', 'created' => 0, 'owned_by' => 'github-copilot'],
                ['id' => 'gpt-4.1', 'object' => 'model', 'created' => 0, 'owned_by' => 'github-copilot'],
                ['id' => 'o3-mini', 'object' => 'model', 'created' => 0, 'owned_by' => 'github-copilot'],
                ['id' => 'claude-3.5-sonnet', 'object' => 'model', 'created' => 0, 'owned_by' => 'github-copilot'],
            ],
        ];
    }
}
