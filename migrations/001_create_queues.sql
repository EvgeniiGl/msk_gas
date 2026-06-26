-- migrations/001_create_queues.sql

BEGIN;

-- Справочник АЗС (брендов)
CREATE TABLE IF NOT EXISTS brands (
                                      id          SMALLSERIAL PRIMARY KEY,
                                      name        VARCHAR(100) NOT NULL UNIQUE
    );

INSERT INTO brands (name) VALUES
                              ('Лукойл (Lukoil)'),
                              ('Газпромнефть'),
                              ('Роснефть'),
                              ('Газпром'),
                              ('Татнефть'),
                              ('Башнефть'),
                              ('Тебойл (Teboil)'),
                              ('ТНК Трасса'),
                              ('ЕКА'),
                              ('Нефтьмагистраль'),
                              ('ОРТК'),
                              ('Эверон'),
                              ('Транс-АЗС'),
                              ('Грейтек'),
                              ('Вектор'),
                              ('Магистраль'),
                              ('Нева-Ойл'),
                              ('Калина Ойл'),
                              ('EKA Shell'),
                              ('BP')
    ON CONFLICT (name) DO NOTHING;

-- Типы топлива (для мультивыбора)
CREATE TABLE IF NOT EXISTS fuel_types (
                                          id          SMALLSERIAL PRIMARY KEY,
                                          code        VARCHAR(20) NOT NULL UNIQUE
    );

INSERT INTO fuel_types (code) VALUES
                                  ('АИ-92'),
                                  ('АИ-95'),
                                  ('АИ-98'),
                                  ('АИ-100'),
                                  ('дизель')
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

-- Состояние пошагового диалога (FSM) для каждого пользователя
CREATE TABLE IF NOT EXISTS user_states (
                                           tg_user_id      BIGINT PRIMARY KEY,
                                           chat_id         BIGINT NOT NULL,
                                           step            VARCHAR(30) NOT NULL,     -- brand|address|working|fuels|queue
    draft           JSONB NOT NULL DEFAULT '{}'::jsonb,
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT now()
    );

CREATE INDEX IF NOT EXISTS idx_queue_entries_brand   ON queue_entries(brand_id);
CREATE INDEX IF NOT EXISTS idx_queue_entries_created ON queue_entries(created_at DESC);

COMMIT;