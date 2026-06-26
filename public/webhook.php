<?php
declare(strict_types=1);

/**
 * Точка входа webhook Telegram.
 * Настройте у Telegram: https://your-domain/webhook.php
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Bot\Db;
use App\Bot\Telegram;
use App\Bot\Handler;

$config = require __DIR__ . '/../app/config.php';

// Проверка секрета webhook (защита эндпоинта)
$secret = $config['bot']['webhook_secret'];
if ($secret !== '') {
    $hdr = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
    if (!hash_equals($secret, $hdr)) {
        http_response_code(403);
        echo 'forbidden';
        exit;
    }
}

$raw = file_get_contents('php://input');
$update = json_decode($raw, true);

if (!is_array($update)) {
    http_response_code(400);
    echo 'bad request';
    exit;
}

// Отвечаем Telegram сразу 200, чтобы не было ретраев при долгой обработке
http_response_code(200);
header('Content-Type: text/plain');
echo 'ok';

if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}

try {
    $db      = new Db($config['db']);
    $tg      = new Telegram($config['bot']['token']);
    $handler = new Handler($db, $tg, $config['bot']);
    $handler->handle($update);
} catch (\Throwable $e) {
    error_log('[bot] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
}