-- Nels TV staging schema for MariaDB 11.4+ (not PostgreSQL).
-- Execute only against a NEW, EMPTY MariaDB database.
-- Application queries are still PostgreSQL-specific: do not expose production APIs yet.
SET NAMES utf8mb4;
CREATE TABLE IF NOT EXISTS sources (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(150) NOT NULL,
 kind ENUM('m3u','xtream','mag') NOT NULL,
 endpoint TEXT NOT NULL,
 credentials TEXT NULL,
 status VARCHAR(20) NOT NULL DEFAULT 'pending',
 last_sync_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS channels (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 source_id BIGINT UNSIGNED NOT NULL,
 external_id VARCHAR(128) NOT NULL,
 kind ENUM('live','movie','episode') NOT NULL,
 name TEXT NOT NULL,
 group_name TEXT NOT NULL,
 logo TEXT NOT NULL,
 epg_id TEXT NOT NULL,
 stream_url TEXT NOT NULL,
 active TINYINT(1) NOT NULL DEFAULT 1,
 last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY channels_source_external (source_id, external_id),
 KEY channels_kind_group_idx (kind, group_name(120)),
 FULLTEXT KEY channels_name_fulltext (name),
 CONSTRAINT channels_source_fk FOREIGN KEY (source_id) REFERENCES sources(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS channel_preferences (
 channel_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
 channel_number INT NULL,
 favorite TINYINT(1) NOT NULL DEFAULT 0,
 hidden TINYINT(1) NOT NULL DEFAULT 0,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY channel_preferences_number_unique (channel_number),
 KEY channel_preferences_favorite_idx (favorite),
 CONSTRAINT channel_preferences_number_ck CHECK (channel_number BETWEEN 1 AND 99999 OR channel_number IS NULL),
 CONSTRAINT channel_preferences_channel_fk FOREIGN KEY (channel_id) REFERENCES channels(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS epg_programmes (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 xmltv_channel VARCHAR(255) NOT NULL,
 title TEXT NOT NULL,
 description TEXT NOT NULL,
 starts_at DATETIME NOT NULL,
 ends_at DATETIME NOT NULL,
 UNIQUE KEY epg_programmes_unique (xmltv_channel, starts_at, ends_at),
 KEY epg_programmes_lookup (xmltv_channel, starts_at, ends_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS epg_channel_mappings (
 channel_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
 xmltv_channel VARCHAR(255) NOT NULL,
 CONSTRAINT epg_channel_mappings_fk FOREIGN KEY (channel_id) REFERENCES channels(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS import_jobs (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 source_id BIGINT UNSIGNED NOT NULL,
 method ENUM('file','remote') NOT NULL,
 file_path TEXT NULL,
 status VARCHAR(16) NOT NULL DEFAULT 'queued',
 attempts SMALLINT NOT NULL DEFAULT 0,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 started_at DATETIME NULL,
 finished_at DATETIME NULL,
 KEY import_jobs_queue (status,id),
 CONSTRAINT import_jobs_source_fk FOREIGN KEY (source_id) REFERENCES sources(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS app_users (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 email VARCHAR(254) NOT NULL,
 password_hash TEXT NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY app_users_email_unique (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS app_sessions (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 user_id BIGINT UNSIGNED NOT NULL,
 token_hash CHAR(64) NOT NULL,
 expires_at DATETIME NOT NULL,
 revoked_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY app_sessions_token_unique (token_hash),
 KEY app_sessions_active (token_hash, expires_at),
 CONSTRAINT app_sessions_user_fk FOREIGN KEY (user_id) REFERENCES app_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS app_devices (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 user_id BIGINT UNSIGNED NULL,
 device_name VARCHAR(80) NOT NULL,
 device_type VARCHAR(32) NOT NULL DEFAULT 'tv',
 device_token_hash CHAR(64) NULL,
 pairing_code_hash CHAR(64) NULL,
 pairing_expires_at DATETIME NULL,
 paired_at DATETIME NULL,
 last_seen_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY app_devices_token_unique (device_token_hash),
 UNIQUE KEY app_devices_pairing_unique (pairing_code_hash),
 CONSTRAINT app_devices_user_fk FOREIGN KEY (user_id) REFERENCES app_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_channel_preferences (
 user_id BIGINT UNSIGNED NOT NULL,
 source_id BIGINT UNSIGNED NOT NULL,
 external_id VARCHAR(128) NOT NULL,
 favorite TINYINT(1) NOT NULL DEFAULT 0,
 hidden TINYINT(1) NOT NULL DEFAULT 0,
 channel_number INT NULL,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY (user_id, source_id, external_id),
 KEY user_channel_preferences_source (user_id,source_id),
 CONSTRAINT user_channel_number_ck CHECK (channel_number BETWEEN 1 AND 99999 OR channel_number IS NULL),
 CONSTRAINT user_channel_user_fk FOREIGN KEY (user_id) REFERENCES app_users(id) ON DELETE CASCADE,
 CONSTRAINT user_channel_source_fk FOREIGN KEY (source_id) REFERENCES sources(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_watch_progress (
 user_id BIGINT UNSIGNED NOT NULL,
 source_id BIGINT UNSIGNED NOT NULL,
 external_id VARCHAR(128) NOT NULL,
 position_seconds INT NOT NULL DEFAULT 0,
 duration_seconds INT NULL,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY (user_id, source_id, external_id),
 KEY user_watch_progress_recent (user_id, updated_at),
 CONSTRAINT user_watch_position_ck CHECK (position_seconds >= 0),
 CONSTRAINT user_watch_duration_ck CHECK (duration_seconds >= 0 OR duration_seconds IS NULL),
 CONSTRAINT user_watch_user_fk FOREIGN KEY (user_id) REFERENCES app_users(id) ON DELETE CASCADE,
 CONSTRAINT user_watch_source_fk FOREIGN KEY (source_id) REFERENCES sources(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vod_series (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 source_id BIGINT UNSIGNED NOT NULL,
 provider_series_id VARCHAR(128) NOT NULL,
 title TEXT NOT NULL,
 cover TEXT NOT NULL,
 synopsis TEXT NOT NULL,
 genre TEXT NOT NULL,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY vod_series_provider_unique (source_id,provider_series_id),
 KEY vod_series_title_idx (source_id,title(120)),
 CONSTRAINT vod_series_source_fk FOREIGN KEY (source_id) REFERENCES sources(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vod_episodes (
 series_id BIGINT UNSIGNED NOT NULL,
 channel_id BIGINT UNSIGNED NOT NULL,
 season_number INT NOT NULL,
 episode_number INT NOT NULL,
 title TEXT NOT NULL,
 PRIMARY KEY (series_id,channel_id),
 UNIQUE KEY vod_episodes_channel_unique (channel_id),
 KEY vod_episodes_order_idx (series_id,season_number,episode_number),
 CONSTRAINT vod_episode_season_ck CHECK (season_number BETWEEN 0 AND 999),
 CONSTRAINT vod_episode_number_ck CHECK (episode_number BETWEEN 0 AND 9999),
 CONSTRAINT vod_episodes_series_fk FOREIGN KEY (series_id) REFERENCES vod_series(id) ON DELETE CASCADE,
 CONSTRAINT vod_episodes_channel_fk FOREIGN KEY (channel_id) REFERENCES channels(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
