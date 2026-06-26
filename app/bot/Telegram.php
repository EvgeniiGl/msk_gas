<?php
declare(strict_types=1);

namespace App\Bot;

use GuzzleHttp\Client;

/**
 * Тонкая обёртка над Telegram Bot API через Guzzle.
 * Используется для отправки сообщений/ответов на callback.
 */
final class Telegram
{
    private Client $http;

    public function __construct(private string $token)
    {
        $this->http = new Client([
            'base_uri' => "https://api.telegram.org/bot{$token}/",
            'timeout'  => 10,
        ]);
    }

    public function call(string $method, array $params): array
    {
        $resp = $this->http->post($method, ['json' => $params]);
        $data = json_decode((string)$resp->getBody(), true);
        return is_array($data) ? $data : [];
    }

    public function sendMessage(int $chatId, string $text, ?array $replyMarkup = null, ?int $threadId = null): array
    {
        $params = [
            'chat_id'    => $chatId,
            'text'       => $text,
            'parse_mode' => 'HTML',
        ];
        if ($threadId !== null) {
            $params['message_thread_id'] = $threadId;
        }
        if ($replyMarkup !== null) {
            $params['reply_markup'] = $replyMarkup;
        }
        return $this->call('sendMessage', $params);
    }

    public function answerCallbackQuery(string $id, string $text = ''): array
    {
        return $this->call('answerCallbackQuery', [
            'callback_query_id' => $id,
            'text'              => $text,
        ]);
    }

    public function editMessageReplyMarkup(int $chatId, int $messageId, array $replyMarkup): array
    {
        return $this->call('editMessageReplyMarkup', [
            'chat_id'      => $chatId,
            'message_id'   => $messageId,
            'reply_markup' => $replyMarkup,
        ]);
    }
}