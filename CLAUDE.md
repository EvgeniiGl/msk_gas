# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A Telegram bot for reporting gas station queue lengths in Moscow. Users interact through a supergroup topic via a 5-step inline-keyboard dialog (FSM). The stack is PHP 8.4 + Phalcon + PostgreSQL, served by nginx + PHP-FPM in Docker.

## Development commands

```bash
# Start all services
docker compose up -d

# Rebuild the PHP-FPM image (after Dockerfile or composer.json changes)
docker compose up -d --build app

# Run database migrations (connect to the running postgres container)
docker compose exec postgres psql -U app -d app -f /dev/stdin < migrations/001_create_queues.sql

# Register the Telegram webhook (run from inside the app container)
docker compose exec app php bin/set_webhook.php https://your-domain/webhook.php

# Remove the webhook
docker compose exec app php bin/set_webhook.php --delete

# Install / update PHP dependencies
docker compose exec app composer install --optimize-autoloader

# Tail PHP-FPM / bot error logs
docker compose logs -f app
```

There is no test suite.

## Architecture

```
public/webhook.php      ← Telegram webhook entry point (validates secret header, returns 200 immediately)
app/config.php          ← Reads all config from env vars; returns a plain PHP array
app/bot/
  Db.php                ← Thin PDO wrapper (all(), one(), run(), pdo())
  Telegram.php          ← Thin Guzzle wrapper for Telegram Bot API (sendMessage, answerCallbackQuery, editMessageReplyMarkup)
  Handler.php           ← All bot logic: FSM, menu, list
bin/set_webhook.php     ← CLI: register or delete the Telegram webhook
migrations/             ← Plain SQL files; apply manually
```

### FSM dialog flow (`Handler.php`)

The bot stores per-user dialog state in the `user_states` table (PostgreSQL, keyed by `tg_user_id`). The `draft` column is JSONB accumulating partial form data across HTTP requests (PHP-FPM workers share no memory).

Steps in order: `brand` → `address` → `working` → `fuels` → `queue` → save & clear.

- **brand / working / fuels** are driven by inline keyboard callbacks (`callback_data` prefix: `brand:`, `work:`, `fuel:`).
- **address / queue** are free-text messages.
- The bot only responds inside a specific chat + topic, filtered by `chat_id` and `thread_id` from env.

### Database schema (key tables)

| Table | Purpose |
|---|---|
| `brands` | Gas station brands (lookup, seeded in migration) |
| `fuel_types` | Fuel codes like АИ-92, дизель (lookup, seeded) |
| `queue_entries` | One row per submitted queue report |
| `queue_entry_fuels` | M:N join: entry ↔ fuel types |
| `user_states` | FSM state per user; cleared after save |

### Environment variables (see `.env.example`)

| Variable | Purpose |
|---|---|
| `TG_BOT_TOKEN` | Bot API token |
| `TG_WEBHOOK_SECRET` | Validated via `X-Telegram-Bot-Api-Secret-Token` header |
| `TG_CHAT_ID` | Supergroup ID (negative); bot ignores other chats |
| `TG_QUEUE_THREAD_ID` | Topic thread ID; bot ignores other topics (0 = any) |
| `DB_*` | PostgreSQL connection |
| `PORT` | Host port nginx binds to (default 8080 in example) |

### Adding a new FSM step

1. Add the step name to the `step` column comment in the migration (documentary only).
2. Add a `case 'stepname':` in `onMessage()` or a new `case 'prefix':` in `onCallback()`.
3. Wire the previous step's handler to call `setState(..., 'stepname', $draft)` and send the prompt.
4. Implement the new handler method.

### Adding a new Telegram API method

Add a method to `Telegram.php` that calls `$this->call('methodName', [...])`. Keep it thin — no business logic.
