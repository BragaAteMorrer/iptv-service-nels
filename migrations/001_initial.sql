CREATE TABLE IF NOT EXISTS sources (
 id BIGSERIAL PRIMARY KEY,
 name VARCHAR(150) NOT NULL,
 kind VARCHAR(16) NOT NULL CHECK (kind IN ('m3u','xtream','mag')),
 endpoint TEXT,
 credentials BYTEA,
 status VARCHAR(20) NOT NULL DEFAULT 'pending',
 last_sync_at TIMESTAMPTZ,
 created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE TABLE IF NOT EXISTS channels (
 id BIGSERIAL PRIMARY KEY,
 source_id BIGINT NOT NULL REFERENCES sources(id) ON DELETE CASCADE,
 external_id VARCHAR(128) NOT NULL,
 kind VARCHAR(12) NOT NULL CHECK (kind IN ('live','movie','episode')),
 name TEXT NOT NULL,
 group_name TEXT NOT NULL DEFAULT '',
 logo TEXT NOT NULL DEFAULT '',
 epg_id TEXT NOT NULL DEFAULT '',
 stream_url TEXT NOT NULL DEFAULT '',
 active BOOLEAN NOT NULL DEFAULT TRUE,
 last_seen_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
 UNIQUE(source_id,external_id)
);
CREATE INDEX IF NOT EXISTS channels_kind_group_idx ON channels(kind,group_name);
CREATE INDEX IF NOT EXISTS channels_name_idx ON channels USING gin (to_tsvector('simple', name));
