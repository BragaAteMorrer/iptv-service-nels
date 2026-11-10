-- Account-owned sources for PostgreSQL installs. Apply after migrations/008_source_access.sql.
-- Shared sources imported before this migration remain platform-managed.
CREATE TABLE IF NOT EXISTS source_owners (
 source_id BIGINT PRIMARY KEY REFERENCES sources(id) ON DELETE CASCADE,
 user_id BIGINT NOT NULL REFERENCES app_users(id) ON DELETE CASCADE,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS source_owners_user ON source_owners(user_id,source_id);
