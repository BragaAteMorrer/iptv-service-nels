CREATE TABLE IF NOT EXISTS epg_programmes (
 id BIGSERIAL PRIMARY KEY,
 xmltv_channel VARCHAR(255) NOT NULL,
 title TEXT NOT NULL,
 description TEXT NOT NULL DEFAULT '',
 starts_at TIMESTAMPTZ NOT NULL,
 ends_at TIMESTAMPTZ NOT NULL,
 UNIQUE(xmltv_channel,starts_at,ends_at)
);
CREATE INDEX IF NOT EXISTS epg_programmes_lookup ON epg_programmes(xmltv_channel,starts_at,ends_at);
CREATE TABLE IF NOT EXISTS epg_channel_mappings (
 channel_id BIGINT PRIMARY KEY REFERENCES channels(id) ON DELETE CASCADE,
 xmltv_channel VARCHAR(255) NOT NULL
);
