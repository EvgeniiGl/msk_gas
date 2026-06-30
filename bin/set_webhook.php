<?php
declare(strict_types=1);

/**
 * Регистрация / удаление webhook.
 *
 *   php bin/set_webhook.php https://your-domain/webhook.php
 *   php bin/set_webhook.php --delete
 */

require __DIR__ . '/../vendor/autoload.php';

use App\bot\Telegram;

$config = require __DIR__ . '/../app/config.php';
$tg = new Telegram($config['bot']['token']);

$arg = $argv[1] ?? '';

if ($arg === '--delete') {
    $res = $tg->call('deleteWebhook', ['drop_pending_updates' => true]);
    echo json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), PHP_EOL;
    exit;
}

if ($arg === '' || !preg_match('#^https://#', $arg)) {
    fwrite(STDERR, "Usage: php bin/set_webhook.php https://your-domain/webhook.php\n");
    exit(1);
}

$params = [
    'url'             => $arg,
    'allowed_updates' => ['message', 'callback_query'],
    'drop_pending_updates' => true,
];
if ($config['bot']['webhook_secret'] !== '') {
    $params['secret_token'] = $config['bot']['webhook_secret'];
}

$res = $tg->call('setWebhook', $params);
echo json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), PHP_EOL;