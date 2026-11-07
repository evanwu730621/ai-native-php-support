<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\DatabaseException;
use App\Exception\ExternalServiceException;
use App\DTO\DocumentAnalysisDTO;
use App\Database\Connection;
use App\Services\AI\LlmClient;
use App\Services\DocumentChunker;
use Psr\Log\LoggerInterface;

final class DocumentAnalysisService
{
    public function __construct(
        private readonly LlmClient $llmClient,
        private readonly DocumentChunker $documentChunker,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function analyze(string $documentText): DocumentAnalysisDTO
    {
        $documentText = trim($documentText);
        if ($documentText === '') {
            throw new \InvalidArgumentException('Document content cannot be empty.');
        }

        $data = $this->llmClient->completeJson([
            [
                'role' => 'system',
                'content' => '你是一位資深客戶滿意度與文檔分析專家。只根據提供內容分析，'
                    . '必須輸出純 JSON，不得包含 Markdown 或任何說明文字。'
                    . '情緒只能是「正面、中立、負面」；嚴重度只能是「低、中、高」。'
                    . '摘要必須精闢且使用繁體中文；key_points 與 suggested_actions 必須是字串陣列。',
            ],
            [
                'role' => 'user',
                'content' => "請分析以下內容並依指定結構回傳 JSON：\n\n" . $documentText,
            ],
        ], DocumentAnalysisDTO::jsonSchema(), 'document_analysis');

        try {
            return DocumentAnalysisDTO::fromArray($data);
        } catch (\InvalidArgumentException $exception) {
            $this->logger?->error('LLM document analysis returned invalid data.', ['exception' => $exception]);
            throw new ExternalServiceException('Invalid document analysis response.', 0, $exception);
        }
    }

    /**
     * @return array{document_id: int, chunk_count: int, chunks: list<string>}
     */
    public function ingest(
        string $originalName,
        string $storedPath,
        string $mimeType,
        int $fileSize,
        string $content
    ): array {
        try {
            $database = Connection::getInstance();
            $database->beginTransaction();

            $documentStatement = $database->prepare(
                'INSERT INTO documents
                    (original_name, stored_path, mime_type, file_size, status)
                 VALUES (:original_name, :stored_path, :mime_type, :file_size, :status)'
            );
            $documentStatement->execute([
                ':original_name' => $originalName,
                ':stored_path' => $storedPath,
                ':mime_type' => $mimeType,
                ':file_size' => $fileSize,
                ':status' => 'processing',
            ]);

            $documentId = (int) $database->lastInsertId();
            $chunks = $this->documentChunker->chunk($content, 500, 50);

            $chunkStatement = $database->prepare(
                'INSERT INTO document_chunks (document_id, chunk_index, content)
                 VALUES (:document_id, :chunk_index, :content)'
            );

            foreach ($chunks as $index => $chunk) {
                $chunkStatement->execute([
                    ':document_id' => $documentId,
                    ':chunk_index' => $index,
                    ':content' => $chunk,
                ]);
            }

            $completeStatement = $database->prepare(
                'UPDATE documents
                 SET chunk_count = :chunk_count, status = :status
                 WHERE id = :document_id'
            );
            $completeStatement->execute([
                ':chunk_count' => count($chunks),
                ':status' => 'ready',
                ':document_id' => $documentId,
            ]);

            $database->commit();

            return [
                'document_id' => $documentId,
                'chunk_count' => count($chunks),
                'chunks' => $chunks,
            ];
        } catch (\PDOException|\RuntimeException $exception) {
            if (isset($database) && $database->inTransaction()) {
                $database->rollBack();
            }

            $this->logger?->error('Unable to persist document chunks.', ['exception' => $exception]);
            throw new DatabaseException('Unable to persist document chunks.', 0, $exception);
        }
    }
}
