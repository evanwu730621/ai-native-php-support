<?php

declare(strict_types=1);

namespace App\Controller;

use App\Exception\DatabaseException;
use App\Exception\ExternalServiceException;
use App\Database\Connection;
use App\Service\SupportService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Psr\Log\LoggerInterface;

final class LineWebhookController
{
    public function __construct(
        private readonly SupportService $supportService,
        private readonly HttpClientInterface $httpClient,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function handle(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        $body = (string) $request->getBody();
        $secret = trim((string) ($_ENV['LINE_CHANNEL_SECRET'] ?? ''));
        $signature = $request->getHeaderLine('X-Line-Signature');

        if ($secret === '') {
            return $this->status($response, 503, 'LINE_CHANNEL_SECRET is not configured.');
        }

        if ($signature === '') {
            return $this->status($response, 401, 'Missing LINE signature.');
        }

        $expectedSignature = base64_encode(hash_hmac('sha256', $body, $secret, true));
        if (!hash_equals($expectedSignature, $signature)) {
            return $this->status($response, 401, 'Invalid LINE signature.');
        }

        try {
            $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->status($response, 400, 'Invalid webhook JSON.');
        }

        if (!is_array($payload) || !isset($payload['events']) || !is_array($payload['events'])) {
            return $this->status($response, 400, 'Invalid webhook payload.');
        }

        foreach ($payload['events'] as $event) {
            if (!is_array($event) || ($event['type'] ?? null) !== 'message') {
                continue;
            }
            if (($event['message']['type'] ?? null) !== 'text') {
                continue;
            }

            $text = trim((string) ($event['message']['text'] ?? ''));
            $replyToken = (string) ($event['replyToken'] ?? '');
            if ($text === '' || $replyToken === '') {
                continue;
            }

            $webhookEventId = trim((string) ($event['webhookEventId'] ?? $event['message']['id'] ?? ''));
            if ($webhookEventId === '') {
                continue;
            }
            try {
                if (!$this->markEventProcessed($webhookEventId)) {
                    continue;
                }
            } catch (\Throwable) {
                $this->logger?->error('Unable to persist LINE webhook event.', [
                    'event_id' => $webhookEventId,
                ]);
                continue;
            }

            try {
                $ticket = $this->supportService->handle($text);
                $this->replyMessages(
                    $replyToken,
                    [$ticket->reply]
                );

                if ($ticket->needsHumanReview) {
                    $this->pushMessage(
                        is_array($event['source'] ?? null)
                            ? (string) ($event['source']['userId'] ?? '')
                            : '',
                        '您的案件已轉由真人客服專員處理，客服人員將於上班時間回覆。'
                    );
                }
            } catch (\Throwable) {
                try {
                    $this->replyMessages(
                        $replyToken,
                        ['系統暫時無法處理訊息，已記錄此問題，請稍後再試。']
                    );
                } catch (\Throwable) {
                    $this->logger?->error('LINE fallback reply failed.', [
                        'reply_token' => $replyToken,
                    ]);
                }
            }
        }

        return $response->withStatus(200);
    }

    private function markEventProcessed(string $eventId): bool
    {
        $statement = Connection::getInstance()->prepare(
            'INSERT INTO line_webhook_events (event_id) VALUES (:event_id)'
        );

        try {
            $statement->execute([':event_id' => substr($eventId, 0, 255)]);

            return true;
        } catch (\PDOException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) === 1062) {
                return false;
            }

            throw new DatabaseException('Unable to persist LINE webhook event.', 0, $exception);
        }
    }

    /**
     * @param list<string> $messages
     */
    private function replyMessages(string $replyToken, array $messages): void
    {
        $accessToken = trim((string) ($_ENV['LINE_CHANNEL_ACCESS_TOKEN'] ?? ''));
        if ($accessToken === '') {
            throw new \RuntimeException('LINE_CHANNEL_ACCESS_TOKEN is not configured.');
        }

        try {
            $this->httpClient->request('POST', 'https://api.line.me/v2/bot/message/reply', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'replyToken' => $replyToken,
                    'messages' => array_map(
                        static fn(string $text): array => ['type' => 'text', 'text' => $text],
                        $messages
                    ),
                ],
            ])->getContent();
        } catch (\Throwable $exception) {
            $this->logger?->error('LINE reply API request failed.', ['exception' => $exception]);
            throw new ExternalServiceException('Unable to reply through LINE.', 0, $exception);
        }
    }

    private function pushMessage(string $userId, string $text): void
    {
        $accessToken = trim((string) ($_ENV['LINE_CHANNEL_ACCESS_TOKEN'] ?? ''));
        if ($accessToken === '' || $userId === '') {
            return;
        }

        try {
            $this->httpClient->request('POST', 'https://api.line.me/v2/bot/message/push', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'to' => $userId,
                    'messages' => [
                        ['type' => 'text', 'text' => $text],
                    ],
                ],
            ])->getContent();
        } catch (\Throwable $exception) {
            $this->logger?->error('LINE push API request failed.', ['exception' => $exception]);
            throw new ExternalServiceException('Unable to push through LINE.', 0, $exception);
        }
    }

    private function status(
        ResponseInterface $response,
        int $status,
        string $message
    ): ResponseInterface {
        $response->getBody()->write(json_encode(
            ['error' => $message],
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }
}
