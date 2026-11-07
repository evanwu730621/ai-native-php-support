<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\DocumentAnalysisService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Smalot\PdfParser\Parser;

final class DocumentAnalysisController
{
    public function __construct(
        private readonly DocumentAnalysisService $documentAnalysisService,
    ) {
    }

    public function analyze(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        $body = $request->getParsedBody();
        $text = is_array($body) ? ($body['text'] ?? '') : '';

        $uploadedFiles = $request->getUploadedFiles();
        if (!is_string($text) || trim($text) === '') {
            $uploaded = $uploadedFiles['document'] ?? null;
            if ($uploaded instanceof \Psr\Http\Message\UploadedFileInterface) {
                $extension = strtolower(pathinfo($uploaded->getClientFilename() ?? '', PATHINFO_EXTENSION));
                if (!in_array($extension, ['txt', 'md', 'csv'], true)) {
                    return $this->jsonError($response, 'Only TXT, MD, and CSV files are supported.', 422);
                }
                if ($uploaded->getSize() !== null && $uploaded->getSize() > 2_000_000) {
                    return $this->jsonError($response, 'Document exceeds the 2 MB limit.', 413);
                }
                $uploaded->getStream()->rewind();
                $text = $uploaded->getStream()->getContents();
            }
        }

        if (!is_string($text) || trim($text) === '') {
            return $this->jsonError($response, 'Document content is required.', 422);
        }

        try {
            $dto = $this->documentAnalysisService->analyze($text);
            $payload = json_encode([
                'summary' => $dto->summary,
                'key_points' => $dto->keyPoints,
                'suggested_actions' => $dto->suggestedActions,
                'sentiment' => $dto->sentiment,
                'severity' => $dto->severity,
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (\Throwable $exception) {
            return $this->jsonError($response, 'Unable to analyze the document.', 502);
        }

        $response->getBody()->write($payload);

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus(200);
    }

    public function upload(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        $uploadedFiles = $request->getUploadedFiles();
        $uploaded = $uploadedFiles['document'] ?? null;

        if (!$uploaded instanceof \Psr\Http\Message\UploadedFileInterface) {
            return $this->jsonError($response, 'Document file is required.', 422);
        }
        if ($uploaded->getError() !== UPLOAD_ERR_OK) {
            return $this->jsonError($response, 'Document upload failed.', 400);
        }
        if (($uploaded->getSize() ?? 0) > 5 * 1024 * 1024) {
            return $this->jsonError($response, 'Document exceeds the 5 MB limit.', 413);
        }

        $originalName = basename($uploaded->getClientFilename() ?? '');
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($extension, ['txt', 'pdf'], true)) {
            return $this->jsonError($response, 'Only TXT and PDF files are supported.', 422);
        }

        $storedPath = '';
        try {
            $directory = dirname(__DIR__, 2) . '/storage/documents';
            if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
                throw new \RuntimeException('Unable to create document storage directory.');
            }

            $storedPath = $directory . '/' . bin2hex(random_bytes(16)) . '.' . $extension;
            $uploaded->moveTo($storedPath);

            $content = match ($extension) {
                'txt' => file_get_contents($storedPath),
                'pdf' => (new Parser())->parseFile($storedPath)->getText(),
            };
            if (!is_string($content) || trim($content) === '') {
                throw new \RuntimeException('The uploaded document contains no readable text.');
            }

            $ingested = $this->documentAnalysisService->ingest(
                $originalName,
                $storedPath,
                $extension === 'pdf' ? 'application/pdf' : 'text/plain',
                (int) $uploaded->getSize(),
                $content
            );
            $analysis = $this->documentAnalysisService->analyze($content);
            $payload = json_encode([
                'document_id' => $ingested['document_id'],
                'chunk_count' => $ingested['chunk_count'],
                'progress' => 100,
                'analysis' => [
                    'summary' => $analysis->summary,
                    'key_points' => $analysis->keyPoints,
                    'suggested_actions' => $analysis->suggestedActions,
                    'sentiment' => $analysis->sentiment,
                    'severity' => $analysis->severity,
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            if ($storedPath !== '' && is_file($storedPath)) {
                @unlink($storedPath);
            }
            return $this->jsonError($response, 'Unable to process the uploaded document.', 502);
        }

        $response->getBody()->write($payload);

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus(200);
    }

    private function jsonError(
        ResponseInterface $response,
        string $message,
        int $status
    ): ResponseInterface {
        $payload = json_encode(
            ['error' => $message],
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
        $response->getBody()->write($payload);

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }
}
