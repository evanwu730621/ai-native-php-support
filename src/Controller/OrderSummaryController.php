<?php

declare(strict_types=1);

namespace App\Controller;

use App\Services\AI\LlmClient;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class OrderSummaryController
{
    public function __construct(
        private readonly LlmClient $llmClient,
    ) {
    }

    public function create(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        $body = $request->getParsedBody();
        $prompt = is_array($body) ? ($body['prompt'] ?? null) : null;

        if (!is_string($prompt) || trim($prompt) === '') {
            return $this->jsonError($response, 'Prompt is required.', 422);
        }

        try {
            $dto = $this->llmClient->structured([
                [
                    'role' => 'system',
                    'content' => 'Return only the requested OrderSummary JSON object.',
                ],
                [
                    'role' => 'user',
                    'content' => $prompt,
                ],
            ]);

            $payload = json_encode([
                'order_number' => $dto->orderNumber,
                'status' => $dto->status,
                'total_amount' => $dto->totalAmount,
                'currency' => $dto->currency,
                'summary' => $dto->summary,
                'items' => $dto->items,
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return $this->jsonError($response, 'Unable to generate the structured response.', 502);
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
