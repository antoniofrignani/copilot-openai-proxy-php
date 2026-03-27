# Copilot Proxy (PHP)

A local PHP proxy that exposes OpenAI-like endpoints and forwards generation to GitHub Copilot.

This project is intended for local/dev usage where a client SDK expects OpenAI APIs (`/v1/responses`, `/v1/files`, `/v1/vector_stores`, etc.), while the model backend is Copilot.

## What We Accomplished

- Added a stable local server command: `composer serve`
- Implemented GitHub Copilot device auth flow in-app: `composer auth:copilot`.
- Automatic `.env` population from auth flow:
  - `GITHUB_TOKEN`
  - `COPILOT_BASE_URL` (from `copilot_internal/user` endpoints metadata)
- Added request logging for all incoming requests.
- Added routing and compatibility for:
  - `POST /v1/responses`
  - `POST /v1/chat/completions`
  - `POST/GET/DELETE /v1/files`
  - `POST/GET/DELETE /v1/vector_stores/{...}`
  - `POST /v1/vector_stores/{id}/files`
  - `POST /v1/vector_stores/{id}/file_batches`
  - `GET /v1/vector_stores/{id}/file_batches/{batch_id}`
  - `POST /v1/vector_stores/{id}/search`
- Added persisted attachment storage (`storage/`) with:
  - file metadata
  - vector store metadata
  - file-to-vector-store associations
- Added retrieval context injection for `/v1/responses` from attached files.
- Added binary extraction support for retrieval:
  - text files (native)
  - PDF via `pdftotext` if available
  - DOCX via `ZipArchive`
  - generic binary printable-strings fallback
- Added response-shape adapters:
  - `/v1/responses` non-stream output format
  - `/v1/responses` stream event transformation
  - finish_reason normalization for SDK compatibility

## Quick Start

1. Install dependencies:

```bash
composer install
```

2. Copy environment template:

```bash
cp .env.example .env
```

3. Authenticate and auto-fill token/base URL:

```bash
composer auth:copilot
```

4. Run server:

```bash
composer serve
```

Server starts at `http://localhost:8080`.

## Environment and Publishing Safety

- `.env` is ignored by git and must **never** be published.
- `.env.example` is tracked and safe to publish as the template.
- `composer auth:copilot` fills `.env` values interactively after GitHub device authorization.
- Required runtime keys:
  - `GITHUB_TOKEN` (set by auth command)
  - `COPILOT_BASE_URL` (set by auth command)

## Current Limitations / Further Testing Needed

- Retrieval quality is lexical chunk matching, not embedding-based semantic search.
- Binary extraction is best-effort and depends on local tools (`pdftotext`) and file type.
- No advanced OCR or rich document parsing for scanned PDFs/images.
- Streaming compatibility should be validated across more SDK/client combinations.
- Endpoint coverage is focused on observed SDK calls; additional endpoints may still be required.
- Persistence is local filesystem JSON storage (`storage/`), not production DB/object storage.
- Concurrency/locking behavior should be stress-tested for higher request volume.
- Add integration tests for:
  - file upload + vector store attach + search + response flow
  - streaming responses
  - cleanup/delete lifecycle

## Inspiration and Reference

Implementation approach was inspired by:

- https://github.com/ericc-ch/copilot-api

In particular, this project used it as reference for:

- GitHub Copilot auth flow shape (device flow/client behavior)
- local serve/developer workflow expectations
- Copilot request/header conventions

## Notes

- This is a compatibility proxy layer, not an official OpenAI backend.
- Use for development/testing and adapt hardening before production usage.
