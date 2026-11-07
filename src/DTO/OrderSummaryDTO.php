<?php

declare(strict_types=1);

namespace App\DTO;

final readonly class OrderSummaryDTO
{
    /**
     * @param list<string> $items
     */
    public function __construct(
        public string $orderNumber,
        public string $status,
        public string $totalAmount,
        public string $currency,
        public string $summary,
        public array $items,
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
                'order_number' => ['type' => 'string'],
                'status' => ['type' => 'string'],
                'total_amount' => ['type' => 'string'],
                'currency' => ['type' => 'string'],
                'summary' => ['type' => 'string'],
                'items' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
            ],
            'required' => [
                'order_number',
                'status',
                'total_amount',
                'currency',
                'summary',
                'items',
            ],
            'additionalProperties' => false,
        ];
    }

    /**
     * @param array<string, scalar|array> $data
     */
    public static function fromArray(array $data): self
    {
        foreach ([
            'order_number',
            'status',
            'total_amount',
            'currency',
            'summary',
        ] as $key) {
            if (!isset($data[$key]) || !is_string($data[$key])) {
                throw new \InvalidArgumentException(sprintf('Field "%s" must be a string.', $key));
            }
        }

        if (!isset($data['items']) || !is_array($data['items'])) {
            throw new \InvalidArgumentException('Field "items" must be an array.');
        }

        foreach ($data['items'] as $item) {
            if (!is_string($item)) {
                throw new \InvalidArgumentException('Every item must be a string.');
            }
        }

        return new self(
            $data['order_number'],
            $data['status'],
            $data['total_amount'],
            $data['currency'],
            $data['summary'],
            array_values($data['items']),
        );
    }
}
