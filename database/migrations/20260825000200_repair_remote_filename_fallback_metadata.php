<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * 修复远程 `filename_only` 占位被错误记录为真实 raw 标签的字段状态。
 *
 * 早期实现为了让未读取音频正文的网络歌曲立即可浏览，把 basename、未知艺人和单曲占位同时写入
 * raw 与 effective。raw 优先级随后会压住已经可靠保存的 scraped 标题。迁移只选择当前仍为远程
 * `filename_only`，并且最新标签快照明确记录 `remoteProbe=skipped` 的歌曲；无法由快照证明来源的行
 * 一律不改。选中歌曲的 raw 描述层改为 JSON null，未锁定字段按 manual > scraped > 临时占位恢复，
 * 锁定字段只清理错误 raw 事实而保持当前投影。歌曲标题和搜索标题在同一迁移中同步，音频、远端对象、
 * 歌词、封面、任务、个人数据和审计均不触碰。
 *
 * 该修复无法从 null 反推出旧 basename，因此明确不可逆；部署回滚只能回退代码，不能伪造 raw 标签。
 * SQL 使用 SQLite JSON1 与 CTE，未来 MySQL 数据迁移必须以 JSON_EXTRACT 和等价短事务重新实现。
 */
final class RepairRemoteFilenameFallbackMetadata extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
WITH filename_only_songs AS (
    SELECT songs.id
    FROM media_songs AS songs
    JOIN music_libraries AS libraries ON libraries.id = songs.library_id
    JOIN media_tag_snapshots AS snapshots ON snapshots.song_id = songs.id
    WHERE libraries.source_type IN ('webdav', 'onedrive', 'google_drive')
      AND libraries.remote_metadata_mode = 'filename_only'
      AND json_valid(snapshots.raw_tags_json) = 1
      AND json_extract(snapshots.raw_tags_json, '$.velin.remoteMetadataMode') = 'filename_only'
      AND json_extract(snapshots.raw_tags_json, '$.velin.remoteProbe') = 'skipped'
)
UPDATE media_metadata_field_states
SET raw_value_json = 'null',
    effective_value_json = CASE
        WHEN is_locked = 1 THEN effective_value_json
        WHEN manual_value_json IS NOT NULL THEN manual_value_json
        WHEN scraped_value_json IS NOT NULL THEN scraped_value_json
        ELSE effective_value_json
    END,
    effective_source = CASE
        WHEN is_locked = 1 THEN effective_source
        WHEN manual_value_json IS NOT NULL THEN 'manual'
        WHEN scraped_value_json IS NOT NULL THEN 'scraped'
        ELSE 'raw'
    END,
    version = version + 1,
    source_updated_at = strftime('%Y-%m-%dT%H:%M:%SZ', 'now'),
    updated_at = strftime('%Y-%m-%dT%H:%M:%SZ', 'now')
WHERE song_id IN (SELECT id FROM filename_only_songs)
  AND (
      raw_value_json <> 'null'
      OR (
          is_locked = 0
          AND manual_value_json IS NULL
          AND scraped_value_json IS NOT NULL
          AND (effective_value_json <> scraped_value_json OR effective_source <> 'scraped')
      )
  )
SQL);

        $this->execute(<<<'SQL'
WITH filename_only_songs AS (
    SELECT songs.id
    FROM media_songs AS songs
    JOIN music_libraries AS libraries ON libraries.id = songs.library_id
    JOIN media_tag_snapshots AS snapshots ON snapshots.song_id = songs.id
    WHERE libraries.source_type IN ('webdav', 'onedrive', 'google_drive')
      AND libraries.remote_metadata_mode = 'filename_only'
      AND json_valid(snapshots.raw_tags_json) = 1
      AND json_extract(snapshots.raw_tags_json, '$.velin.remoteMetadataMode') = 'filename_only'
      AND json_extract(snapshots.raw_tags_json, '$.velin.remoteProbe') = 'skipped'
)
UPDATE media_songs
SET title = (
        SELECT CAST(json_extract(states.effective_value_json, '$') AS TEXT)
        FROM media_metadata_field_states AS states
        WHERE states.song_id = media_songs.id AND states.field_key = 'title'
    ),
    normalized_title = lower(trim((
        SELECT CAST(json_extract(states.effective_value_json, '$') AS TEXT)
        FROM media_metadata_field_states AS states
        WHERE states.song_id = media_songs.id AND states.field_key = 'title'
    ))),
    updated_at = strftime('%Y-%m-%dT%H:%M:%SZ', 'now')
WHERE id IN (SELECT id FROM filename_only_songs)
  AND EXISTS (
      SELECT 1
      FROM media_metadata_field_states AS states
      WHERE states.song_id = media_songs.id
        AND states.field_key = 'title'
        AND json_valid(states.effective_value_json) = 1
        AND json_type(states.effective_value_json, '$') = 'text'
  )
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Remote filename fallback raw metadata cannot be reconstructed after repair.');
    }
}
