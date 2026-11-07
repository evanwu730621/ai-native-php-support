<?php

declare(strict_types=1);

namespace App\Controller;

use App\Services\AI\LlmClient;
use App\Tools\QueryOrderTool;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class ChatStreamController
{
    public function __construct(
        private readonly LlmClient $llmClient,
        private readonly QueryOrderTool $queryOrderTool,
    ) {
    }

    public function stream(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        $parsedBody = $request->getParsedBody();
        if (!is_array($parsedBody)) {
            return $this->jsonError($response, 'A JSON request body is required.', 400);
        }

        $prompt = $parsedBody['prompt'] ?? null;
        if (!is_string($prompt) || trim($prompt) === '') {
            return $this->jsonError($response, 'Prompt is required.', 422);
        }

        if (ob_get_level() > 0) {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
        }

        set_time_limit(0);
        ignore_user_abort(true);

        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache, no-transform');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');

        http_response_code(200);

        try {
            foreach ($this->llmClient->streamWithTools([
                [
                    'role' => 'user',
                    'content' => $prompt,
                ],
            ], [], []) as $text) {
                echo 'data: ' . json_encode(
                    ['text' => $text],
                    JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
                ) . "\n\n";
                flush();
            }

            echo "event: done\ndata: [DONE]\n\n";
            flush();
        } catch (\Throwable) {
            echo 'event: error' . "\n";
            echo 'data: ' . json_encode(
                ['message' => 'Unable to complete the AI response.'],
                JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            ) . "\n\n";
            flush();
        }

        return $response;
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
