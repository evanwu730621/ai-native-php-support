<?php

declare(strict_types=1);

use App\Database\Connection;
use App\Services\AI\LlmClient;
use App\Tools\QueryOrderTool;
use DI\ContainerBuilder;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Psr\Log\LoggerInterface;

return static function (ContainerBuilder $containerBuilder): void {
    $containerBuilder->addDefinitions([
        'settings' => [
            'appName' => $_ENV['APP_NAME'] ?? 'AI Native App',
            'appEnv' => $_ENV['APP_ENV'] ?? 'production',
            'appDebug' => filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOL),
            'logPath' => dirname(__DIR__) . '/storage/logs/app.log',
        ],

        HttpClientInterface::class => static function (): HttpClientInterface {
            return HttpClient::create([
                'timeout' => 300.0,
            ]);
        },

        LlmClient::class => static function (
            HttpClientInterface $httpClient,
            LoggerInterface $logger
        ): LlmClient {
            return new LlmClient(
                $httpClient,
                $_ENV['GEMINI_API_KEY'] ?? '',
                $_ENV['GEMINI_MODEL'] ?? 'gemini-2.5-flash',
                $_ENV['GEMINI_BASE_URL'] ?? 'https://generativelanguage.googleapis.com/v1beta',
                (float) ($_ENV['GEMINI_TIMEOUT'] ?? 300.0),
                $logger,
            );
        },

        \PDO::class => static function (): \PDO {
            return Connection::getInstance();
        },

        QueryOrderTool::class => static function (\PDO $connection): QueryOrderTool {
            return new QueryOrderTool($connection);
        },

        LoggerInterface::class => static function (): LoggerInterface {
            $logPath = dirname(__DIR__) . '/storage/logs/app.log';
            $logDirectory = dirname($logPath);

            if (!is_dir($logDirectory)) {
                mkdir($logDirectory, 0775, true);
            }

            $logger = new Logger('application');
            $logger->pushHandler(new StreamHandler($logPath, Logger::DEBUG));

            return $logger;
        },
    ]);
};
