<?php

namespace App\Services;

use RuntimeException;

class AttachmentStore
{
    private string $basePath;
    private string $uploadsPath;
    private string $filesJsonPath;
    private string $vectorStoresJsonPath;

    public function __construct()
    {
        $this->basePath = dirname(__DIR__, 2) . '/storage';
        $this->uploadsPath = $this->basePath . '/uploads';
        $this->filesJsonPath = $this->basePath . '/files.json';
        $this->vectorStoresJsonPath = $this->basePath . '/vector_stores.json';

        $this->ensureStorage();
    }

    public function createFileFromUpload(array $upload, string $purpose): array
    {
        $tmpPath = $upload['tmp_name'] ?? null;
        $filename = $upload['name'] ?? 'upload.bin';
        $bytes = (int) ($upload['size'] ?? 0);

        if (!is_string($tmpPath) || $tmpPath === '' || !is_file($tmpPath)) {
            throw new RuntimeException('Invalid uploaded file');
        }

        $fileId = 'file_' . bin2hex(random_bytes(12));
        $extension = pathinfo((string) $filename, PATHINFO_EXTENSION);
        $storedName = $fileId . ($extension !== '' ? '.' . $extension : '');
        $storedPath = $this->uploadsPath . '/' . $storedName;

        if (!move_uploaded_file($tmpPath, $storedPath) && !rename($tmpPath, $storedPath)) {
            throw new RuntimeException('Failed to persist uploaded file');
        }

        $record = [
            'id' => $fileId,
            'object' => 'file',
            'bytes' => $bytes,
            'created_at' => time(),
            'filename' => (string) $filename,
            'purpose' => $purpose,
            'status' => 'processed',
            'status_details' => null,
            'path' => $storedPath,
        ];

        $files = $this->loadJson($this->filesJsonPath);
        $files[] = $record;
        $this->saveJson($this->filesJsonPath, $files);

        return $this->publicFile($record);
    }

    public function listFiles(): array
    {
        $files = $this->loadJson($this->filesJsonPath);
        return array_map(fn (array $f) => $this->publicFile($f), $files);
    }

    public function getFile(string $id): ?array
    {
        $file = $this->findFile($id);
        return $file ? $this->publicFile($file) : null;
    }

    public function deleteFile(string $id): bool
    {
        $files = $this->loadJson($this->filesJsonPath);
        $newFiles = [];
        $deleted = false;

        foreach ($files as $file) {
            if (($file['id'] ?? '') !== $id) {
                $newFiles[] = $file;
                continue;
            }

            $deleted = true;
            $path = $file['path'] ?? null;
            if (is_string($path) && $path !== '' && is_file($path)) {
                @unlink($path);
            }
        }

        if (!$deleted) {
            return false;
        }

        $this->saveJson($this->filesJsonPath, $newFiles);
        $this->detachFileFromAllVectorStores($id);
        return true;
    }

    public function createVectorStore(array $payload): array
    {
        $id = isset($payload['id']) && is_string($payload['id']) && $payload['id'] !== ''
            ? $payload['id']
            : 'vs_' . bin2hex(random_bytes(12));

        $existing = $this->findVectorStore($id);
        if ($existing !== null) {
            return $this->publicVectorStore($existing);
        }

        $record = $this->newVectorStoreRecord($id, $payload);

        $stores = $this->loadJson($this->vectorStoresJsonPath);
        $stores[] = $record;
        $this->saveJson($this->vectorStoresJsonPath, $stores);

        return $this->publicVectorStore($record);
    }

    public function getVectorStore(string $id): ?array
    {
        $store = $this->findVectorStore($id);
        return $store ? $this->publicVectorStore($store) : null;
    }

    public function deleteVectorStore(string $id): bool
    {
        $stores = $this->loadJson($this->vectorStoresJsonPath);
        $newStores = [];
        $deleted = false;

        foreach ($stores as $store) {
            if (($store['id'] ?? '') === $id) {
                $deleted = true;
                continue;
            }
            $newStores[] = $store;
        }

        if (!$deleted) {
            return false;
        }

        $this->saveJson($this->vectorStoresJsonPath, $newStores);
        return true;
    }

    public function attachFileToVectorStore(string $vectorStoreId, string $fileId): ?array
    {
        $file = $this->findFile($fileId);
        if (!$file) {
            return null;
        }

        $this->createVectorStore(['id' => $vectorStoreId]);
        $stores = $this->loadJson($this->vectorStoresJsonPath);
        foreach ($stores as &$store) {
            if (($store['id'] ?? '') !== $vectorStoreId) {
                continue;
            }

            $store['file_ids'] = is_array($store['file_ids'] ?? null) ? $store['file_ids'] : [];
            if (!in_array($fileId, $store['file_ids'], true)) {
                $store['file_ids'][] = $fileId;
            }

            $store['file_records'] = is_array($store['file_records'] ?? null) ? $store['file_records'] : [];
            $existing = null;
            foreach ($store['file_records'] as $record) {
                if (($record['file_id'] ?? '') === $fileId) {
                    $existing = $record;
                    break;
                }
            }

            if ($existing === null) {
                $existing = [
                    'id' => 'vsf_' . bin2hex(random_bytes(12)),
                    'object' => 'vector_store.file',
                    'created_at' => time(),
                    'vector_store_id' => $vectorStoreId,
                    'file_id' => $fileId,
                    'status' => 'completed',
                    'last_error' => null,
                ];
                $store['file_records'][] = $existing;
            }

            $store['usage_bytes'] = $this->calculateStoreBytes($store['file_ids']);
            $store['file_counts'] = $this->calculateFileCounts($store['file_ids']);
            $store['last_active_at'] = time();

            $this->saveJson($this->vectorStoresJsonPath, $stores);
            return $existing;
        }

        return null;
    }

    public function listVectorStoreFiles(string $vectorStoreId): ?array
    {
        $this->createVectorStore(['id' => $vectorStoreId]);
        $store = $this->findVectorStore($vectorStoreId);
        if (!$store) {
            return null;
        }

        $records = is_array($store['file_records'] ?? null) ? $store['file_records'] : [];
        return [
            'object' => 'list',
            'data' => $records,
            'first_id' => $records[0]['id'] ?? null,
            'last_id' => $records !== [] ? $records[count($records) - 1]['id'] : null,
            'has_more' => false,
        ];
    }

    public function createVectorStoreFileBatch(string $vectorStoreId, array $fileIds): ?array
    {
        $this->createVectorStore(['id' => $vectorStoreId]);
        $store = $this->findVectorStore($vectorStoreId);
        if (!$store) {
            return null;
        }

        $completed = 0;
        foreach ($fileIds as $fileId) {
            if (!is_string($fileId)) {
                continue;
            }
            $attached = $this->attachFileToVectorStore($vectorStoreId, $fileId);
            if ($attached !== null) {
                $completed++;
            }
        }

        return [
            'id' => 'vsfb_' . bin2hex(random_bytes(12)),
            'object' => 'vector_store.files_batch',
            'created_at' => time(),
            'vector_store_id' => $vectorStoreId,
            'status' => 'completed',
            'file_counts' => [
                'in_progress' => 0,
                'completed' => $completed,
                'failed' => 0,
                'cancelled' => 0,
                'total' => count($fileIds),
            ],
        ];
    }

    public function searchVectorStore(string $vectorStoreId, string $query, int $maxResults = 5): ?array
    {
        $this->createVectorStore(['id' => $vectorStoreId]);
        $store = $this->findVectorStore($vectorStoreId);
        if (!$store) {
            return null;
        }

        $chunks = [];
        foreach (($store['file_ids'] ?? []) as $fileId) {
            $file = $this->findFile((string) $fileId);
            if (!$file) {
                continue;
            }

            $text = $this->readTextFile($file);
            if ($text === '') {
                continue;
            }

            foreach ($this->splitTextIntoChunks($text, 1200, 200) as $chunk) {
                $chunks[] = [
                    'file_id' => $file['id'],
                    'filename' => $file['filename'],
                    'text' => $chunk,
                    'score' => $this->scoreChunk($chunk, $query),
                ];
            }
        }

        usort($chunks, fn (array $a, array $b) => $b['score'] <=> $a['score']);
        $top = array_slice($chunks, 0, max(1, $maxResults));

        $results = [];
        foreach ($top as $idx => $chunk) {
            $results[] = [
                'file_id' => $chunk['file_id'],
                'filename' => $chunk['filename'],
                'score' => (float) $chunk['score'],
                'attributes' => new \stdClass(),
                'content' => [[
                    'type' => 'text',
                    'text' => $chunk['text'],
                ]],
                'rank' => $idx + 1,
            ];
        }

        return [
            'object' => 'vector_store.search_results.page',
            'search_query' => $query,
            'data' => $results,
            'has_more' => false,
            'next_page' => null,
        ];
    }

    public function buildContextForVectorStores(array $vectorStoreIds, string $query): string
    {
        $chunks = [];
        foreach ($vectorStoreIds as $vectorStoreId) {
            if (!is_string($vectorStoreId)) {
                continue;
            }
            $store = $this->findVectorStore($vectorStoreId);
            if (!$store) {
                continue;
            }

            foreach (($store['file_ids'] ?? []) as $fileId) {
                $file = $this->findFile((string) $fileId);
                if (!$file) {
                    continue;
                }

                $text = $this->readTextFile($file);
                if ($text === '') {
                    continue;
                }

                foreach ($this->splitTextIntoChunks($text, 1200, 200) as $chunk) {
                    $chunks[] = [
                        'file_id' => $file['id'],
                        'filename' => $file['filename'],
                        'text' => $chunk,
                        'score' => $this->scoreChunk($chunk, $query),
                    ];
                }
            }
        }

        if ($chunks === []) {
            return '';
        }

        usort($chunks, fn (array $a, array $b) => $b['score'] <=> $a['score']);
        $top = array_slice($chunks, 0, 5);
        $result = [];
        foreach ($top as $item) {
            $result[] = '[' . $item['filename'] . '] ' . $item['text'];
        }

        return implode("\n\n---\n\n", $result);
    }

    private function readTextFile(array $file): string
    {
        $path = $file['path'] ?? null;
        if (!is_string($path) || !is_file($path)) {
            return '';
        }

        $content = @file_get_contents($path);
        if (!is_string($content) || $content === '') {
            return '';
        }

        $extension = strtolower(pathinfo((string) ($file['filename'] ?? ''), PATHINFO_EXTENSION));
        $textExtensions = ['txt', 'md', 'markdown', 'json', 'csv', 'log', 'xml', 'yaml', 'yml', 'php', 'js', 'ts', 'html'];
        if (in_array($extension, $textExtensions, true)) {
            return mb_substr($content, 0, 200000);
        }

        if ($extension === 'pdf') {
            $pdfText = $this->extractPdfText($path);
            if ($pdfText !== '') {
                return mb_substr($pdfText, 0, 200000);
            }
        }

        if ($extension === 'docx') {
            $docxText = $this->extractDocxText($path);
            if ($docxText !== '') {
                return mb_substr($docxText, 0, 200000);
            }
        }

        return mb_substr($this->extractBinaryStrings($content), 0, 200000);
    }

    private function extractPdfText(string $path): string
    {
        if (!$this->commandExists('pdftotext')) {
            return '';
        }

        $cmd = 'pdftotext ' . escapeshellarg($path) . ' - 2>/dev/null';
        $output = shell_exec($cmd);
        if (!is_string($output)) {
            return '';
        }

        return trim($output);
    }

    private function extractDocxText(string $path): string
    {
        if (!class_exists(\ZipArchive::class)) {
            return '';
        }

        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            return '';
        }

        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        if (!is_string($xml) || $xml === '') {
            return '';
        }

        $text = strip_tags($xml);
        return trim(preg_replace('/\s+/', ' ', $text) ?? '');
    }

    private function extractBinaryStrings(string $content): string
    {
        $normalized = preg_replace('/[\x00-\x08\x0E-\x1F\x7F]/', ' ', $content);
        if (!is_string($normalized)) {
            return '';
        }

        preg_match_all('/[ -~]{4,}/', $normalized, $matches);
        $strings = $matches[0] ?? [];
        if (!is_array($strings) || $strings === []) {
            return '';
        }

        return implode("\n", array_slice($strings, 0, 4000));
    }

    private function commandExists(string $command): bool
    {
        $result = shell_exec('command -v ' . escapeshellarg($command) . ' 2>/dev/null');
        return is_string($result) && trim($result) !== '';
    }

    private function splitTextIntoChunks(string $text, int $chunkSize, int $overlap): array
    {
        $chunks = [];
        $length = mb_strlen($text);
        $offset = 0;
        while ($offset < $length) {
            $chunk = trim((string) mb_substr($text, $offset, $chunkSize));
            if ($chunk !== '') {
                $chunks[] = $chunk;
            }

            $offset += max(1, $chunkSize - $overlap);
        }

        return $chunks;
    }

    private function scoreChunk(string $chunk, string $query): int
    {
        $terms = preg_split('/\W+/', strtolower($query));
        if (!is_array($terms)) {
            return 0;
        }

        $score = 0;
        $haystack = strtolower($chunk);
        foreach ($terms as $term) {
            if ($term === '' || strlen($term) < 3) {
                continue;
            }
            if (str_contains($haystack, $term)) {
                $score += 1;
            }
        }

        return $score;
    }

    private function findFile(string $id): ?array
    {
        foreach ($this->loadJson($this->filesJsonPath) as $file) {
            if (($file['id'] ?? '') === $id) {
                return $file;
            }
        }

        return null;
    }

    private function findVectorStore(string $id): ?array
    {
        foreach ($this->loadJson($this->vectorStoresJsonPath) as $store) {
            if (($store['id'] ?? '') === $id) {
                return $store;
            }
        }

        return null;
    }

    private function detachFileFromAllVectorStores(string $fileId): void
    {
        $stores = $this->loadJson($this->vectorStoresJsonPath);
        foreach ($stores as &$store) {
            $store['file_ids'] = array_values(array_filter(
                $store['file_ids'] ?? [],
                fn ($id) => $id !== $fileId
            ));

            $store['file_records'] = array_values(array_filter(
                $store['file_records'] ?? [],
                fn ($record) => ($record['file_id'] ?? null) !== $fileId
            ));

            $store['usage_bytes'] = $this->calculateStoreBytes($store['file_ids']);
            $store['file_counts'] = $this->calculateFileCounts($store['file_ids']);
        }

        $this->saveJson($this->vectorStoresJsonPath, $stores);
    }

    private function calculateStoreBytes(array $fileIds): int
    {
        $sum = 0;
        foreach ($fileIds as $fileId) {
            $file = $this->findFile((string) $fileId);
            if ($file) {
                $sum += (int) ($file['bytes'] ?? 0);
            }
        }

        return $sum;
    }

    private function calculateFileCounts(array $fileIds): array
    {
        $total = count($fileIds);
        return [
            'in_progress' => 0,
            'completed' => $total,
            'failed' => 0,
            'cancelled' => 0,
            'total' => $total,
        ];
    }

    private function publicVectorStore(array $record): array
    {
        unset($record['file_ids'], $record['file_records']);
        return $record;
    }

    private function publicFile(array $record): array
    {
        unset($record['path']);
        return $record;
    }

    private function newVectorStoreRecord(string $id, array $payload): array
    {
        $createdAt = time();
        return [
            'id' => $id,
            'object' => 'vector_store',
            'created_at' => $createdAt,
            'name' => $payload['name'] ?? null,
            'status' => 'completed',
            'usage_bytes' => 0,
            'file_counts' => [
                'in_progress' => 0,
                'completed' => 0,
                'failed' => 0,
                'cancelled' => 0,
                'total' => 0,
            ],
            'expires_after' => $payload['expires_after'] ?? null,
            'expires_at' => null,
            'last_active_at' => $createdAt,
            'metadata' => is_array($payload['metadata'] ?? null) ? $payload['metadata'] : new \stdClass(),
            'file_ids' => [],
            'file_records' => [],
        ];
    }

    private function ensureStorage(): void
    {
        if (!is_dir($this->basePath) && !mkdir($this->basePath, 0777, true) && !is_dir($this->basePath)) {
            throw new RuntimeException('Unable to create storage directory');
        }

        if (!is_dir($this->uploadsPath) && !mkdir($this->uploadsPath, 0777, true) && !is_dir($this->uploadsPath)) {
            throw new RuntimeException('Unable to create uploads directory');
        }

        if (!is_file($this->filesJsonPath)) {
            $this->saveJson($this->filesJsonPath, []);
        }

        if (!is_file($this->vectorStoresJsonPath)) {
            $this->saveJson($this->vectorStoresJsonPath, []);
        }
    }

    private function loadJson(string $path): array
    {
        $raw = @file_get_contents($path);
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    private function saveJson(string $path, array $data): void
    {
        $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded) || file_put_contents($path, $encoded . PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException('Unable to write storage data');
        }
    }
}
