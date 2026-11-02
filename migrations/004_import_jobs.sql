CREATE TABLE IF NOT EXISTS import_jobs (
 id BIGSERIAL PRIMARY KEY,
 source_id BIGINT NOT NULL REFERENCES sources(id) ON DELETE CASCADE,
 method VARCHAR(12) NOT NULL CHECK(method IN ('file','remote')),
 file_path TEXT,
 status VARCHAR(16) NOT NULL DEFAULT 'queued',
 attempts SMALLINT NOT NULL DEFAULT 0,
 created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
 started_at TIMESTAMPTZ,
 finished_at TIMESTAMPTZ
);
CREATE INDEX IF NOT EXISTS import_jobs_queue ON import_jobs(id) WHERE status='queued';
