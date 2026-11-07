<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\DTO\OrderSummaryDTO;
use App\Exception\ExternalServiceException;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class LlmClient
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $geminiApiKey = '',
        private readonly string $geminiModel = 'gemini-2.5-flash',
        private readonly string $geminiBaseUrl = 'https://generativelanguage.googleapis.com/v1beta',
        private readonly float $timeout = 300.0,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * @param list<array{role: string, content: string}> $messages
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    public function completeJson(array $messages, array $schema, string $schemaName): array
    {
        if (trim($this->geminiApiKey) === '') {
            return $this->mockStructuredData($schemaName, $messages);
        }
        $prompt = $this->buildPrompt($messages);
        $payload = $this->requestGemini($prompt, [
            'responseMimeType' => 'application/json',
            'responseSchema' => $this->sanitizeSchema($schema),
        ]);
        $text = $this->extractText($payload);

        $text = trim($text);
        if (str_starts_with($text, '```')) {
            $text = preg_replace('/^```(?:json)?\s*/i', '', $text) ?? $text;
            $text = preg_replace('/\s*```$/', '', $text) ?? $text;
            $text = trim($text);
        }

        try {
            $data = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new ExternalServiceException(
                'Gemini returned invalid JSON: ' . $text,
                0,
                $exception,
            );
        }

        if (!is_array($data)) {
            throw new ExternalServiceException('Gemini structured response must be a JSON object.');
        }

        return $data;
    }

    /**
     * @param list<array{role: string, content: string}> $messages
     */
    public function stream(array $messages): iterable
    {
        $payload = $this->requestGemini($this->buildPrompt($messages));
        $text = $this->extractText($payload);
        foreach ($this->splitCharacters($text) as $character) {
            yield $character;
        }
    }

    /**
     * @param list<array{role: string, content: string}> $messages
     */
    public function structured(array $messages): OrderSummaryDTO
    {
        $data = $this->completeJson(
            $messages,
            OrderSummaryDTO::jsonSchema(),
            'order_summary',
        );

        try {
            return OrderSummaryDTO::fromArray($data);
        } catch (\InvalidArgumentException $exception) {
            throw new ExternalServiceException(
                'Gemini returned an invalid OrderSummary object.',
                0,
                $exception,
            );
        }
    }

    /**
     * Tool calling is kept as a compatibility API.
     *
     * @param list<array{role: string, content: string}> $messages
     * @param list<array<string, mixed>> $tools
     * @param array<string, callable(array<string, mixed>): array<string, mixed>> $toolHandlers
     * @return iterable<string>
     */
    public function streamWithTools(
        array $messages,
        array $tools,
        array $toolHandlers,
        int $maxRounds = 5
    ): iterable {
        unset($tools, $toolHandlers, $maxRounds);
        yield from $this->stream($messages);
    }

    /**
     * @param array<string, mixed> $generationConfig
     * @return array<string, mixed>
     */
    private function requestGemini(string $prompt, array $generationConfig = []): array
    {
        if (trim($this->geminiApiKey) === '') {
            throw new ExternalServiceException('Gemini API key is not configured.');
        }

        $baseUrl = rtrim($this->geminiBaseUrl, '/');
        $url = sprintf(
            '%s/models/%s:generateContent?key=%s',
            $baseUrl,
            rawurlencode($this->geminiModel),
            rawurlencode($this->geminiApiKey),
        );

        $body = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt],
                    ],
                ],
            ],
        ];
        if ($generationConfig !== []) {
            $body['generationConfig'] = [
                'responseMimeType' => $generationConfig['responseMimeType'],
                'responseSchema' => $generationConfig['responseSchema'],
            ];
        }

        try {
            $response = $this->httpClient->request('POST', $url, [
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
                'json' => $body,
                'timeout' => $this->timeout,
                'verify_peer' => false,
                'verify_host' => false,
            ]);
            $status = $response->getStatusCode();
            $rawBody = $response->getContent(false);

            if ($status < 200 || $status >= 300) {
                $this->logger?->error('Gemini API request failed.', [
                    'status' => $status,
                    'response' => $rawBody,
                ]);
                throw new ExternalServiceException(
                    sprintf('Gemini HTTP %d: %s', $status, $rawBody)
                );
            }
        } catch (TransportExceptionInterface $exception) {
            $this->logger?->error('Gemini transport request failed.', [
                'exception' => $exception->getMessage(),
            ]);
            throw new ExternalServiceException(
                'Gemini transport error: ' . $exception->getMessage(),
                0,
                $exception,
            );
        }

        try {
            $payload = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new ExternalServiceException(
                'Gemini returned invalid JSON: ' . $rawBody,
                0,
                $exception,
            );
        }

        if (!is_array($payload)) {
            throw new ExternalServiceException('Gemini response payload is not an object.');
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function extractText(array $payload): string
    {
        $parts = $payload['candidates'][0]['content']['parts'] ?? null;
        if (!is_array($parts)) {
            throw new ExternalServiceException(
                'Gemini returned no candidates[0].content.parts text. '
                . json_encode($payload, JSON_UNESCAPED_UNICODE)
            );
        }

        $text = '';
        foreach ($parts as $part) {
            if (is_array($part) && is_string($part['text'] ?? null)) {
                $text .= $part['text'];
            }
        }

        if (trim($text) === '') {
            throw new ExternalServiceException('Gemini returned empty response text.');
        }

        return $text;
    }

    /**
     * @param list<array{role: string, content: string}> $messages
     */
    private function buildPrompt(array $messages): string
    {
        $parts = [];
        foreach ($messages as $message) {
            $role = strtoupper($message['role'] ?? 'USER');
            $parts[] = $role . ': ' . $message['content'];
        }
        return implode("\n\n", $parts);
    }

    /**
     * Gemini's responseSchema uses OpenAPI-compatible schema fields and rejects
     * JSON Schema keywords such as additionalProperties.
     *
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private function sanitizeSchema(array $schema): array
    {
        unset($schema['additionalProperties']);
        unset($schema['$schema']);
        unset($schema['definitions']);
        unset($schema['$defs']);

        foreach ($schema as $key => $value) {
            if (is_array($value)) {
                $schema[$key] = $this->sanitizeSchemaValue($value);
            }
        }

        return $schema;
    }

    /**
     * @param array<mixed> $value
     * @return array<mixed>
     */
    private function sanitizeSchemaValue(array $value): array
    {
        $sanitized = [];
        foreach ($value as $key => $item) {
            if (is_string($key) && in_array($key, [
                'additionalProperties',
                '$schema',
                'definitions',
                '$defs',
            ], true)) {
                continue;
            }

            if (is_array($item)) {
                $sanitized[$key] = $this->sanitizeSchemaValue($item);
            } else {
                $sanitized[$key] = $item;
            }
        }

        return $sanitized;
    }

    /**
     * @return iterable<string>
     */
    private function splitCharacters(string $text): iterable
    {
        foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $character) {
            yield $character;
        }
    }

    /**
     * @param list<array{role: string, content: string}> $messages
     * @return array<string, mixed>
     */
    private function mockStructuredData(string $schemaName, array $messages): array
    {
        $prompt = $this->lastUserMessage($messages);

        if ($schemaName === 'document_analysis') {
            return [
                'summary' => '文件主要介紹企業導入自動化客服與文件分析的效益及建議。',
                'sentiment' => '中立',
                'severity' => '低',
                'key_points' => [
                    mb_substr(trim($prompt), 0, 120),
                    '建議優先導入訂單與退款流程。',
                ],
                'suggested_actions' => [
                    '評估客服與訂單流程導入可行性。',
                    '由專責團隊進行階段式測試。',
                ],
            ];
        }

        return [
            'category' => 'general',
            'priority' => 'normal',
            'sentiment' => 'normal',
            'confidence_score' => 100,
            'reply' => '您好，您可以在訂單查詢頁面輸入訂單編號查看目前進度。',
            'suggested_replies' => ['請提供訂單編號，以便協助您查詢。'],
            'needs_human_review' => false,
        ];
    }

    /**
     * @param list<array{role: string, content: string}> $messages
     */
    private function lastUserMessage(array $messages): string
    {
        for ($index = count($messages) - 1; $index >= 0; $index--) {
            if (($messages[$index]['role'] ?? null) === 'user') {
                $content = $messages[$index]['content'] ?? '';
                return is_string($content) ? $content : '';
            }
        }

        return '';
    }
}
