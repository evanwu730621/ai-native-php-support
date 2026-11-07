<?php

declare(strict_types=1);

use App\Exception\ExternalServiceException;
use App\Services\AI\LlmClient;
use Symfony\Component\HttpClient\HttpClient;

require dirname(__DIR__) . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

$apiKey = trim((string) ($_ENV['GEMINI_API_KEY'] ?? getenv('GEMINI_API_KEY') ?: ''));
$model = trim((string) ($_ENV['GEMINI_MODEL'] ?? getenv('GEMINI_MODEL') ?: 'gemini-2.5-flash'));
$baseUrl = rtrim(
    (string) ($_ENV['GEMINI_BASE_URL'] ?? getenv('GEMINI_BASE_URL')
        ?: 'https://generativelanguage.googleapis.com/v1beta'),
    '/',
);

if ($apiKey === '') {
    fwrite(STDERR, "[FAIL] GEMINI_API_KEY is not configured.\n");
    exit(1);
}

$client = new LlmClient(HttpClient::create(), $apiKey, $model, $baseUrl, 60.0);

try {
    $reply = '';
    foreach ($client->stream([[
        'role' => 'user',
        'content' => 'Hello, test',
    ]]) as $character) {
        $reply .= $character;
    }

    echo "[PASS] Gemini replied: " . trim($reply) . PHP_EOL;
    exit(0);
} catch (ExternalServiceException|\Throwable $exception) {
    fwrite(STDERR, "[FAIL] " . $exception->getMessage() . PHP_EOL);
    exit(1);
}
