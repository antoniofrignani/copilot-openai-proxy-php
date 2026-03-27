<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use RuntimeException;

class TokenManager
{
    private Client $http;
    private ?string $cachedToken = null;
    private int $expiresAt = 0;
    private const USER_AGENT = 'GitHubCopilotChat/0.26.7';
    private const EDITOR_PLUGIN_VERSION = 'copilot-chat/0.26.7';
    private const EDITOR_VERSION = 'vscode/1.99.0';
    private const API_VERSION = '2025-04-01';

    public function __construct()
    {
        $this->http = new Client();
    }

    public function getToken(): string
    {
        if ($this->cachedToken && time() < $this->expiresAt) {
            return $this->cachedToken;
        }

        return $this->refreshToken();
    }

    public function refreshToken(): string
    {
        $githubToken = $_ENV['GITHUB_TOKEN'] ?? null;
        if (!$githubToken) {
            throw new RuntimeException('GITHUB_TOKEN is not configured');
        }

        try {
            $data = $this->fetchCopilotTokenData($githubToken, 'https://api.github.com/copilot_internal/v2/token');
        } catch (RuntimeException $e) {
            if (str_contains($e->getMessage(), '"status":"404"')) {
                $data = $this->fetchCopilotTokenData($githubToken, 'https://api.github.com/copilot_internal/user');
            } else {
                throw $e;
            }
        }

        if (!isset($data['token']) || !is_string($data['token']) || $data['token'] === '') {
            if (isset($data['chat_enabled'])) {
                throw new RuntimeException(
                    'GitHub token is valid but cannot mint a Copilot API token. Use a token from the GitHub Copilot device flow (client_id Iv1.b507a08c87ecfe98), not a PAT or GitHub CLI token.'
                );
            }

            throw new RuntimeException('No Copilot token returned by GitHub. Ensure your account has Copilot access and GITHUB_TOKEN is valid.');
        }

        $this->cachedToken = $data['token'];
        $this->expiresAt = $this->resolveExpiry($data);

        return $this->cachedToken;
    }

    private function fetchCopilotTokenData(string $githubToken, string $url): array
    {
        try {
            $res = $this->http->get(
                $url,
                [
                    'headers' => [
                        'Authorization' => 'token ' . $githubToken,
                        'Accept' => 'application/json',
                        'Content-Type' => 'application/json',
                        'Editor-Version' => self::EDITOR_VERSION,
                        'Editor-Plugin-Version' => self::EDITOR_PLUGIN_VERSION,
                        'User-Agent' => self::USER_AGENT,
                        'X-GitHub-Api-Version' => self::API_VERSION,
                        'X-Vscode-User-Agent-Library-Version' => 'electron-fetch'
                    ]
                ]
            );
        } catch (RequestException $e) {
            $details = $e->hasResponse() ? trim((string) $e->getResponse()->getBody()) : $e->getMessage();
            throw new RuntimeException('Failed to get Copilot token from GitHub: ' . $details, 0, $e);
        }

        $data = json_decode($res->getBody(), true);
        if (!is_array($data)) {
            throw new RuntimeException('Invalid token response received from GitHub');
        }

        return $data;
    }

    private function resolveExpiry(array $data): int
    {
        if (isset($data['expires_at']) && is_numeric($data['expires_at'])) {
            return max(time() + 60, ((int) $data['expires_at']) - 60);
        }

        return time() + 50 * 60;
    }
}
