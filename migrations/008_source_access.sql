-- PostgreSQL equivalent for local Docker, no default access.
CREATE TABLE IF NOT EXISTS user_source_access (
 user_id BIGINT NOT NULL REFERENCES app_users(id) ON DELETE CASCADE,
 source_id BIGINT NOT NULL REFERENCES sources(id) ON DELETE CASCADE,
 granted_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
 PRIMARY KEY (user_id, source_id)
);
CREATE INDEX IF NOT EXISTS user_source_access_source ON user_source_access(source_id);
