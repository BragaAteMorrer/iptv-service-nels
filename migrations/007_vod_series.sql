-- Stable Xtream series identity, independent of per-episode channel row IDs.
CREATE TABLE IF NOT EXISTS vod_series (
 id BIGSERIAL PRIMARY KEY,
 source_id BIGINT NOT NULL REFERENCES sources(id) ON DELETE CASCADE,
 provider_series_id VARCHAR(128) NOT NULL,
 title TEXT NOT NULL,
 cover TEXT NOT NULL DEFAULT '',
 synopsis TEXT NOT NULL DEFAULT '',
 genre TEXT NOT NULL DEFAULT '',
 updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
 UNIQUE(source_id,provider_series_id)
);
CREATE INDEX IF NOT EXISTS vod_series_title_idx ON vod_series(source_id,lower(title));
CREATE TABLE IF NOT EXISTS vod_episodes (
 series_id BIGINT NOT NULL REFERENCES vod_series(id) ON DELETE CASCADE,
 channel_id BIGINT NOT NULL REFERENCES channels(id) ON DELETE CASCADE,
 season_number INTEGER NOT NULL CHECK(season_number BETWEEN 0 AND 999),
 episode_number INTEGER NOT NULL CHECK(episode_number BETWEEN 0 AND 9999),
 title TEXT NOT NULL DEFAULT '',
 PRIMARY KEY(series_id,channel_id),
 UNIQUE(channel_id)
);
CREATE INDEX IF NOT EXISTS vod_episodes_order_idx ON vod_episodes(series_id,season_number,episode_number);
