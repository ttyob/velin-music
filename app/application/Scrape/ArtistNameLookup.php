<?php

declare(strict_types=1);

namespace app\application\Scrape;

/**
 * 为文件名方向判断提供只读、精确的艺人名存在性证据。
 *
 * 实现不得做模糊猜测，也不得把同名结果提升为确定艺人身份；返回 true 只表示名称可作为候选艺人侧。
 * 辅助库缺失、损坏或查询失败必须返回 false，使扫描继续使用原有双向候选，不能传播基础设施异常。
 */
interface ArtistNameLookup
{
    /** 判断规范化后的完整名称是否存在；调用不得写入辅助库、业务库或媒体文件。 */
    public function contains(string $name): bool;
}
