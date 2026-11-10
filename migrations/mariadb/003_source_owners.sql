-- Account-owned IPTV sources. Run AFTER 001_schema.sql and 002_source_access.sql.
-- Existing globally managed sources have no owner and remain administrator-managed.
CREATE TABLE IF NOT EXISTS source_owners (
 source_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
 user_id BIGINT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY source_owners_user (user_id, source_id),
 CONSTRAINT source_owners_source_fk FOREIGN KEY (source_id) REFERENCES sources(id) ON DELETE CASCADE,
 CONSTRAINT source_owners_user_fk FOREIGN KEY (user_id) REFERENCES app_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
