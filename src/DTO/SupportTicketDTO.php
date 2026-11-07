<?php

declare(strict_types=1);

namespace App\DTO;

final readonly class SupportTicketDTO
{
    /**
     * @param list<string> $suggestedReplies
     */
    public function __construct(
        public string $ticketId,
        public string $category,
        public string $priority,
        public string $sentiment,
        public string $reply,
        public array $suggestedReplies,
        public bool $needsHumanReview,
        public int $confidenceScore = 100,
    ) {
    }

    /**
     * @return array<string, scalar|array>
     */
    public static function jsonSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'category' => ['type' => 'string'],
                'priority' => ['type' => 'string', 'enum' => ['low', 'normal', 'high', 'urgent']],
                'sentiment' => ['type' => 'string', 'enum' => ['normal', 'angry', 'urgent']],
                'confidence_score' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
                'reply' => ['type' => 'string'],
                'suggested_replies' => ['type' => 'array', 'items' => ['type' => 'string']],
                'needs_human_review' => ['type' => 'boolean'],
            ],
            'required' => [
                'category',
                'priority',
                'sentiment',
                'confidence_score',
                'reply',
                'suggested_replies',
                'needs_human_review',
            ],
            'additionalProperties' => false,
        ];
    }
}
