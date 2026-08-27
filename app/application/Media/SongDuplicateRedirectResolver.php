<?php

declare(strict_types=1);

namespace app\application\Media;

use Illuminate\Database\Query\Builder;
use stdClass;
use support\Db;

/**
 * 解析仍有强证据支持的重复歌曲旧 ID，并为目录查询排除有效来源变体。
 *
 * 重定向不是永久别名：同 inode 必须继续共享保存的 device/inode，同字节必须继续具有 ready SHA-256。
 * 任一来源或目标缺失、证据变化、操作撤销都会停止隐藏并返回原 ID，避免扫描后把已经变化的音频错误映射
 * 到旧目标。该服务只读数据库，不修改重定向状态，也不把哈希、inode 或库存身份暴露给调用方。
 */
final class SongDuplicateRedirectResolver
{
    /** 返回当前有效目标；没有表、没有活动关系或证据失效时保持原歌曲 ID。 */
    public function resolve(string $songId): string
    {
        if (!$this->available()) return $songId;
        /** @var stdClass|null $row */
        $row = $this->validRedirectQuery('redirects')->where('redirects.source_song_id', $songId)
            ->first(['redirects.target_song_id']);

        return $row instanceof stdClass ? (string) $row->target_song_id : $songId;
    }

    /**
     * 排除当前仍有效的来源歌曲。
     *
     * alias 只能由服务端固定调用点提供；子查询使用 EXISTS，不连接外层结果，因此不会放大目录行数。
     */
    public function excludeActiveSources(Builder $query, string $alias): void
    {
        if (!$this->available()) return;
        $query->whereNotExists(function (Builder $redirects) use ($alias): void {
            $redirects->selectRaw('1')->from('song_duplicate_redirects as hidden_redirects');
            $this->validRedirectQueryOn($redirects, 'hidden_redirects');
            $redirects->whereColumn('hidden_redirects.source_song_id', $alias . '.id');
        });
    }

    /** 构造可独立执行的有效关系查询。 */
    private function validRedirectQuery(string $alias): Builder
    {
        $query = Db::table('song_duplicate_redirects as ' . $alias);
        $this->validRedirectQueryOn($query, $alias);
        return $query;
    }

    /** 把来源、目标库存及强证据条件附加到已有子查询。 */
    private function validRedirectQueryOn(Builder $query, string $alias): void
    {
        $query->join('media_songs as redirect_sources', 'redirect_sources.id', '=', $alias . '.source_song_id')
            ->join('library_file_inventory as redirect_source_files', 'redirect_source_files.id', '=', 'redirect_sources.inventory_file_id')
            ->join('media_songs as redirect_targets', 'redirect_targets.id', '=', $alias . '.target_song_id')
            ->join('library_file_inventory as redirect_target_files', 'redirect_target_files.id', '=', 'redirect_targets.inventory_file_id')
            ->where($alias . '.status', 'active')
            ->where(function (Builder $evidence) use ($alias): void {
                $evidence->where(function (Builder $inode) use ($alias): void {
                    $inode->where($alias . '.evidence_kind', 'inode')
                        // 迁移将证据统一保存为 TEXT；显式 CAST 避免 SQLite 在列对列比较时因类型亲和性
                        // 不同而让数值相等的 inode 失配。别名只来自本类固定调用点，不含请求输入。
                        ->whereRaw('CAST(redirect_source_files.device_id AS TEXT) = ' . $alias . '.evidence_key_a')
                        ->whereRaw('CAST(redirect_target_files.device_id AS TEXT) = ' . $alias . '.evidence_key_a')
                        ->whereRaw('CAST(redirect_source_files.inode AS TEXT) = ' . $alias . '.evidence_key_b')
                        ->whereRaw('CAST(redirect_target_files.inode AS TEXT) = ' . $alias . '.evidence_key_b');
                })->orWhere(function (Builder $bytes) use ($alias): void {
                    $bytes->where($alias . '.evidence_kind', 'byte_hash')
                        ->where('redirect_source_files.byte_hash_status', 'ready')
                        ->where('redirect_target_files.byte_hash_status', 'ready')
                        ->whereColumn('redirect_source_files.byte_sha256', $alias . '.evidence_key_a')
                        ->whereColumn('redirect_target_files.byte_sha256', $alias . '.evidence_key_a');
                });
            });
    }

    /**
     * 仅在重定向关系及其全部实时证据列齐全时启用。
     *
     * 滚动部署、失败迁移恢复和领域单测可能短暂出现新关系表已存在但库存列不完整的 schema；此时执行
     * 半条 JOIN 会让所有目录不可用。解析器选择失败关闭为“不隐藏、不重定向”，待 schema 完整后自然
     * 启用，不写兼容标记，也不猜测缺失证据。
     */
    private function available(): bool
    {
        $schema = Db::connection()->getSchemaBuilder();
        if (!$schema->hasTable('song_duplicate_redirects')
            || !$schema->hasTable('media_songs')
            || !$schema->hasTable('library_file_inventory')) {
            return false;
        }
        foreach (['device_id', 'inode', 'byte_hash_status', 'byte_sha256'] as $column) {
            if (!$schema->hasColumn('library_file_inventory', $column)) return false;
        }
        return $schema->hasColumn('media_songs', 'inventory_file_id');
    }
}
