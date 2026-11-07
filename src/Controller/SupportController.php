<?php

declare(strict_types=1);

namespace App\Controller;

use App\Exception\ExternalServiceException;
use App\Service\SupportService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

final class SupportController
{
    public function __construct(
        private readonly SupportService $supportService,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function handle(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        $body = $request->getParsedBody();
        $message = is_array($body) ? ($body['message'] ?? null) : null;
        if (!is_string($message) || trim($message) === '') {
            try {
                $request->getBody()->rewind();
                $rawBody = json_decode(
                    $this->readRequestBody($request),
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                );
                $message = is_array($rawBody) ? ($rawBody['message'] ?? null) : null;
            } catch (\JsonException) {
                $message = null;
            }
        }

        if (!is_string($message) || trim($message) === '') {
            error_log(sprintf(
                '[SupportController] Empty message diagnostics: body="%s", parsed=%s, content-type=%s, php-input="%s"',
                substr((string) $request->getBody(), 0, 256),
                is_array($body) ? json_encode($body) : get_debug_type($body),
                $request->getHeaderLine('Content-Type'),
                substr((string) file_get_contents('php://input'), 0, 256)
            ));
            return $this->jsonError($response, 'Support message is required.', 422);
        }

        try {
            $dto = $this->supportService->handle($message);
            $payload = json_encode([
                'ticket_id' => $dto->ticketId,
                'category' => $dto->category,
                'priority' => $dto->priority,
                'sentiment' => $dto->sentiment,
                'reply' => $dto->reply,
                'suggested_replies' => $dto->suggestedReplies,
                'needs_human_review' => $dto->needsHumanReview,
                'confidence_score' => $dto->confidenceScore,
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (\Throwable $exception) {
            $this->logger?->error('Support request failed.', [
                'exception' => $exception,
                'message' => $exception->getMessage(),
            ]);
            error_log(sprintf(
                '[SupportController] %s in %s:%d',
                $exception->getMessage(),
                $exception->getFile(),
                $exception->getLine()
            ));
            $publicMessage = 'Unable to process the support request.';
            if (($this->debugEnabled() ?? false) && $exception->getMessage() !== '') {
                $publicMessage .= ' ' . $exception->getMessage();
            }
            return $this->jsonError($response, $publicMessage, 502);
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

    private function readRequestBody(ServerRequestInterface $request): string
    {
        $body = (string) $request->getBody();
        if (trim($body) !== '') {
            return $body;
        }

        $input = file_get_contents('php://input');
        return is_string($input) ? $input : '';
    }

    private function debugEnabled(): bool
    {
        $value = $_ENV['APP_DEBUG'] ?? getenv('APP_DEBUG');
        return filter_var($value, FILTER_VALIDATE_BOOL);
    }
}
