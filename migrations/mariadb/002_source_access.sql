-- Apply after 001_schema.sql. No access granted by default.
CREATE TABLE IF NOT EXISTS user_source_access (
 user_id BIGINT UNSIGNED NOT NULL,
 source_id BIGINT UNSIGNED NOT NULL,
 granted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY (user_id, source_id),
 KEY user_source_access_source (source_id),
 CONSTRAINT user_source_access_user_fk FOREIGN KEY (user_id) REFERENCES app_users(id) ON DELETE CASCADE,
 CONSTRAINT user_source_access_source_fk FOREIGN KEY (source_id) REFERENCES sources(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
