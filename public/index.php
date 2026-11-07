<?php

declare(strict_types=1);

use DI\Bridge\Slim\Bridge;
use DI\ContainerBuilder;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

require dirname(__DIR__) . '/vendor/autoload.php';

if (is_file(dirname(__DIR__) . '/.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
    $dotenv->safeLoad();
}

$containerBuilder = new ContainerBuilder();
$configureContainer = require dirname(__DIR__) . '/config/container.php';
$configureContainer($containerBuilder);

$container = $containerBuilder->build();
$app = Bridge::create($container);

$scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
$scriptDirectory = str_replace('\\', '/', dirname($scriptName));
if ($scriptDirectory === '.') {
    $scriptDirectory = '';
}
if (str_ends_with($scriptDirectory, '/public')) {
    $scriptDirectory = substr($scriptDirectory, 0, -strlen('/public'));
}
$scriptDirectory = rtrim($scriptDirectory, '/');
if ($scriptDirectory !== '') {
    $app->setBasePath($scriptDirectory);
}

$app->addBodyParsingMiddleware();
$app->addRoutingMiddleware();
$app->addErrorMiddleware(
    (bool) $container->get('settings')['appDebug'],
    true,
    true
);

$app->get('/health', static function (Request $request, Response $response): Response {
    $payload = json_encode(
        [
            'status' => 'ok',
            'service' => 'AI Native App',
        ],
        JSON_THROW_ON_ERROR
    );

    $response->getBody()->write($payload);

    return $response
        ->withHeader('Content-Type', 'application/json')
        ->withStatus(200);
});

$app->get('/', static function (Request $request, Response $response): Response {
    $html = file_get_contents(dirname(__DIR__) . '/public/index.html');
    if ($html === false) {
        return $response->withStatus(500);
    }

    $response->getBody()->write($html);

    return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
});

$app->post('/api/chat/stream', [\App\Controller\ChatStreamController::class, 'stream']);
$app->post('/api/orders/summary', [\App\Controller\OrderSummaryController::class, 'create']);
$app->post('/api/documents/analyze', [\App\Controller\DocumentAnalysisController::class, 'analyze']);
$app->post('/api/documents/upload', [\App\Controller\DocumentAnalysisController::class, 'upload']);
$app->post('/api/support/analyze', [\App\Controller\SupportController::class, 'handle']);
$app->post('/webhook/line', [\App\Controller\LineWebhookController::class, 'handle']);

$app->run();
