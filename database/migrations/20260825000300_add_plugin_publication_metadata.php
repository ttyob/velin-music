<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * 为插件媒体发布账本增加受信描述元数据与扫描发现 ETag。
 *
 * `filename_only` 网络库不会读取音频正文，旧账本又只保存路径和内容摘要，导致插件明明已经写入标签，
 * 增量扫描仍只能建立“未知艺术家/单曲”占位。新增 metadata_json 只保存版本化白名单描述字段；
 * discovery_etag 保存上传客户端与目录发现共用的无秘密对象身份。扫描器必须同时复验库、路径、大小和
 * ETag 才能采用快照，外部替换对象后自动失效。历史 OneDrive/WebDAV 行没有发现 ETag 时，代码只允许
 * 使用可由库存 ETag、路径、大小和源 SHA-256 重算并匹配的旧 remote_version，Google Drive 历史行不
 * 具备可重算身份，因此保持普通 filename_only 行为。
 *
 * 迁移仅从仍存在且成功的 LX Music、music-resource 私有任务表回填基础标题、艺人和专辑，不读取密文、
 * 歌词、封面地址或远端凭据；插件未安装时跳过对应回填。回滚只移除可重建账本列，不删除媒体、任务或
 * 业务元数据，但会让 filename_only 再次失去标签接管能力，生产回滚前必须停止插件 Worker 和扫描。
 */
final class AddPluginPublicationMetadata extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
ALTER TABLE plugin_media_publications
ADD COLUMN metadata_json TEXT NULL
CHECK (metadata_json IS NULL OR (json_valid(metadata_json) = 1 AND json_extract(metadata_json, '$.schemaVersion') = 1))
SQL);
        $this->execute(<<<'SQL'
ALTER TABLE plugin_media_publications
ADD COLUMN discovery_etag TEXT NULL
CHECK (discovery_etag IS NULL OR length(discovery_etag) BETWEEN 1 AND 512)
SQL);

        if ($this->hasTable('lx_music_download_jobs')) {
            $this->execute(<<<'SQL'
UPDATE plugin_media_publications
SET metadata_json = (
    SELECT json_object(
        'schemaVersion', 1,
        'title', trim(jobs.title),
        'artists', json_array(trim(jobs.artist)),
        'albumTitle', CASE WHEN trim(jobs.album) = '' THEN NULL ELSE trim(jobs.album) END,
        'albumArtists', json_array(trim(jobs.artist)),
        'trackNumber', NULL,
        'trackTotal', NULL,
        'discNumber', NULL,
        'discTotal', NULL,
        'genres', json_array(),
        'releaseDate', NULL,
        'isrc', NULL,
        'durationMs', NULL
    )
    FROM lx_music_download_jobs AS jobs
    WHERE jobs.id = plugin_media_publications.task_id
      AND jobs.status = 'succeeded'
      AND trim(jobs.title) <> ''
      AND trim(jobs.artist) <> ''
)
WHERE plugin_key = 'lx-music'
  AND status = 'published'
  AND metadata_json IS NULL
  AND EXISTS (
      SELECT 1 FROM lx_music_download_jobs AS jobs
      WHERE jobs.id = plugin_media_publications.task_id
        AND jobs.status = 'succeeded'
        AND trim(jobs.title) <> ''
        AND trim(jobs.artist) <> ''
  )
SQL);
        }

        if ($this->hasTable('music_resource_download_jobs')) {
            $this->execute(<<<'SQL'
UPDATE plugin_media_publications
SET metadata_json = (
    SELECT json_object(
        'schemaVersion', 1,
        'title', trim(jobs.title),
        'artists', json_array(trim(jobs.artist)),
        'albumTitle', CASE WHEN trim(jobs.album) = '' THEN NULL ELSE trim(jobs.album) END,
        'albumArtists', json_array(trim(jobs.artist)),
        'trackNumber', NULL,
        'trackTotal', NULL,
        'discNumber', NULL,
        'discTotal', NULL,
        'genres', json_array(),
        'releaseDate', NULL,
        'isrc', NULL,
        'durationMs', NULL
    )
    FROM music_resource_download_jobs AS jobs
    WHERE jobs.id = plugin_media_publications.task_id
      AND jobs.status = 'succeeded'
      AND trim(jobs.title) <> ''
      AND trim(jobs.artist) <> ''
)
WHERE plugin_key = 'music-resource'
  AND status = 'published'
  AND metadata_json IS NULL
  AND EXISTS (
      SELECT 1 FROM music_resource_download_jobs AS jobs
      WHERE jobs.id = plugin_media_publications.task_id
        AND jobs.status = 'succeeded'
        AND trim(jobs.title) <> ''
        AND trim(jobs.artist) <> ''
  )
SQL);
        }
    }

    public function down(): void
    {
        $this->execute('ALTER TABLE plugin_media_publications DROP COLUMN discovery_etag');
        $this->execute('ALTER TABLE plugin_media_publications DROP COLUMN metadata_json');
    }
}
