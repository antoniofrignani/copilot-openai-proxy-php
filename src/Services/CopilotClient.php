<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use RuntimeException;

class CopilotClient
{
    private Client $http;
    private TokenManager $tokenManager;
    private const USER_AGENT = 'GitHubCopilotChat/0.26.7';
    private const EDITOR_PLUGIN_VERSION = 'copilot-chat/0.26.7';
    private const EDITOR_VERSION = 'vscode/1.99.0';
    private const API_VERSION = '2025-04-01';

    public function __construct()
    {
        $baseUrl = $_ENV['COPILOT_BASE_URL'] ?? null;
        if (!$baseUrl) {
            throw new RuntimeException('COPILOT_BASE_URL is not configured');
        }

        $this->http = new Client([
            'base_uri' => $baseUrl
        ]);

        $this->tokenManager = new TokenManager();
    }

    public function chat(array $messages): array
    {
        $res = $this->postChat([
            'messages' => $messages,
            'stream' => false
        ], false);

        return json_decode($res->getBody(), true);
    }

    public function streamChat(array $messages)
    {
        return $this->postChat([
            'messages' => $messages,
            'stream' => true
        ], true);
    }

    private function postChat(array $payload, bool $stream)
    {
        $primaryPath = '/v1/chat/completions';
        $fallbackPath = '/chat/completions';

        try {
            return $this->http->post($primaryPath, [
                'headers' => $this->headers($stream),
                'json' => $payload,
                'stream' => $stream
            ]);
        } catch (ClientException $e) {
            $status = $e->getResponse() ? $e->getResponse()->getStatusCode() : 0;
            if ($status !== 404) {
                throw $e;
            }

            return $this->http->post($fallbackPath, [
                'headers' => $this->headers($stream),
                'json' => $payload,
                'stream' => $stream
            ]);
        }
    }

    private function headers(bool $stream = false): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->tokenManager->getToken(),
            'Content-Type' => 'application/json',
            'Accept' => $stream ? 'text/event-stream' : 'application/json',
            'Copilot-Integration-Id' => 'vscode-chat',
            'Editor-Version' => self::EDITOR_VERSION,
            'Editor-Plugin-Version' => self::EDITOR_PLUGIN_VERSION,
            'User-Agent' => self::USER_AGENT,
            'OpenAI-Intent' => 'conversation-panel',
            'X-Request-Id' => bin2hex(random_bytes(16)),
            'X-GitHub-Api-Version' => self::API_VERSION,
            'X-Vscode-User-Agent-Library-Version' => 'electron-fetch'
        ];
    }
}
