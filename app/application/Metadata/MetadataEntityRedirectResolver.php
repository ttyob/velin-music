<?php

declare(strict_types=1);

namespace app\application\Metadata;

use stdClass;
use support\Db;

/**
 * 把已合并艺术家/专辑的旧稳定 ID 解析到当前活动目标（ADMIN-META-010）。
 *
 * 解析只读取服务端生成的 active 重定向，不判断授权；调用方必须把最终 ID 重新放入原有实时媒体范围
 * 查询，不能因知道旧 ID 就获得目标访问权。连续合并可能形成合法链，最多跟随 32 跳；检测到循环、
 * 非法目标或超长链时返回原 ID，使上层按普通不可见对象处理，不泄露损坏的内部映射。
 */
final class MetadataEntityRedirectResolver
{
    /** 返回 artist/album 的最终活动 ID；未知类型和非法 ID 保持原值。 */
    public function resolve(string $type, string $entityId): string
    {
        if (!in_array($type, ['artist', 'album'], true)
            || preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $entityId) !== 1) {
            return $entityId;
        }
        $current = $entityId;
        $visited = [];
        for ($hop = 0; $hop < 32; ++$hop) {
            if (isset($visited[$current])) return $entityId;
            $visited[$current] = true;
            /** @var stdClass|null $row */
            $row = Db::table('metadata_entity_redirects')->where('entity_type', $type)
                ->where('source_entity_id', $current)->where('status', 'active')
                ->orderByDesc('created_at')->first(['target_entity_id']);
            if (!$row instanceof stdClass) return $current;
            $target = (string) $row->target_entity_id;
            if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $target) !== 1) return $entityId;
            $current = $target;
        }

        return $entityId;
    }
}
