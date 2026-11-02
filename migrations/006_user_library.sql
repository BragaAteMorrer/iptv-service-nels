-- Durable, account-scoped preferences remain after source reimport.
CREATE TABLE IF NOT EXISTS user_channel_preferences (
 user_id BIGINT NOT NULL REFERENCES app_users(id) ON DELETE CASCADE,
 source_id BIGINT NOT NULL REFERENCES sources(id) ON DELETE CASCADE,
 external_id TEXT NOT NULL,
 favorite BOOLEAN NOT NULL DEFAULT FALSE,
 hidden BOOLEAN NOT NULL DEFAULT FALSE,
 channel_number INTEGER CHECK(channel_number BETWEEN 1 AND 99999),
 updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
 PRIMARY KEY(user_id,source_id,external_id)
);
CREATE INDEX IF NOT EXISTS user_channel_preferences_source ON user_channel_preferences(user_id,source_id);
CREATE TABLE IF NOT EXISTS user_watch_progress (
 user_id BIGINT NOT NULL REFERENCES app_users(id) ON DELETE CASCADE,
 source_id BIGINT NOT NULL REFERENCES sources(id) ON DELETE CASCADE,
 external_id TEXT NOT NULL,
 position_seconds INTEGER NOT NULL DEFAULT 0 CHECK(position_seconds >= 0),
 duration_seconds INTEGER CHECK(duration_seconds >= 0),
 updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
 PRIMARY KEY(user_id,source_id,external_id)
);
CREATE INDEX IF NOT EXISTS user_watch_progress_recent ON user_watch_progress(user_id,updated_at DESC);
