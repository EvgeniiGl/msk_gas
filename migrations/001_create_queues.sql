-- migrations/001_create_queues.sql
-- Очереди на АЗС (Telegram, топик "Очереди")

BEGIN;

-- Справочник АЗС (брендов)
CREATE TABLE IF NOT EXISTS brands (
                                      id          SMALLSERIAL PRIMARY KEY,
                                      name        VARCHAR(100) NOT NULL UNIQUE,
    sort_order  SMALLINT NOT NULL DEFAULT 0
    );

INSERT INTO brands (name, sort_order) VALUES
                                          ('Лукойл (Lukoil)', 1),
                                          ('Газпромнефть', 2),
                                          ('Роснефть', 3),
                                          ('Газпром', 4),
                                          ('Татнефть', 5),
                                          ('Башнефть', 6),
                                          ('Тебойл (Teboil)', 7),
                                          ('ТНК Трасса', 8),
                                          ('ЕКА', 9),
                                          ('Нефтьмагистраль', 10),
                                          ('ОРТК', 11),
                                          ('Эверон', 12),
                                          ('Транс-АЗС', 13),
                                          ('Грейтек', 14),
                                          ('Вектор', 15),
                                          ('Магистраль', 16),
                                          ('Нева-Ойл', 17),
                                          ('Калина Ойл', 18),
                                          ('EKA Shell', 19),
                                          ('BP', 20)
    ON CONFLICT (name) DO NOTHING;

-- Типы топлива (для мультивыбора)
CREATE TABLE IF NOT EXISTS fuel_types (
                                          id          SMALLSERIAL PRIMARY KEY,
                                          code        VARCHAR(20) NOT NULL UNIQUE,
    sort_order  SMALLINT NOT NULL DEFAULT 0
    );

INSERT INTO fuel_types (code, sort_order) VALUES
                                              ('АИ-92', 1),
                                              ('АИ-95', 2),
                                              ('АИ-98', 3),
                                              ('АИ-100', 4),
                                              ('дизель', 5)
    ON CONFLICT (code) DO NOTHING;

-- Основная таблица записей очередей
CREATE TABLE IF NOT EXISTS queue_entries (
                                             id              BIGSERIAL PRIMARY KEY,
                                             brand_id        SMALLINT NOT NULL REFERENCES brands(id),
    address         TEXT NOT NULL,
    is_working      BOOLEAN NOT NULL DEFAULT TRUE,
    queue_size      INTEGER NOT NULL CHECK (queue_size >= 0),
    tg_user_id      BIGINT NOT NULL,          -- кто добавил
    tg_username     VARCHAR(64),
    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT now()
    );

-- Связь запись <-> марки топлива (мультивыбор)
CREATE TABLE IF NOT EXISTS queue_entry_fuels (
                                                 entry_id        BIGINT NOT NULL REFERENCES queue_entries(id) ON DELETE CASCADE,
    fuel_type_id    SMALLINT NOT NULL REFERENCES fuel_types(id),
    PRIMARY KEY (entry_id, fuel_type_id)
    );

-- Состояние пошагового диалога (FSM) для каждого пользователя.
-- Хранится в БД, т.к. PHP-FPM воркеры не разделяют память между запросами.
CREATE TABLE IF NOT EXISTS user_states (
                                           tg_user_id      BIGINT PRIMARY KEY,
                                           chat_id         BIGINT NOT NULL,
                                           thread_id       BIGINT,                   -- message_thread_id топика
                                           step            VARCHAR(30) NOT NULL,     -- brand|address|working|fuels|queue
    draft           JSONB NOT NULL DEFAULT '{}'::jsonb,
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT now()
    );

CREATE INDEX IF NOT EXISTS idx_queue_entries_brand   ON queue_entries(brand_id);
CREATE INDEX IF NOT EXISTS idx_queue_entries_created ON queue_entries(created_at DESC);

COMMIT;