-- Create system schema and tables for the Headless Sync Platform

CREATE SCHEMA IF NOT EXISTS system;
CREATE SCHEMA IF NOT EXISTS content;

-- 1. system.aggregate_versions
CREATE TABLE IF NOT EXISTS system.aggregate_versions (
    aggregate_type VARCHAR(50) NOT NULL,
    aggregate_id VARCHAR(50) NOT NULL,
    version INTEGER NOT NULL DEFAULT 1,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (aggregate_type, aggregate_id)
);

-- 2. system.events
CREATE TABLE IF NOT EXISTS system.events (
    id UUID PRIMARY KEY,
    event_type VARCHAR(100) NOT NULL,
    event_version INTEGER NOT NULL DEFAULT 1,
    aggregate_type VARCHAR(50) NOT NULL,
    aggregate_id VARCHAR(50) NOT NULL,
    aggregate_version INTEGER NOT NULL,
    source_updated_at TIMESTAMP WITH TIME ZONE NOT NULL,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    payload JSONB NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_events_aggregate ON system.events(aggregate_type, aggregate_id);

-- 3. system.queue_jobs
CREATE TABLE IF NOT EXISTS system.queue_jobs (
    job_id BIGSERIAL PRIMARY KEY,
    queue_name VARCHAR(100) NOT NULL,
    event_id UUID NOT NULL,
    payload JSONB NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'queued',
    attempts INTEGER NOT NULL DEFAULT 0,
    available_at TIMESTAMP WITH TIME ZONE NOT NULL,
    reserved_at TIMESTAMP WITH TIME ZONE,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_queue_jobs_claim ON system.queue_jobs(queue_name, status, available_at);

-- 4. system.dead_letter_jobs
CREATE TABLE IF NOT EXISTS system.dead_letter_jobs (
    job_id BIGINT PRIMARY KEY,
    queue_name VARCHAR(100) NOT NULL,
    event_id UUID NOT NULL,
    payload JSONB NOT NULL,
    failed_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    exception_message TEXT
);

-- 5. system.worker_heartbeats
CREATE TABLE IF NOT EXISTS system.worker_heartbeats (
    worker_id VARCHAR(100) PRIMARY KEY,
    status VARCHAR(50) NOT NULL,
    last_heartbeat_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    started_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    processed_count INTEGER DEFAULT 0,
    failed_count INTEGER DEFAULT 0,
    memory_bytes BIGINT DEFAULT 0
);

-- 6. system.module_versions
CREATE TABLE IF NOT EXISTS system.module_versions (
    module_name VARCHAR(100) PRIMARY KEY,
    version INTEGER NOT NULL,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- 7. system.audit_log
CREATE TABLE IF NOT EXISTS system.audit_log (
    id BIGSERIAL PRIMARY KEY,
    action VARCHAR(100) NOT NULL,
    target_type VARCHAR(50),
    target_id VARCHAR(50),
    details TEXT,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);
