<?php

declare(strict_types=1);

namespace App\DTO;

final readonly class DocumentAnalysisDTO
{
    /**
     * @param list<string> $keyPoints
     * @param list<string> $suggestedActions
     */
    public function __construct(
        public string $summary,
        public array $keyPoints,
        public array $suggestedActions,
        public string $sentiment,
        public string $severity,
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
                'summary' => ['type' => 'string'],
                'key_points' => ['type' => 'array', 'items' => ['type' => 'string']],
                'suggested_actions' => ['type' => 'array', 'items' => ['type' => 'string']],
                'sentiment' => [
                    'type' => 'string',
                    'enum' => ['正面', '中立', '負面'],
                ],
                'severity' => ['type' => 'string', 'enum' => ['低', '中', '高']],
            ],
            'required' => [
                'summary',
                'key_points',
                'suggested_actions',
                'sentiment',
                'severity',
            ],
            'additionalProperties' => false,
        ];
    }

    /**
     * @param array<string, scalar|array> $data
     */
    public static function fromArray(array $data): self
    {
        foreach (['summary', 'sentiment', 'severity'] as $key) {
            if (!isset($data[$key]) || !is_string($data[$key])) {
                throw new \InvalidArgumentException(sprintf('Field "%s" must be a string.', $key));
            }
        }

        foreach (['key_points', 'suggested_actions'] as $key) {
            if (!isset($data[$key]) || !is_array($data[$key])) {
                throw new \InvalidArgumentException(sprintf('Field "%s" must be an array.', $key));
            }
            foreach ($data[$key] as $item) {
                if (!is_string($item)) {
                    throw new \InvalidArgumentException(sprintf('Field "%s" must contain strings.', $key));
                }
            }
        }

        return new self(
            $data['summary'],
            array_values($data['key_points']),
            array_values($data['suggested_actions']),
            $data['sentiment'],
            $data['severity'],
        );
    }
}
