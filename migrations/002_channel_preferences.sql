CREATE TABLE IF NOT EXISTS channel_preferences (
 channel_id BIGINT PRIMARY KEY REFERENCES channels(id) ON DELETE CASCADE,
 channel_number INTEGER CHECK (channel_number BETWEEN 1 AND 99999),
 favorite BOOLEAN NOT NULL DEFAULT FALSE,
 hidden BOOLEAN NOT NULL DEFAULT FALSE,
 updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE UNIQUE INDEX IF NOT EXISTS channel_preferences_number_unique
ON channel_preferences(channel_number) WHERE channel_number IS NOT NULL;
CREATE INDEX IF NOT EXISTS channel_preferences_favorite_idx ON channel_preferences(favorite) WHERE favorite;
