<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * 为资源插件下载后的本地/远程入库建立逐文件发布账本。
 *
 * 网络对象与 SQLite 无法组成原子事务，因此每个任务文件先以 `(plugin_key, task_id, source_fingerprint)`
 * 预留，再在事务外上传，最后提交 published。崩溃后 Worker 复用同一行和目标路径，协议客户端只有在
 * 大小与摘要能证明远端对象属于同一源时才恢复成功；不同任务不能占用同一库相对路径。表不外键引用
 * 插件私有任务表，因为插件可以独立卸载且已发布媒体仍属于音乐库。回滚只删除恢复账本，不删除任何
 * 本地或远端媒体，因而可能使旧任务失去幂等证据，生产回滚前必须停止插件 Worker。删除音乐库时账本
 * 随库级业务事实级联清理，但本地或远端
 * 媒体不会由外键或删除流程触碰，保持现有“移除库不删除源文件”边界。
 */
final class CreatePluginMediaPublications extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
CREATE TABLE plugin_media_publications (
    id TEXT PRIMARY KEY NOT NULL,
    plugin_key TEXT NOT NULL CHECK (length(plugin_key) BETWEEN 2 AND 48),
    task_id TEXT NOT NULL CHECK (length(task_id) = 26),
    library_id TEXT NOT NULL REFERENCES music_libraries(id) ON DELETE CASCADE,
    source_fingerprint TEXT NOT NULL CHECK (length(source_fingerprint) = 64),
    relative_path TEXT NOT NULL CHECK (length(relative_path) BETWEEN 3 AND 768),
    source_type TEXT NOT NULL CHECK (source_type IN ('local','webdav','onedrive','google_drive')),
    size_bytes INTEGER NOT NULL CHECK (size_bytes > 0),
    sha256 TEXT NOT NULL CHECK (length(sha256) = 64),
    status TEXT NOT NULL CHECK (status IN ('pending','uploading','published','failed')),
    remote_version TEXT NULL CHECK (remote_version IS NULL OR length(remote_version) BETWEEN 1 AND 512),
    error_code TEXT NULL CHECK (error_code IS NULL OR length(error_code) BETWEEN 1 AND 96),
    published_at TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE (plugin_key, task_id, source_fingerprint),
    UNIQUE (library_id, relative_path)
)
SQL);
        $this->execute('CREATE INDEX idx_plugin_media_publications_task ON plugin_media_publications(plugin_key, task_id, status)');
        $this->execute('CREATE INDEX idx_plugin_media_publications_library ON plugin_media_publications(library_id, published_at DESC)');
    }

    public function down(): void
    {
        $this->execute('DROP TABLE plugin_media_publications');
    }
}
