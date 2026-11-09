CREATE TABLE IF NOT EXISTS app_users (
 id BIGSERIAL PRIMARY KEY,
 email VARCHAR(254) NOT NULL UNIQUE,
 password_hash TEXT NOT NULL,
 created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE TABLE IF NOT EXISTS app_sessions (
 id BIGSERIAL PRIMARY KEY,
 user_id BIGINT NOT NULL REFERENCES app_users(id) ON DELETE CASCADE,
 token_hash CHAR(64) NOT NULL UNIQUE,
 expires_at TIMESTAMPTZ NOT NULL,
 revoked_at TIMESTAMPTZ,
 created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS app_sessions_active ON app_sessions(token_hash,expires_at);
CREATE TABLE IF NOT EXISTS app_devices (
 id BIGSERIAL PRIMARY KEY,
 user_id BIGINT REFERENCES app_users(id) ON DELETE CASCADE,
 device_name VARCHAR(80) NOT NULL,
 device_type VARCHAR(32) NOT NULL DEFAULT 'tv',
 device_token_hash CHAR(64) UNIQUE,
 pairing_code_hash CHAR(64) UNIQUE,
 pairing_expires_at TIMESTAMPTZ,
 paired_at TIMESTAMPTZ,
 last_seen_at TIMESTAMPTZ,
 created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS app_devices_pairing ON app_devices(pairing_code_hash) WHERE pairing_code_hash IS NOT NULL;
