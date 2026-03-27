<?php

namespace App\Controllers;

use App\Services\AttachmentStore;
use Throwable;

class FilesController
{
    private AttachmentStore $store;

    public function __construct()
    {
        $this->store = new AttachmentStore();
    }

    public function handle(string $path, string $method): void
    {
        try {
            if ($method === 'POST' && $path === '/v1/files') {
                $this->uploadFile();
                return;
            }

            if ($method === 'GET' && $path === '/v1/files') {
                $this->listFiles();
                return;
            }

            if ($method === 'GET' && preg_match('#^/v1/files/([^/]+)$#', $path, $matches) === 1) {
                $this->getFile($matches[1]);
                return;
            }

            if ($method === 'DELETE' && preg_match('#^/v1/files/([^/]+)$#', $path, $matches) === 1) {
                $this->deleteFile($matches[1]);
                return;
            }

            http_response_code(501);
            header('Content-Type: application/json');
            echo json_encode([
                'error' => [
                    'message' => 'Files endpoint not implemented in proxy',
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

    private function uploadFile(): void
    {
        if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode([
                'error' => [
                    'message' => 'Missing multipart file field named "file"',
                    'type' => 'invalid_request_error',
                ],
            ]);
            return;
        }

        $purpose = isset($_POST['purpose']) && is_string($_POST['purpose']) ? $_POST['purpose'] : 'assistants';
        $created = $this->store->createFileFromUpload($_FILES['file'], $purpose);

        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode($created);
    }

    private function listFiles(): void
    {
        $files = $this->store->listFiles();
        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode([
            'object' => 'list',
            'data' => $files,
            'has_more' => false,
        ]);
    }

    private function getFile(string $id): void
    {
        $file = $this->store->getFile($id);
        if ($file === null) {
            http_response_code(404);
            header('Content-Type: application/json');
            echo json_encode([
                'error' => [
                    'message' => 'File not found',
                    'type' => 'invalid_request_error',
                ],
            ]);
            return;
        }

        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode($file);
    }

    private function deleteFile(string $id): void
    {
        $deleted = $this->store->deleteFile($id);
        if (!$deleted) {
            http_response_code(404);
            header('Content-Type: application/json');
            echo json_encode([
                'error' => [
                    'message' => 'File not found',
                    'type' => 'invalid_request_error',
                ],
            ]);
            return;
        }

        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode([
            'id' => $id,
            'object' => 'file',
            'deleted' => $deleted,
        ]);
    }
}
