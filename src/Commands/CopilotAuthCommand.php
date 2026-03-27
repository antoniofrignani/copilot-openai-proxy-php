<?php

namespace App\Commands;

use GuzzleHttp\Client;
use RuntimeException;

class CopilotAuthCommand
{
    private const GITHUB_CLIENT_ID = 'Iv1.b507a08c87ecfe98';

    public function run(): int
    {
        $client = new Client([
            'http_errors' => false,
            'timeout' => 30,
        ]);

        $device = $this->requestDeviceCode($client);
        $verificationUri = (string) $device['verification_uri'];
        $userCode = (string) $device['user_code'];
        $deviceCode = (string) $device['device_code'];
        $expiresAt = time() + (int) ($device['expires_in'] ?? 900);
        $interval = max(1, (int) ($device['interval'] ?? 5));

        fwrite(STDOUT, "Open: {$verificationUri}\n");
        fwrite(STDOUT, "Code: {$userCode}\n");
        fwrite(STDOUT, "Waiting for authorization...\n");

        while (time() < $expiresAt) {
            sleep($interval);

            $payload = $this->requestAccessToken($client, $deviceCode);
            if (isset($payload['access_token']) && is_string($payload['access_token'])) {
                $token = $payload['access_token'];
                $user = $this->fetchCopilotUser($client, $token);
                $copilotBaseUrl = $user['endpoints']['api'] ?? null;
                $this->writeEnvValue('GITHUB_TOKEN', $token);
                if (is_string($copilotBaseUrl) && $copilotBaseUrl !== '') {
                    $this->writeEnvValue('COPILOT_BASE_URL', $copilotBaseUrl);
                }

                $login = $user['login'] ?? 'unknown';
                fwrite(STDOUT, "Token saved to .env for GitHub user: {$login}\n");
                if (is_string($copilotBaseUrl) && $copilotBaseUrl !== '') {
                    fwrite(STDOUT, "Set COPILOT_BASE_URL={$copilotBaseUrl}\n");
                }
                return 0;
            }

            $error = $payload['error'] ?? null;
            if ($error === 'authorization_pending') {
                continue;
            }

            if ($error === 'slow_down') {
                $interval += 5;
                continue;
            }

            if ($error === 'access_denied') {
                throw new RuntimeException('Authorization was denied.');
            }

            if ($error === 'expired_token') {
                throw new RuntimeException('Device code expired. Run the command again.');
            }

            throw new RuntimeException('OAuth token request failed: ' . json_encode($payload));
        }

        throw new RuntimeException('Authorization timed out. Run the command again.');
    }

    private function requestDeviceCode(Client $client): array
    {
        $res = $client->post('https://github.com/login/device/code', [
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'form_params' => [
                'client_id' => self::GITHUB_CLIENT_ID,
                'scope' => 'read:user',
            ],
        ]);

        $data = json_decode((string) $res->getBody(), true);
        if ($res->getStatusCode() >= 400 || !is_array($data) || !isset($data['device_code'], $data['user_code'], $data['verification_uri'])) {
            throw new RuntimeException('Failed to request device code: ' . (string) $res->getBody());
        }

        return $data;
    }

    private function requestAccessToken(Client $client, string $deviceCode): array
    {
        $res = $client->post('https://github.com/login/oauth/access_token', [
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'form_params' => [
                'client_id' => self::GITHUB_CLIENT_ID,
                'device_code' => $deviceCode,
                'grant_type' => 'urn:ietf:params:oauth:grant-type:device_code',
            ],
        ]);

        $data = json_decode((string) $res->getBody(), true);
        if (!is_array($data)) {
            throw new RuntimeException('Invalid OAuth token response: ' . (string) $res->getBody());
        }

        return $data;
    }

    private function fetchCopilotUser(Client $client, string $token): array
    {
        $res = $client->get('https://api.github.com/copilot_internal/user', [
            'headers' => [
                'Authorization' => 'token ' . $token,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'Editor-Version' => 'vscode/1.99.0',
                'Editor-Plugin-Version' => 'copilot-chat/0.26.7',
                'User-Agent' => 'GitHubCopilotChat/0.26.7',
                'X-GitHub-Api-Version' => '2025-04-01',
                'X-Vscode-User-Agent-Library-Version' => 'electron-fetch',
            ],
        ]);

        $data = json_decode((string) $res->getBody(), true);
        if ($res->getStatusCode() >= 400 || !is_array($data)) {
            throw new RuntimeException('Token validated by OAuth but not accepted by Copilot endpoint: ' . (string) $res->getBody());
        }

        return $data;
    }

    private function writeEnvValue(string $key, string $value): void
    {
        $envPath = getcwd() . '/.env';
        $line = $key . '=' . $value;

        if (!file_exists($envPath)) {
            file_put_contents($envPath, $line . PHP_EOL);
            return;
        }

        $current = file_get_contents($envPath);
        if ($current === false) {
            throw new RuntimeException('Unable to read .env');
        }

        $pattern = '/^' . preg_quote($key, '/') . '=.*$/m';
        if (preg_match($pattern, $current) === 1) {
            $updated = preg_replace($pattern, $line, $current, 1);
        } else {
            $updated = rtrim($current, "\r\n") . PHP_EOL . $line . PHP_EOL;
        }

        if (!is_string($updated) || file_put_contents($envPath, $updated) === false) {
            throw new RuntimeException("Unable to write {$key} to .env");
        }
    }
}
