<?php

declare(strict_types=1);

namespace App\Tools;

use App\Exception\DatabaseException;
use Psr\Log\LoggerInterface;

final class QueryOrderTool
{
    public function __construct(
        private readonly \PDO $connection,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * @return array<string, scalar|array>
     */
    public function definition(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'query_order',
                'description' => '查詢指定訂單編號的非敏感訂單摘要。',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'order_number' => [
                            'type' => 'string',
                            'description' => '完整訂單編號，例如 ORD-DEMO-001。',
                            'pattern' => '^ORD-[A-Za-z0-9_-]+$',
                        ],
                    ],
                    'required' => ['order_number'],
                    'additionalProperties' => false,
                ],
            ],
        ];
    }

    /**
     * @param array<string, scalar|array> $arguments
     * @return array<string, scalar|array>
     */
    public function execute(array $arguments): array
    {
        $orderNumber = $arguments['order_number'] ?? null;
        if (!is_string($orderNumber) || preg_match('/^ORD-[A-Za-z0-9_-]+$/', $orderNumber) !== 1) {
            return [
                'error' => 'Invalid order number.',
            ];
        }

        try {
            $statement = $this->connection->prepare(
                <<<'SQL'
SELECT order_no, status, total_amount, created_at
FROM orders
WHERE order_no = :order_number
LIMIT 1
SQL
            );
            $statement->bindValue(':order_number', $orderNumber, \PDO::PARAM_STR);
            $statement->execute();
            $order = $statement->fetch(\PDO::FETCH_ASSOC);
        } catch (\PDOException $exception) {
            $this->logger?->error('Unable to query an order.', ['exception' => $exception]);
            throw new DatabaseException('Unable to query the orders database.', 0, $exception);
        }
        if (!is_array($order)) {
            return [
                'found' => false,
            ];
        }

        return [
            'found' => true,
            'order_number' => $order['order_no'],
            'status' => $order['status'],
            'total_amount' => (string) $order['total_amount'],
            'created_at' => $order['created_at'],
        ];
    }
}
