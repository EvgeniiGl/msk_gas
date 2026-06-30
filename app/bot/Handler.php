<?php
declare(strict_types=1);

namespace App\bot;

/**
 * Обработчик апдейтов Telegram с конечным автоматом (FSM).
 *
 * Шаги добавления записи:
 *   brand   -> выбор АЗС (inline-кнопки из таблицы brands)
 *   address -> ввод адреса текстом
 *   working -> да / нет
 *   fuels   -> мультивыбор марок топлива (галочки, кнопка "Готово")
 *   queue   -> количество машин в очереди (число)
 */
final class Handler
{
    public function __construct(
        private Db $db,
        private Telegram $tg,
        private array $botCfg
    ) {}

    public function handle(array $update): void
    {
        if (isset($update['callback_query'])) {
            $this->onCallback($update['callback_query']);
            return;
        }
        if (isset($update['message'])) {
            $this->onMessage($update['message']);
        }
    }

    // ---------- Фильтр топика ----------

    private function inQueueTopic(array $msg): bool
    {
        $chatId   = (int)($msg['chat']['id'] ?? 0);
        $threadId = (int)($msg['message_thread_id'] ?? 0);

        if ($this->botCfg['chat_id'] && $chatId !== $this->botCfg['chat_id']) {
            return false;
        }
        if ($this->botCfg['thread_id'] && $threadId !== $this->botCfg['thread_id']) {
            return false;
        }
        return true;
    }

    // ---------- Сообщения ----------

    private function onMessage(array $msg): void
    {
        if (!$this->inQueueTopic($msg)) {
            return;
        }

        $chatId   = (int)$msg['chat']['id'];
        $threadId = isset($msg['message_thread_id']) ? (int)$msg['message_thread_id'] : null;
        $userId   = (int)($msg['from']['id'] ?? 0);
        $text     = trim((string)($msg['text'] ?? ''));

        if ($userId === 0) {
            return;
        }

        // Команда вызова меню (т.к. "вход в топик" Telegram не присылает)
        if ($text === '/menu' || $text === '/start' || mb_strtolower($text) === 'меню') {
            $this->showMenu($chatId, $threadId);
            return;
        }

        $state = $this->getState($userId);
        if ($state === null) {
            return; // нет активного диалога — игнорируем обычный текст
        }

        switch ($state['step']) {
            case 'address':
                $this->stepAddress($state, $text);
                break;
            case 'queue':
                $this->stepQueue($state, $text);
                break;
            // brand / working / fuels управляются кнопками, текст игнорируем
        }
    }

    private function showMenu(int $chatId, ?int $threadId): void
    {
        $kb = ['inline_keyboard' => [[
                                         ['text' => '📋 Список',   'callback_data' => 'menu:list'],
                                         ['text' => '🔎 Поиск',    'callback_data' => 'menu:search'],
                                         ['text' => '➕ Добавить', 'callback_data' => 'menu:add'],
                                     ]]];
        $this->tg->sendMessage($chatId, 'Очереди на АЗС. Выберите действие:', $kb, $threadId);
    }

    // ---------- Callback-кнопки ----------

    private function onCallback(array $cq): void
    {
        $msg      = $cq['message'] ?? [];
        if (!$this->inQueueTopic($msg)) {
            $this->tg->answerCallbackQuery($cq['id']);
            return;
        }

        $chatId   = (int)($msg['chat']['id'] ?? 0);
        $threadId = isset($msg['message_thread_id']) ? (int)$msg['message_thread_id'] : null;
        $userId   = (int)($cq['from']['id'] ?? 0);
        $username = (string)($cq['from']['username'] ?? '');
        $data     = (string)($cq['data'] ?? '');

        [$ns, $arg] = array_pad(explode(':', $data, 2), 2, '');

        switch ($ns) {
            case 'menu':
                $this->onMenu($cq, $chatId, $threadId, $userId, $username, $arg);
                break;
            case 'brand':
                $this->cbBrand($cq, $userId, (int)$arg);
                break;
            case 'work':
                $this->cbWorking($cq, $userId, $arg === '1');
                break;
            case 'fuel':
                $this->cbFuelToggle($cq, $userId, $arg);
                break;
            default:
                $this->tg->answerCallbackQuery($cq['id']);
        }
    }

    private function onMenu(array $cq, int $chatId, ?int $threadId, int $userId, string $username, string $action): void
    {
        $this->tg->answerCallbackQuery($cq['id']);

        switch ($action) {
            case 'add':
                $this->setState($userId, $chatId, $threadId, 'brand', [
                    'username' => $username,
                    'fuels'    => [],
                ]);
                $this->askBrand($chatId, $threadId);
                break;
            case 'list':
                $this->showList($chatId, $threadId);
                break;
            case 'search':
                $this->tg->sendMessage($chatId, 'Поиск пока в разработке.', null, $threadId);
                break;
        }
    }

    // ---------- Шаг 1: АЗС ----------

    private function askBrand(int $chatId, ?int $threadId): void
    {
        $brands = $this->db->all('SELECT id, name FROM brands ORDER BY sort_order, id');
        $rows = [];
        $row  = [];
        foreach ($brands as $b) {
            $row[] = ['text' => $b['name'], 'callback_data' => 'brand:' . $b['id']];
            if (count($row) === 2) { $rows[] = $row; $row = []; }
        }
        if ($row) { $rows[] = $row; }

        $this->tg->sendMessage($chatId, '<b>Шаг 1/5.</b> Выберите АЗС:', ['inline_keyboard' => $rows], $threadId);
    }

    private function cbBrand(array $cq, int $userId, int $brandId): void
    {
        $state = $this->getState($userId);
        if ($state === null || $state['step'] !== 'brand') {
            $this->tg->answerCallbackQuery($cq['id']);
            return;
        }
        $brand = $this->db->one('SELECT name FROM brands WHERE id = :id', ['id' => $brandId]);
        if ($brand === null) {
            $this->tg->answerCallbackQuery($cq['id'], 'АЗС не найдена');
            return;
        }
        $draft = $state['draft'];
        $draft['brand_id']   = $brandId;
        $draft['brand_name'] = $brand['name'];

        $this->setState($userId, (int)$state['chat_id'], $this->threadOf($state), 'address', $draft);
        $this->tg->answerCallbackQuery($cq['id'], $brand['name']);
        $this->tg->sendMessage((int)$state['chat_id'], '<b>Шаг 2/5.</b> Введите адрес АЗС:', null, $this->threadOf($state));
    }

    // ---------- Шаг 2: адрес ----------

    private function stepAddress(array $state, string $text): void
    {
        if ($text === '') {
            $this->tg->sendMessage((int)$state['chat_id'], 'Адрес не может быть пустым. Введите адрес:', null, $this->threadOf($state));
            return;
        }
        $draft = $state['draft'];
        $draft['address'] = mb_substr($text, 0, 500);

        $this->setState((int)$state['tg_user_id'], (int)$state['chat_id'], $this->threadOf($state), 'working', $draft);

        $kb = ['inline_keyboard' => [[
                                         ['text' => '✅ Да', 'callback_data' => 'work:1'],
                                         ['text' => '❌ Нет', 'callback_data' => 'work:0'],
                                     ]]];
        $this->tg->sendMessage((int)$state['chat_id'], '<b>Шаг 3/5.</b> АЗС работает?', $kb, $this->threadOf($state));
    }

    // ---------- Шаг 3: работает ----------

    private function cbWorking(array $cq, int $userId, bool $working): void
    {
        $state = $this->getState($userId);
        if ($state === null || $state['step'] !== 'working') {
            $this->tg->answerCallbackQuery($cq['id']);
            return;
        }
        $draft = $state['draft'];
        $draft['is_working'] = $working;

        $this->setState($userId, (int)$state['chat_id'], $this->threadOf($state), 'fuels', $draft);
        $this->tg->answerCallbackQuery($cq['id'], $working ? 'Работает' : 'Не работает');
        $this->askFuels($state, $draft);
    }

    // ---------- Шаг 4: марки топлива (мультивыбор) ----------

    private function askFuels(array $state, array $draft): void
    {
        $this->tg->sendMessage(
            (int)$state['chat_id'],
            '<b>Шаг 4/5.</b> Выберите марки топлива (можно несколько), затем «Готово»:',
            $this->fuelKeyboard($draft['fuels'] ?? []),
            $this->threadOf($state)
        );
    }

    private function fuelKeyboard(array $selected): array
    {
        $fuels = $this->db->all('SELECT id, code FROM fuel_types ORDER BY sort_order, id');
        $rows = [];
        $row  = [];
        foreach ($fuels as $f) {
            $mark = in_array((int)$f['id'], $selected, true) ? '☑️ ' : '⬜ ';
            $row[] = ['text' => $mark . $f['code'], 'callback_data' => 'fuel:' . $f['id']];
            if (count($row) === 2) { $rows[] = $row; $row = []; }
        }
        if ($row) { $rows[] = $row; }
        $rows[] = [['text' => '✅ Готово', 'callback_data' => 'fuel:done']];
        return ['inline_keyboard' => $rows];
    }

    private function cbFuelToggle(array $cq, int $userId, string $arg): void
    {
        $state = $this->getState($userId);
        if ($state === null || $state['step'] !== 'fuels') {
            $this->tg->answerCallbackQuery($cq['id']);
            return;
        }
        $draft    = $state['draft'];
        $selected = array_map('intval', $draft['fuels'] ?? []);

        if ($arg === 'done') {
            if (empty($selected)) {
                $this->tg->answerCallbackQuery($cq['id'], 'Выберите хотя бы одну марку');
                return;
            }
            $this->setState($userId, (int)$state['chat_id'], $this->threadOf($state), 'queue', $draft);
            $this->tg->answerCallbackQuery($cq['id']);
            $this->tg->sendMessage((int)$state['chat_id'], '<b>Шаг 5/5.</b> Сколько машин в очереди? Введите число:', null, $this->threadOf($state));
            return;
        }

        $fuelId = (int)$arg;
        if (in_array($fuelId, $selected, true)) {
            $selected = array_values(array_diff($selected, [$fuelId]));
        } else {
            $selected[] = $fuelId;
        }
        $draft['fuels'] = $selected;
        $this->setState($userId, (int)$state['chat_id'], $this->threadOf($state), 'fuels', $draft);

        $this->tg->answerCallbackQuery($cq['id']);
        $msgId = (int)($cq['message']['message_id'] ?? 0);
        if ($msgId) {
            $this->tg->editMessageReplyMarkup((int)$state['chat_id'], $msgId, $this->fuelKeyboard($selected));
        }
    }

    // ---------- Шаг 5: очередь + сохранение ----------

    private function stepQueue(array $state, string $text): void
    {
        if (!preg_match('/^\d{1,6}$/', $text)) {
            $this->tg->sendMessage((int)$state['chat_id'], 'Введите количество машин числом (например 5):', null, $this->threadOf($state));
            return;
        }
        $draft = $state['draft'];
        $draft['queue_size'] = (int)$text;

        $entryId = $this->save((int)$state['tg_user_id'], $draft);
        $this->clearState((int)$state['tg_user_id']);

        $fuelCodes = $this->db->all(
            'SELECT code FROM fuel_types WHERE id = ANY(:ids) ORDER BY sort_order',
            ['ids' => '{' . implode(',', array_map('intval', $draft['fuels'])) . '}']
        );
        $fuelStr = implode(', ', array_column($fuelCodes, 'code'));

        $summary = sprintf(
            "✅ <b>Запись #%d добавлена</b>\n\n🏁 <b>АЗС:</b> %s\n📍 <b>Адрес:</b> %s\n⚙️ <b>Работает:</b> %s\n⛽ <b>Марки:</b> %s\n🚗 <b>Очередь:</b> %d",
            $entryId,
            htmlspecialchars($draft['brand_name']),
            htmlspecialchars($draft['address']),
            $draft['is_working'] ? 'да' : 'нет',
            htmlspecialchars($fuelStr),
            $draft['queue_size']
        );
        $this->tg->sendMessage((int)$state['chat_id'], $summary, null, $this->threadOf($state));
    }

    private function save(int $userId, array $draft): int
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare(
                'INSERT INTO queue_entries (brand_id, address, is_working, queue_size, tg_user_id, tg_username)
                 VALUES (:brand_id, :address, :is_working, :queue_size, :uid, :uname)
                 RETURNING id'
            );
            $st->execute([
                'brand_id'   => $draft['brand_id'],
                'address'    => $draft['address'],
                'is_working' => $draft['is_working'] ? 't' : 'f',
                'queue_size' => $draft['queue_size'],
                'uid'        => $userId,
                'uname'      => $draft['username'] ?: null,
            ]);
            $entryId = (int)$st->fetchColumn();

            $insFuel = $pdo->prepare(
                'INSERT INTO queue_entry_fuels (entry_id, fuel_type_id) VALUES (:e, :f)
                 ON CONFLICT DO NOTHING'
            );
            foreach (array_unique(array_map('intval', $draft['fuels'])) as $fid) {
                $insFuel->execute(['e' => $entryId, 'f' => $fid]);
            }

            $pdo->commit();
            return $entryId;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    // ---------- Список ----------

    private function showList(int $chatId, ?int $threadId): void
    {
        $rows = $this->db->all(
            "SELECT qe.id, b.name AS brand, qe.address, qe.is_working, qe.queue_size,
                    qe.created_at,
                    COALESCE(string_agg(ft.code, ', ' ORDER BY ft.sort_order), '') AS fuels
             FROM queue_entries qe
             JOIN brands b ON b.id = qe.brand_id
             LEFT JOIN queue_entry_fuels qef ON qef.entry_id = qe.id
             LEFT JOIN fuel_types ft ON ft.id = qef.fuel_type_id
             GROUP BY qe.id, b.name
             ORDER BY qe.created_at DESC
             LIMIT 10"
        );

        if (empty($rows)) {
            $this->tg->sendMessage($chatId, 'Пока нет записей.', null, $threadId);
            return;
        }

        $lines = ['<b>Последние записи:</b>', ''];
        foreach ($rows as $r) {
            $lines[] = sprintf(
                "🏁 <b>%s</b> — %s\n📍 %s | ⛽ %s | 🚗 %d%s",
                htmlspecialchars($r['brand']),
                $r['is_working'] === true || $r['is_working'] === 't' ? 'работает' : 'не работает',
                htmlspecialchars($r['address']),
                htmlspecialchars($r['fuels']),
                (int)$r['queue_size'],
                ''
            );
            $lines[] = '';
        }
        $this->tg->sendMessage($chatId, implode("\n", $lines), null, $threadId);
    }

    // ---------- FSM storage ----------

    private function getState(int $userId): ?array
    {
        $row = $this->db->one('SELECT * FROM user_states WHERE tg_user_id = :id', ['id' => $userId]);
        if ($row === null) {
            return null;
        }
        $row['draft'] = json_decode((string)$row['draft'], true) ?: [];
        return $row;
    }

    private function setState(int $userId, int $chatId, ?int $threadId, string $step, array $draft): void
    {
        $this->db->run(
            'INSERT INTO user_states (tg_user_id, chat_id, thread_id, step, draft, updated_at)
             VALUES (:uid, :chat, :thread, :step, :draft, now())
             ON CONFLICT (tg_user_id) DO UPDATE
                SET chat_id = EXCLUDED.chat_id,
                    thread_id = EXCLUDED.thread_id,
                    step = EXCLUDED.step,
                    draft = EXCLUDED.draft,
                    updated_at = now()',
            [
                'uid'    => $userId,
                'chat'   => $chatId,
                'thread' => $threadId,
                'step'   => $step,
                'draft'  => json_encode($draft, JSON_UNESCAPED_UNICODE),
            ]
        );
    }

    private function clearState(int $userId): void
    {
        $this->db->run('DELETE FROM user_states WHERE tg_user_id = :id', ['id' => $userId]);
    }

    private function threadOf(array $state): ?int
    {
        return isset($state['thread_id']) && $state['thread_id'] !== null
            ? (int)$state['thread_id']
            : null;
    }
}