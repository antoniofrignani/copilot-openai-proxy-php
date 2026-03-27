<?php

namespace App;

use App\Controllers\ChatController;
use App\Controllers\FilesController;
use App\Controllers\VectorStoreController;

class Router
{
    public function handle(string $uri, string $method)
    {
        $path = parse_url($uri, PHP_URL_PATH) ?: $uri;
        $this->logRequest($uri, $method);

        if (
            $method === 'POST'
            && in_array($path, ['/v1/chat/completions', '/v1/responses'], true)
        ) {
            (new ChatController())->handle();
            return;
        }

        if (str_starts_with($path, '/v1/vector_stores')) {
            (new VectorStoreController())->handle($path, $method);
            return;
        }

        if (str_starts_with($path, '/v1/files')) {
            (new FilesController())->handle($path, $method);
            return;
        }

        http_response_code(404);
        echo json_encode(['error' => 'Not found']);
    }

    private function logRequest(string $uri, string $method): void
    {
        $clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        error_log(sprintf('[%s] %s %s from %s', date('c'), $method, $uri, $clientIp));
    }
}
