<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * 建立首个稳定版本前的 Velin Music SQLite 开发基线（NFR-REL-006A、DEV-MIG-003A）。
 *
 * 为什么存在：项目在首发前已经形成大量仅用于开发演进的迁移。本基线由空库完整重放旧链后机械生成，
 * 固定当前终态的表、索引、触发器和系统初始数据，使新部署只执行一个版本，同时让已经运行旧链的开发
 * 数据库保留全部用户、媒体、授权、任务和配置事实。
 *
 * 前置条件与不变量：仅支持 SQLite；Phinx 必须在独占迁移事务中调用。空库除 `phinxlog` 外不得存在任何
 * 对象；非空库必须与基线的规范化 schema SHA-256 完全一致。空库只写固定内置主题、角色、能力、默认
 * 音乐库、存储策略、系统设置和九个元数据源，不包含任何开发实例数据或秘密。已存在终态只删除旧
 * `phinxlog` 行，绝不执行 DDL 或改写业务表。
 *
 * 失败与并发：未知对象、缺少约束、外键错误、SQL 执行失败或摘要不一致均抛出异常，由 Phinx 回滚整个
 * 事务，不允许留下半个 schema 或部分迁移记录。迁移期间不得并发启动 Webman Worker。重复运行由
 * Phinx 的唯一版本记录幂等跳过。
 *
 * 回滚与 MySQL：基线不可向下回滚；误操作只能恢复执行前的已验证 SQLite 备份。未来 MySQL 支持必须
 * 作为独立数据迁移重新表达类型、排序规则、CHECK、触发器和索引，不能直接执行本文件的 SQLite SQL。
 */
final class CreateVelinMusicBaseline extends AbstractMigration
{
    private const VERSION = 20260810000300;
    private const SCHEMA_FINGERPRINT = 'a3baa30b12c1d878497db7e63c2b3b92854e3c4ef3ce096864d24ed771bbfcc1';

    private const BASELINE_SQL = <<<'SQL'
PRAGMA defer_foreign_keys = ON;

CREATE TABLE app_authorization_codes (
    id TEXT PRIMARY KEY NOT NULL,
    secret_digest TEXT NOT NULL UNIQUE CHECK (length(secret_digest) = 64),
    user_id TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    client_id TEXT NOT NULL CHECK (length(client_id) BETWEEN 1 AND 80),
    code_challenge TEXT NOT NULL CHECK (length(code_challenge) = 43),
    device_name TEXT NOT NULL CHECK (length(device_name) BETWEEN 1 AND 120),
    platform TEXT NOT NULL CHECK (platform IN ('android', 'ios', 'windows', 'macos', 'linux')),
    app_version TEXT NOT NULL CHECK (length(app_version) BETWEEN 1 AND 40),
    push_token_digest TEXT NULL CHECK (push_token_digest IS NULL OR length(push_token_digest) = 64),
    scopes_json TEXT NOT NULL CHECK (length(scopes_json) BETWEEN 2 AND 2048),
    expires_at TEXT NOT NULL,
    consumed_at TEXT NULL,
    created_at TEXT NOT NULL
);

CREATE TABLE app_token_families (
    id TEXT PRIMARY KEY NOT NULL,
    user_id TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    client_id TEXT NOT NULL CHECK (length(client_id) BETWEEN 1 AND 80),
    device_name TEXT NOT NULL CHECK (length(device_name) BETWEEN 1 AND 120),
    platform TEXT NOT NULL CHECK (platform IN ('android', 'ios', 'windows', 'macos', 'linux')),
    app_version TEXT NOT NULL CHECK (length(app_version) BETWEEN 1 AND 40),
    push_token_digest TEXT NULL CHECK (push_token_digest IS NULL OR length(push_token_digest) = 64),
    scopes_json TEXT NOT NULL CHECK (length(scopes_json) BETWEEN 2 AND 2048),
    last_used_at TEXT NOT NULL,
    expires_at TEXT NOT NULL,
    revoked_at TEXT NULL,
    revoked_reason TEXT NULL CHECK (revoked_reason IS NULL OR length(revoked_reason) BETWEEN 1 AND 64),
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE app_tokens (
    id TEXT PRIMARY KEY NOT NULL,
    family_id TEXT NOT NULL REFERENCES app_token_families(id) ON DELETE CASCADE,
    user_id TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    token_type TEXT NOT NULL CHECK (token_type IN ('access', 'refresh')),
    secret_digest TEXT NOT NULL UNIQUE CHECK (length(secret_digest) = 64),
    scopes_json TEXT NOT NULL CHECK (length(scopes_json) BETWEEN 2 AND 2048),
    expires_at TEXT NOT NULL,
    used_at TEXT NULL,
    revoked_at TEXT NULL,
    revoked_reason TEXT NULL CHECK (revoked_reason IS NULL OR length(revoked_reason) BETWEEN 1 AND 64),
    replaced_by_id TEXT NULL,
    created_at TEXT NOT NULL
);

CREATE TABLE "artwork_provider_candidates" (
    id TEXT PRIMARY KEY NOT NULL,
    search_job_id TEXT NOT NULL REFERENCES "artwork_provider_search_jobs"(id) ON DELETE CASCADE,
    remote_asset_id TEXT NOT NULL,
    provider_key TEXT NOT NULL,
    artwork_kind TEXT NOT NULL,
    mime_type TEXT NOT NULL CHECK (mime_type IN ('image/jpeg', 'image/png')),
    width INTEGER NOT NULL CHECK (width BETWEEN 1 AND 8192),
    height INTEGER NOT NULL CHECK (height BETWEEN 1 AND 8192),
    size_bytes INTEGER NOT NULL CHECK (size_bytes BETWEEN 32 AND 20971520),
    resource_sha256 TEXT NOT NULL CHECK (length(resource_sha256) = 64),
    attribution_required INTEGER NOT NULL CHECK (attribution_required IN (0, 1)),
    attribution_text TEXT NOT NULL,
    attribution_url TEXT NULL,
    created_at TEXT NOT NULL,
    UNIQUE (search_job_id, remote_asset_id)
);

CREATE TABLE "artwork_provider_import_jobs" (
    id TEXT PRIMARY KEY NOT NULL,
    search_job_id TEXT NOT NULL REFERENCES "artwork_provider_search_jobs"(id) ON DELETE RESTRICT,
    candidate_id TEXT NOT NULL REFERENCES "artwork_provider_candidates"(id) ON DELETE RESTRICT,
    song_id TEXT NULL REFERENCES media_songs(id) ON DELETE CASCADE,
    album_id TEXT NULL REFERENCES media_albums(id) ON DELETE CASCADE,
    artist_id TEXT NULL REFERENCES media_artists(id) ON DELETE CASCADE,
    library_id TEXT NOT NULL REFERENCES music_libraries(id) ON DELETE RESTRICT,
    requested_by TEXT NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
    request_id TEXT NOT NULL,
    idempotency_key_sha256 TEXT NOT NULL CHECK (length(idempotency_key_sha256) = 64),
    evidence_sha256 TEXT NOT NULL CHECK (length(evidence_sha256) = 64),
    status TEXT NOT NULL CHECK (status IN ('queued', 'running', 'succeeded', 'failed', 'cancelled')),
    phase TEXT NOT NULL CHECK (phase IN ('queued', 'validating', 'reading_resource', 'normalizing', 'persisting', 'completed', 'failed', 'cancelled')),
    imported_candidate_id TEXT NULL REFERENCES "media_manual_artwork_candidates"(id) ON DELETE SET NULL,
    failure_count INTEGER NOT NULL DEFAULT 0 CHECK (failure_count BETWEEN 0 AND 10),
    next_attempt_at TEXT NOT NULL,
    worker_id TEXT NULL,
    heartbeat_at TEXT NULL,
    error_code TEXT NULL,
    version INTEGER NOT NULL DEFAULT 1 CHECK (version > 0),
    started_at TEXT NULL,
    finished_at TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL, auto_select INTEGER NOT NULL DEFAULT 0 CHECK (auto_select IN (0,1)), scrape_target_id TEXT NULL REFERENCES metadata_sync_scrape_targets(id) ON DELETE SET NULL,
    UNIQUE (requested_by, idempotency_key_sha256),
    CHECK ((song_id IS NOT NULL) + (album_id IS NOT NULL) + (artist_id IS NOT NULL) = 1)
);

CREATE TABLE "artwork_provider_search_jobs" (
    id TEXT PRIMARY KEY NOT NULL,
    song_id TEXT NULL REFERENCES media_songs(id) ON DELETE CASCADE,
    album_id TEXT NULL REFERENCES media_albums(id) ON DELETE CASCADE,
    artist_id TEXT NULL REFERENCES media_artists(id) ON DELETE CASCADE,
    library_id TEXT NOT NULL REFERENCES music_libraries(id) ON DELETE RESTRICT,
    evidence_song_id TEXT NOT NULL REFERENCES media_songs(id) ON DELETE CASCADE,
    requested_by TEXT NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
    request_id TEXT NOT NULL,
    idempotency_key_sha256 TEXT NOT NULL CHECK (length(idempotency_key_sha256) = 64),
    evidence_sha256 TEXT NOT NULL CHECK (length(evidence_sha256) = 64),
    locale TEXT NOT NULL,
    region TEXT NOT NULL CHECK (length(region) = 2),
    status TEXT NOT NULL CHECK (status IN ('queued', 'running', 'succeeded', 'failed', 'cancelled')),
    phase TEXT NOT NULL CHECK (phase IN ('queued', 'submitting', 'polling', 'reading_result', 'completed', 'failed', 'cancelled')),
    remote_job_id TEXT NULL,
    remote_result_id TEXT NULL,
    candidate_count INTEGER NOT NULL DEFAULT 0 CHECK (candidate_count BETWEEN 0 AND 100),
    failure_count INTEGER NOT NULL DEFAULT 0 CHECK (failure_count BETWEEN 0 AND 10),
    next_attempt_at TEXT NOT NULL,
    worker_id TEXT NULL,
    heartbeat_at TEXT NULL,
    error_code TEXT NULL,
    version INTEGER NOT NULL DEFAULT 1 CHECK (version > 0),
    started_at TEXT NULL,
    finished_at TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL, auto_import INTEGER NOT NULL DEFAULT 0 CHECK (auto_import IN (0,1)), scrape_target_id TEXT NULL REFERENCES metadata_sync_scrape_targets(id) ON DELETE SET NULL,
    UNIQUE (requested_by, idempotency_key_sha256),
    CHECK ((song_id IS NOT NULL) + (album_id IS NOT NULL) + (artist_id IS NOT NULL) = 1)
);

CREATE TABLE audio_tag_writeback_batch_plans (
    id TEXT PRIMARY KEY NOT NULL,
    requested_by TEXT NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
    request_id TEXT NOT NULL,
    plan_hash TEXT NOT NULL CHECK (length(plan_hash) = 64),
    idempotency_key_sha256 TEXT NULL CHECK (idempotency_key_sha256 IS NULL OR length(idempotency_key_sha256) = 64),
    target_count INTEGER NOT NULL CHECK (target_count BETWEEN 1 AND 50),
    processed_count INTEGER NOT NULL DEFAULT 0 CHECK (processed_count BETWEEN 0 AND target_count),
    succeeded_count INTEGER NOT NULL DEFAULT 0 CHECK (succeeded_count BETWEEN 0 AND target_count),
    failed_count INTEGER NOT NULL DEFAULT 0 CHECK (failed_count BETWEEN 0 AND target_count),
    status TEXT NOT NULL CHECK (status IN ('draft','queued','running','succeeded','partial','failed','expired')),
    version INTEGER NOT NULL DEFAULT 1 CHECK (version > 0),
    expires_at TEXT NOT NULL,
    confirmed_at TEXT NULL,
    started_at TEXT NULL,
    finished_at TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE audio_tag_writeback_batch_targets (
    batch_plan_id TEXT NOT NULL REFERENCES audio_tag_writeback_batch_plans(id) ON DELETE RESTRICT,
    position INTEGER NOT NULL CHECK (position BETWEEN 0 AND 49),
    writeback_plan_id TEXT NOT NULL UNIQUE REFERENCES audio_tag_writeback_plans(id) ON DELETE RESTRICT,
    song_id TEXT NOT NULL REFERENCES media_songs(id) ON DELETE RESTRICT,
    library_id TEXT NOT NULL REFERENCES music_libraries(id) ON DELETE RESTRICT,
    status TEXT NOT NULL CHECK (status IN ('planned','queued','running','succeeded','failed','stale')),
    error_code TEXT NULL,
    updated_at TEXT NOT NULL,
    PRIMARY KEY (batch_plan_id,position),
    UNIQUE (batch_plan_id,song_id)
);

CREATE TABLE audio_tag_writeback_jobs (
    id TEXT PRIMARY KEY NOT NULL,
    plan_id TEXT NOT NULL UNIQUE REFERENCES audio_tag_writeback_plans(id) ON DELETE RESTRICT,
    library_id TEXT NOT NULL REFERENCES music_libraries(id) ON DELETE RESTRICT,
    song_id TEXT NOT NULL REFERENCES media_songs(id) ON DELETE RESTRICT,
    requested_by TEXT NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
    request_id TEXT NOT NULL,
    idempotency_key_sha256 TEXT NOT NULL CHECK (length(idempotency_key_sha256) = 64),
    status TEXT NOT NULL CHECK (status IN ('queued','running','succeeded','failed','cancelled')),
    phase TEXT NOT NULL CHECK (phase IN ('queued','validating','writing','verifying','persisting','indexing','completed','failed','cancelled')),
    attempt INTEGER NOT NULL DEFAULT 0 CHECK (attempt BETWEEN 0 AND 5),
    worker_id TEXT NULL,
    heartbeat_at TEXT NULL,
    started_at TEXT NULL,
    finished_at TEXT NULL,
    error_code TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE(requested_by,idempotency_key_sha256)
);

CREATE TABLE audio_tag_writeback_operation_logs (
    id TEXT PRIMARY KEY NOT NULL,
    job_id TEXT NOT NULL REFERENCES audio_tag_writeback_jobs(id) ON DELETE RESTRICT,
    sequence INTEGER NOT NULL CHECK (sequence > 0),
    operation TEXT NOT NULL CHECK (operation IN ('backup','rewrite','verify','publish','compensate')),
    status TEXT NOT NULL CHECK (status IN ('started','succeeded','failed','compensated')),
    detached_hardlink INTEGER NOT NULL DEFAULT 0 CHECK (detached_hardlink IN (0,1)),
    output_sha256 TEXT NULL CHECK (output_sha256 IS NULL OR length(output_sha256) = 64),
    output_size_bytes INTEGER NULL CHECK (output_size_bytes IS NULL OR output_size_bytes >= 0),
    error_code TEXT NULL,
    started_at TEXT NOT NULL,
    finished_at TEXT NULL,
    UNIQUE(job_id,sequence)
);

CREATE TABLE audio_tag_writeback_plans (
    id TEXT PRIMARY KEY NOT NULL,
    song_id TEXT NOT NULL REFERENCES media_songs(id) ON DELETE RESTRICT,
    library_id TEXT NOT NULL REFERENCES music_libraries(id) ON DELETE RESTRICT,
    inventory_file_id TEXT NOT NULL REFERENCES library_file_inventory(id) ON DELETE RESTRICT,
    created_by TEXT NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
    status TEXT NOT NULL CHECK (status IN ('planned','queued','running','succeeded','failed','stale','expired')),
    expected_song_updated_at TEXT NOT NULL,
    expected_library_version INTEGER NOT NULL CHECK (expected_library_version > 0),
    field_versions_json TEXT NOT NULL CHECK (length(field_versions_json) BETWEEN 2 AND 65536),
    metadata_snapshot_json TEXT NOT NULL CHECK (length(metadata_snapshot_json) BETWEEN 2 AND 262144),
    metadata_sha256 TEXT NOT NULL CHECK (length(metadata_sha256) = 64),
    source_relative_path TEXT NOT NULL CHECK (length(source_relative_path) BETWEEN 1 AND 4096),
    source_device INTEGER NOT NULL CHECK (source_device >= 0),
    source_inode INTEGER NOT NULL CHECK (source_inode >= 0),
    source_file_size INTEGER NOT NULL CHECK (source_file_size >= 0),
    source_modified_at INTEGER NOT NULL CHECK (source_modified_at >= 0),
    source_link_count INTEGER NOT NULL CHECK (source_link_count > 0),
    plan_hash TEXT NOT NULL CHECK (length(plan_hash) = 64),
    version INTEGER NOT NULL DEFAULT 1 CHECK (version > 0),
    expires_at TEXT NOT NULL,
    confirmed_at TEXT NULL,
    finished_at TEXT NULL,
    error_code TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE audit_logs (
    id TEXT PRIMARY KEY NOT NULL,
    actor_user_id TEXT NULL REFERENCES users(id) ON DELETE SET NULL,
    action TEXT NOT NULL,
    object_type TEXT NOT NULL,
    object_id TEXT NULL,
    result TEXT NOT NULL CHECK (result IN ('success', 'failure', 'denied')),
    request_id TEXT NOT NULL,
    metadata_json TEXT NOT NULL,
    created_at TEXT NOT NULL
);

CREATE TABLE auth_login_attempts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    identity_hash TEXT NOT NULL,
    success INTEGER NOT NULL CHECK (success IN (0, 1)),
    attempted_at TEXT NOT NULL
);

CREATE TABLE auth_sessions (
    id TEXT PRIMARY KEY NOT NULL,
    user_id TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    session_hash TEXT NOT NULL UNIQUE,
    user_agent_hash TEXT NOT NULL,
    created_at TEXT NOT NULL,
    last_seen_at TEXT NOT NULL,
    expires_at TEXT NOT NULL,
    revoked_at TEXT NULL,
    revoked_reason TEXT NULL
);

CREATE TABLE capabilities (
    capability_key TEXT PRIMARY KEY NOT NULL,
    description TEXT NOT NULL
);

CREATE TABLE dlna_playback_tickets (
    id TEXT PRIMARY KEY NOT NULL,
    token_digest TEXT NOT NULL UNIQUE CHECK (length(token_digest) = 64),
    user_id TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    song_id TEXT NOT NULL REFERENCES media_songs(id) ON DELETE CASCADE,
    device_digest TEXT NOT NULL CHECK (length(device_digest) = 64),
    output_format TEXT NOT NULL CHECK (output_format IN ('raw', 'mp3', 'aac', 'opus')),
    expires_at TEXT NOT NULL,
    revoked_at TEXT NULL,
    last_used_at TEXT NULL,
    created_at TEXT NOT NULL
);

CREATE TABLE duplicate_media_decisions (
    group_id TEXT PRIMARY KEY NOT NULL CHECK (length(group_id) = 24),
    evidence_kind TEXT NOT NULL CHECK (evidence_kind IN ('inode', 'byte_hash', 'fingerprint', 'metadata')),
    action TEXT NOT NULL CHECK (action = 'keep_all'),
    keep_song_id TEXT NOT NULL REFERENCES media_songs(id) ON DELETE CASCADE,
    member_count INTEGER NOT NULL CHECK (member_count >= 2),
    decided_by TEXT NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE external_playback_tickets (
    id TEXT PRIMARY KEY NOT NULL,
    token_digest TEXT NOT NULL UNIQUE CHECK (length(token_digest) = 64),
    user_id TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    song_id TEXT NOT NULL REFERENCES media_songs(id) ON DELETE CASCADE,
    protocol TEXT NOT NULL CHECK (protocol IN ('dlna', 'google_cast')),
    output_format TEXT NOT NULL CHECK (output_format IN ('raw', 'mp3', 'aac', 'opus')),
    mime_type TEXT NOT NULL CHECK (length(mime_type) BETWEEN 1 AND 80),
    supports_range INTEGER NOT NULL CHECK (supports_range IN (0, 1)),
    starts_before TEXT NOT NULL,
    started_at TEXT NULL,
    expires_at TEXT NULL,
    last_used_at TEXT NULL,
    revoked_at TEXT NULL,
    created_at TEXT NOT NULL
);

CREATE TABLE internet_radio_stations (
    id TEXT PRIMARY KEY NOT NULL,
    name TEXT NOT NULL,
    stream_url TEXT NOT NULL,
    homepage_url TEXT NULL,
    artwork_url TEXT NULL,
    enabled INTEGER NOT NULL DEFAULT 1 CHECK (enabled IN (0, 1)),
    version INTEGER NOT NULL DEFAULT 1 CHECK (version > 0),
    created_by TEXT NULL REFERENCES users(id) ON DELETE SET NULL,
    updated_by TEXT NULL REFERENCES users(id) ON DELETE SET NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE library_file_inventory (
    id TEXT PRIMARY KEY NOT NULL,
    library_id TEXT NOT NULL REFERENCES music_libraries(id) ON DELETE CASCADE,
    relative_path TEXT NOT NULL,
    resolved_path TEXT NOT NULL,
    extension TEXT NOT NULL,
    device_id INTEGER NOT NULL,
    inode INTEGER NOT NULL,
    file_size INTEGER NOT NULL CHECK (file_size >= 0),
    modified_at INTEGER NOT NULL CHECK (modified_at >= 0),
    status TEXT NOT NULL DEFAULT 'available' CHECK (status IN ('available', 'missing')),
    first_seen_scan_job_id TEXT NOT NULL REFERENCES library_scan_jobs(id) ON DELETE RESTRICT,
    last_seen_scan_job_id TEXT NOT NULL REFERENCES library_scan_jobs(id) ON DELETE RESTRICT,
    missing_since TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL, metadata_status TEXT NOT NULL DEFAULT 'pending' CHECK (metadata_status IN ('pending', 'ready', 'failed')), metadata_signature TEXT NULL, last_metadata_scan_job_id TEXT NULL REFERENCES library_scan_jobs(id) ON DELETE SET NULL, metadata_error_code TEXT NULL, metadata_error_message TEXT NULL, byte_hash_status TEXT NOT NULL DEFAULT 'pending' CHECK (byte_hash_status IN ('pending', 'ready', 'failed')), byte_sha256 TEXT NULL CHECK (byte_sha256 IS NULL OR length(byte_sha256) = 64), acoustic_fingerprint_status TEXT NOT NULL DEFAULT 'pending' CHECK (acoustic_fingerprint_status IN ('pending', 'ready', 'failed')), acoustic_fingerprint_sha256 TEXT NULL CHECK (acoustic_fingerprint_sha256 IS NULL OR length(acoustic_fingerprint_sha256) = 64), acoustic_duration_seconds INTEGER NULL CHECK (acoustic_duration_seconds IS NULL OR acoustic_duration_seconds >= 0), duplicate_evidence_signature TEXT NULL, duplicate_evidence_error_code TEXT NULL, remote_etag TEXT NULL,
    UNIQUE (library_id, relative_path)
);

CREATE TABLE library_m3u_sources (
    id TEXT PRIMARY KEY NOT NULL,
    library_id TEXT NOT NULL REFERENCES music_libraries(id) ON DELETE CASCADE,
    relative_path TEXT NOT NULL,
    resolved_path TEXT NOT NULL,
    display_name TEXT NOT NULL CHECK (length(display_name) BETWEEN 1 AND 255),
    extension TEXT NOT NULL CHECK (extension IN ('m3u', 'm3u8')),
    device_id INTEGER NOT NULL CHECK (device_id >= 0),
    inode INTEGER NOT NULL CHECK (inode >= 0),
    file_size INTEGER NOT NULL CHECK (file_size >= 0),
    modified_at INTEGER NOT NULL CHECK (modified_at >= 0),
    status TEXT NOT NULL DEFAULT 'available' CHECK (status IN ('available', 'missing')),
    first_seen_scan_job_id TEXT NOT NULL REFERENCES library_scan_jobs(id) ON DELETE RESTRICT,
    last_seen_scan_job_id TEXT NOT NULL REFERENCES library_scan_jobs(id) ON DELETE RESTRICT,
    missing_since TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE (library_id, relative_path)
);

CREATE TABLE library_scan_file_results (
    scan_job_id TEXT NOT NULL REFERENCES library_scan_jobs(id) ON DELETE CASCADE,
    inventory_file_id TEXT NOT NULL REFERENCES library_file_inventory(id) ON DELETE CASCADE,
    file_name TEXT NOT NULL,
    result_kind TEXT NOT NULL CHECK (result_kind IN ('unchanged', 'indexed', 'failed')),
    metadata_parsed INTEGER NOT NULL CHECK (metadata_parsed IN (0, 1)),
    metadata_updated INTEGER NOT NULL CHECK (metadata_updated IN (0, 1)),
    song_id TEXT NULL REFERENCES media_songs(id) ON DELETE SET NULL,
    title TEXT NULL,
    artists_json TEXT NOT NULL,
    album_title TEXT NULL,
    duration_ms INTEGER NULL CHECK (duration_ms IS NULL OR duration_ms >= 0),
    codec_name TEXT NULL,
    sample_rate INTEGER NULL CHECK (sample_rate IS NULL OR sample_rate >= 0),
    bitrate INTEGER NULL CHECK (bitrate IS NULL OR bitrate >= 0),
    lyrics_count INTEGER NOT NULL DEFAULT 0 CHECK (lyrics_count >= 0),
    lyric_languages_json TEXT NOT NULL,
    lyric_kinds_json TEXT NOT NULL,
    embedded_lyrics_found INTEGER NOT NULL DEFAULT 0 CHECK (embedded_lyrics_found IN (0, 1)),
    lyrics_export_status TEXT NOT NULL CHECK (
        lyrics_export_status IN ('not_found', 'created', 'already_exists', 'disabled', 'invalid', 'not_writable', 'failed')
    ),
    error_code TEXT NULL,
    error_message TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL, artwork_status TEXT NOT NULL DEFAULT 'not_found' CHECK (artwork_status IN ('not_found', 'indexed', 'unchanged', 'invalid')),
    PRIMARY KEY (scan_job_id, inventory_file_id)
);

CREATE TABLE library_scan_jobs (
    id TEXT PRIMARY KEY NOT NULL,
    library_id TEXT NOT NULL REFERENCES music_libraries(id) ON DELETE CASCADE,
    requested_by TEXT NULL REFERENCES users(id) ON DELETE SET NULL,
    scan_type TEXT NOT NULL CHECK (scan_type IN ('incremental', 'full')),
    status TEXT NOT NULL CHECK (
        status IN ('queued', 'running', 'cancel_requested', 'cancelled', 'succeeded', 'failed')
    ),
    phase TEXT NOT NULL CHECK (
        phase IN ('queued', 'discovering', 'reconciling', 'completed', 'cancelled', 'failed')
    ),
    processed_entries INTEGER NOT NULL DEFAULT 0 CHECK (processed_entries >= 0),
    discovered_files INTEGER NOT NULL DEFAULT 0 CHECK (discovered_files >= 0),
    added_files INTEGER NOT NULL DEFAULT 0 CHECK (added_files >= 0),
    missing_files INTEGER NOT NULL DEFAULT 0 CHECK (missing_files >= 0),
    ignored_entries INTEGER NOT NULL DEFAULT 0 CHECK (ignored_entries >= 0),
    failed_entries INTEGER NOT NULL DEFAULT 0 CHECK (failed_entries >= 0),
    attempt INTEGER NOT NULL DEFAULT 0 CHECK (attempt >= 0),
    worker_id TEXT NULL,
    heartbeat_at TEXT NULL,
    cancel_requested_at TEXT NULL,
    started_at TEXT NULL,
    finished_at TEXT NULL,
    error_code TEXT NULL,
    error_message TEXT NULL,
    request_id TEXT NOT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
, metadata_parsed_files INTEGER NOT NULL DEFAULT 0 CHECK (metadata_parsed_files >= 0), metadata_failed_files INTEGER NOT NULL DEFAULT 0 CHECK (metadata_failed_files >= 0), updated_files INTEGER NOT NULL DEFAULT 0 CHECK (updated_files >= 0));

CREATE TABLE library_user_grants (
    library_id TEXT NOT NULL REFERENCES music_libraries(id) ON DELETE CASCADE,
    user_id TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    access_level TEXT NOT NULL CHECK (access_level IN ('read', 'manage')),
    granted_by TEXT NULL REFERENCES users(id) ON DELETE SET NULL,
    granted_at TEXT NOT NULL,
    PRIMARY KEY (library_id, user_id)
);

CREATE TABLE lyrics_audio_tag_writeback_batch_plans (
    id TEXT PRIMARY KEY NOT NULL,
    requested_by TEXT NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
    request_id TEXT NOT NULL,
    plan_hash TEXT NOT NULL CHECK (length(plan_hash) = 64),
    idempotency_key_sha256 TEXT NULL CHECK (idempotency_key_sha256 IS NULL OR length(idempotency_key_sha256) = 64),
    target_count INTEGER NOT NULL CHECK (target_count BETWEEN 1 AND 50),
    processed_count INTEGER NOT NULL DEFAULT 0 CHECK (processed_count BETWEEN 0 AND target_count),
    succeeded_count INTEGER NOT NULL DEFAULT 0 CHECK (succeeded_count BETWEEN 0 AND target_count),
    failed_count INTEGER NOT NULL DEFAULT 0 CHECK (failed_count BETWEEN 0 AND target_count),
    status TEXT NOT NULL CHECK (status IN ('draft','queued','running','succeeded','partial','failed','expired')),
    version INTEGER NOT NULL DEFAULT 1 CHECK (version > 0),
    expires_at TEXT NOT NULL,
    confirmed_at TEXT NULL,
    started_at TEXT NULL,
    finished_at TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE lyrics_audio_tag_writeback_batch_targets (
    batch_plan_id TEXT NOT NULL REFERENCES lyrics_audio_tag_writeback_batch_plans(id) ON DELETE RESTRICT,
    position INTEGER NOT NULL CHECK (position BETWEEN 0 AND 49),
    writeback_plan_id TEXT NOT NULL UNIQUE REFERENCES lyrics_audio_tag_writeback_plans(id) ON DELETE RESTRICT,
    song_id TEXT NOT NULL REFERENCES media_songs(id) ON DELETE RESTRICT,
    lyric_id TEXT NOT NULL REFERENCES media_lyrics(id) ON DELETE RESTRICT,
    library_id TEXT NOT NULL REFERENCES music_libraries(id) ON DELETE RESTRICT,
    status TEXT NOT NULL CHECK (status IN ('planned','queued','running','succeeded','failed','stale')),
    error_code TEXT NULL,
    updated_at TEXT NOT NULL,
    PRIMARY KEY (batch_plan_id,position),
    UNIQUE (batch_plan_id,song_id)
);

CREATE TABLE lyrics_audio_tag_writeback_jobs (
    id TEXT PRIMARY KEY NOT NULL,
    plan_id TEXT NOT NULL UNIQUE REFERENCES lyrics_audio_tag_writeback_plans(id) ON DELETE RESTRICT,
    library_id TEXT NOT NULL REFERENCES music_libraries(id) ON DELETE RESTRICT,
    song_id TEXT NOT NULL REFERENCES media_songs(id) ON DELETE RESTRICT,
    lyric_id TEXT NOT NULL REFERENCES media_lyrics(id) ON DELETE RESTRICT,
    requested_by TEXT NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
    request_id TEXT NOT NULL,
    idempotency_key_sha256 TEXT NOT NULL CHECK (length(idempotency_key_sha256) = 64),
    status TEXT NOT NULL CHECK (status IN ('queued','running','succeeded','failed','cancelled')),
    phase TEXT NOT NULL CHECK (phase IN ('queued','validating','writing','verifying','persisting','indexing','completed','failed','cancelled')),
    attempt INTEGER NOT NULL DEFAULT 0 CHECK (attempt BETWEEN 0 AND 5),
    worker_id TEXT NULL,
    heartbeat_at TEXT NULL,
    started_at TEXT NULL,
    finished_at TEXT NULL,
    error_code TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE(requested_by,idempotency_key_sha256)
);

CREATE TABLE lyrics_audio_tag_writeback_operation_logs (
    id TEXT PRIMARY KEY NOT NULL,
    job_id TEXT NOT NULL REFERENCES lyrics_audio_tag_writeback_jobs(id) ON DELETE RESTRICT,
    sequence INTEGER NOT NULL CHECK (sequence > 0),
    operation TEXT NOT NULL CHECK (operation IN ('rewrite','verify','backup','compensate')),
    status TEXT NOT NULL CHECK (status IN ('started','succeeded','failed','compensated')),
    detached_hardlink INTEGER NOT NULL DEFAULT 0 CHECK (detached_hardlink IN (0,1)),
    output_sha256 TEXT NULL CHECK (output_sha256 IS NULL OR length(output_sha256) = 64),
    output_size_bytes INTEGER NULL CHECK (output_size_bytes IS NULL OR output_size_bytes >= 0),
    error_code TEXT NULL,
    started_at TEXT NOT NULL,
    finished_at TEXT NULL,
    UNIQUE(job_id,sequence)
);

CREATE TABLE lyrics_audio_tag_writeback_plans (
    id TEXT PRIMARY KEY NOT NULL,
    song_id TEXT NOT NULL REFERENCES media_songs(id) ON DELETE RESTRICT,
    lyric_id TEXT NOT NULL REFERENCES media_lyrics(id) ON DELETE RESTRICT,
    library_id TEXT NOT NULL REFERENCES music_libraries(id) ON DELETE RESTRICT,
    inventory_file_id TEXT NOT NULL REFERENCES library_file_inventory(id) ON DELETE RESTRICT,
    created_by TEXT NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
    status TEXT NOT NULL CHECK (status IN ('planned','queued','running','succeeded','failed','stale','expired')),
    expected_lyric_version INTEGER NOT NULL CHECK (expected_lyric_version > 0),
    expected_library_version INTEGER NOT NULL CHECK (expected_library_version > 0),
    source_kind TEXT NOT NULL CHECK (source_kind IN ('sidecar','embedded','provider','manual')),
    source_format TEXT NOT NULL,
    language TEXT NOT NULL,
    lyric_kind TEXT NOT NULL CHECK (lyric_kind IN ('plain','line','word')),
    license_policy TEXT NOT NULL CHECK (license_policy IN ('local_controlled','redistributable')),
    lyric_content_sha256 TEXT NOT NULL CHECK (length(lyric_content_sha256) = 64),
    output_sha256 TEXT NOT NULL CHECK (length(output_sha256) = 64),
    output_size_bytes INTEGER NOT NULL CHECK (output_size_bytes BETWEEN 1 AND 1048576),
    existing_tag_sha256 TEXT NULL CHECK (existing_tag_sha256 IS NULL OR length(existing_tag_sha256) = 64),
    source_relative_path TEXT NOT NULL CHECK (length(source_relative_path) BETWEEN 1 AND 4096),
    source_device INTEGER NOT NULL CHECK (source_device >= 0),
    source_inode INTEGER NOT NULL CHECK (source_inode >= 0),
    source_file_size INTEGER NOT NULL CHECK (source_file_size >= 0),
    source_modified_at INTEGER NOT NULL CHECK (source_modified_at >= 0),
    source_link_count INTEGER NOT NULL CHECK (source_link_count > 0),
    container_key TEXT NOT NULL CHECK (container_key IN ('mp3','flac','ogg','opus','mp4')),
    tag_key TEXT NOT NULL CHECK (tag_key = 'lyrics'),
    plan_hash TEXT NOT NULL CHECK (length(plan_hash) = 64),
    version INTEGER NOT NULL DEFAULT 1 CHECK (version > 0),
    expires_at TEXT NOT NULL,
    confirmed_at TEXT NULL,
    finished_at TEXT NULL,
    error_code TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE lyrics_writeback_batch_plans (
    id TEXT PRIMARY KEY NOT NULL,
    requested_by TEXT NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
    request_id TEXT NOT NULL,
    plan_hash TEXT NOT NULL CHECK (length(plan_hash) = 64),
    idempotency_key_sha256 TEXT NULL CHECK (idempotency_key_sha256 IS NULL OR length(idempotency_key_sha256) = 64),
    target_count INTEGER NOT NULL CHECK (target_count BETWEEN 1 AND 50),
    confirmable_count INTEGER NOT NULL CHECK (confirmable_count BETWEEN 0 AND target_count),
    conflict_count INTEGER NOT NULL CHECK (conflict_count BETWEEN 0 AND target_count),
    processed_count INTEGER NOT NULL DEFAULT 0 CHECK (processed_count BETWEEN 0 AND target_count),
    succeeded_count INTEGER NOT NULL DEFAULT 0 CHECK (succeeded_count BETWEEN 0 AND target_count),
    failed_count INTEGER NOT NULL DEFAULT 0 CHECK (failed_count BETWEEN 0 AND target_count),
    status TEXT NOT NULL CHECK (status IN ('draft', 'queued', 'running', 'succeeded', 'partial', 'failed', 'expired')),
    version INTEGER NOT NULL DEFAULT 1 CHECK (version > 0),
    expires_at TEXT NOT NULL,
    confirmed_at TEXT NULL,
    started_at TEXT NULL,
    finished_at TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL, replacement_count INTEGER NOT NULL DEFAULT 0 CHECK (replacement_count BETWEEN 0 AND 50),
    CHECK (confirmable_count + conflict_count = target_count)
);

CREATE TABLE lyrics_writeback_batch_targets (
    batch_plan_id TEXT NOT NULL REFERENCES lyrics_writeback_batch_plans(id) ON DELETE RESTRICT,
    position INTEGER NOT NULL CHECK (position BETWEEN 0 AND 49),
    writeback_plan_id TEXT NOT NULL UNIQUE REFERENCES lyrics_writeback_plans(id) ON DELETE RESTRICT,
    song_id TEXT NOT NULL REFERENCES media_songs(id) ON DELETE RESTRICT,
    lyric_id TEXT NOT NULL REFERENCES media_lyrics(id) ON DELETE RESTRICT,
    library_id TEXT NOT NULL REFERENCES music_libraries(id) ON DELETE RESTRICT,
    status TEXT NOT NULL CHECK (status IN ('planned', 'conflict', 'queued', 'running', 'succeeded', 'failed', 'stale')),
    error_code TEXT NULL,
    updated_at TEXT NOT NULL,
    PRIMARY KEY (batch_plan_id, position),
    UNIQUE (batch_plan_id, song_id)
);

CREATE TABLE lyrics_writeback_jobs (
    id TEXT PRIMARY KEY NOT NULL,
    plan_id TEXT NOT NULL UNIQUE REFERENCES lyrics_writeback_plans(id) ON DELETE RESTRICT,
    library_id TEXT NOT NULL REFERENCES music_libraries(id) ON DELETE RESTRICT,
    song_id TEXT NOT NULL REFERENCES media_songs(id) ON DELETE RESTRICT,
    lyric_id TEXT NOT NULL REFERENCES media_lyrics(id) ON DELETE RESTRICT,
    requested_by TEXT NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
    request_id TEXT NOT NULL,
    idempotency_key_sha256 TEXT NOT NULL CHECK (length(idempotency_key_sha256) = 64),
    status TEXT NOT NULL CHECK (status IN ('queued', 'running', 'succeeded', 'failed', 'cancelled')),
    phase TEXT NOT NULL CHECK (phase IN ('queued', 'validating', 'writing', 'publishing', 'indexing', 'completed', 'failed', 'cancelled')),
    attempt INTEGER NOT NULL DEFAULT 0 CHECK (attempt BETWEEN 0 AND 5),
    worker_id TEXT NULL,
    heartbeat_at TEXT NULL,
    started_at TEXT NULL,
    finished_at TEXT NULL,
    error_code TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE (requested_by, idempotency_key_sha256)
);

CREATE TABLE lyrics_writeback_operation_logs (
    id TEXT PRIMARY KEY NOT NULL,
    job_id TEXT NOT NULL REFERENCES lyrics_writeback_jobs(id) ON DELETE RESTRICT,
    sequence INTEGER NOT NULL CHECK (sequence > 0),
    operation TEXT NOT NULL CHECK (operation IN ('write_temp', 'publish', 'verify', 'compensate')),
    target_relative_path TEXT NOT NULL,
    status TEXT NOT NULL CHECK (status IN ('started', 'succeeded', 'failed', 'compensated')),
    output_sha256 TEXT NULL CHECK (output_sha256 IS NULL OR length(output_sha256) = 64),
    output_size_bytes INTEGER NULL CHECK (output_size_bytes IS NULL OR output_size_bytes >= 0),
    error_code TEXT NULL,
    started_at TEXT NOT NULL,
    finished_at TEXT NULL,
    UNIQUE (job_id, sequence)
);

CREATE TABLE lyrics_writeback_plans (
    id TEXT PRIMARY KEY NOT NULL,
    song_id TEXT NOT NULL REFERENCES media_songs(id) ON DELETE RESTRICT,
    lyric_id TEXT NOT NULL REFERENCES media_lyrics(id) ON DELETE RESTRICT,
    library_id TEXT NOT NULL REFERENCES music_libraries(id) ON DELETE RESTRICT,
    inventory_file_id TEXT NOT NULL REFERENCES library_file_inventory(id) ON DELETE RESTRICT,
    created_by TEXT NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
    status TEXT NOT NULL CHECK (status IN ('planned', 'queued', 'running', 'succeeded', 'failed', 'stale', 'cancelled')),
    expected_lyric_version INTEGER NOT NULL CHECK (expected_lyric_version > 0),
    expected_library_version INTEGER NOT NULL CHECK (expected_library_version > 0),
    source_kind TEXT NOT NULL CHECK (source_kind IN ('sidecar', 'embedded', 'provider', 'manual')),
    source_format TEXT NOT NULL,
    language TEXT NOT NULL,
    lyric_kind TEXT NOT NULL CHECK (lyric_kind IN ('plain', 'line', 'word')),
    match_score REAL NULL CHECK (match_score IS NULL OR (match_score >= 0 AND match_score <= 1)),
    license_policy TEXT NOT NULL CHECK (license_policy IN ('local_controlled', 'redistributable')),
    lyric_content_sha256 TEXT NOT NULL CHECK (length(lyric_content_sha256) = 64),
    output_sha256 TEXT NOT NULL CHECK (length(output_sha256) = 64),
    output_size_bytes INTEGER NOT NULL CHECK (output_size_bytes > 0 AND output_size_bytes <= 1048576),
    source_device INTEGER NOT NULL CHECK (source_device >= 0),
    source_inode INTEGER NOT NULL CHECK (source_inode >= 0),
    source_file_size INTEGER NOT NULL CHECK (source_file_size >= 0),
    source_modified_at INTEGER NOT NULL CHECK (source_modified_at >= 0),
    target_relative_path TEXT NOT NULL,
    target_state TEXT NOT NULL CHECK (target_state IN ('absent', 'conflict')),
    plan_hash TEXT NOT NULL CHECK (length(plan_hash) = 64),
    version INTEGER NOT NULL DEFAULT 1 CHECK (version > 0),
    expires_at TEXT NOT NULL,
    confirmed_at TEXT NULL,
    finished_at TEXT NULL,
    error_code TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
, replace_existing INTEGER NOT NULL DEFAULT 0 CHECK (replace_existing IN (0, 1)), existing_target_device INTEGER NULL CHECK (existing_target_device IS NULL OR existing_target_device >= 0), existing_target_inode INTEGER NULL CHECK (existing_target_inode IS NULL OR existing_target_inode >= 0), existing_target_size INTEGER NULL CHECK (existing_target_size IS NULL OR (existing_target_size >= 0 AND existing_target_size <= 1048576)), existing_target_modified_at INTEGER NULL CHECK (existing_target_modified_at IS NULL OR existing_target_modified_at >= 0), existing_target_sha256 TEXT NULL CHECK (existing_target_sha256 IS NULL OR length(existing_target_sha256) = 64));

CREATE TABLE lyrics_writeback_scan_requests (
    library_id TEXT PRIMARY KEY NOT NULL REFERENCES music_libraries(id) ON DELETE CASCADE,
    status TEXT NOT NULL CHECK (status IN ('pending', 'enqueued')),
    scan_job_id TEXT NULL REFERENCES library_scan_jobs(id) ON DELETE SET NULL,
    first_requested_at TEXT NOT NULL,
    last_requested_at TEXT NOT NULL,
    enqueued_at TEXT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE media_album_artists (
    album_id TEXT NOT NULL REFERENCES media_albums(id) ON DELETE CASCADE,
    artist_id TEXT NOT NULL REFERENCES media_artists(id) ON DELETE RESTRICT,
    position INTEGER NOT NULL CHECK (position >= 0),
    PRIMARY KEY (album_id, artist_id)
);

CREATE TABLE "media_album_artworks" (
    `album_id` TEXT PRIMARY KEY NOT NULL REFERENCES media_albums(id) ON DELETE CASCADE,
    `source_inventory_file_id` TEXT NOT NULL REFERENCES library_file_inventory(id) ON DELETE CASCADE,
    `source_file_name` TEXT NOT NULL,
    `source_kind` TEXT NOT NULL CHECK (`source_kind` IN ('sidecar', 'embedded', 'provider', 'manual')),
    `mime_type` TEXT NOT NULL CHECK (`mime_type` IN ('image/jpeg', 'image/png', 'image/webp')),
    `width` INTEGER NOT NULL CHECK (`width` > 0),
    `height` INTEGER NOT NULL CHECK (`height` > 0),
    `file_size` INTEGER NOT NULL CHECK (`file_size` > 0),
    `modified_at` INTEGER NOT NULL CHECK (`modified_at` >= 0),
    `device_id` INTEGER NOT NULL,
    `inode` INTEGER NOT NULL,
    `content_sha256` TEXT NOT NULL,
    `selection_priority` INTEGER NOT NULL,
    `selection_key` TEXT NOT NULL,
    `created_at` TEXT NOT NULL,
    `updated_at` TEXT NOT NULL
, `embedded_stream_index` INTEGER NULL);

CREATE TABLE media_album_metadata_field_states (
    album_id TEXT NOT NULL REFERENCES media_albums(id) ON DELETE CASCADE,
    field_key TEXT NOT NULL CHECK (length(field_key) BETWEEN 2 AND 64),
    raw_value_json TEXT NOT NULL CHECK (length(raw_value_json) BETWEEN 1 AND 65536),
    scraped_value_json TEXT NULL CHECK (scraped_value_json IS NULL OR length(scraped_value_json) BETWEEN 1 AND 65536),
    manual_value_json TEXT NULL CHECK (manual_value_json IS NULL OR length(manual_value_json) BETWEEN 1 AND 65536),
    effective_value_json TEXT NOT NULL CHECK (length(effective_value_json) BETWEEN 1 AND 65536),
    effective_source TEXT NOT NULL CHECK (effective_source IN ('raw', 'scraped', 'manual')),
    is_locked INTEGER NOT NULL DEFAULT 0 CHECK (is_locked IN (0, 1)),
    version INTEGER NOT NULL DEFAULT 1 CHECK (version > 0),
    source_updated_at TEXT NOT NULL,
    manual_updated_at TEXT NULL,
    updated_by TEXT NULL REFERENCES users(id) ON DELETE SET NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    PRIMARY KEY (album_id, field_key)
);

CREATE TABLE media_albums (
    id TEXT PRIMARY KEY NOT NULL,
    library_id TEXT NOT NULL REFERENCES music_libraries(id) ON DELETE CASCADE,
    identity_key TEXT NOT NULL,
    title TEXT NOT NULL,
    sort_title TEXT NULL,
    release_date TEXT NULL,
    release_year INTEGER NULL,
    disc_total INTEGER NULL CHECK (disc_total IS NULL OR disc_total >= 0),
    musicbrainz_release_id TEXT NULL,
    musicbrainz_release_group_id TEXT NULL,
    song_count INTEGER NOT NULL DEFAULT 0 CHECK (song_count >= 0),
    duration_ms INTEGER NOT NULL DEFAULT 0 CHECK (duration_ms >= 0),
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL, normalized_title TEXT NOT NULL DEFAULT '', identity_source_title TEXT NOT NULL DEFAULT '',
    UNIQUE (library_id, identity_key)
);

CREATE TABLE media_artist_artworks (
    artist_id TEXT NOT NULL REFERENCES media_artists(id) ON DELETE CASCADE,
    library_id TEXT NOT NULL REFERENCES music_libraries(id) ON DELETE CASCADE,
    source_inventory_file_id TEXT NOT NULL REFERENCES library_file_inventory(id) ON DELETE CASCADE,
    source_file_name TEXT NOT NULL,
    directory_levels_up INTEGER NOT NULL CHECK (directory_levels_up IN (0, 1)),
    source_kind TEXT NOT NULL CHECK (source_kind IN ('artist_sidecar', 'single_sidecar', 'provider', 'manual')),
    mime_type TEXT NOT NULL CHECK (mime_type IN ('image/jpeg', 'image/png', 'image/webp')),
    width INTEGER NOT NULL CHECK (width > 0),
    height INTEGER NOT NULL CHECK (height > 0),
    file_size INTEGER NOT NULL CHECK (file_size > 0),
    modified_at INTEGER NOT NULL CHECK (modified_at >= 0),
    device_id INTEGER NOT NULL,
    inode INTEGER NOT NULL,
    content_sha256 TEXT NOT NULL,
    selection_priority INTEGER NOT NULL,
    selection_key TEXT NOT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    PRIMARY KEY (artist_id, library_id)
);

CREATE TABLE media_artist_metadata_field_states (
    artist_id TEXT NOT NULL REFERENCES media_artists(id) ON DELETE CASCADE,
    field_key TEXT NOT NULL CHECK (length(field_key) BETWEEN 2 AND 64),
    raw_value_json TEXT NOT NULL CHECK (length(raw_value_json) BETWEEN 1 AND 65536),
    scraped_value_json TEXT NULL CHECK (scraped_value_json IS NULL OR length(scraped_value_json) BETWEEN 1 AND 65536),
    manual_value_json TEXT NULL CHECK (manual_value_json IS NULL OR length(manual_value_json) BETWEEN 1 AND 65536),
    effective_value_json TEXT NOT NULL CHECK (length(effective_value_json) BETWEEN 1 AND 65536),
    effective_source TEXT NOT NULL CHECK (effective_source IN ('raw', 'scraped', 'manual')),
    is_locked INTEGER NOT NULL DEFAULT 0 CHECK (is_locked IN (0, 1)),
    version INTEGER NOT NULL DEFAULT 1 CHECK (version > 0),
    source_updated_at TEXT NOT NULL,
    manual_updated_at TEXT NULL,
    updated_by TEXT NULL REFERENCES users(id) ON DELETE SET NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    PRIMARY KEY (artist_id, field_key)
);

CREATE TABLE media_artists (
    id TEXT PRIMARY KEY NOT NULL,
    name TEXT NOT NULL,
    normalized_name TEXT NOT NULL UNIQUE,
    sort_name TEXT NULL,
    musicbrainz_artist_id TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE "media_artwork_selection_overrides" (
    id TEXT PRIMARY KEY NOT NULL,
    song_id TEXT NULL REFERENCES media_songs(id) ON DELETE CASCADE,
    album_id TEXT NULL REFERENCES media_albums(id) ON DELETE CASCADE,
    artist_id TEXT NULL REFERENCES media_artists(id) ON DELETE CASCADE,
    library_id TEXT NOT NULL REFERENCES music_libraries(id) ON DELETE CASCADE,
    candidate_id TEXT NOT NULL REFERENCES "media_manual_artwork_candidates"(id) ON DELETE RESTRICT,
    version INTEGER NOT NULL DEFAULT 1 CHECK (version > 0),
    updated_by TEXT NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    CHECK ((song_id IS NOT NULL) + (album_id IS NOT NULL) + (artist_id IS NOT NULL) = 1)
);

CREATE TABLE media_genres (
    id TEXT PRIMARY KEY NOT NULL,
    name TEXT NOT NULL,
    normalized_name TEXT NOT NULL UNIQUE,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE media_lyrics (
    id TEXT PRIMARY KEY NOT NULL,
    song_id TEXT NOT NULL REFERENCES media_songs(id) ON DELETE CASCADE,
    source_kind TEXT NOT NULL CHECK (source_kind IN ('sidecar','embedded','provider','manual')),
    source_locator_digest TEXT NOT NULL CHECK (length(source_locator_digest) = 64),
    storage_kind TEXT NOT NULL CHECK (storage_kind IN ('managed_cache','adjacent')),
    storage_locator TEXT NOT NULL CHECK (length(storage_locator) BETWEEN 1 AND 1024),
    language TEXT NOT NULL DEFAULT 'und',
    lyric_kind TEXT NOT NULL CHECK (lyric_kind IN ('plain','line','word')),
    source_format TEXT NOT NULL CHECK (source_format IN ('lrc','txt','enhanced-json')),
    content_sha256 TEXT NOT NULL CHECK (length(content_sha256) = 64),
    priority INTEGER NOT NULL DEFAULT 0,
    match_score REAL NULL CHECK (match_score IS NULL OR (match_score >= 0 AND match_score <= 1)),
    license_policy TEXT NOT NULL CHECK (license_policy IN ('local_controlled','cache_allowed','redistributable')),
    source_size_bytes INTEGER NOT NULL CHECK (source_size_bytes BETWEEN 1 AND 1048576),
    source_modified_at INTEGER NOT NULL CHECK (source_modified_at >= 0),
    version INTEGER NOT NULL DEFAULT 1 CHECK (version > 0),
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE (song_id, source_kind, source_locator_digest)
);

CREATE TABLE media_lyrics_parse_diagnostics (
    song_id TEXT NOT NULL REFERENCES media_songs(id) ON DELETE CASCADE,
    source_locator_digest TEXT NOT NULL,
    language TEXT NOT NULL,
    source_format TEXT NOT NULL CHECK (source_format IN ('lrc', 'txt')),
    error_code TEXT NOT NULL CHECK (error_code = 'LYRICS_PARSE_FAILED'),
    source_size_bytes INTEGER NOT NULL CHECK (source_size_bytes > 0),
    source_modified_at INTEGER NOT NULL CHECK (source_modified_at >= 0),
    last_attempt_at TEXT NOT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    PRIMARY KEY (song_id, source_locator_digest)
);

CREATE TABLE media_lyrics_primary_selections (
    song_id TEXT PRIMARY KEY NOT NULL REFERENCES media_songs(id) ON DELETE CASCADE,
    lyric_id TEXT NOT NULL UNIQUE REFERENCES media_lyrics(id) ON DELETE CASCADE,
    version INTEGER NOT NULL DEFAULT 1 CHECK (version > 0),
    updated_by TEXT NULL REFERENCES users(id) ON DELETE SET NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE "media_manual_artwork_candidates" (
    id TEXT PRIMARY KEY NOT NULL,
    song_id TEXT NULL REFERENCES media_songs(id) ON DELETE CASCADE,
    album_id TEXT NULL REFERENCES media_albums(id) ON DELETE CASCADE,
    artist_id TEXT NULL REFERENCES media_artists(id) ON DELETE CASCADE,
    library_id TEXT NOT NULL REFERENCES music_libraries(id) ON DELETE CASCADE,
    created_by TEXT NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
    mime_type TEXT NOT NULL CHECK (mime_type = 'image/webp'),
    image_bytes BLOB NOT NULL,
    byte_size INTEGER NOT NULL CHECK (byte_size BETWEEN 1 AND 8388608),
    width INTEGER NOT NULL CHECK (width BETWEEN 1 AND 1600),
    height INTEGER NOT NULL CHECK (height BETWEEN 1 AND 1600),
    content_sha256 TEXT NOT NULL CHECK (length(content_sha256) = 64),
    crop_x INTEGER NOT NULL CHECK (crop_x BETWEEN 0 AND 10000),
    crop_y INTEGER NOT NULL CHECK (crop_y BETWEEN 0 AND 10000),
    crop_width INTEGER NOT NULL CHECK (crop_width BETWEEN 1 AND 10000),
    crop_height INTEGER NOT NULL CHECK (crop_height BETWEEN 1 AND 10000),
    origin_kind TEXT NOT NULL DEFAULT 'upload' CHECK (origin_kind IN ('upload', 'provider')),
    provider_key TEXT NULL,
    provider_asset_digest TEXT NULL,
    attribution_required INTEGER NOT NULL DEFAULT 0 CHECK (attribution_required IN (0, 1)),
    attribution_text TEXT NOT NULL DEFAULT '',
    attribution_url TEXT NULL,
    created_at TEXT NOT NULL,
    CHECK ((song_id IS NOT NULL) + (album_id IS NOT NULL) + (artist_id IS NOT NULL) = 1)
);

CREATE TABLE media_metadata_field_states (
    song_id TEXT NOT NULL REFERENCES media_songs(id) ON DELETE CASCADE,
    field_key TEXT NOT NULL CHECK (length(field_key) BETWEEN 2 AND 64),
    raw_value_json TEXT NOT NULL CHECK (length(raw_value_json) BETWEEN 1 AND 65536),
    scraped_value_json TEXT NULL CHECK (scraped_value_json IS NULL OR length(scraped_value_json) BETWEEN 1 AND 65536),
    manual_value_json TEXT NULL CHECK (manual_value_json IS NULL OR length(manual_value_json) BETWEEN 1 AND 65536),
    effective_value_json TEXT NOT NULL CHECK (length(effective_value_json) BETWEEN 1 AND 65536),
    effective_source TEXT NOT NULL CHECK (effective_source IN ('raw', 'scraped', 'manual')),
    is_locked INTEGER NOT NULL DEFAULT 0 CHECK (is_locked IN (0, 1)),
    version INTEGER NOT NULL DEFAULT 1 CHECK (version > 0),
    source_updated_at TEXT NOT NULL,
    manual_updated_at TEXT NULL,
    updated_by TEXT NULL REFERENCES users(id) ON DELETE SET NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    PRIMARY KEY (song_id, field_key)
);

CREATE TABLE media_song_artists (
    song_id TEXT NOT NULL REFERENCES media_songs(id) ON DELETE CASCADE,
    artist_id TEXT NOT NULL REFERENCES media_artists(id) ON DELETE RESTRICT,
    role TEXT NOT NULL CHECK (role IN ('primary', 'featured')),
    position INTEGER NOT NULL CHECK (position >= 0),
    PRIMARY KEY (song_id, artist_id, role)
);

CREATE TABLE media_song_genres (
    song_id TEXT NOT NULL REFERENCES media_songs(id) ON DELETE CASCADE,
    genre_id TEXT NOT NULL REFERENCES media_genres(id) ON DELETE RESTRICT,
    position INTEGER NOT NULL CHECK (position >= 0),
    PRIMARY KEY (song_id, genre_id)
);

CREATE TABLE media_songs (
    id TEXT PRIMARY KEY NOT NULL,
    library_id TEXT NOT NULL REFERENCES music_libraries(id) ON DELETE CASCADE,
    inventory_file_id TEXT NOT NULL UNIQUE REFERENCES library_file_inventory(id) ON DELETE CASCADE,
    album_id TEXT NOT NULL REFERENCES media_albums(id) ON DELETE RESTRICT,
    title TEXT NOT NULL,
    sort_title TEXT NULL,
    track_number INTEGER NULL CHECK (track_number IS NULL OR track_number >= 0),
    track_total INTEGER NULL CHECK (track_total IS NULL OR track_total >= 0),
    disc_number INTEGER NULL CHECK (disc_number IS NULL OR disc_number >= 0),
    disc_total INTEGER NULL CHECK (disc_total IS NULL OR disc_total >= 0),
    release_date TEXT NULL,
    release_year INTEGER NULL,
    composer TEXT NULL,
    comment TEXT NULL,
    bpm REAL NULL CHECK (bpm IS NULL OR bpm >= 0),
    isrc TEXT NULL,
    musicbrainz_track_id TEXT NULL,
    duration_ms INTEGER NOT NULL DEFAULT 0 CHECK (duration_ms >= 0),
    codec_name TEXT NULL,
    container_name TEXT NULL,
    bitrate INTEGER NULL CHECK (bitrate IS NULL OR bitrate >= 0),
    bit_depth INTEGER NULL CHECK (bit_depth IS NULL OR bit_depth >= 0),
    sample_rate INTEGER NULL CHECK (sample_rate IS NULL OR sample_rate >= 0),
    channels INTEGER NULL CHECK (channels IS NULL OR channels >= 0),
    replaygain_track_gain REAL NULL,
    replaygain_track_peak REAL NULL,
    replaygain_album_gain REAL NULL,
    replaygain_album_peak REAL NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
, normalized_title TEXT NOT NULL DEFAULT '');

CREATE TABLE media_tag_snapshots (
    song_id TEXT PRIMARY KEY NOT NULL REFERENCES media_songs(id) ON DELETE CASCADE,
    scan_job_id TEXT NOT NULL REFERENCES library_scan_jobs(id) ON DELETE RESTRICT,
    parser_version INTEGER NOT NULL CHECK (parser_version > 0),
    raw_tags_json TEXT NOT NULL,
    probed_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE metadata_batch_plans (
    id TEXT PRIMARY KEY NOT NULL,
    requested_by TEXT NULL REFERENCES users(id) ON DELETE SET NULL,
    request_id TEXT NOT NULL,
    filter_json TEXT NOT NULL CHECK (length(filter_json) BETWEEN 2 AND 65536),
    operations_json TEXT NOT NULL CHECK (length(operations_json) BETWEEN 2 AND 65536),
    snapshot_sha256 TEXT NOT NULL CHECK (length(snapshot_sha256) = 64),
    sample_json TEXT NOT NULL CHECK (length(sample_json) BETWEEN 2 AND 262144),
    target_count INTEGER NOT NULL CHECK (target_count BETWEEN 1 AND 10000),
    processed_count INTEGER NOT NULL DEFAULT 0 CHECK (processed_count >= 0),
    succeeded_count INTEGER NOT NULL DEFAULT 0 CHECK (succeeded_count >= 0),
    failed_count INTEGER NOT NULL DEFAULT 0 CHECK (failed_count >= 0),
    status TEXT NOT NULL CHECK (status IN ('draft', 'queued', 'running', 'succeeded', 'partial', 'failed', 'expired')),
    version INTEGER NOT NULL DEFAULT 1 CHECK (version > 0),
    attempt INTEGER NOT NULL DEFAULT 0 CHECK (attempt BETWEEN 0 AND 5),
    worker_id TEXT NULL,
    heartbeat_at TEXT NULL,
    expires_at TEXT NOT NULL,
    confirmed_at TEXT NULL,
    started_at TEXT NULL,
    finished_at TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE metadata_batch_targets (
    plan_id TEXT NOT NULL REFERENCES metadata_batch_plans(id) ON DELETE RESTRICT,
    song_id TEXT NOT NULL REFERENCES media_songs(id) ON DELETE RESTRICT,
    library_id TEXT NOT NULL REFERENCES music_libraries(id) ON DELETE RESTRICT,
    field_versions_json TEXT NOT NULL CHECK (length(field_versions_json) BETWEEN 2 AND 65536),
    status TEXT NOT NULL CHECK (status IN ('pending', 'running', 'succeeded', 'failed')),
    attempt INTEGER NOT NULL DEFAULT 0 CHECK (attempt BETWEEN 0 AND 5),
    error_code TEXT NULL,
    change_set_id TEXT NULL REFERENCES metadata_change_sets(id) ON DELETE SET NULL,
    started_at TEXT NULL,
    finished_at TEXT NULL,
    updated_at TEXT NOT NULL,
    PRIMARY KEY (plan_id, song_id)
);

CREATE TABLE metadata_change_items (
    id TEXT PRIMARY KEY NOT NULL,
    change_set_id TEXT NOT NULL REFERENCES metadata_change_sets(id) ON DELETE RESTRICT,
    object_type TEXT NOT NULL CHECK (object_type IN ('song', 'album', 'artist')),
    object_id TEXT NOT NULL,
    field_key TEXT NOT NULL CHECK (length(field_key) BETWEEN 2 AND 64),
    operation TEXT NOT NULL CHECK (operation IN ('set', 'append', 'remove', 'clear', 'lock', 'unlock')),
    before_value_json TEXT NOT NULL CHECK (length(before_value_json) BETWEEN 1 AND 65536),
    after_value_json TEXT NOT NULL CHECK (length(after_value_json) BETWEEN 1 AND 65536),
    source_before TEXT NOT NULL CHECK (source_before IN ('raw', 'scraped', 'manual')),
    source_after TEXT NOT NULL CHECK (source_after IN ('raw', 'scraped', 'manual')),
    result TEXT NOT NULL CHECK (result IN ('succeeded', 'failed', 'skipped')),
    error_code TEXT NULL,
    created_at TEXT NOT NULL
);

CREATE TABLE metadata_change_sets (
    id TEXT PRIMARY KEY NOT NULL,
    actor_user_id TEXT NULL REFERENCES users(id) ON DELETE SET NULL,
    command_type TEXT NOT NULL CHECK (command_type IN ('single', 'batch')),
    source_kind TEXT NOT NULL CHECK (source_kind IN ('admin', 'worker')),
    object_count INTEGER NOT NULL CHECK (object_count >= 0),
    changed_field_count INTEGER NOT NULL CHECK (changed_field_count >= 0),
    task_id TEXT NULL,
    status TEXT NOT NULL CHECK (status IN ('running', 'succeeded', 'partial', 'failed')),
    request_id TEXT NOT NULL,
    created_at TEXT NOT NULL,
    finished_at TEXT NULL
);

CREATE TABLE metadata_entity_operations (
    id TEXT PRIMARY KEY NOT NULL,
    entity_type TEXT NOT NULL CHECK (entity_type IN ('artist', 'album')),
    operation_type TEXT NOT NULL CHECK (operation_type IN ('merge', 'split')),
    source_entity_id TEXT NOT NULL,
    target_entity_id TEXT NOT NULL,
    created_entity_id TEXT NULL,
    actor_user_id TEXT NULL REFERENCES users(id) ON DELETE SET NULL,
    library_id TEXT NULL REFERENCES music_libraries(id) ON DELETE SET NULL,
    status TEXT NOT NULL CHECK (status IN ('applied', 'rolled_back')),
    version INTEGER NOT NULL DEFAULT 1 CHECK (version > 0),
    affected_song_count INTEGER NOT NULL CHECK (affected_song_count >= 0),
    affected_favorite_count INTEGER NOT NULL CHECK (affected_favorite_count >= 0),
    affected_playlist_count INTEGER NOT NULL CHECK (affected_playlist_count >= 0),
    snapshot_json TEXT NOT NULL CHECK (length(snapshot_json) BETWEEN 2 AND 16777216),
    postcondition_sha256 TEXT NOT NULL CHECK (length(postcondition_sha256) = 64),
    created_at TEXT NOT NULL,
    rolled_back_at TEXT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE metadata_entity_redirects (
    entity_type TEXT NOT NULL CHECK (entity_type IN ('artist', 'album')),
    source_entity_id TEXT NOT NULL,
    target_entity_id TEXT NOT NULL,
    operation_id TEXT PRIMARY KEY NOT NULL REFERENCES metadata_entity_operations(id) ON DELETE RESTRICT,
    status TEXT NOT NULL CHECK (status IN ('active', 'reverted')),
    created_at TEXT NOT NULL,
    reverted_at TEXT NULL
);

CREATE TABLE "metadata_sync_scrape_channel_results" (
    id TEXT PRIMARY KEY NOT NULL,
    target_id TEXT NOT NULL REFERENCES metadata_sync_scrape_targets(id) ON DELETE CASCADE,
    position INTEGER NOT NULL CHECK (position BETWEEN 0 AND 8),
    channel_key TEXT NOT NULL CHECK (channel_key IN ('netease', 'qq', 'kugou', 'kuwo', 'migu', 'soda', 'apple_music', 'musicbrainz', 'lrclib')),
    display_name TEXT NOT NULL CHECK (length(display_name) BETWEEN 1 AND 50),
    status TEXT NOT NULL CHECK (status IN ('matched', 'unmatched', 'unavailable')),
    candidate_json TEXT NULL CHECK (candidate_json IS NULL OR length(candidate_json) BETWEEN 2 AND 16384),
    diagnostics_json TEXT NULL CHECK (diagnostics_json IS NULL OR length(diagnostics_json) BETWEEN 2 AND 16384),
    created_at TEXT NOT NULL,
    has_lyrics INTEGER NOT NULL DEFAULT 0 CHECK (has_lyrics IN (0, 1)),
    has_artwork INTEGER NOT NULL DEFAULT 0 CHECK (has_artwork IN (0, 1)),
    UNIQUE (target_id, channel_key),
    UNIQUE (target_id, position)
);

CREATE TABLE metadata_sync_scrape_jobs (
    id TEXT PRIMARY KEY NOT NULL,
    requested_by TEXT NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
    request_id TEXT NOT NULL,
    status TEXT NOT NULL CHECK (status IN ('queued', 'running', 'succeeded', 'partial', 'failed')),
    target_count INTEGER NOT NULL CHECK (target_count BETWEEN 1 AND 50),
    processed_count INTEGER NOT NULL DEFAULT 0 CHECK (processed_count BETWEEN 0 AND target_count),
    succeeded_count INTEGER NOT NULL DEFAULT 0 CHECK (succeeded_count BETWEEN 0 AND target_count),
    unmatched_count INTEGER NOT NULL DEFAULT 0 CHECK (unmatched_count BETWEEN 0 AND target_count),
    failed_count INTEGER NOT NULL DEFAULT 0 CHECK (failed_count BETWEEN 0 AND target_count),
    version INTEGER NOT NULL DEFAULT 1 CHECK (version > 0),
    created_at TEXT NOT NULL,
    started_at TEXT NULL,
    finished_at TEXT NULL,
    updated_at TEXT NOT NULL
, confirmation_required INTEGER NOT NULL DEFAULT 0 CHECK (confirmation_required IN (0, 1)));

CREATE TABLE metadata_sync_scrape_targets (
    id TEXT PRIMARY KEY NOT NULL,
    job_id TEXT NOT NULL REFERENCES metadata_sync_scrape_jobs(id) ON DELETE CASCADE,
    position INTEGER NOT NULL CHECK (position BETWEEN 0 AND 49),
    song_id TEXT NULL REFERENCES media_songs(id) ON DELETE SET NULL,
    library_id TEXT NOT NULL REFERENCES music_libraries(id) ON DELETE RESTRICT,
    evidence_json TEXT NOT NULL,
    evidence_sha256 TEXT NOT NULL CHECK (length(evidence_sha256) = 64),
    status TEXT NOT NULL CHECK (status IN ('pending', 'running', 'succeeded', 'unmatched', 'failed')),
    selected_source TEXT NULL,
    score INTEGER NULL CHECK (score IS NULL OR (score BETWEEN 0 AND 100)),
    lyrics_saved INTEGER NOT NULL DEFAULT 0 CHECK (lyrics_saved IN (0, 1)),
    error_code TEXT NULL,
    attempt INTEGER NOT NULL DEFAULT 0 CHECK (attempt >= 0),
    worker_id TEXT NULL,
    heartbeat_at TEXT NULL,
    created_at TEXT NOT NULL,
    started_at TEXT NULL,
    finished_at TEXT NULL,
    updated_at TEXT NOT NULL, artwork_status TEXT NOT NULL DEFAULT 'pending' CHECK (artwork_status IN ('pending','selected','preserved','unavailable','failed')), awaiting_confirmation INTEGER NOT NULL DEFAULT 0 CHECK (awaiting_confirmation IN (0, 1)), selected_candidate_sha256 TEXT NULL CHECK (selected_candidate_sha256 IS NULL OR length(selected_candidate_sha256) = 64), selection_json TEXT NULL CHECK (selection_json IS NULL OR length(selection_json) BETWEEN 2 AND 4096), phase TEXT NOT NULL DEFAULT 'completed' CHECK (phase IN ('provider_query','awaiting_confirmation','applying','completing_resources','completed')), next_attempt_at TEXT NULL, resource_status_json TEXT NULL CHECK (resource_status_json IS NULL OR length(resource_status_json) BETWEEN 2 AND 16384),
    UNIQUE (job_id, position),
    UNIQUE (job_id, song_id)
);

CREATE TABLE metadata_writeback_scan_requests (
    library_id TEXT PRIMARY KEY NOT NULL REFERENCES music_libraries(id) ON DELETE CASCADE,
    status TEXT NOT NULL CHECK (status IN ('pending','enqueued')),
    scan_job_id TEXT NULL REFERENCES library_scan_jobs(id) ON DELETE SET NULL,
    first_requested_at TEXT NOT NULL,
    last_requested_at TEXT NOT NULL,
    enqueued_at TEXT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE music_libraries (
    id TEXT PRIMARY KEY NOT NULL,
    name TEXT NOT NULL COLLATE NOCASE UNIQUE,
    root_path TEXT NOT NULL,
    resolved_root_path TEXT NOT NULL UNIQUE,
    status TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'disabled')),
    symlink_policy TEXT NOT NULL DEFAULT 'ignore' CHECK (symlink_policy IN ('ignore', 'within_root')),
    default_locale TEXT NOT NULL DEFAULT 'zh-CN',
    scan_mode TEXT NOT NULL DEFAULT 'manual' CHECK (scan_mode IN ('manual', 'scheduled', 'watch')),
    scan_status TEXT NOT NULL DEFAULT 'never_scanned' CHECK (
        scan_status IN ('never_scanned', 'queued', 'scanning', 'ready', 'error')
    ),
    artist_count INTEGER NOT NULL DEFAULT 0 CHECK (artist_count >= 0),
    album_count INTEGER NOT NULL DEFAULT 0 CHECK (album_count >= 0),
    song_count INTEGER NOT NULL DEFAULT 0 CHECK (song_count >= 0),
    last_scanned_at TEXT NULL,
    version INTEGER NOT NULL DEFAULT 1 CHECK (version > 0),
    created_by TEXT NULL REFERENCES users(id) ON DELETE SET NULL,
    updated_by TEXT NULL REFERENCES users(id) ON DELETE SET NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
, inbox_path TEXT NULL, resolved_inbox_path TEXT NULL, import_approval_mode TEXT NOT NULL DEFAULT 'manual' CHECK (import_approval_mode IN ('auto', 'manual')), import_stability_seconds INTEGER NOT NULL DEFAULT 60 CHECK (import_stability_seconds BETWEEN 5 AND 86400), scrape_storage_mode TEXT NOT NULL DEFAULT 'managed_cache' CHECK (scrape_storage_mode IN ('managed_cache', 'adjacent')), source_type TEXT NOT NULL DEFAULT 'local' CHECK (source_type IN ('local', 'webdav', 'onedrive')));

CREATE TABLE "music_sources" (
    `source_key` TEXT PRIMARY KEY,
    `display_name` TEXT NOT NULL,
    `enabled` INTEGER NOT NULL DEFAULT 1 CHECK (`enabled` IN (0, 1)),
    `priority` INTEGER NOT NULL CHECK (`priority` BETWEEN 1 AND 1000),
    `capabilities_json` TEXT NOT NULL CHECK (length(capabilities_json) BETWEEN 2 AND 256),
    `implementation_status` TEXT NOT NULL CHECK (`implementation_status` IN ('available', 'partial')),
    `version` INTEGER NOT NULL DEFAULT 1 CHECK (`version` >= 1),
    `created_at` TEXT NOT NULL,
    `updated_at` TEXT NOT NULL
, `use_proxy` INTEGER NOT NULL DEFAULT 0);

CREATE TABLE onedrive_device_authorizations (
    id TEXT PRIMARY KEY NOT NULL,
    actor_user_id TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    tenant_id TEXT NOT NULL,
    client_id TEXT NOT NULL,
    device_code_ciphertext TEXT NULL,
    user_code TEXT NOT NULL,
    verification_uri TEXT NOT NULL,
    status TEXT NOT NULL CHECK (status IN ('pending', 'authorized', 'denied', 'expired', 'consumed')),
    interval_seconds INTEGER NOT NULL CHECK (interval_seconds BETWEEN 1 AND 60),
    next_poll_at TEXT NOT NULL,
    expires_at TEXT NOT NULL,
    refresh_token_ciphertext TEXT NULL,
    account_id TEXT NULL,
    drive_id TEXT NULL,
    consumed_at TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    CHECK ((status = 'pending' AND device_code_ciphertext IS NOT NULL AND refresh_token_ciphertext IS NULL)
        OR (status = 'authorized' AND device_code_ciphertext IS NOT NULL AND refresh_token_ciphertext IS NOT NULL AND account_id IS NOT NULL AND drive_id IS NOT NULL)
        OR (status IN ('denied', 'expired', 'consumed') AND device_code_ciphertext IS NULL AND refresh_token_ciphertext IS NULL))
);

CREATE TABLE onedrive_library_connections (
    library_id TEXT PRIMARY KEY NOT NULL REFERENCES music_libraries(id) ON DELETE CASCADE,
    tenant_id TEXT NOT NULL,
    client_id TEXT NOT NULL,
    legacy_client_secret_ciphertext TEXT NULL,
    legacy_user_principal_name TEXT NULL COLLATE NOCASE,
    refresh_token_ciphertext TEXT NULL,
    account_id TEXT NULL,
    drive_id TEXT NULL,
    remote_root_path TEXT NOT NULL,
    authorization_status TEXT NOT NULL CHECK (authorization_status IN ('authorized', 'reauthorization_required')),
    last_verified_at TEXT NULL,
    last_error_code TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    CHECK ((authorization_status = 'authorized' AND refresh_token_ciphertext IS NOT NULL AND account_id IS NOT NULL AND drive_id IS NOT NULL)
        OR authorization_status = 'reauthorization_required'),
    UNIQUE (drive_id, remote_root_path)
);

CREATE TABLE personal_access_tokens (
    id TEXT PRIMARY KEY NOT NULL,
    user_id TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    name TEXT NOT NULL CHECK (length(name) BETWEEN 1 AND 80),
    secret_digest TEXT NOT NULL UNIQUE CHECK (length(secret_digest) = 64),
    scopes_json TEXT NOT NULL CHECK (length(scopes_json) BETWEEN 2 AND 2048),
    expires_at TEXT NULL,
    last_used_at TEXT NULL,
    revoked_at TEXT NULL,
    revoked_reason TEXT NULL CHECK (revoked_reason IS NULL OR length(revoked_reason) BETWEEN 1 AND 64),
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE personal_data_export_jobs (
    id TEXT PRIMARY KEY NOT NULL,
    user_id TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    requested_by TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    request_id TEXT NOT NULL,
    status TEXT NOT NULL CHECK (status IN (
        'queued', 'running', 'cancel_requested', 'cancelled', 'succeeded', 'failed'
    )),
    phase TEXT NOT NULL CHECK (phase IN (
        'queued', 'collecting', 'writing', 'verifying', 'completed', 'cancelled', 'failed'
    )),
    artifact_filename TEXT NULL UNIQUE,
    byte_size INTEGER NULL CHECK (byte_size IS NULL OR byte_size >= 0),
    sha256 TEXT NULL CHECK (sha256 IS NULL OR length(sha256) = 64),
    record_count INTEGER NOT NULL DEFAULT 0 CHECK (record_count >= 0),
    attempt INTEGER NOT NULL DEFAULT 0 CHECK (attempt BETWEEN 0 AND 3),
    worker_id TEXT NULL,
    heartbeat_at TEXT NULL,
    cancel_requested_at TEXT NULL,
    started_at TEXT NULL,
    finished_at TEXT NULL,
    expires_at TEXT NULL,
    error_code TEXT NULL CHECK (error_code IS NULL OR length(error_code) BETWEEN 1 AND 96),
    version INTEGER NOT NULL DEFAULT 1 CHECK (version > 0),
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE play_queue_items (
    queue_id TEXT NOT NULL REFERENCES play_queues(id) ON DELETE CASCADE,
    position INTEGER NOT NULL CHECK (position >= 0),
    song_id TEXT NOT NULL REFERENCES media_songs(id) ON DELETE CASCADE,
    added_at TEXT NOT NULL,
    PRIMARY KEY (queue_id, position)
);

CREATE TABLE play_queues (
    id TEXT PRIMARY KEY NOT NULL,
    user_id TEXT NOT NULL UNIQUE REFERENCES users(id) ON DELETE CASCADE,
    current_index INTEGER NULL CHECK (current_index IS NULL OR current_index >= 0),
    position_ms INTEGER NOT NULL DEFAULT 0 CHECK (position_ms >= 0),
    repeat_mode TEXT NOT NULL DEFAULT 'off' CHECK (repeat_mode IN ('off', 'all', 'one')),
    shuffle_enabled INTEGER NOT NULL DEFAULT 0 CHECK (shuffle_enabled IN (0, 1)),
    version INTEGER NOT NULL DEFAULT 1 CHECK (version > 0),
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE playback_events (
    id TEXT PRIMARY KEY NOT NULL,
    event_id TEXT NOT NULL,
    session_id TEXT NOT NULL REFERENCES playback_sessions(id) ON DELETE CASCADE,
    user_id TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    player_id TEXT NOT NULL,
    event_type TEXT NOT NULL CHECK (event_type IN ('started', 'progress', 'paused', 'completed', 'stopped')),
    position_ms INTEGER NOT NULL CHECK (position_ms >= 0),
    occurred_at TEXT NOT NULL,
    received_at TEXT NOT NULL,
    counted_now INTEGER NOT NULL CHECK (counted_now IN (0, 1)),
    session_counted INTEGER NOT NULL CHECK (session_counted IN (0, 1)),
    listened_ms_after INTEGER NOT NULL CHECK (listened_ms_after >= 0),
    play_count_after INTEGER NOT NULL CHECK (play_count_after >= 0),
    status_after TEXT NOT NULL CHECK (status_after IN ('playing', 'paused', 'completed', 'stopped')),
    UNIQUE (user_id, player_id, event_id)
);

CREATE TABLE playback_leases (
    id TEXT PRIMARY KEY NOT NULL,
    user_id TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    player_id TEXT NOT NULL,
    song_id TEXT NOT NULL REFERENCES media_songs(id) ON DELETE CASCADE,
    acquired_at TEXT NOT NULL,
    heartbeat_at TEXT NOT NULL,
    expires_at TEXT NOT NULL,
    UNIQUE (user_id, player_id)
);

CREATE TABLE "playback_sessions" (
    `id` TEXT PRIMARY KEY NOT NULL,
    `playback_id` TEXT NOT NULL,
    `user_id` TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    `player_id` TEXT NOT NULL,
    `song_id` TEXT NOT NULL REFERENCES media_songs(id) ON DELETE CASCADE,
    `queue_version` INTEGER NOT NULL CHECK (`queue_version` >= 0),
    `started_at` TEXT NOT NULL,
    `last_occurred_at` TEXT NOT NULL,
    `last_received_at` TEXT NOT NULL,
    `start_position_ms` INTEGER NOT NULL CHECK (`start_position_ms` >= 0),
    `last_position_ms` INTEGER NOT NULL CHECK (`last_position_ms` >= 0),
    `listened_ms` INTEGER NOT NULL DEFAULT 0 CHECK (`listened_ms` >= 0),
    `threshold_ms` INTEGER NOT NULL CHECK (`threshold_ms` >= 1),
    `status` TEXT NOT NULL CHECK (`status` IN ('playing', 'paused', 'completed', 'stopped')),
    `counted_at` TEXT NULL,
    `cleared_at` TEXT NULL,
    `created_at` TEXT NOT NULL,
    `updated_at` TEXT NOT NULL, `reported_state` VARCHAR(16) NULL, `playback_rate` DECIMAL(6,3) NOT NULL DEFAULT 1,
    UNIQUE (user_id, player_id, playback_id)
);

CREATE TABLE playlist_covers (
    playlist_id TEXT PRIMARY KEY NOT NULL REFERENCES playlists(id) ON DELETE CASCADE,
    webp_bytes BLOB NOT NULL,
    content_sha256 TEXT NOT NULL CHECK (length(content_sha256) = 64),
    byte_size INTEGER NOT NULL CHECK (byte_size > 0 AND byte_size <= 2097152),
    width INTEGER NOT NULL DEFAULT 800 CHECK (width = 800),
    height INTEGER NOT NULL DEFAULT 800 CHECK (height = 800),
    updated_by TEXT NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
    updated_at TEXT NOT NULL
);

CREATE TABLE playlist_import_entries (
    playlist_id TEXT NOT NULL REFERENCES playlists(id) ON DELETE CASCADE,
    position INTEGER NOT NULL CHECK (position >= 0),
    status TEXT NOT NULL CHECK (status IN ('matched', 'unmatched', 'ambiguous', 'unsupported')),
    song_id TEXT NULL REFERENCES media_songs(id) ON DELETE SET NULL,
    candidate_count INTEGER NULL CHECK (candidate_count IS NULL OR (candidate_count >= 0 AND candidate_count <= 100)),
    reason_code TEXT NULL CHECK (reason_code IS NULL OR length(reason_code) BETWEEN 1 AND 64),
    created_at TEXT NOT NULL, source_title TEXT NULL CHECK (source_title IS NULL OR length(source_title) BETWEEN 1 AND 500), source_artists_json TEXT NULL CHECK (source_artists_json IS NULL OR length(source_artists_json) BETWEEN 2 AND 4000), source_album TEXT NULL CHECK (source_album IS NULL OR length(source_album) BETWEEN 1 AND 500),
    PRIMARY KEY (playlist_id, position)
);

CREATE TABLE playlist_import_idempotency (
    owner_user_id TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    key_digest TEXT NOT NULL CHECK (length(key_digest) = 64),
    request_digest TEXT NOT NULL CHECK (length(request_digest) = 64),
    playlist_id TEXT NOT NULL REFERENCES playlists(id) ON DELETE CASCADE,
    report_json TEXT NOT NULL CHECK (length(report_json) BETWEEN 2 AND 1048576),
    created_at TEXT NOT NULL,
    expires_at TEXT NOT NULL,
    PRIMARY KEY (owner_user_id, key_digest)
);

CREATE TABLE playlist_items (
    playlist_id TEXT NOT NULL REFERENCES playlists(id) ON DELETE CASCADE,
    position INTEGER NOT NULL CHECK (position >= 0),
    song_id TEXT NOT NULL REFERENCES media_songs(id) ON DELETE CASCADE,
    added_by_user_id TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    added_at TEXT NOT NULL,
    PRIMARY KEY (playlist_id, position)
);

CREATE TABLE playlist_m3u_sync_rules (
    playlist_id TEXT PRIMARY KEY NOT NULL REFERENCES playlists(id) ON DELETE CASCADE,
    source_id TEXT NOT NULL REFERENCES library_m3u_sources(id) ON DELETE RESTRICT,
    enabled INTEGER NOT NULL DEFAULT 1 CHECK (enabled IN (0, 1)),
    status TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'conflict', 'missing', 'invalid')),
    source_digest TEXT NULL CHECK (source_digest IS NULL OR length(source_digest) = 64),
    last_playlist_version INTEGER NOT NULL CHECK (last_playlist_version > 0),
    version INTEGER NOT NULL DEFAULT 1 CHECK (version > 0),
    report_json TEXT NULL CHECK (report_json IS NULL OR length(report_json) BETWEEN 2 AND 1048576),
    error_code TEXT NULL CHECK (error_code IS NULL OR length(error_code) BETWEEN 1 AND 80),
    last_checked_at TEXT NULL,
    last_synced_at TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE playlists (
    id TEXT PRIMARY KEY NOT NULL,
    owner_user_id TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    name TEXT NOT NULL,
    description TEXT NULL,
    visibility TEXT NOT NULL DEFAULT 'private' CHECK (visibility IN ('private', 'server')),
    song_count INTEGER NOT NULL DEFAULT 0 CHECK (song_count >= 0),
    duration_ms INTEGER NOT NULL DEFAULT 0 CHECK (duration_ms >= 0),
    version INTEGER NOT NULL DEFAULT 1 CHECK (version > 0),
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
, kind TEXT NOT NULL DEFAULT 'manual' CHECK (kind IN ('manual', 'smart')), scope TEXT NOT NULL DEFAULT 'user' CHECK (scope IN ('user', 'system')), source TEXT NOT NULL DEFAULT 'manual' CHECK (source IN ('manual', 'lastfm')), source_key TEXT NULL);

CREATE TABLE realtime_events (
    sequence INTEGER PRIMARY KEY AUTOINCREMENT,
    topic TEXT NOT NULL CHECK (topic IN ('notifications.changed', 'jobs.changed', 'permissions.changed')),
    audience_user_id TEXT NULL REFERENCES users(id) ON DELETE CASCADE,
    library_id TEXT NULL REFERENCES music_libraries(id) ON DELETE CASCADE,
    resource_version TEXT NOT NULL CHECK (length(resource_version) BETWEEN 1 AND 64),
    created_at TEXT NOT NULL,
    expires_at TEXT NOT NULL,
    CHECK (
        (topic = 'jobs.changed' AND audience_user_id IS NULL AND library_id IS NOT NULL)
        OR (topic IN ('notifications.changed', 'permissions.changed') AND audience_user_id IS NOT NULL)
    )
);

CREATE TABLE resource_download_jobs (
    id TEXT PRIMARY KEY NOT NULL,
    search_lease_id TEXT NOT NULL UNIQUE REFERENCES resource_search_result_leases(id) ON DELETE RESTRICT,
    requested_by TEXT NULL REFERENCES users(id) ON DELETE SET NULL,
    library_id TEXT NOT NULL REFERENCES music_libraries(id) ON DELETE RESTRICT,
    title TEXT NOT NULL,
    indexer TEXT NOT NULL,
    expected_size_bytes INTEGER NULL CHECK (expected_size_bytes IS NULL OR expected_size_bytes >= 0),
    import_mode TEXT NOT NULL CHECK (import_mode IN ('copy', 'move', 'hardlink', 'symlink', 'transcode')),
    transcode_keep_source INTEGER NOT NULL DEFAULT 0 CHECK (transcode_keep_source IN (0, 1)),    monitor_mode TEXT NOT NULL DEFAULT 'qbittorrent_api' CHECK (monitor_mode = 'qbittorrent_api'),
    cleanup_policy TEXT NOT NULL DEFAULT 'never' CHECK (cleanup_policy IN ('never', 'after_import', 'after_delay', 'after_seeding')),
    cleanup_delay_hours INTEGER NOT NULL DEFAULT 24 CHECK (cleanup_delay_hours BETWEEN 1 AND 8760),
    minimum_seed_time_hours INTEGER NOT NULL DEFAULT 72 CHECK (minimum_seed_time_hours BETWEEN 1 AND 8760),
    cleanup_status TEXT NOT NULL DEFAULT 'not_required' CHECK (cleanup_status IN ('not_required', 'pending', 'waiting', 'processing', 'retrying', 'succeeded')),
    cleanup_due_at TEXT NULL,
    cleanup_attempt INTEGER NOT NULL DEFAULT 0 CHECK (cleanup_attempt >= 0),
    cleanup_error_code TEXT NULL,
    cleanup_finished_at TEXT NULL,    status TEXT NOT NULL CHECK (status IN ('queued', 'submitting', 'downloading', 'importing', 'succeeded', 'failed')),
    download_ref_ciphertext TEXT NULL,
    torrent_hash_ciphertext TEXT NULL,
    progress_basis_points INTEGER NOT NULL DEFAULT 0 CHECK (progress_basis_points BETWEEN 0 AND 10000),
    downloaded_bytes INTEGER NOT NULL DEFAULT 0 CHECK (downloaded_bytes >= 0),
    total_bytes INTEGER NOT NULL DEFAULT 0 CHECK (total_bytes >= 0),
    speed_bytes_per_second INTEGER NOT NULL DEFAULT 0 CHECK (speed_bytes_per_second >= 0),
    eta_seconds INTEGER NULL CHECK (eta_seconds IS NULL OR eta_seconds >= 0),
    downloader_state TEXT NULL,
    imported_files INTEGER NOT NULL DEFAULT 0 CHECK (imported_files >= 0),
    scan_job_id TEXT NULL REFERENCES library_scan_jobs(id) ON DELETE SET NULL,
    error_code TEXT NULL,
    worker_id TEXT NULL,
    attempt INTEGER NOT NULL DEFAULT 0 CHECK (attempt >= 0),
    heartbeat_at TEXT NULL,
    started_at TEXT NULL,
    finished_at TEXT NULL,
    request_id TEXT NOT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
, transcode_format TEXT NULL CHECK (transcode_format IS NULL OR transcode_format IN ('opus', 'aac', 'mp3', 'flac')), transcode_bitrate_kbps INTEGER NULL CHECK (transcode_bitrate_kbps IS NULL OR transcode_bitrate_kbps IN (96, 128, 160, 192, 256, 320)));

CREATE TABLE resource_search_result_leases (
    id TEXT PRIMARY KEY NOT NULL,
    actor_id TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    title TEXT NOT NULL,
    indexer TEXT NOT NULL,
    size_bytes INTEGER NULL CHECK (size_bytes IS NULL OR size_bytes >= 0),
    download_ref_ciphertext TEXT NOT NULL,
    expires_at TEXT NOT NULL,
    created_at TEXT NOT NULL
);

CREATE TABLE role_capabilities (
    role_id TEXT NOT NULL REFERENCES roles(id) ON DELETE CASCADE,
    capability_key TEXT NOT NULL REFERENCES capabilities(capability_key) ON DELETE CASCADE,
    PRIMARY KEY (role_id, capability_key)
);

CREATE TABLE roles (
    id TEXT PRIMARY KEY NOT NULL,
    role_key TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    description TEXT NOT NULL,
    is_system INTEGER NOT NULL DEFAULT 1 CHECK (is_system IN (0, 1)),
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE scrape_asset_publications (
    id TEXT PRIMARY KEY NOT NULL,
    scrape_target_id TEXT NOT NULL REFERENCES metadata_sync_scrape_targets(id) ON DELETE CASCADE,
    song_id TEXT NULL REFERENCES media_songs(id) ON DELETE SET NULL,
    library_id TEXT NOT NULL REFERENCES music_libraries(id) ON DELETE RESTRICT,
    inventory_file_id TEXT NOT NULL REFERENCES library_file_inventory(id) ON DELETE RESTRICT,
    resource_kind TEXT NOT NULL CHECK (resource_kind IN ('lyrics', 'artwork')),
    source_record_id TEXT NOT NULL,
    source_sha256 TEXT NOT NULL CHECK (length(source_sha256) = 64),
    source_version INTEGER NOT NULL CHECK (source_version > 0),
    license_policy TEXT NOT NULL CHECK (
        license_policy IN ('local_controlled', 'display_only', 'cache_allowed', 'redistributable')
    ),
    storage_mode TEXT NOT NULL CHECK (storage_mode IN ('managed_cache', 'adjacent')),
    status TEXT NOT NULL CHECK (
        status IN ('queued', 'running', 'succeeded', 'skipped', 'conflict', 'failed')
    ),
    published_relative_path TEXT NULL,
    error_code TEXT NULL,
    attempt INTEGER NOT NULL DEFAULT 0 CHECK (attempt >= 0),
    worker_id TEXT NULL,
    heartbeat_at TEXT NULL,
    created_at TEXT NOT NULL,
    started_at TEXT NULL,
    finished_at TEXT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE (scrape_target_id, resource_kind, source_record_id)
);

CREATE TABLE scrobble_connections (
    id TEXT PRIMARY KEY NOT NULL,
    user_id TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    provider TEXT NOT NULL CHECK (provider IN ('lastfm', 'listenbrainz', 'maloja')),
    username TEXT NULL,
    endpoint_url TEXT NULL,
    credentials_ciphertext TEXT NOT NULL,
    enabled INTEGER NOT NULL DEFAULT 1 CHECK (enabled IN (0, 1)),
    version INTEGER NOT NULL DEFAULT 1 CHECK (version > 0),
    last_success_at TEXT NULL,
    last_failure_at TEXT NULL,
    last_error_code TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE (user_id, provider)
);

CREATE TABLE scrobble_delivery_jobs (
    id TEXT PRIMARY KEY NOT NULL,
    connection_id TEXT NOT NULL REFERENCES scrobble_connections(id) ON DELETE CASCADE,
    user_id TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    source_event_key TEXT NOT NULL,
    delivery_type TEXT NOT NULL CHECK (delivery_type IN ('now_playing', 'scrobble')),
    song_title TEXT NOT NULL,
    artist_name TEXT NOT NULL,
    album_title TEXT NULL,
    duration_ms INTEGER NOT NULL CHECK (duration_ms >= 0),
    occurred_at TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'queued' CHECK (status IN ('queued', 'running', 'succeeded', 'failed', 'cancelled')),
    attempt_count INTEGER NOT NULL DEFAULT 0 CHECK (attempt_count >= 0 AND attempt_count <= 5),
    next_attempt_at TEXT NOT NULL,
    worker_id TEXT NULL,
    claimed_at TEXT NULL,
    completed_at TEXT NULL,
    error_code TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE (connection_id, source_event_key, delivery_type)
);

CREATE TABLE "smart_playlist_definitions" (
    playlist_id TEXT PRIMARY KEY NOT NULL REFERENCES playlists(id) ON DELETE CASCADE,
    rule_json TEXT NOT NULL CHECK (length(rule_json) BETWEEN 2 AND 65536),
    sort_field TEXT NOT NULL CHECK (sort_field IN (
        'title', 'album', 'artist', 'release_year', 'duration_ms', 'bitrate',
        'play_count', 'last_played_at', 'added_at', 'random'
    )),
    sort_direction TEXT NOT NULL CHECK (sort_direction IN ('asc', 'desc')),
    result_limit INTEGER NOT NULL CHECK (result_limit BETWEEN 1 AND 500),
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE storage_cache_cleanup_runs (
    id TEXT PRIMARY KEY NOT NULL,
    cache_key TEXT NOT NULL CHECK (cache_key = 'transcodeSpool'),
    status TEXT NOT NULL CHECK (status IN ('succeeded', 'partial', 'failed')),
    planned_files INTEGER NOT NULL CHECK (planned_files >= 0),
    planned_bytes INTEGER NOT NULL CHECK (planned_bytes >= 0),
    deleted_files INTEGER NOT NULL CHECK (deleted_files >= 0),
    deleted_bytes INTEGER NOT NULL CHECK (deleted_bytes >= 0),
    skipped_files INTEGER NOT NULL CHECK (skipped_files >= 0),
    requested_by TEXT NULL REFERENCES users(id) ON DELETE SET NULL,
    created_at TEXT NOT NULL,
    finished_at TEXT NOT NULL
);

CREATE TABLE storage_governance_policy (
    id INTEGER PRIMARY KEY NOT NULL CHECK (id = 1),
    attention_free_percent INTEGER NOT NULL CHECK (attention_free_percent BETWEEN 2 AND 50),
    critical_free_percent INTEGER NOT NULL CHECK (critical_free_percent BETWEEN 1 AND 25),
    safety_reserve_bytes INTEGER NOT NULL CHECK (safety_reserve_bytes BETWEEN 67108864 AND 1099511627776),
    version INTEGER NOT NULL CHECK (version >= 1),
    updated_by TEXT NULL REFERENCES users(id) ON DELETE SET NULL,
    updated_at TEXT NOT NULL,
    CHECK (critical_free_percent < attention_free_percent)
);

CREATE TABLE storage_mount_baselines (
    root_key TEXT PRIMARY KEY NOT NULL CHECK (root_key IN (
        'library', 'incoming', 'result', 'transcodeCache', 'uploadStaging', 'database', 'backups'
    )),
    resolved_path TEXT NOT NULL,
    device_id TEXT NOT NULL,
    mount_point TEXT NOT NULL,
    filesystem_type TEXT NOT NULL,
    version INTEGER NOT NULL CHECK (version >= 1),
    confirmed_by TEXT NULL REFERENCES users(id) ON DELETE SET NULL,
    confirmed_at TEXT NOT NULL
);

CREATE TABLE system_error_events (
    id TEXT PRIMARY KEY,
    fingerprint TEXT NOT NULL UNIQUE,
    severity TEXT NOT NULL CHECK (severity IN ('error', 'critical')),
    source TEXT NOT NULL CHECK (source IN ('http', 'worker')),
    exception_class TEXT NULL,
    error_code TEXT NULL,
    message_summary TEXT NOT NULL,
    request_id TEXT NULL,
    job_id TEXT NULL,
    route_method TEXT NULL,
    route_path TEXT NULL,
    stack_json TEXT NOT NULL DEFAULT '[]',
    occurrence_count INTEGER NOT NULL DEFAULT 1 CHECK (occurrence_count >= 1),
    status TEXT NOT NULL DEFAULT 'open' CHECK (status IN ('open', 'resolved')),
    resolved_by TEXT NULL REFERENCES users(id) ON DELETE SET NULL,
    resolved_at TEXT NULL,
    version INTEGER NOT NULL DEFAULT 1 CHECK (version >= 1),
    first_occurred_at TEXT NOT NULL,
    last_occurred_at TEXT NOT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    CHECK ((status = 'open' AND resolved_at IS NULL) OR (status = 'resolved' AND resolved_at IS NOT NULL))
);

CREATE TABLE system_playlist_sync_rules (
    playlist_id TEXT PRIMARY KEY NOT NULL REFERENCES playlists(id) ON DELETE CASCADE,
    provider TEXT NOT NULL CHECK (provider IN ('lastfm')),
    preset TEXT NOT NULL CHECK (preset IN ('global', 'chinese', 'rock')),
    enabled INTEGER NOT NULL DEFAULT 1 CHECK (enabled IN (0, 1)),
    interval_seconds INTEGER NOT NULL DEFAULT 21600 CHECK (interval_seconds BETWEEN 3600 AND 604800),
    last_attempt_at TEXT NULL,
    last_success_at TEXT NULL,
    last_error_code TEXT NULL,
    next_sync_at TEXT NULL,
    version INTEGER NOT NULL DEFAULT 1 CHECK (version > 0),
    updated_by TEXT NULL REFERENCES users(id) ON DELETE SET NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE system_settings (
    setting_key TEXT PRIMARY KEY NOT NULL,
    value_json TEXT NOT NULL,
    version INTEGER NOT NULL DEFAULT 1 CHECK (version > 0),
    updated_by TEXT NULL REFERENCES users(id) ON DELETE SET NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE themes (
    id TEXT PRIMARY KEY NOT NULL,
    name TEXT NOT NULL,
    kind TEXT NOT NULL CHECK (kind IN ('builtin', 'custom')),
    status TEXT NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'published', 'disabled')),
    tokens_json TEXT NOT NULL,
    version INTEGER NOT NULL DEFAULT 1 CHECK (version > 0),
    created_by TEXT NULL REFERENCES users(id) ON DELETE SET NULL,
    updated_by TEXT NULL REFERENCES users(id) ON DELETE SET NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    deleted_at TEXT NULL
);

CREATE TABLE upload_chunks (
    file_id TEXT NOT NULL REFERENCES upload_files(id) ON DELETE CASCADE,
    byte_offset INTEGER NOT NULL CHECK (byte_offset >= 0),
    byte_length INTEGER NOT NULL CHECK (byte_length BETWEEN 1 AND 8388608),
    sha256 TEXT NOT NULL CHECK (length(sha256) = 64),
    received_at TEXT NOT NULL,
    PRIMARY KEY (file_id, byte_offset)
);

CREATE TABLE upload_files (
    id TEXT PRIMARY KEY NOT NULL,
    session_id TEXT NOT NULL REFERENCES upload_sessions(id) ON DELETE CASCADE,
    client_key TEXT NOT NULL CHECK (length(client_key) BETWEEN 1 AND 128),
    relative_path TEXT NOT NULL CHECK (length(relative_path) BETWEEN 1 AND 1024),
    extension TEXT NOT NULL CHECK (length(extension) BETWEEN 1 AND 16),
    media_kind TEXT NOT NULL CHECK (media_kind IN ('audio', 'image', 'lyrics', 'playlist', 'cue')),
    byte_size INTEGER NOT NULL CHECK (byte_size BETWEEN 1 AND 4294967296),
    received_bytes INTEGER NOT NULL DEFAULT 0 CHECK (received_bytes >= 0 AND received_bytes <= byte_size),
    expected_sha256 TEXT NULL CHECK (expected_sha256 IS NULL OR length(expected_sha256) = 64),
    content_sha256 TEXT NULL CHECK (content_sha256 IS NULL OR length(content_sha256) = 64),
    status TEXT NOT NULL CHECK (status IN (
        'pending', 'uploading', 'received', 'validating', 'complete', 'published', 'cancelled', 'failed'
    )),
    staging_device INTEGER NULL CHECK (staging_device IS NULL OR staging_device >= 0),
    staging_inode INTEGER NULL CHECK (staging_inode IS NULL OR staging_inode >= 0),
    staging_size INTEGER NULL CHECK (staging_size IS NULL OR staging_size >= 0),
    staging_modified_at INTEGER NULL CHECK (staging_modified_at IS NULL OR staging_modified_at >= 0),
    error_code TEXT NULL,
    version INTEGER NOT NULL DEFAULT 1 CHECK (version > 0),
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    completed_at TEXT NULL,
    published_at TEXT NULL,
    UNIQUE (session_id, client_key),
    UNIQUE (session_id, relative_path)
);

CREATE TABLE upload_sessions (
    id TEXT PRIMARY KEY NOT NULL,
    user_id TEXT NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
    library_id TEXT NOT NULL REFERENCES music_libraries(id) ON DELETE RESTRICT,
    status TEXT NOT NULL CHECK (status IN (
        'created', 'uploading', 'ready', 'publishing', 'completed', 'cancelled', 'expired', 'failed'
    )),
    total_files INTEGER NOT NULL CHECK (total_files BETWEEN 1 AND 200),
    total_bytes INTEGER NOT NULL CHECK (total_bytes BETWEEN 1 AND 21474836480),
    received_bytes INTEGER NOT NULL DEFAULT 0 CHECK (received_bytes >= 0 AND received_bytes <= total_bytes),
    completed_files INTEGER NOT NULL DEFAULT 0 CHECK (completed_files >= 0 AND completed_files <= total_files),
    published_files INTEGER NOT NULL DEFAULT 0 CHECK (published_files >= 0 AND published_files <= total_files),
    idempotency_digest TEXT NOT NULL CHECK (length(idempotency_digest) = 64),
    request_digest TEXT NOT NULL CHECK (length(request_digest) = 64),
    error_code TEXT NULL,
    attempt INTEGER NOT NULL DEFAULT 0 CHECK (attempt BETWEEN 0 AND 5),
    worker_id TEXT NULL,
    heartbeat_at TEXT NULL,
    started_at TEXT NULL,
    finished_at TEXT NULL,
    version INTEGER NOT NULL DEFAULT 1 CHECK (version > 0),
    expires_at TEXT NOT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    completed_at TEXT NULL,
    cancelled_at TEXT NULL,
    cancel_requested_at TEXT NULL,
    cancel_reason TEXT NULL CHECK (cancel_reason IS NULL OR length(cancel_reason) BETWEEN 1 AND 64),
    cleanup_state TEXT NULL CHECK (cleanup_state IS NULL OR cleanup_state IN ('pending', 'running', 'succeeded', 'failed')),
    cleanup_attempt INTEGER NOT NULL DEFAULT 0 CHECK (cleanup_attempt BETWEEN 0 AND 5),
    cleanup_error_code TEXT NULL CHECK (cleanup_error_code IS NULL OR length(cleanup_error_code) BETWEEN 1 AND 96),
    scan_status TEXT NULL CHECK (scan_status IS NULL OR scan_status IN ('pending', 'enqueued')),
    scan_job_id TEXT NULL REFERENCES library_scan_jobs(id) ON DELETE SET NULL,
    UNIQUE (user_id, idempotency_digest)
);

CREATE TABLE "user_album_preferences" (
    user_id TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    album_id TEXT NOT NULL REFERENCES media_albums(id) ON DELETE CASCADE,
    is_favorite INTEGER NOT NULL DEFAULT 0 CHECK (is_favorite IN (0, 1)),
    favorited_at TEXT NULL,
    updated_at TEXT NOT NULL,
    PRIMARY KEY (user_id, album_id),
    CHECK (is_favorite = 1),
    CHECK ((is_favorite = 1 AND favorited_at IS NOT NULL) OR (is_favorite = 0 AND favorited_at IS NULL))
);

CREATE TABLE "user_artist_preferences" (
    user_id TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    artist_id TEXT NOT NULL REFERENCES media_artists(id) ON DELETE CASCADE,
    is_favorite INTEGER NOT NULL DEFAULT 0 CHECK (is_favorite IN (0, 1)),
    favorited_at TEXT NULL,
    updated_at TEXT NOT NULL,
    PRIMARY KEY (user_id, artist_id),
    CHECK (is_favorite = 1),
    CHECK ((is_favorite = 1 AND favorited_at IS NOT NULL) OR (is_favorite = 0 AND favorited_at IS NULL))
);

CREATE TABLE user_avatars (
    user_id TEXT PRIMARY KEY NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    png_bytes BLOB NOT NULL,
    content_sha256 TEXT NOT NULL CHECK (length(content_sha256) = 64),
    byte_size INTEGER NOT NULL CHECK (byte_size > 0 AND byte_size <= 1048576),
    width INTEGER NOT NULL DEFAULT 256 CHECK (width = 256),
    height INTEGER NOT NULL DEFAULT 256 CHECK (height = 256),
    version INTEGER NOT NULL DEFAULT 1 CHECK (version > 0),
    updated_at TEXT NOT NULL
);

CREATE TABLE user_capabilities (
    user_id TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    capability_key TEXT NOT NULL REFERENCES capabilities(capability_key) ON DELETE CASCADE,
    assigned_by TEXT NULL REFERENCES users(id) ON DELETE SET NULL,
    assigned_at TEXT NOT NULL,
    PRIMARY KEY (user_id, capability_key)
);

CREATE TABLE user_dlna_devices (
    user_id TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    device_id TEXT NOT NULL CHECK (length(device_id) BETWEEN 6 AND 185),
    device_name TEXT NOT NULL CHECK (length(device_name) BETWEEN 1 AND 200),
    manufacturer TEXT NULL CHECK (manufacturer IS NULL OR length(manufacturer) <= 200),
    model TEXT NULL CHECK (model IS NULL OR length(model) <= 200),
    last_seen_at TEXT NOT NULL,
    last_used_at TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    PRIMARY KEY (user_id, device_id)
);

CREATE TABLE user_dlna_playback_states (
    user_id TEXT PRIMARY KEY NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    device_id TEXT NOT NULL CHECK (length(device_id) BETWEEN 6 AND 185),
    device_name TEXT NOT NULL CHECK (length(device_name) BETWEEN 1 AND 200),
    song_id TEXT NOT NULL REFERENCES media_songs(id) ON DELETE CASCADE,
    output_format TEXT NOT NULL CHECK (output_format IN ('raw', 'mp3', 'aac', 'opus')),
    duration_ms INTEGER NOT NULL CHECK (duration_ms >= 0),
    position_ms INTEGER NOT NULL CHECK (position_ms >= 0),
    transport_state TEXT NOT NULL CHECK (length(transport_state) BETWEEN 1 AND 40),
    volume INTEGER NULL CHECK (volume IS NULL OR volume BETWEEN 0 AND 100),
    state_version INTEGER NOT NULL DEFAULT 1 CHECK (state_version >= 1),
    observed_at TEXT NOT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE "user_dlna_preferences" (
    user_id TEXT PRIMARY KEY NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    preferred_format TEXT NOT NULL DEFAULT 'raw' CHECK (preferred_format IN ('raw', 'mp3', 'aac', 'opus')),
    updated_at TEXT NOT NULL
);

CREATE TABLE user_internet_radio_preferences (
    user_id TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    station_id TEXT NOT NULL REFERENCES internet_radio_stations(id) ON DELETE CASCADE,
    is_favorite INTEGER NOT NULL DEFAULT 0 CHECK (is_favorite IN (0, 1)),
    favorited_at TEXT NULL,
    updated_at TEXT NOT NULL,
    PRIMARY KEY (user_id, station_id)
);

CREATE TABLE user_notification_mutes (
    user_id TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    notification_type TEXT NOT NULL CHECK (notification_type IN ('scan')),
    muted_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    PRIMARY KEY (user_id, notification_type)
);

CREATE TABLE "user_notifications" (
    id TEXT PRIMARY KEY NOT NULL,
    user_id TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    notification_type TEXT NOT NULL CHECK (notification_type IN ('scan', 'scrape', 'security', 'permission', 'storage', 'destructive')),
    kind TEXT NOT NULL CHECK (kind IN ('scan.succeeded', 'scan.failed', 'scrape.awaiting_confirmation', 'security.login', 'permission.changed', 'storage.critical', 'destructive.failed')),
    severity TEXT NOT NULL CHECK (severity IN ('info', 'warning', 'error', 'critical')),
    library_id TEXT NULL REFERENCES music_libraries(id) ON DELETE SET NULL,
    object_type TEXT NULL CHECK (object_type IS NULL OR object_type IN ('library_scan_job', 'metadata_sync_scrape_job')),
    object_id TEXT NULL,
    context_json TEXT NOT NULL CHECK (length(context_json) BETWEEN 2 AND 4096),
    dedupe_key TEXT NOT NULL CHECK (length(dedupe_key) BETWEEN 1 AND 255),
    aggregate_count INTEGER NOT NULL DEFAULT 1 CHECK (aggregate_count >= 1),
    read_at TEXT NULL,
    expires_at TEXT NOT NULL,
    first_occurred_at TEXT NOT NULL,
    last_occurred_at TEXT NOT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE (user_id, dedupe_key)
);

CREATE TABLE user_player_profiles (
    id TEXT PRIMARY KEY NOT NULL,
    user_id TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    player_key TEXT NOT NULL,
    source TEXT NOT NULL CHECK (source IN ('web', 'subsonic')),
    name TEXT NOT NULL,
    preferred_format TEXT NULL CHECK (preferred_format IS NULL OR preferred_format IN ('raw', 'mp3', 'aac', 'opus')),
    max_bitrate_kbps INTEGER NULL CHECK (max_bitrate_kbps IS NULL OR max_bitrate_kbps IN (64, 96, 128, 160, 192, 256, 320)),
    last_seen_at TEXT NOT NULL,
    version INTEGER NOT NULL DEFAULT 1 CHECK (version > 0),
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE (user_id, player_key)
);

CREATE TABLE user_preferences (
    user_id TEXT PRIMARY KEY NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    theme_id TEXT NULL REFERENCES themes(id) ON DELETE SET NULL,
    locale TEXT NOT NULL DEFAULT 'zh-CN',
    timezone TEXT NOT NULL DEFAULT 'Asia/Shanghai',
    version INTEGER NOT NULL DEFAULT 1 CHECK (version > 0),
    updated_at TEXT NOT NULL
, stream_mode TEXT NOT NULL DEFAULT 'auto' CHECK (stream_mode IN ('direct', 'auto', 'transcode')), transcode_format TEXT NOT NULL DEFAULT 'opus' CHECK (transcode_format IN ('mp3', 'aac', 'opus')), max_bitrate_kbps INTEGER NOT NULL DEFAULT 192 CHECK (max_bitrate_kbps IN (64, 96, 128, 160, 192, 256, 320)), replaygain_mode TEXT NOT NULL DEFAULT 'off' CHECK (replaygain_mode IN ('off', 'track', 'album')), replaygain_prevent_clipping INTEGER NOT NULL DEFAULT 1 CHECK (replaygain_prevent_clipping IN (0, 1)), reduce_motion INTEGER NOT NULL DEFAULT 0 CHECK (reduce_motion IN (0, 1)));

CREATE TABLE user_roles (
    user_id TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    role_id TEXT NOT NULL REFERENCES roles(id) ON DELETE RESTRICT,
    assigned_by TEXT NULL REFERENCES users(id) ON DELETE SET NULL,
    assigned_at TEXT NOT NULL,
    PRIMARY KEY (user_id, role_id)
);

CREATE TABLE user_song_bookmarks (
    user_id TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    song_id TEXT NOT NULL REFERENCES media_songs(id) ON DELETE CASCADE,
    position_ms INTEGER NOT NULL CHECK (position_ms >= 0),
    comment TEXT NULL,
    version INTEGER NOT NULL DEFAULT 1 CHECK (version > 0),
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    PRIMARY KEY (user_id, song_id)
);

CREATE TABLE user_song_play_stats (
    user_id TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    song_id TEXT NOT NULL REFERENCES media_songs(id) ON DELETE CASCADE,
    play_count INTEGER NOT NULL DEFAULT 0 CHECK (play_count >= 0),
    last_played_at TEXT NULL,
    last_activity_at TEXT NOT NULL,
    last_position_ms INTEGER NOT NULL DEFAULT 0 CHECK (last_position_ms >= 0),
    updated_at TEXT NOT NULL,
    PRIMARY KEY (user_id, song_id)
);

CREATE TABLE "user_song_preferences" (
    user_id TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    song_id TEXT NOT NULL REFERENCES media_songs(id) ON DELETE CASCADE,
    is_favorite INTEGER NOT NULL DEFAULT 0 CHECK (is_favorite IN (0, 1)),
    favorited_at TEXT NULL,
    updated_at TEXT NOT NULL,
    PRIMARY KEY (user_id, song_id),
    CHECK (is_favorite = 1),
    CHECK ((is_favorite = 1 AND favorited_at IS NOT NULL) OR (is_favorite = 0 AND favorited_at IS NULL))
);

CREATE TABLE users (
    id TEXT PRIMARY KEY NOT NULL,
    username TEXT NOT NULL COLLATE NOCASE UNIQUE,
    display_name TEXT NOT NULL,
    email TEXT COLLATE NOCASE,
    password_hash TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'disabled', 'deleted')),
    is_super_admin INTEGER NOT NULL DEFAULT 0 CHECK (is_super_admin IN (0, 1)),
    locale TEXT NOT NULL DEFAULT 'zh-CN',
    timezone TEXT NOT NULL DEFAULT 'Asia/Shanghai',
    permission_version INTEGER NOT NULL DEFAULT 1 CHECK (permission_version > 0),
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    deleted_at TEXT NULL
, account_expires_at TEXT NULL, last_login_at TEXT NULL, subsonic_secret_ciphertext TEXT NULL);

CREATE TABLE webdav_library_connections (
    library_id TEXT PRIMARY KEY NOT NULL REFERENCES music_libraries(id) ON DELETE CASCADE,
    base_url TEXT NOT NULL,
    remote_root_path TEXT NOT NULL,
    username TEXT NOT NULL,
    password_ciphertext TEXT NOT NULL,
    verify_tls INTEGER NOT NULL DEFAULT 1 CHECK (verify_tls IN (0, 1)),
    last_verified_at TEXT NULL,
    last_error_code TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE (base_url, remote_root_path)
);

CREATE INDEX idx_album_artworks_source ON media_album_artworks(source_inventory_file_id, source_file_name);

CREATE INDEX idx_app_auth_codes_expiry ON app_authorization_codes(expires_at, consumed_at);

CREATE INDEX idx_app_token_families_active ON app_token_families(user_id, revoked_at, expires_at);

CREATE INDEX idx_app_token_families_user ON app_token_families(user_id, created_at DESC);

CREATE INDEX idx_app_tokens_expiry ON app_tokens(expires_at, revoked_at);

CREATE INDEX idx_app_tokens_family ON app_tokens(family_id, token_type, created_at DESC);

CREATE INDEX idx_artist_artworks_source ON media_artist_artworks(source_inventory_file_id, source_file_name);

CREATE INDEX idx_artwork_provider_candidate_job ON artwork_provider_candidates(search_job_id,provider_key,id);

CREATE UNIQUE INDEX idx_artwork_provider_import_active_album ON artwork_provider_import_jobs(album_id) WHERE album_id IS NOT NULL AND status IN ('queued','running');

CREATE UNIQUE INDEX idx_artwork_provider_import_active_artist ON artwork_provider_import_jobs(artist_id,library_id) WHERE artist_id IS NOT NULL AND status IN ('queued','running');

CREATE UNIQUE INDEX idx_artwork_provider_import_active_song ON artwork_provider_import_jobs(song_id) WHERE song_id IS NOT NULL AND status IN ('queued','running');

CREATE INDEX idx_artwork_provider_import_album ON artwork_provider_import_jobs(album_id,library_id,created_at DESC);

CREATE INDEX idx_artwork_provider_import_artist ON artwork_provider_import_jobs(artist_id,library_id,created_at DESC);

CREATE INDEX idx_artwork_provider_import_queue ON artwork_provider_import_jobs(status,next_attempt_at,created_at);

CREATE INDEX idx_artwork_provider_import_scrape_target ON artwork_provider_import_jobs(scrape_target_id,status);

CREATE INDEX idx_artwork_provider_import_song ON artwork_provider_import_jobs(song_id,library_id,created_at DESC);

CREATE UNIQUE INDEX idx_artwork_provider_search_active_album ON artwork_provider_search_jobs(album_id) WHERE album_id IS NOT NULL AND status IN ('queued','running');

CREATE UNIQUE INDEX idx_artwork_provider_search_active_artist ON artwork_provider_search_jobs(artist_id,library_id) WHERE artist_id IS NOT NULL AND status IN ('queued','running');

CREATE UNIQUE INDEX idx_artwork_provider_search_active_song ON artwork_provider_search_jobs(song_id) WHERE song_id IS NOT NULL AND status IN ('queued','running');

CREATE INDEX idx_artwork_provider_search_album ON artwork_provider_search_jobs(album_id,library_id,created_at DESC);

CREATE INDEX idx_artwork_provider_search_artist ON artwork_provider_search_jobs(artist_id,library_id,created_at DESC);

CREATE INDEX idx_artwork_provider_search_queue ON artwork_provider_search_jobs(status,next_attempt_at,created_at);

CREATE INDEX idx_artwork_provider_search_scrape_target ON artwork_provider_search_jobs(scrape_target_id,status);

CREATE INDEX idx_artwork_provider_search_song ON artwork_provider_search_jobs(song_id,library_id,created_at DESC);

CREATE UNIQUE INDEX idx_artwork_selection_album ON media_artwork_selection_overrides(album_id) WHERE album_id IS NOT NULL;

CREATE UNIQUE INDEX idx_artwork_selection_artist ON media_artwork_selection_overrides(artist_id,library_id) WHERE artist_id IS NOT NULL;

CREATE UNIQUE INDEX idx_artwork_selection_song ON media_artwork_selection_overrides(song_id) WHERE song_id IS NOT NULL;

CREATE INDEX idx_audio_tag_batch_actor ON audio_tag_writeback_batch_plans(requested_by,created_at DESC);

CREATE INDEX idx_audio_tag_batch_queue ON audio_tag_writeback_batch_plans(status,created_at,id);

CREATE INDEX idx_audio_tag_batch_target_library ON audio_tag_writeback_batch_targets(library_id,status);

CREATE INDEX idx_audio_tag_batch_target_work ON audio_tag_writeback_batch_targets(batch_plan_id,status,position);

CREATE INDEX idx_audio_tag_job_queue ON audio_tag_writeback_jobs(status,created_at);

CREATE INDEX idx_audio_tag_job_scope ON audio_tag_writeback_jobs(library_id,created_at DESC);

CREATE INDEX idx_audio_tag_plan_scope ON audio_tag_writeback_plans(library_id,status,created_at DESC);

CREATE INDEX idx_audio_tag_plan_song ON audio_tag_writeback_plans(song_id,created_at DESC);

CREATE INDEX idx_audit_logs_actor ON audit_logs(actor_user_id, created_at);

CREATE INDEX idx_audit_logs_created_at ON audit_logs(created_at);

CREATE INDEX idx_auth_sessions_user_active ON auth_sessions(user_id, expires_at) WHERE revoked_at IS NULL;

CREATE INDEX idx_dlna_tickets_device ON dlna_playback_tickets(device_digest, expires_at);

CREATE INDEX idx_dlna_tickets_expiry ON dlna_playback_tickets(expires_at);

CREATE INDEX idx_dlna_tickets_user ON dlna_playback_tickets(user_id, created_at);

CREATE INDEX idx_duplicate_media_decisions_kind ON duplicate_media_decisions(evidence_kind, updated_at);

CREATE INDEX idx_external_tickets_expiry ON external_playback_tickets(expires_at, revoked_at);

CREATE INDEX idx_external_tickets_start ON external_playback_tickets(starts_before, started_at);

CREATE INDEX idx_external_tickets_user ON external_playback_tickets(user_id, created_at DESC);

CREATE INDEX idx_internet_radio_stations_name ON internet_radio_stations(enabled, name, id);

CREATE INDEX idx_inventory_metadata ON library_file_inventory(library_id, metadata_status, last_seen_scan_job_id);

CREATE INDEX idx_library_grants_user ON library_user_grants(user_id, access_level);

CREATE INDEX idx_library_inventory_acoustic ON library_file_inventory(acoustic_fingerprint_status, acoustic_fingerprint_sha256, status);

CREATE INDEX idx_library_inventory_byte_hash ON library_file_inventory(byte_hash_status, byte_sha256, status);

CREATE INDEX idx_library_inventory_identity ON library_file_inventory(library_id, device_id, inode);

CREATE INDEX idx_library_inventory_status ON library_file_inventory(library_id, status, relative_path);

CREATE INDEX idx_library_m3u_sources_scope ON library_m3u_sources(library_id, status, display_name, id);

CREATE INDEX idx_library_scan_jobs_queue ON library_scan_jobs(status, created_at);

CREATE INDEX idx_library_scan_jobs_scope ON library_scan_jobs(library_id, status, created_at DESC);

CREATE INDEX idx_login_attempts_identity_time ON auth_login_attempts(identity_hash, attempted_at);

CREATE INDEX idx_lyrics_audio_tag_batch_actor ON lyrics_audio_tag_writeback_batch_plans(requested_by,created_at DESC);

CREATE INDEX idx_lyrics_audio_tag_batch_queue ON lyrics_audio_tag_writeback_batch_plans(status,created_at,id);

CREATE INDEX idx_lyrics_audio_tag_batch_target_library ON lyrics_audio_tag_writeback_batch_targets(library_id,status);

CREATE INDEX idx_lyrics_audio_tag_batch_target_work ON lyrics_audio_tag_writeback_batch_targets(batch_plan_id,status,position);

CREATE INDEX idx_lyrics_audio_tag_job_queue ON lyrics_audio_tag_writeback_jobs(status,created_at);

CREATE INDEX idx_lyrics_audio_tag_job_scope ON lyrics_audio_tag_writeback_jobs(library_id,created_at DESC);

CREATE INDEX idx_lyrics_audio_tag_plan_scope ON lyrics_audio_tag_writeback_plans(library_id,status,created_at DESC);

CREATE INDEX idx_lyrics_audio_tag_plan_song ON lyrics_audio_tag_writeback_plans(song_id,created_at DESC);

CREATE INDEX idx_lyrics_batch_actor ON lyrics_writeback_batch_plans(requested_by, created_at DESC);

CREATE INDEX idx_lyrics_batch_queue ON lyrics_writeback_batch_plans(status, created_at, id);

CREATE INDEX idx_lyrics_batch_target_library ON lyrics_writeback_batch_targets(library_id, status);

CREATE INDEX idx_lyrics_batch_target_work ON lyrics_writeback_batch_targets(batch_plan_id, status, position);

CREATE INDEX idx_lyrics_writeback_job_queue ON lyrics_writeback_jobs(status, created_at);

CREATE INDEX idx_lyrics_writeback_job_scope ON lyrics_writeback_jobs(library_id, created_at DESC);

CREATE INDEX idx_lyrics_writeback_plan_scope ON lyrics_writeback_plans(library_id, status, created_at DESC);

CREATE INDEX idx_lyrics_writeback_plan_song ON lyrics_writeback_plans(song_id, created_at DESC);

CREATE INDEX idx_lyrics_writeback_scan_queue ON lyrics_writeback_scan_requests(status, first_requested_at);

CREATE INDEX idx_manual_artwork_album ON media_manual_artwork_candidates(album_id,library_id,created_at DESC);

CREATE INDEX idx_manual_artwork_artist ON media_manual_artwork_candidates(artist_id,library_id,created_at DESC);

CREATE INDEX idx_manual_artwork_song ON media_manual_artwork_candidates(song_id,library_id,created_at DESC);

CREATE INDEX idx_media_album_artists_artist ON media_album_artists(artist_id, album_id);

CREATE INDEX idx_media_album_metadata_field_states_source ON media_album_metadata_field_states(effective_source, is_locked, updated_at DESC);

CREATE INDEX idx_media_albums_library_search ON media_albums(library_id, normalized_title, id);

CREATE INDEX idx_media_albums_library_title ON media_albums(library_id, title, id);

CREATE INDEX idx_media_artist_metadata_field_states_source ON media_artist_metadata_field_states(effective_source, is_locked, updated_at DESC);

CREATE INDEX idx_media_lyrics_parse_error_song ON media_lyrics_parse_diagnostics(song_id, updated_at);

CREATE INDEX idx_media_lyrics_song_priority ON media_lyrics(song_id, priority DESC, language, id);

CREATE INDEX idx_media_song_artists_artist ON media_song_artists(artist_id, song_id);

CREATE INDEX idx_media_song_genres_genre ON media_song_genres(genre_id, song_id);

CREATE INDEX idx_media_songs_album_track ON media_songs(album_id, disc_number, track_number, title);

CREATE INDEX idx_media_songs_library_search ON media_songs(library_id, normalized_title, id);

CREATE INDEX idx_media_songs_library_title ON media_songs(library_id, title, id);

CREATE INDEX idx_metadata_batch_actor ON metadata_batch_plans(requested_by, created_at DESC);

CREATE INDEX idx_metadata_batch_queue ON metadata_batch_plans(status, created_at, id);

CREATE INDEX idx_metadata_batch_targets_library ON metadata_batch_targets(library_id, status);

CREATE INDEX idx_metadata_batch_targets_work ON metadata_batch_targets(plan_id, status, song_id);

CREATE INDEX idx_metadata_change_items_object ON metadata_change_items(object_type, object_id, created_at DESC);

CREATE INDEX idx_metadata_change_items_set ON metadata_change_items(change_set_id, result, id);

CREATE INDEX idx_metadata_change_sets_created ON metadata_change_sets(created_at DESC, id DESC);

CREATE INDEX idx_metadata_change_sets_task ON metadata_change_sets(task_id, status);

CREATE INDEX idx_metadata_fields_source ON media_metadata_field_states(effective_source, is_locked, updated_at DESC);

CREATE INDEX idx_metadata_operations_created ON metadata_entity_operations(created_at DESC, id DESC);

CREATE INDEX idx_metadata_operations_entity ON metadata_entity_operations(entity_type, source_entity_id, status, created_at DESC);

CREATE UNIQUE INDEX idx_metadata_redirects_active_source ON metadata_entity_redirects(entity_type, source_entity_id) WHERE status = 'active';

CREATE INDEX idx_metadata_redirects_target ON metadata_entity_redirects(entity_type, target_entity_id, status);

CREATE INDEX idx_metadata_sync_scrape_channels_target ON metadata_sync_scrape_channel_results(target_id, position);

CREATE INDEX idx_metadata_sync_scrape_confirmation ON metadata_sync_scrape_targets(awaiting_confirmation, job_id);

CREATE UNIQUE INDEX idx_metadata_sync_scrape_history_requeue ON metadata_sync_scrape_jobs(requested_by, request_id) WHERE request_id LIKE 'scrape-history-requeue:%';

CREATE INDEX idx_metadata_sync_scrape_jobs_status ON metadata_sync_scrape_jobs(status, created_at);

CREATE INDEX idx_metadata_sync_scrape_targets_claim ON metadata_sync_scrape_targets(status, worker_id, created_at);

CREATE INDEX idx_metadata_sync_scrape_targets_job ON metadata_sync_scrape_targets(job_id, position);

CREATE INDEX idx_metadata_sync_scrape_targets_library ON metadata_sync_scrape_targets(library_id, job_id);

CREATE INDEX idx_metadata_sync_scrape_targets_stage ON metadata_sync_scrape_targets(status,next_attempt_at,job_id,position);

CREATE INDEX idx_metadata_writeback_scan_queue ON metadata_writeback_scan_requests(status,first_requested_at);

CREATE UNIQUE INDEX idx_music_libraries_inbox_path ON music_libraries(inbox_path) WHERE inbox_path IS NOT NULL;

CREATE UNIQUE INDEX idx_music_libraries_resolved_inbox ON music_libraries(resolved_inbox_path) WHERE resolved_inbox_path IS NOT NULL;

CREATE INDEX idx_music_libraries_source ON music_libraries(source_type, status);

CREATE INDEX idx_music_libraries_status ON music_libraries(status, name);

CREATE INDEX idx_music_sources_dispatch ON music_sources(enabled, priority, source_key);

CREATE INDEX idx_personal_access_tokens_active ON personal_access_tokens(user_id, revoked_at, expires_at);

CREATE INDEX idx_personal_access_tokens_user_created ON personal_access_tokens(user_id, created_at DESC, id DESC);

CREATE UNIQUE INDEX idx_personal_exports_one_active
ON personal_data_export_jobs(user_id)
WHERE status IN ('queued', 'running', 'cancel_requested');

CREATE INDEX idx_personal_exports_user_created ON personal_data_export_jobs(user_id, created_at DESC, id DESC);

CREATE INDEX idx_personal_exports_worker ON personal_data_export_jobs(status, updated_at, id);

CREATE INDEX idx_play_queue_items_song ON play_queue_items(song_id, queue_id);

CREATE INDEX idx_playback_events_retention ON playback_events(received_at, id);

CREATE INDEX idx_playback_events_session ON playback_events(session_id, received_at);

CREATE INDEX idx_playback_leases_expiry ON playback_leases(expires_at);

CREATE INDEX idx_playback_leases_user_expiry ON playback_leases(user_id, expires_at);

CREATE INDEX idx_playback_sessions_current ON playback_sessions(user_id, status, updated_at);

CREATE INDEX idx_playback_sessions_history ON playback_sessions(user_id, cleared_at, updated_at, id);

CREATE INDEX idx_playback_sessions_song ON playback_sessions(song_id, user_id, updated_at);

CREATE INDEX idx_playlist_import_entries_song ON playlist_import_entries(song_id);

CREATE INDEX idx_playlist_import_idempotency_expiry ON playlist_import_idempotency(expires_at);

CREATE INDEX idx_playlist_items_song ON playlist_items(song_id, playlist_id);

CREATE INDEX idx_playlist_m3u_sync_source ON playlist_m3u_sync_rules(source_id, enabled, status);

CREATE INDEX idx_playlists_kind_owner ON playlists(kind, owner_user_id, updated_at, id);

CREATE INDEX idx_playlists_owner ON playlists(owner_user_id, updated_at, id);

CREATE INDEX idx_playlists_scope_updated ON playlists(scope, updated_at, id);

CREATE INDEX idx_playlists_visibility ON playlists(visibility, updated_at, id);

CREATE INDEX idx_realtime_events_expiry ON realtime_events(expires_at, sequence);

CREATE INDEX idx_realtime_events_library ON realtime_events(library_id, sequence);

CREATE INDEX idx_realtime_events_user ON realtime_events(audience_user_id, sequence);

CREATE INDEX idx_resource_download_jobs_cleanup ON resource_download_jobs(cleanup_status, cleanup_due_at);

CREATE INDEX idx_resource_download_jobs_created ON resource_download_jobs(created_at DESC);

CREATE INDEX idx_resource_download_jobs_library ON resource_download_jobs(library_id, created_at DESC);

CREATE INDEX idx_resource_download_jobs_queue ON resource_download_jobs(status, updated_at);

CREATE INDEX idx_resource_search_leases_expiry ON resource_search_result_leases(expires_at);

CREATE INDEX idx_scan_file_results_list ON library_scan_file_results(scan_job_id, file_name, inventory_file_id);

CREATE INDEX idx_scan_file_results_status ON library_scan_file_results(scan_job_id, result_kind, lyrics_export_status);

CREATE INDEX idx_scrape_asset_publications_claim ON scrape_asset_publications(status, worker_id, created_at);

CREATE INDEX idx_scrape_asset_publications_source ON scrape_asset_publications(resource_kind, source_record_id);

CREATE INDEX idx_scrape_asset_publications_target ON scrape_asset_publications(scrape_target_id, resource_kind);

CREATE INDEX idx_scrobble_connections_user ON scrobble_connections(user_id, enabled, provider);

CREATE INDEX idx_scrobble_delivery_claim ON scrobble_delivery_jobs(status, next_attempt_at, created_at, id);

CREATE INDEX idx_scrobble_delivery_connection ON scrobble_delivery_jobs(connection_id, status, created_at);

CREATE INDEX idx_scrobble_delivery_user_failure ON scrobble_delivery_jobs(user_id, status, completed_at, id);

CREATE INDEX idx_storage_cache_cleanup_runs_finished_at ON storage_cache_cleanup_runs(finished_at DESC);

CREATE INDEX idx_storage_mount_baselines_confirmed_by ON storage_mount_baselines(confirmed_by);

CREATE INDEX idx_system_error_request ON system_error_events(request_id);

CREATE INDEX idx_system_error_severity_last ON system_error_events(severity, last_occurred_at DESC);

CREATE INDEX idx_system_error_status_last ON system_error_events(status, last_occurred_at DESC);

CREATE INDEX idx_system_playlist_sync_due ON system_playlist_sync_rules(provider, enabled, next_sync_at, playlist_id);

CREATE INDEX idx_themes_status ON themes(status);

CREATE INDEX idx_upload_files_session_status ON upload_files(session_id, status, id);

CREATE INDEX idx_upload_sessions_cleanup ON upload_sessions(cleanup_state, updated_at) WHERE cleanup_state IN ('pending', 'running');

CREATE INDEX idx_upload_sessions_library_created ON upload_sessions(library_id, created_at DESC);

CREATE INDEX idx_upload_sessions_owner_created ON upload_sessions(user_id, created_at DESC);

CREATE INDEX idx_upload_sessions_scan ON upload_sessions(scan_status, library_id, updated_at) WHERE scan_status = 'pending';

CREATE INDEX idx_user_album_preferences_favorite ON user_album_preferences(user_id, is_favorite, favorited_at, album_id);

CREATE INDEX idx_user_artist_preferences_favorite ON user_artist_preferences(user_id, is_favorite, favorited_at, artist_id);

CREATE INDEX idx_user_capabilities_capability ON user_capabilities(capability_key, user_id);

CREATE INDEX idx_user_dlna_devices_recent ON user_dlna_devices(user_id, last_used_at, last_seen_at);

CREATE INDEX idx_user_dlna_playback_song ON user_dlna_playback_states(song_id);

CREATE INDEX idx_user_notifications_inbox ON user_notifications(user_id, last_occurred_at DESC, id DESC);

CREATE INDEX idx_user_notifications_unread ON user_notifications(user_id, read_at, expires_at);

CREATE INDEX idx_user_player_profiles_recent ON user_player_profiles(user_id, last_seen_at, id);

CREATE INDEX idx_user_radio_favorites ON user_internet_radio_preferences(user_id, is_favorite, favorited_at DESC);

CREATE INDEX idx_user_roles_role ON user_roles(role_id, user_id);

CREATE INDEX idx_user_song_bookmarks_recent ON user_song_bookmarks(user_id, updated_at DESC, song_id);

CREATE INDEX idx_user_song_bookmarks_song ON user_song_bookmarks(song_id, user_id);

CREATE INDEX idx_user_song_play_stats_count ON user_song_play_stats(user_id, play_count, song_id);

CREATE INDEX idx_user_song_play_stats_recent ON user_song_play_stats(user_id, last_activity_at, song_id);

CREATE INDEX idx_user_song_preferences_favorite ON user_song_preferences(user_id, is_favorite, favorited_at, song_id);

CREATE INDEX idx_users_status ON users(status);

CREATE INDEX onedrive_device_authorizations_actor_created_idx ON onedrive_device_authorizations (actor_user_id, created_at);

CREATE INDEX onedrive_device_authorizations_expiry_idx ON onedrive_device_authorizations (status, expires_at);

CREATE UNIQUE INDEX uq_audio_tag_batch_idempotency
ON audio_tag_writeback_batch_plans(requested_by,idempotency_key_sha256)
WHERE idempotency_key_sha256 IS NOT NULL;

CREATE UNIQUE INDEX uq_library_scan_jobs_one_active
ON library_scan_jobs(library_id)
WHERE status IN ('queued', 'running', 'cancel_requested');

CREATE UNIQUE INDEX uq_lyrics_audio_tag_batch_idempotency
ON lyrics_audio_tag_writeback_batch_plans(requested_by,idempotency_key_sha256)
WHERE idempotency_key_sha256 IS NOT NULL;

CREATE UNIQUE INDEX uq_lyrics_batch_idempotency
ON lyrics_writeback_batch_plans(requested_by, idempotency_key_sha256)
WHERE idempotency_key_sha256 IS NOT NULL;

CREATE UNIQUE INDEX uq_playlists_system_source ON playlists(scope, source, source_key) WHERE scope = 'system' AND source_key IS NOT NULL;

INSERT INTO "themes" ("id", "name", "kind", "status", "tokens_json", "version", "created_by", "updated_by", "created_at", "updated_at", "deleted_at") VALUES ('contrast', '高对比蓝', 'builtin', 'published', '{"color":"#005FCC"}', '3', NULL, NULL, '2026-08-10T00:00:00Z', '2026-08-10T00:00:00Z', NULL);

INSERT INTO "themes" ("id", "name", "kind", "status", "tokens_json", "version", "created_by", "updated_by", "created_at", "updated_at", "deleted_at") VALUES ('coral', '珊瑚红', 'builtin', 'published', '{"color":"#B63D34"}', '3', NULL, NULL, '2026-08-10T00:00:00Z', '2026-08-10T00:00:00Z', NULL);

INSERT INTO "themes" ("id", "name", "kind", "status", "tokens_json", "version", "created_by", "updated_by", "created_at", "updated_at", "deleted_at") VALUES ('gold', '鎏金', 'builtin', 'published', '{"color":"#785600"}', '3', NULL, NULL, '2026-08-10T00:00:00Z', '2026-08-10T00:00:00Z', NULL);

INSERT INTO "themes" ("id", "name", "kind", "status", "tokens_json", "version", "created_by", "updated_by", "created_at", "updated_at", "deleted_at") VALUES ('jade', '翡翠绿', 'builtin', 'published', '{"color":"#087F5B"}', '3', NULL, NULL, '2026-08-10T00:00:00Z', '2026-08-10T00:00:00Z', NULL);

INSERT INTO "themes" ("id", "name", "kind", "status", "tokens_json", "version", "created_by", "updated_by", "created_at", "updated_at", "deleted_at") VALUES ('rose', '玫瑰', 'builtin', 'published', '{"color":"#A52C5B"}', '3', NULL, NULL, '2026-08-10T00:00:00Z', '2026-08-10T00:00:00Z', NULL);

INSERT INTO "themes" ("id", "name", "kind", "status", "tokens_json", "version", "created_by", "updated_by", "created_at", "updated_at", "deleted_at") VALUES ('velin', 'Velin 蓝', 'builtin', 'published', '{"color":"#315FD6"}', '3', NULL, NULL, '2026-08-10T00:00:00Z', '2026-08-10T00:00:00Z', NULL);

INSERT INTO "system_settings" ("setting_key", "value_json", "version", "updated_by", "updated_at") VALUES ('integration.jackett', '{"enabled":false,"baseUrl":"","apiKeyCiphertext":null}', '1', NULL, '2026-08-10T00:00:00Z');

INSERT INTO "system_settings" ("setting_key", "value_json", "version", "updated_by", "updated_at") VALUES ('integration.qbittorrent', '{"enabled":false,"baseUrl":"","username":"","passwordCiphertext":null,"defaultImportMode":"copy","monitorMode":"qbittorrent_api","cleanupPolicy":"never","cleanupDelayHours":24,"minimumSeedTimeHours":72,"transcodeFormat":"opus","transcodeBitrateKbps":192}', '4', NULL, '2026-08-10T00:00:00Z');

INSERT INTO "system_settings" ("setting_key", "value_json", "version", "updated_by", "updated_at") VALUES ('network.proxy', '{"enabled":false,"scheme":"http","host":"","port":7890,"username":"","passwordCiphertext":null}', '1', NULL, '2026-08-10T00:00:00Z');

INSERT INTO "system_settings" ("setting_key", "value_json", "version", "updated_by", "updated_at") VALUES ('recommendation.lastfm', '{"enabled":false,"apiKeyCiphertext":null,"version":1,"lastRefreshAt":null,"lastErrorCode":null,"playlistCount":0}', '1', NULL, '2026-08-10T00:00:00Z');

INSERT INTO "system_settings" ("setting_key", "value_json", "version", "updated_by", "updated_at") VALUES ('site.basic', '{"siteName":"Velin Music","defaultLocale":"zh-CN","defaultTimezone":"Asia\/Shanghai","defaultPageSize":50}', '1', NULL, '2026-08-10T00:00:00Z');

INSERT INTO "system_settings" ("setting_key", "value_json", "version", "updated_by", "updated_at") VALUES ('system.limits', '{"maxConcurrentStreams":4,"maxConcurrentTranscodes":2,"maxTranscodeBitrateKbps":320,"downloadAllowed":true,"maxHighCostJobs":2}', '2', NULL, '2026-08-10T00:00:00Z');

INSERT INTO "roles" ("id", "role_key", "name", "description", "is_system", "created_at", "updated_at") VALUES ('01KYKW00000000000000000001', 'administrator', '管理员', '管理用户、音乐库、存储和媒体工作流，不含系统与恢复权限。', '1', '2026-08-10T00:00:00Z', '2026-08-10T00:00:00Z');

INSERT INTO "roles" ("id", "role_key", "name", "description", "is_system", "created_at", "updated_at") VALUES ('01KYKW00000000000000000002', 'listener', '听众', '浏览、播放、下载、分享和管理个人播放列表。', '1', '2026-08-10T00:00:00Z', '2026-08-10T00:00:00Z');

INSERT INTO "capabilities" ("capability_key", "description") VALUES ('cast', '控制局域网 DLNA 音响');

INSERT INTO "capabilities" ("capability_key", "description") VALUES ('create_playlist', '创建和编辑个人播放列表');

INSERT INTO "capabilities" ("capability_key", "description") VALUES ('download', '下载已授权媒体');

INSERT INTO "capabilities" ("capability_key", "description") VALUES ('edit_metadata', '编辑获授权媒体元数据');

INSERT INTO "capabilities" ("capability_key", "description") VALUES ('jukebox', '控制服务器播放设备');

INSERT INTO "capabilities" ("capability_key", "description") VALUES ('manage_library', '管理获授权音乐库');

INSERT INTO "capabilities" ("capability_key", "description") VALUES ('manage_storage', '管理登记存储与上传会话');

INSERT INTO "capabilities" ("capability_key", "description") VALUES ('manage_system', '管理系统级设置与维护状态');

INSERT INTO "capabilities" ("capability_key", "description") VALUES ('manage_users', '管理非超级管理员账户');

INSERT INTO "capabilities" ("capability_key", "description") VALUES ('play', '播放已授权媒体');

INSERT INTO "capabilities" ("capability_key", "description") VALUES ('run_scrape', '运行获授权刮削与整理任务');

INSERT INTO "capabilities" ("capability_key", "description") VALUES ('transcode', '使用服务端转码');

INSERT INTO "capabilities" ("capability_key", "description") VALUES ('view_audit', '查看权限范围内审计记录');

INSERT INTO "capabilities" ("capability_key", "description") VALUES ('view_play_privacy', '查看获授权的用户播放活动');

INSERT INTO "role_capabilities" ("role_id", "capability_key") VALUES ('01KYKW00000000000000000001', 'cast');

INSERT INTO "role_capabilities" ("role_id", "capability_key") VALUES ('01KYKW00000000000000000001', 'create_playlist');

INSERT INTO "role_capabilities" ("role_id", "capability_key") VALUES ('01KYKW00000000000000000001', 'download');

INSERT INTO "role_capabilities" ("role_id", "capability_key") VALUES ('01KYKW00000000000000000001', 'edit_metadata');

INSERT INTO "role_capabilities" ("role_id", "capability_key") VALUES ('01KYKW00000000000000000001', 'jukebox');

INSERT INTO "role_capabilities" ("role_id", "capability_key") VALUES ('01KYKW00000000000000000001', 'manage_library');

INSERT INTO "role_capabilities" ("role_id", "capability_key") VALUES ('01KYKW00000000000000000001', 'manage_storage');

INSERT INTO "role_capabilities" ("role_id", "capability_key") VALUES ('01KYKW00000000000000000001', 'manage_users');

INSERT INTO "role_capabilities" ("role_id", "capability_key") VALUES ('01KYKW00000000000000000001', 'play');

INSERT INTO "role_capabilities" ("role_id", "capability_key") VALUES ('01KYKW00000000000000000001', 'run_scrape');

INSERT INTO "role_capabilities" ("role_id", "capability_key") VALUES ('01KYKW00000000000000000001', 'transcode');

INSERT INTO "role_capabilities" ("role_id", "capability_key") VALUES ('01KYKW00000000000000000001', 'view_audit');

INSERT INTO "role_capabilities" ("role_id", "capability_key") VALUES ('01KYKW00000000000000000001', 'view_play_privacy');

INSERT INTO "role_capabilities" ("role_id", "capability_key") VALUES ('01KYKW00000000000000000002', 'create_playlist');

INSERT INTO "role_capabilities" ("role_id", "capability_key") VALUES ('01KYKW00000000000000000002', 'download');

INSERT INTO "role_capabilities" ("role_id", "capability_key") VALUES ('01KYKW00000000000000000002', 'play');

INSERT INTO "role_capabilities" ("role_id", "capability_key") VALUES ('01KYKW00000000000000000002', 'transcode');

INSERT INTO "music_libraries" ("id", "name", "root_path", "resolved_root_path", "status", "symlink_policy", "default_locale", "scan_mode", "scan_status", "artist_count", "album_count", "song_count", "last_scanned_at", "version", "created_by", "updated_by", "created_at", "updated_at", "inbox_path", "resolved_inbox_path", "import_approval_mode", "import_stability_seconds", "scrape_storage_mode", "source_type") VALUES ('01KYM200000000000000000001', '默认音乐库', '/media/library', '/media/library', 'active', 'ignore', 'zh-CN', 'manual', 'never_scanned', '0', '0', '0', NULL, '1', NULL, NULL, '2026-08-10T00:00:00Z', '2026-08-10T00:00:00Z', '/media/work/result', '/media/work/result', 'manual', '60', 'managed_cache', 'local');

INSERT INTO "storage_governance_policy" ("id", "attention_free_percent", "critical_free_percent", "safety_reserve_bytes", "version", "updated_by", "updated_at") VALUES ('1', '10', '5', '536870912', '1', NULL, '2026-08-10T00:00:00Z');

INSERT INTO "music_sources" ("source_key", "display_name", "enabled", "priority", "capabilities_json", "implementation_status", "version", "created_at", "updated_at", "use_proxy") VALUES ('apple_music', 'Apple Music', '1', '70', '["songs","albums","artwork"]', 'partial', '1', '2026-08-10T00:00:00Z', '2026-08-10T00:00:00Z', '0');

INSERT INTO "music_sources" ("source_key", "display_name", "enabled", "priority", "capabilities_json", "implementation_status", "version", "created_at", "updated_at", "use_proxy") VALUES ('kugou', '酷狗音乐', '1', '30', '["songs","albums","artwork","lyrics"]', 'available', '1', '2026-08-10T00:00:00Z', '2026-08-10T00:00:00Z', '0');

INSERT INTO "music_sources" ("source_key", "display_name", "enabled", "priority", "capabilities_json", "implementation_status", "version", "created_at", "updated_at", "use_proxy") VALUES ('kuwo', '酷我音乐', '1', '40', '["songs","albums","artwork","lyrics"]', 'available', '1', '2026-08-10T00:00:00Z', '2026-08-10T00:00:00Z', '0');

INSERT INTO "music_sources" ("source_key", "display_name", "enabled", "priority", "capabilities_json", "implementation_status", "version", "created_at", "updated_at", "use_proxy") VALUES ('lrclib', 'LRCLIB', '1', '90', '["songs","lyrics"]', 'available', '1', '2026-08-10T00:00:00Z', '2026-08-10T00:00:00Z', '0');

INSERT INTO "music_sources" ("source_key", "display_name", "enabled", "priority", "capabilities_json", "implementation_status", "version", "created_at", "updated_at", "use_proxy") VALUES ('migu', '咪咕音乐', '1', '50', '["songs","albums","artwork","lyrics"]', 'available', '1', '2026-08-10T00:00:00Z', '2026-08-10T00:00:00Z', '0');

INSERT INTO "music_sources" ("source_key", "display_name", "enabled", "priority", "capabilities_json", "implementation_status", "version", "created_at", "updated_at", "use_proxy") VALUES ('musicbrainz', 'MusicBrainz', '1', '80', '["songs","albums","artwork"]', 'available', '2', '2026-08-10T00:00:00Z', '2026-08-10T00:00:00Z', '0');

INSERT INTO "music_sources" ("source_key", "display_name", "enabled", "priority", "capabilities_json", "implementation_status", "version", "created_at", "updated_at", "use_proxy") VALUES ('netease', '网易云音乐', '1', '10', '["songs","albums","artwork","lyrics"]', 'available', '1', '2026-08-10T00:00:00Z', '2026-08-10T00:00:00Z', '0');

INSERT INTO "music_sources" ("source_key", "display_name", "enabled", "priority", "capabilities_json", "implementation_status", "version", "created_at", "updated_at", "use_proxy") VALUES ('qq', 'QQ 音乐', '1', '20', '["songs","albums","artwork","lyrics"]', 'available', '1', '2026-08-10T00:00:00Z', '2026-08-10T00:00:00Z', '0');

INSERT INTO "music_sources" ("source_key", "display_name", "enabled", "priority", "capabilities_json", "implementation_status", "version", "created_at", "updated_at", "use_proxy") VALUES ('soda', '汽水音乐', '1', '60', '["songs","albums","artwork","lyrics"]', 'available', '1', '2026-08-10T00:00:00Z', '2026-08-10T00:00:00Z', '0');

CREATE TRIGGER music_sources_use_proxy_insert_check BEFORE INSERT ON music_sources WHEN NEW.use_proxy NOT IN (0, 1) BEGIN SELECT RAISE(ABORT, 'invalid music source proxy flag'); END;

CREATE TRIGGER music_sources_use_proxy_update_check BEFORE UPDATE OF use_proxy ON music_sources WHEN NEW.use_proxy NOT IN (0, 1) BEGIN SELECT RAISE(ABORT, 'invalid music source proxy flag'); END;

CREATE TRIGGER realtime_artwork_provider_import_delete
AFTER DELETE ON artwork_provider_import_jobs
BEGIN
    INSERT INTO realtime_events (topic,audience_user_id,library_id,resource_version,created_at,expires_at)
    VALUES ('jobs.changed',NULL,OLD.library_id,OLD.updated_at,
        strftime('%Y-%m-%dT%H:%M:%SZ','now'),strftime('%Y-%m-%dT%H:%M:%SZ','now','+7 days'));
END;

CREATE TRIGGER realtime_artwork_provider_import_insert
AFTER INSERT ON artwork_provider_import_jobs
BEGIN
    INSERT INTO realtime_events (topic,audience_user_id,library_id,resource_version,created_at,expires_at)
    VALUES ('jobs.changed',NULL,NEW.library_id,NEW.updated_at,
        strftime('%Y-%m-%dT%H:%M:%SZ','now'),strftime('%Y-%m-%dT%H:%M:%SZ','now','+7 days'));
END;

CREATE TRIGGER realtime_artwork_provider_import_update
AFTER UPDATE ON artwork_provider_import_jobs
BEGIN
    INSERT INTO realtime_events (topic,audience_user_id,library_id,resource_version,created_at,expires_at)
    VALUES ('jobs.changed',NULL,NEW.library_id,NEW.updated_at,
        strftime('%Y-%m-%dT%H:%M:%SZ','now'),strftime('%Y-%m-%dT%H:%M:%SZ','now','+7 days'));
END;

CREATE TRIGGER realtime_artwork_provider_search_delete
AFTER DELETE ON artwork_provider_search_jobs
BEGIN
    INSERT INTO realtime_events (topic,audience_user_id,library_id,resource_version,created_at,expires_at)
    VALUES ('jobs.changed',NULL,OLD.library_id,OLD.updated_at,
        strftime('%Y-%m-%dT%H:%M:%SZ','now'),strftime('%Y-%m-%dT%H:%M:%SZ','now','+7 days'));
END;

CREATE TRIGGER realtime_artwork_provider_search_insert
AFTER INSERT ON artwork_provider_search_jobs
BEGIN
    INSERT INTO realtime_events (topic,audience_user_id,library_id,resource_version,created_at,expires_at)
    VALUES ('jobs.changed',NULL,NEW.library_id,NEW.updated_at,
        strftime('%Y-%m-%dT%H:%M:%SZ','now'),strftime('%Y-%m-%dT%H:%M:%SZ','now','+7 days'));
END;

CREATE TRIGGER realtime_artwork_provider_search_update
AFTER UPDATE ON artwork_provider_search_jobs
BEGIN
    INSERT INTO realtime_events (topic,audience_user_id,library_id,resource_version,created_at,expires_at)
    VALUES ('jobs.changed',NULL,NEW.library_id,NEW.updated_at,
        strftime('%Y-%m-%dT%H:%M:%SZ','now'),strftime('%Y-%m-%dT%H:%M:%SZ','now','+7 days'));
END;

CREATE TRIGGER realtime_audio_tag_batch_delete
AFTER DELETE ON audio_tag_writeback_batch_targets
BEGIN
    INSERT INTO realtime_events(topic,audience_user_id,library_id,resource_version,created_at,expires_at)
    VALUES('jobs.changed',NULL,OLD.library_id,OLD.updated_at,
        strftime('%Y-%m-%dT%H:%M:%SZ','now'),strftime('%Y-%m-%dT%H:%M:%SZ','now','+7 days'));
END;

CREATE TRIGGER realtime_audio_tag_batch_insert
AFTER INSERT ON audio_tag_writeback_batch_targets
BEGIN
    INSERT INTO realtime_events(topic,audience_user_id,library_id,resource_version,created_at,expires_at)
    VALUES('jobs.changed',NULL,NEW.library_id,NEW.updated_at,
        strftime('%Y-%m-%dT%H:%M:%SZ','now'),strftime('%Y-%m-%dT%H:%M:%SZ','now','+7 days'));
END;

CREATE TRIGGER realtime_audio_tag_batch_update
AFTER UPDATE ON audio_tag_writeback_batch_targets
BEGIN
    INSERT INTO realtime_events(topic,audience_user_id,library_id,resource_version,created_at,expires_at)
    VALUES('jobs.changed',NULL,NEW.library_id,NEW.updated_at,
        strftime('%Y-%m-%dT%H:%M:%SZ','now'),strftime('%Y-%m-%dT%H:%M:%SZ','now','+7 days'));
END;

CREATE TRIGGER realtime_audio_tag_writeback_job_delete
AFTER DELETE ON audio_tag_writeback_jobs
BEGIN
    INSERT INTO realtime_events (
        topic, audience_user_id, library_id, resource_version, created_at, expires_at
    ) VALUES (
        'jobs.changed', NULL, OLD.library_id, OLD.updated_at,
        strftime('%Y-%m-%dT%H:%M:%SZ', 'now'),
        strftime('%Y-%m-%dT%H:%M:%SZ', 'now', '+7 days')
    );
END;

CREATE TRIGGER realtime_audio_tag_writeback_job_insert
AFTER INSERT ON audio_tag_writeback_jobs
BEGIN
    INSERT INTO realtime_events (
        topic, audience_user_id, library_id, resource_version, created_at, expires_at
    ) VALUES (
        'jobs.changed', NULL, NEW.library_id, NEW.updated_at,
        strftime('%Y-%m-%dT%H:%M:%SZ', 'now'),
        strftime('%Y-%m-%dT%H:%M:%SZ', 'now', '+7 days')
    );
END;

CREATE TRIGGER realtime_audio_tag_writeback_job_update
AFTER UPDATE ON audio_tag_writeback_jobs
BEGIN
    INSERT INTO realtime_events (
        topic, audience_user_id, library_id, resource_version, created_at, expires_at
    ) VALUES (
        'jobs.changed', NULL, NEW.library_id, NEW.updated_at,
        strftime('%Y-%m-%dT%H:%M:%SZ', 'now'),
        strftime('%Y-%m-%dT%H:%M:%SZ', 'now', '+7 days')
    );
END;

CREATE TRIGGER realtime_library_grant_delete
AFTER DELETE ON library_user_grants
BEGIN
    INSERT INTO realtime_events (
        topic, audience_user_id, library_id, resource_version, created_at, expires_at
    ) VALUES (
        'permissions.changed', OLD.user_id, NULL, strftime('%Y-%m-%dT%H:%M:%SZ', 'now'), strftime('%Y-%m-%dT%H:%M:%SZ', 'now'),
        strftime('%Y-%m-%dT%H:%M:%SZ', 'now', '+7 days')
    );
END;

CREATE TRIGGER realtime_library_grant_insert
AFTER INSERT ON library_user_grants
BEGIN
    INSERT INTO realtime_events (
        topic, audience_user_id, library_id, resource_version, created_at, expires_at
    ) VALUES (
        'permissions.changed', NEW.user_id, NULL, strftime('%Y-%m-%dT%H:%M:%SZ', 'now'), strftime('%Y-%m-%dT%H:%M:%SZ', 'now'),
        strftime('%Y-%m-%dT%H:%M:%SZ', 'now', '+7 days')
    );
END;

CREATE TRIGGER realtime_library_grant_update
AFTER UPDATE ON library_user_grants
BEGIN
    INSERT INTO realtime_events (
        topic, audience_user_id, library_id, resource_version, created_at, expires_at
    ) VALUES (
        'permissions.changed', NEW.user_id, NULL, strftime('%Y-%m-%dT%H:%M:%SZ', 'now'), strftime('%Y-%m-%dT%H:%M:%SZ', 'now'),
        strftime('%Y-%m-%dT%H:%M:%SZ', 'now', '+7 days')
    );
END;

CREATE TRIGGER realtime_lyrics_audio_tag_batch_delete
AFTER DELETE ON lyrics_audio_tag_writeback_batch_targets
BEGIN
    INSERT INTO realtime_events(topic,audience_user_id,library_id,resource_version,created_at,expires_at)
    VALUES('jobs.changed',NULL,OLD.library_id,OLD.updated_at,
        strftime('%Y-%m-%dT%H:%M:%SZ','now'),strftime('%Y-%m-%dT%H:%M:%SZ','now','+7 days'));
END;

CREATE TRIGGER realtime_lyrics_audio_tag_batch_insert
AFTER INSERT ON lyrics_audio_tag_writeback_batch_targets
BEGIN
    INSERT INTO realtime_events(topic,audience_user_id,library_id,resource_version,created_at,expires_at)
    VALUES('jobs.changed',NULL,NEW.library_id,NEW.updated_at,
        strftime('%Y-%m-%dT%H:%M:%SZ','now'),strftime('%Y-%m-%dT%H:%M:%SZ','now','+7 days'));
END;

CREATE TRIGGER realtime_lyrics_audio_tag_batch_update
AFTER UPDATE ON lyrics_audio_tag_writeback_batch_targets
BEGIN
    INSERT INTO realtime_events(topic,audience_user_id,library_id,resource_version,created_at,expires_at)
    VALUES('jobs.changed',NULL,NEW.library_id,NEW.updated_at,
        strftime('%Y-%m-%dT%H:%M:%SZ','now'),strftime('%Y-%m-%dT%H:%M:%SZ','now','+7 days'));
END;

CREATE TRIGGER realtime_lyrics_audio_tag_job_delete
AFTER DELETE ON lyrics_audio_tag_writeback_jobs
BEGIN
    INSERT INTO realtime_events (
        topic, audience_user_id, library_id, resource_version, created_at, expires_at
    ) VALUES (
        'jobs.changed', NULL, OLD.library_id, OLD.updated_at,
        strftime('%Y-%m-%dT%H:%M:%SZ', 'now'),
        strftime('%Y-%m-%dT%H:%M:%SZ', 'now', '+7 days')
    );
END;

CREATE TRIGGER realtime_lyrics_audio_tag_job_insert
AFTER INSERT ON lyrics_audio_tag_writeback_jobs
BEGIN
    INSERT INTO realtime_events (
        topic, audience_user_id, library_id, resource_version, created_at, expires_at
    ) VALUES (
        'jobs.changed', NULL, NEW.library_id, NEW.updated_at,
        strftime('%Y-%m-%dT%H:%M:%SZ', 'now'),
        strftime('%Y-%m-%dT%H:%M:%SZ', 'now', '+7 days')
    );
END;

CREATE TRIGGER realtime_lyrics_audio_tag_job_update
AFTER UPDATE ON lyrics_audio_tag_writeback_jobs
BEGIN
    INSERT INTO realtime_events (
        topic, audience_user_id, library_id, resource_version, created_at, expires_at
    ) VALUES (
        'jobs.changed', NULL, NEW.library_id, NEW.updated_at,
        strftime('%Y-%m-%dT%H:%M:%SZ', 'now'),
        strftime('%Y-%m-%dT%H:%M:%SZ', 'now', '+7 days')
    );
END;

CREATE TRIGGER realtime_lyrics_writeback_job_delete
AFTER DELETE ON lyrics_writeback_jobs
BEGIN
    INSERT INTO realtime_events (
        topic, audience_user_id, library_id, resource_version, created_at, expires_at
    ) VALUES (
        'jobs.changed', NULL, OLD.library_id, OLD.updated_at,
        strftime('%Y-%m-%dT%H:%M:%SZ', 'now'),
        strftime('%Y-%m-%dT%H:%M:%SZ', 'now', '+7 days')
    );
END;

CREATE TRIGGER realtime_lyrics_writeback_job_insert
AFTER INSERT ON lyrics_writeback_jobs
BEGIN
    INSERT INTO realtime_events (
        topic, audience_user_id, library_id, resource_version, created_at, expires_at
    ) VALUES (
        'jobs.changed', NULL, NEW.library_id, NEW.updated_at,
        strftime('%Y-%m-%dT%H:%M:%SZ', 'now'),
        strftime('%Y-%m-%dT%H:%M:%SZ', 'now', '+7 days')
    );
END;

CREATE TRIGGER realtime_lyrics_writeback_job_update
AFTER UPDATE ON lyrics_writeback_jobs
BEGIN
    INSERT INTO realtime_events (
        topic, audience_user_id, library_id, resource_version, created_at, expires_at
    ) VALUES (
        'jobs.changed', NULL, NEW.library_id, NEW.updated_at,
        strftime('%Y-%m-%dT%H:%M:%SZ', 'now'),
        strftime('%Y-%m-%dT%H:%M:%SZ', 'now', '+7 days')
    );
END;

CREATE TRIGGER realtime_notification_delete
AFTER DELETE ON user_notifications
BEGIN
    INSERT INTO realtime_events (
        topic, audience_user_id, library_id, resource_version, created_at, expires_at
    ) VALUES (
        'notifications.changed', OLD.user_id, NULL, OLD.updated_at,
        strftime('%Y-%m-%dT%H:%M:%SZ', 'now'), strftime('%Y-%m-%dT%H:%M:%SZ', 'now', '+7 days')
    );
END;

CREATE TRIGGER realtime_notification_insert
AFTER INSERT ON user_notifications
BEGIN
    INSERT INTO realtime_events (
        topic, audience_user_id, library_id, resource_version, created_at, expires_at
    ) VALUES (
        'notifications.changed', NEW.user_id, NULL, NEW.updated_at,
        strftime('%Y-%m-%dT%H:%M:%SZ', 'now'), strftime('%Y-%m-%dT%H:%M:%SZ', 'now', '+7 days')
    );
END;

CREATE TRIGGER realtime_notification_mute_delete
AFTER DELETE ON user_notification_mutes
BEGIN
    INSERT INTO realtime_events (
        topic, audience_user_id, library_id, resource_version, created_at, expires_at
    ) VALUES (
        'notifications.changed', OLD.user_id, NULL, OLD.updated_at, strftime('%Y-%m-%dT%H:%M:%SZ', 'now'),
        strftime('%Y-%m-%dT%H:%M:%SZ', 'now', '+7 days')
    );
END;

CREATE TRIGGER realtime_notification_mute_insert
AFTER INSERT ON user_notification_mutes
BEGIN
    INSERT INTO realtime_events (
        topic, audience_user_id, library_id, resource_version, created_at, expires_at
    ) VALUES (
        'notifications.changed', NEW.user_id, NULL, NEW.updated_at, strftime('%Y-%m-%dT%H:%M:%SZ', 'now'),
        strftime('%Y-%m-%dT%H:%M:%SZ', 'now', '+7 days')
    );
END;

CREATE TRIGGER realtime_notification_mute_update
AFTER UPDATE ON user_notification_mutes
BEGIN
    INSERT INTO realtime_events (
        topic, audience_user_id, library_id, resource_version, created_at, expires_at
    ) VALUES (
        'notifications.changed', NEW.user_id, NULL, NEW.updated_at, strftime('%Y-%m-%dT%H:%M:%SZ', 'now'),
        strftime('%Y-%m-%dT%H:%M:%SZ', 'now', '+7 days')
    );
END;

CREATE TRIGGER realtime_notification_update
AFTER UPDATE ON user_notifications
BEGIN
    INSERT INTO realtime_events (
        topic, audience_user_id, library_id, resource_version, created_at, expires_at
    ) VALUES (
        'notifications.changed', NEW.user_id, NULL, NEW.updated_at,
        strftime('%Y-%m-%dT%H:%M:%SZ', 'now'), strftime('%Y-%m-%dT%H:%M:%SZ', 'now', '+7 days')
    );
END;

CREATE TRIGGER realtime_role_capability_delete
AFTER DELETE ON role_capabilities
BEGIN
    INSERT INTO realtime_events (
        topic, audience_user_id, library_id, resource_version, created_at, expires_at
    )
    SELECT
        'permissions.changed', user_id, NULL, strftime('%Y-%m-%dT%H:%M:%SZ', 'now'),
        strftime('%Y-%m-%dT%H:%M:%SZ', 'now'), strftime('%Y-%m-%dT%H:%M:%SZ', 'now', '+7 days')
    FROM user_roles WHERE role_id = OLD.role_id;
END;

CREATE TRIGGER realtime_role_capability_insert
AFTER INSERT ON role_capabilities
BEGIN
    INSERT INTO realtime_events (
        topic, audience_user_id, library_id, resource_version, created_at, expires_at
    )
    SELECT
        'permissions.changed', user_id, NULL, strftime('%Y-%m-%dT%H:%M:%SZ', 'now'),
        strftime('%Y-%m-%dT%H:%M:%SZ', 'now'), strftime('%Y-%m-%dT%H:%M:%SZ', 'now', '+7 days')
    FROM user_roles WHERE role_id = NEW.role_id;
END;

CREATE TRIGGER realtime_scan_job_delete
AFTER DELETE ON library_scan_jobs
BEGIN
    INSERT INTO realtime_events (
        topic, audience_user_id, library_id, resource_version, created_at, expires_at
    ) VALUES (
        'jobs.changed', NULL, OLD.library_id, OLD.updated_at, strftime('%Y-%m-%dT%H:%M:%SZ', 'now'),
        strftime('%Y-%m-%dT%H:%M:%SZ', 'now', '+7 days')
    );
END;

CREATE TRIGGER realtime_scan_job_insert
AFTER INSERT ON library_scan_jobs
BEGIN
    INSERT INTO realtime_events (
        topic, audience_user_id, library_id, resource_version, created_at, expires_at
    ) VALUES (
        'jobs.changed', NULL, NEW.library_id, NEW.updated_at, strftime('%Y-%m-%dT%H:%M:%SZ', 'now'),
        strftime('%Y-%m-%dT%H:%M:%SZ', 'now', '+7 days')
    );
END;

CREATE TRIGGER realtime_scan_job_update
AFTER UPDATE ON library_scan_jobs
BEGIN
    INSERT INTO realtime_events (
        topic, audience_user_id, library_id, resource_version, created_at, expires_at
    ) VALUES (
        'jobs.changed', NULL, NEW.library_id, NEW.updated_at, strftime('%Y-%m-%dT%H:%M:%SZ', 'now'),
        strftime('%Y-%m-%dT%H:%M:%SZ', 'now', '+7 days')
    );
END;

CREATE TRIGGER realtime_upload_session_delete
AFTER DELETE ON upload_sessions
BEGIN
    INSERT INTO realtime_events (
        topic, audience_user_id, library_id, resource_version, created_at, expires_at
    ) VALUES (
        'jobs.changed', NULL, OLD.library_id, OLD.updated_at,
        strftime('%Y-%m-%dT%H:%M:%SZ', 'now'), strftime('%Y-%m-%dT%H:%M:%SZ', 'now', '+7 days')
    );
END;

CREATE TRIGGER realtime_upload_session_insert
AFTER INSERT ON upload_sessions
BEGIN
    INSERT INTO realtime_events (
        topic, audience_user_id, library_id, resource_version, created_at, expires_at
    ) VALUES (
        'jobs.changed', NULL, NEW.library_id, NEW.updated_at,
        strftime('%Y-%m-%dT%H:%M:%SZ', 'now'), strftime('%Y-%m-%dT%H:%M:%SZ', 'now', '+7 days')
    );
END;

CREATE TRIGGER realtime_upload_session_update
AFTER UPDATE ON upload_sessions
BEGIN
    INSERT INTO realtime_events (
        topic, audience_user_id, library_id, resource_version, created_at, expires_at
    ) VALUES (
        'jobs.changed', NULL, NEW.library_id, NEW.updated_at,
        strftime('%Y-%m-%dT%H:%M:%SZ', 'now'), strftime('%Y-%m-%dT%H:%M:%SZ', 'now', '+7 days')
    );
END;

CREATE TRIGGER realtime_user_permission_update
AFTER UPDATE OF permission_version, status, deleted_at ON users
WHEN NEW.permission_version <> OLD.permission_version
    OR NEW.status <> OLD.status
    OR NEW.deleted_at IS NOT OLD.deleted_at
BEGIN
    INSERT INTO realtime_events (
        topic, audience_user_id, library_id, resource_version, created_at, expires_at
    ) VALUES (
        'permissions.changed', NEW.id, NULL, CAST(NEW.permission_version AS TEXT),
        strftime('%Y-%m-%dT%H:%M:%SZ', 'now'), strftime('%Y-%m-%dT%H:%M:%SZ', 'now', '+7 days')
    );
END;

CREATE TRIGGER realtime_user_role_delete
AFTER DELETE ON user_roles
BEGIN
    INSERT INTO realtime_events (
        topic, audience_user_id, library_id, resource_version, created_at, expires_at
    ) VALUES (
        'permissions.changed', OLD.user_id, NULL, strftime('%Y-%m-%dT%H:%M:%SZ', 'now'), strftime('%Y-%m-%dT%H:%M:%SZ', 'now'),
        strftime('%Y-%m-%dT%H:%M:%SZ', 'now', '+7 days')
    );
END;

CREATE TRIGGER realtime_user_role_insert
AFTER INSERT ON user_roles
BEGIN
    INSERT INTO realtime_events (
        topic, audience_user_id, library_id, resource_version, created_at, expires_at
    ) VALUES (
        'permissions.changed', NEW.user_id, NULL, strftime('%Y-%m-%dT%H:%M:%SZ', 'now'), strftime('%Y-%m-%dT%H:%M:%SZ', 'now'),
        strftime('%Y-%m-%dT%H:%M:%SZ', 'now', '+7 days')
    );
END;
SQL;

    /** 空库创建终态；已是终态的开发库只收敛旧迁移记录。 */
    public function up(): void
    {
        $connection = $this->getAdapter()->getConnection();
        if (!$connection instanceof PDO) {
            throw new RuntimeException('Velin Music baseline requires a PDO SQLite connection.');
        }
        if ((string) $connection->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
            throw new RuntimeException('Velin Music baseline only supports SQLite.');
        }

        $objectCount = (int) $connection->query(<<<'SQL'
SELECT COUNT(*)
FROM sqlite_schema
WHERE name NOT LIKE 'sqlite_%' AND name <> 'phinxlog'
SQL)->fetchColumn();

        if ($objectCount === 0) {
            $connection->exec(self::BASELINE_SQL);
        }

        $this->assertTerminalSchema($connection);
        $connection->exec('DELETE FROM phinxlog WHERE version < ' . self::VERSION);
    }

    /**
     * 基线删除会同时破坏用户、权限、媒体索引和审计事实，因此永远拒绝自动向下迁移。
     *
     * 运维必须停止服务并恢复迁移前的一致性备份；本方法无文件系统或外部服务副作用。
     */
    public function down(): void
    {
        throw new RuntimeException('Velin Music baseline is irreversible; restore a verified backup.');
    }

    /** 对空库创建结果和既有终态执行同一份完整 schema 与外键验证。 */
    private function assertTerminalSchema(PDO $connection): void
    {
        $canonical = '';
        $rows = $connection->query(<<<'SQL'
SELECT type, name, tbl_name, COALESCE(sql, '') AS sql
FROM sqlite_schema
WHERE name NOT LIKE 'sqlite_%' AND name <> 'phinxlog'
ORDER BY type, name
SQL)->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $canonical .= $row['type'] . "\x1f" . $row['name'] . "\x1f"
                . $row['tbl_name'] . "\x1f" . $row['sql'] . "\n";
        }

        $actual = hash('sha256', $canonical);
        if (!hash_equals(self::SCHEMA_FINGERPRINT, $actual)) {
            throw new RuntimeException(
                "Velin Music baseline schema mismatch: expected " . self::SCHEMA_FINGERPRINT . ", got {$actual}.",
            );
        }

        $violation = $connection->query('PRAGMA foreign_key_check')->fetch(PDO::FETCH_ASSOC);
        if ($violation !== false) {
            throw new RuntimeException('Velin Music baseline contains a foreign-key violation.');
        }
    }
}