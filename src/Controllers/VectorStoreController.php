<?php

namespace App\Controllers;

use App\Services\AttachmentStore;
use Throwable;

class VectorStoreController
{
    private AttachmentStore $store;

    public function __construct()
    {
        $this->store = new AttachmentStore();
    }

    public function handle(string $path, string $method): void
    {
        try {
            if ($method === 'POST' && $path === '/v1/vector_stores') {
                $this->createVectorStore();
                return;
            }

            if ($method === 'GET' && preg_match('#^/v1/vector_stores/([^/]+)$#', $path, $matches) === 1) {
                $this->getVectorStore($matches[1]);
                return;
            }

            if ($method === 'DELETE' && preg_match('#^/v1/vector_stores/([^/]+)$#', $path, $matches) === 1) {
                $this->deleteVectorStore($matches[1]);
                return;
            }

            if ($method === 'POST' && preg_match('#^/v1/vector_stores/([^/]+)/files$#', $path, $matches) === 1) {
                $this->attachFile($matches[1]);
                return;
            }

            if ($method === 'GET' && preg_match('#^/v1/vector_stores/([^/]+)/files$#', $path, $matches) === 1) {
                $this->listFiles($matches[1]);
                return;
            }

            if ($method === 'POST' && preg_match('#^/v1/vector_stores/([^/]+)/file_batches$#', $path, $matches) === 1) {
                $this->createFileBatch($matches[1]);
                return;
            }

            if ($method === 'POST' && preg_match('#^/v1/vector_stores/([^/]+)/search$#', $path, $matches) === 1) {
                $this->search($matches[1]);
                return;
            }

            if ($method === 'GET' && preg_match('#^/v1/vector_stores/([^/]+)/file_batches/([^/]+)$#', $path, $matches) === 1) {
                $this->getFileBatch($matches[1], $matches[2]);
                return;
            }

            http_response_code(501);
            header('Content-Type: application/json');
            echo json_encode([
                'error' => [
                    'message' => 'Vector store endpoint not implemented in proxy',
                    'type' => 'not_implemented',
                ],
            ]);
        } catch (Throwable $e) {
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode([
                'error' => [
                    'message' => $e->getMessage(),
                    'type' => 'internal_error',
                ],
            ]);
        }
    }

    private function createVectorStore(): void
    {
        $payload = json_decode(file_get_contents('php://input'), true);
        if (!is_array($payload)) {
            $payload = [];
        }

        $vectorStore = $this->store->createVectorStore($payload);
        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode($vectorStore);
    }

    private function getVectorStore(string $id): void
    {
        $vectorStore = $this->store->getVectorStore($id);
        if ($vectorStore === null) {
            $vectorStore = $this->store->createVectorStore(['id' => $id]);
        }

        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode($vectorStore);
    }

    private function attachFile(string $vectorStoreId): void
    {
        $payload = json_decode(file_get_contents('php://input'), true);
        $fileId = is_array($payload) && isset($payload['file_id']) && is_string($payload['file_id']) ? $payload['file_id'] : null;
        if (!$fileId) {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode([
                'error' => [
                    'message' => 'file_id is required',
                    'type' => 'invalid_request_error',
                ],
            ]);
            return;
        }

        $record = $this->store->attachFileToVectorStore($vectorStoreId, $fileId);
        if ($record === null) {
            http_response_code(404);
            header('Content-Type: application/json');
            echo json_encode([
                'error' => [
                    'message' => 'Vector store or file not found',
                    'type' => 'invalid_request_error',
                ],
            ]);
            return;
        }

        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode($record);
    }

    private function listFiles(string $vectorStoreId): void
    {
        $list = $this->store->listVectorStoreFiles($vectorStoreId);
        if ($list === null) {
            http_response_code(404);
            header('Content-Type: application/json');
            echo json_encode([
                'error' => [
                    'message' => 'Vector store not found',
                    'type' => 'invalid_request_error',
                ],
            ]);
            return;
        }

        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode($list);
    }

    private function createFileBatch(string $vectorStoreId): void
    {
        $payload = json_decode(file_get_contents('php://input'), true);
        $fileIds = is_array($payload['file_ids'] ?? null) ? $payload['file_ids'] : [];
        $batch = $this->store->createVectorStoreFileBatch($vectorStoreId, $fileIds);
        if ($batch === null) {
            http_response_code(404);
            header('Content-Type: application/json');
            echo json_encode([
                'error' => [
                    'message' => 'Vector store not found',
                    'type' => 'invalid_request_error',
                ],
            ]);
            return;
        }

        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode($batch);
    }

    private function getFileBatch(string $vectorStoreId, string $batchId): void
    {
        $store = $this->store->getVectorStore($vectorStoreId);
        if ($store === null) {
            http_response_code(404);
            header('Content-Type: application/json');
            echo json_encode([
                'error' => [
                    'message' => 'Vector store not found',
                    'type' => 'invalid_request_error',
                ],
            ]);
            return;
        }

        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode([
            'id' => $batchId,
            'object' => 'vector_store.files_batch',
            'created_at' => time(),
            'vector_store_id' => $vectorStoreId,
            'status' => 'completed',
            'file_counts' => $store['file_counts'] ?? [
                'in_progress' => 0,
                'completed' => 0,
                'failed' => 0,
                'cancelled' => 0,
                'total' => 0,
            ],
        ]);
    }

    private function search(string $vectorStoreId): void
    {
        $payload = json_decode(file_get_contents('php://input'), true);
        if (!is_array($payload)) {
            $payload = [];
        }

        $query = '';
        if (is_string($payload['query'] ?? null)) {
            $query = $payload['query'];
        } elseif (is_array($payload['query'] ?? null)) {
            $query = implode(' ', array_filter($payload['query'], 'is_string'));
        }

        $max = (int) ($payload['max_num_results'] ?? 5);
        $result = $this->store->searchVectorStore($vectorStoreId, $query, $max);
        if ($result === null) {
            http_response_code(404);
            header('Content-Type: application/json');
            echo json_encode([
                'error' => [
                    'message' => 'Vector store not found',
                    'type' => 'invalid_request_error',
                ],
            ]);
            return;
        }

        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode($result);
    }

    private function deleteVectorStore(string $id): void
    {
        $deleted = $this->store->deleteVectorStore($id);
        if (!$deleted) {
            http_response_code(404);
            header('Content-Type: application/json');
            echo json_encode([
                'error' => [
                    'message' => 'Vector store not found',
                    'type' => 'invalid_request_error',
                ],
            ]);
            return;
        }

        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode([
            'id' => $id,
            'object' => 'vector_store.deleted',
            'deleted' => true,
        ]);
    }
}
