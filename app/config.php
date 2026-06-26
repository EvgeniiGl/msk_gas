<?php
declare(strict_types=1);

return [
    'bot' => [
        'token'    => getenv('TG_BOT_TOKEN') ?: '',
        'username' => getenv('TG_BOT_USERNAME') ?: 'moskov_gas_bot',
        // Секрет для проверки заголовка X-Telegram-Bot-Api-Secret-Token
        'webhook_secret' => getenv('TG_WEBHOOK_SECRET') ?: '',
        // ID супергруппы (chat_id, отрицательный, вида -100...).
        // Бот реагирует только в этой группе.
        'chat_id'   => (int)(getenv('TG_CHAT_ID') ?: 0),
        // message_thread_id топика "Очереди". Бот реагирует только в нём.
        // Узнать: написать сообщение в топик и посмотреть message_thread_id в апдейте.
        'thread_id' => (int)(getenv('TG_QUEUE_THREAD_ID') ?: 0),
    ],
    'db' => [
        'host'     => getenv('DB_HOST') ?: 'postgres',
        'port'     => (int)(getenv('DB_PORT') ?: 5432),
        'dbname'   => getenv('DB_NAME') ?: 'app',
        'user'     => getenv('DB_USER') ?: 'app',
        'password' => getenv('DB_PASSWORD') ?: '',
    ],
];
