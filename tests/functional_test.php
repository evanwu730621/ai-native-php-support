<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Service\DocumentAnalysisService;
use App\Service\SupportService;
use App\Services\AI\LlmClient;
use App\Services\DocumentChunker;
use App\Tools\QueryOrderTool;
use Symfony\Component\HttpClient\HttpClient;

final class FunctionalTestRunner
{
    private int $failures = 0;

    public function run(string $name, callable $test): void
    {
        try {
            $test();
            fwrite(STDOUT, '[PASS] ' . $name . PHP_EOL);
        } catch (\Throwable $exception) {
            $this->failures++;
            fwrite(STDOUT, '[FAIL] ' . $name . ': ' . $exception->getMessage() . PHP_EOL);
        }
    }

    public function summary(): void
    {
        fwrite(STDOUT, PHP_EOL);
        if ($this->failures === 0) {
            fwrite(STDOUT, '[PASS] 所有功能測試完成。' . PHP_EOL);
            return;
        }

        fwrite(STDOUT, '[FAIL] 失敗測試數：' . $this->failures . PHP_EOL);
        exit(1);
    }
}

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new \RuntimeException($message);
    }
}

function createSqliteConnection(): \PDO
{
    $pdo = new \PDO('sqlite::memory:');
    $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    $pdo->exec(
        <<<'SQL'
CREATE TABLE tickets (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id TEXT NOT NULL,
    title TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'open',
    priority TEXT NOT NULL DEFAULT 'normal',
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE ticket_messages (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    ticket_id INTEGER NOT NULL,
    sender_type TEXT NOT NULL,
    content TEXT NOT NULL,
    tokens_used INTEGER NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE orders (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    order_no TEXT NOT NULL UNIQUE,
    customer_name TEXT NOT NULL,
    total_amount REAL NOT NULL DEFAULT 0,
    status TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
INSERT INTO orders (order_no, customer_name, total_amount, status)
VALUES ('ORD-DEMO-001', 'Test Customer', 1290.00, 'processing');
SQL
    );

    return $pdo;
}

$runner = new FunctionalTestRunner();
$llmClient = new LlmClient(
    HttpClient::create(),
    '',
);
$database = createSqliteConnection();

$runner->run('文檔分析測試', static function () use ($llmClient): void {
    $service = new DocumentAnalysisService($llmClient, new DocumentChunker());
    $dto = $service->analyze(
        '本公司於第四季推出自動化客服方案。該方案可降低人工處理時間，'
        . '並透過文件分析協助客服人員快速定位常見問題。建議優先導入訂單與退款流程。'
    );

    assertTrue(trim($dto->summary) !== '', '文檔摘要不可為空。');
    assertTrue(is_array($dto->keyPoints), '文檔標籤欄位應為陣列。');
    assertTrue($dto->keyPoints !== [], '文檔標籤不可為空。');
});

$runner->run('一般客服詢問測試', static function () use ($llmClient, $database): void {
    $service = new SupportService($llmClient, null, $database);
    $dto = $service->handle('請問訂單進度要去哪裡查詢？');

    assertTrue(trim($dto->reply) !== '', 'AI 回覆不可為空。');
    assertTrue(!$dto->needsHumanReview, '一般詢問不應觸發真人轉接。');
});

$runner->run('不滿客訴真人轉接測試', static function () use ($llmClient, $database): void {
    $service = new SupportService($llmClient, null, $database);
    $dto = $service->handle('我要退費，請幫我找真人客服處理！');

    assertTrue($dto->needsHumanReview, '退費客訴應觸發真人轉接。');
    assertTrue(
        $dto->reply === '已為您記錄並指派專人處理，客服人員將於上班時間回覆',
        '真人轉接回覆不符合預期。'
    );
});

$runner->run('無資料庫 Mock 降級測試', static function () use ($llmClient): void {
    putenv('DB_HOST=');
    putenv('DB_NAME=');
    $_ENV['DB_HOST'] = '';
    $_ENV['DB_NAME'] = '';

    $service = new SupportService($llmClient);
    $dto = $service->handle('請問訂單要幫我退款嗎？請找真人客服。');

    assertTrue($dto->needsHumanReview, '無資料庫降級仍應觸發真人轉接。');
    assertTrue(str_starts_with($dto->ticketId, 'TK-MOCK-'), '降級模式應回傳 Mock Ticket 編號。');
});

$runner->run('訂單查詢工具測試', static function () use ($database): void {
    $tool = new QueryOrderTool($database);
    $result = $tool->execute(['order_number' => 'ORD-DEMO-001']);

    assertTrue(($result['found'] ?? false) === true, '訂單應可被成功查詢。');
    assertTrue(($result['order_number'] ?? '') === 'ORD-DEMO-001', '訂單編號不符合預期。');
    assertTrue(($result['status'] ?? '') === 'processing', '訂單狀態不符合預期。');
});

$runner->summary();
