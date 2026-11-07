<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\DatabaseException;
use App\DTO\SupportTicketDTO;
use App\Services\AI\LlmClient;
use App\Database\Connection;
use App\Tools\QueryOrderTool;
use Psr\Log\LoggerInterface;

final class SupportService
{
    private const HUMAN_HANDOFF_REPLY = '已為您記錄並指派專人處理，客服人員將於上班時間回覆';

    public function __construct(
        private readonly LlmClient $llmClient,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?\PDO $database = null,
        private readonly ?QueryOrderTool $queryOrderTool = null,
    ) {
    }

    public function handle(string $message): SupportTicketDTO
    {
        $message = trim($message);
        if ($message === '') {
            throw new \InvalidArgumentException('Support message cannot be empty.');
        }

        try {
            $database = $this->database ?? Connection::getInstance();
            $orderTool = $this->queryOrderTool ?? new QueryOrderTool($database, $this->logger);
        } catch (DatabaseException|\RuntimeException $exception) {
            $this->logger?->error('Support database is unavailable.', ['exception' => $exception]);
            $database = null;
            $orderTool = $this->queryOrderTool;
        }

        $orderContext = $this->queryOrderContext($message, $orderTool, $database);
        $systemPrompt = 'You are an automated customer-support assistant. Classify the request, '
            . 'assess your confidence, and route complex or sensitive requests for human review. '
            . 'Return sentiment as one of: normal, angry, urgent. '
            . 'Return confidence_score from 0 to 100.'
            . $orderContext;

        $data = $this->llmClient->completeJson([
            [
                'role' => 'system',
                'content' => $systemPrompt,
            ],
            [
                'role' => 'user',
                'content' => $message,
            ],
        ], SupportTicketDTO::jsonSchema(), 'support_ticket');

        if ($database === null) {
            return $this->mockTicket(
                $message,
                $data,
                $confidenceScore = $this->confidenceScore($data),
                $sentiment = $this->sentiment($data),
                $needsHumanReview = $this->needsHumanReview($message, $data),
            );
        }

        $suggestedReplies = [];
        foreach ($data['suggested_replies'] ?? [] as $reply) {
            if (is_string($reply)) {
                $suggestedReplies[] = $reply;
            }
        }

        $confidenceScore = isset($data['confidence_score'])
            && is_int($data['confidence_score'])
            ? max(0, min(100, $data['confidence_score']))
            : 100;
        $sentiment = is_string($data['sentiment'] ?? null) ? $data['sentiment'] : 'normal';
        if (!in_array($sentiment, ['normal', 'angry', 'urgent'], true)) {
            $sentiment = 'normal';
        }

        $handoffReasons = $this->detectHumanHandoffReasons(
            $message,
            $confidenceScore,
            $sentiment,
            (bool) ($data['needs_human_review'] ?? false)
        );
        $needsHumanReview = $handoffReasons !== [];
        if ($needsHumanReview) {
            $reply = self::HUMAN_HANDOFF_REPLY;
            $suggestedReplies = [];
        } else {
            $reply = is_string($data['reply'] ?? null) ? $data['reply'] : '';
        }

        try {
            $database->beginTransaction();

            $title = mb_substr(preg_replace('/\s+/', ' ', $message) ?? '', 0, 255);
            $ticketStatement = $database->prepare(
                'INSERT INTO tickets (user_id, title, status, priority)
                 VALUES (:user_id, :title, :status, :priority)'
            );
            $priority = is_string($data['priority'] ?? null) ? $data['priority'] : 'normal';
            $status = $needsHumanReview ? 'pending_human_agent' : 'open';
            $ticketStatement->execute([
                ':user_id' => 'anonymous',
                ':title' => $title,
                ':status' => $status,
                ':priority' => $priority,
            ]);

            $ticketId = (int) $database->lastInsertId();

            $messageStatement = $database->prepare(
                'INSERT INTO ticket_messages (ticket_id, sender_type, content, tokens_used)
                 VALUES (:ticket_id, :sender_type, :content, NULL)'
            );
            $messageStatement->execute([
                ':ticket_id' => $ticketId,
                ':sender_type' => 'user',
                ':content' => $message,
            ]);

            $messageStatement->execute([
                ':ticket_id' => $ticketId,
                ':sender_type' => 'ai',
                ':content' => $reply,
            ]);

            if ($needsHumanReview) {
                $messageStatement->execute([
                    ':ticket_id' => $ticketId,
                    ':sender_type' => 'agent',
                    ':content' => 'Automatic human-agent handoff: ' . implode('; ', $handoffReasons),
                ]);
            }

            $database->commit();
        } catch (\PDOException $exception) {
            if ($database->inTransaction()) {
                $database->rollBack();
            }

            $this->logger?->error('Unable to save support ticket.', ['exception' => $exception]);
            return $this->mockTicket(
                $message,
                $data,
                $confidenceScore,
                $sentiment,
                $needsHumanReview,
            );
        }

        return new SupportTicketDTO(
            ticketId: 'TK-' . str_pad((string) $ticketId, 10, '0', STR_PAD_LEFT),
            category: is_string($data['category'] ?? null) ? $data['category'] : 'general',
            priority: is_string($data['priority'] ?? null) ? $data['priority'] : 'normal',
            sentiment: $sentiment,
            reply: $reply,
            suggestedReplies: $suggestedReplies,
            needsHumanReview: $needsHumanReview,
            confidenceScore: $confidenceScore,
        );
    }

    /**
     * @param array<string, scalar|array> $data
     */
    private function mockTicket(
        string $message,
        array $data,
        int $confidenceScore,
        string $sentiment,
        bool $needsHumanReview,
    ): SupportTicketDTO {
        $reply = $needsHumanReview
            ? self::HUMAN_HANDOFF_REPLY
            : (is_string($data['reply'] ?? null)
                ? $data['reply']
                : '（模擬客服回覆）已收到您的訊息，我們會協助您確認需求。');

        $suggestedReplies = [];
        foreach ($data['suggested_replies'] ?? [] as $suggestedReply) {
            if (is_string($suggestedReply)) {
                $suggestedReplies[] = $suggestedReply;
            }
        }

        return new SupportTicketDTO(
            ticketId: 'TK-MOCK-' . substr(hash('sha256', $message), 0, 8),
            category: is_string($data['category'] ?? null) ? $data['category'] : 'general',
            priority: is_string($data['priority'] ?? null) ? $data['priority'] : 'normal',
            sentiment: $sentiment,
            reply: $reply,
            suggestedReplies: $suggestedReplies,
            needsHumanReview: $needsHumanReview,
            confidenceScore: $confidenceScore,
        );
    }

    private function queryOrderContext(
        string $message,
        ?QueryOrderTool $orderTool,
        ?\PDO $database
    ): string {
        if ($orderTool === null && $database === null) {
            return '';
        }

        if (preg_match('/\bORD-[A-Z0-9_-]+\b/i', $message, $matches) !== 1) {
            return '';
        }

        $orderNumber = strtoupper($matches[0]);
        if ($orderTool === null) {
            return '';
        }

        try {
            $result = $orderTool->execute(['order_number' => $orderNumber]);
        } catch (\Throwable $exception) {
            $this->logger?->error('Unable to query order from support chat.', [
                'order_number' => $orderNumber,
                'exception' => $exception,
            ]);
            return '';
        }

        if (($result['found'] ?? false) !== true) {
            return "\n\n你現在是本系統的官方客服，已被授權查詢訂單資料。"
                . '系統資料庫中查無此訂單編號，請禮貌告知客戶：「系統資料庫中查無此訂單編號，請確認是否輸入正確」。';
        }

        return "\n\n你現在是本系統的官方客服，你已被授權查詢訂單資料。"
            . '系統已為你查出該筆訂單的真實資料如下：【'
            . '訂單編號：' . (string) $result['order_number']
            . '；金額：' . (string) $result['total_amount'] . ' TWD'
            . '；狀態：' . (string) $result['status']
            . '；建立時間：' . (string) $result['created_at']
            . '】，請直接根據這些真實資料親切回答客戶的金額與進度，切勿回答無權限或無法查詢！';
    }

    /**
     * @param array<string, scalar|array> $data
     */
    private function confidenceScore(array $data): int
    {
        return isset($data['confidence_score']) && is_int($data['confidence_score'])
            ? max(0, min(100, $data['confidence_score']))
            : 100;
    }

    /**
     * @param array<string, scalar|array> $data
     */
    private function sentiment(array $data): string
    {
        $sentiment = is_string($data['sentiment'] ?? null) ? $data['sentiment'] : 'normal';

        return in_array($sentiment, ['normal', 'angry', 'urgent'], true)
            ? $sentiment
            : 'normal';
    }

    /**
     * @param array<string, scalar|array> $data
     */
    private function needsHumanReview(string $message, array $data): bool
    {
        return $this->detectHumanHandoffReasons(
            $message,
            $this->confidenceScore($data),
            $this->sentiment($data),
            (bool) ($data['needs_human_review'] ?? false)
        ) !== [];
    }

    /**
     * @return list<string>
     */
    private function detectHumanHandoffReasons(
        string $message,
        int $confidenceScore,
        string $sentiment,
        bool $modelRequestedReview
    ): array {
        $reasons = [];

        if ($confidenceScore < 70) {
            $reasons[] = sprintf('Low AI confidence score: %d', $confidenceScore);
        }

        if (preg_match(
            '/退費|退款|消保官|找真人|真人客服|轉接人工|投訴|投訴主管/u',
            $message
        ) === 1) {
            $reasons[] = 'Strong escalation keywords detected in customer message';
        }

        if (in_array($sentiment, ['angry', 'urgent'], true)) {
            $reasons[] = sprintf('High-risk customer sentiment: %s', $sentiment);
        }

        if ($modelRequestedReview) {
            $reasons[] = 'AI requested human review';
        }

        return array_values(array_unique($reasons));
    }
}
