<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * 移除独立转码权限，使播放、下载和投放转码继承各自基础能力（AUTH-TRANSCODE-001）。
 *
 * 为什么存在：转码是已授权媒体传输的质量实现，不再作为账号可单独授予或撤销的动作。迁移先从 App
 * 授权码、令牌族、活动令牌和个人访问令牌的 JSON scope 中删除 `transcode`，再删除能力词表记录；外键
 * 级联同时清理角色及用户直接授权。播放、下载、cast、音乐库范围、系统并发和码率限制均保持不变。
 *
 * SQLite 行为与锁：JSON 使用 json_each/json_group_array 结构化重建，不能用字符串替换破坏合法 JSON；
 * 整个迁移位于 Phinx 事务和短写锁中，数据量只与本机令牌数相关。未来 MySQL 使用 JSON_TABLE 与
 * JSON_ARRAYAGG 实现同一过滤语义。任一 JSON 损坏或 SQL 失败会回滚全部删除，不留下半迁移状态。
 *
 * 回滚：历史直接授权和令牌 scope 的来源已经不可判定，down 明确失败而不伪造恢复。回退应用版本前
 * 必须先恢复迁移前数据库备份；媒体、偏好和音频文件不受影响。
 */
final class RemoveTranscodeCapability extends AbstractMigration
{
    public function up(): void
    {
        foreach (['app_authorization_codes', 'app_token_families', 'app_tokens', 'personal_access_tokens'] as $table) {
            $this->execute(sprintf(<<<'SQL'
UPDATE %s
SET scopes_json = COALESCE((
    SELECT json_group_array(value)
    FROM json_each(%s.scopes_json)
    WHERE value <> 'transcode'
), '[]')
WHERE EXISTS (
    SELECT 1 FROM json_each(%s.scopes_json) WHERE value = 'transcode'
)
SQL, $table, $table, $table));
        }

        // SQLite 部署可能在历史版本关闭过 foreign_keys，不能假定 ON DELETE CASCADE 一定替我们清理。
        // 先显式删除两类授权关系，再删除词表；任一语句失败由迁移事务整体回滚，不留下悬空权限行。
        $this->execute("DELETE FROM role_capabilities WHERE capability_key = 'transcode'");
        $this->execute("DELETE FROM user_capabilities WHERE capability_key = 'transcode'");
        $this->execute("DELETE FROM capabilities WHERE capability_key = 'transcode'");
    }

    public function down(): void
    {
        throw new RuntimeException(
            'RemoveTranscodeCapability is irreversible; restore the pre-migration database backup.',
        );
    }
}
