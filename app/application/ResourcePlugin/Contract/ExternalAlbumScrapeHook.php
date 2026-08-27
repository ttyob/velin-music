<?php

declare(strict_types=1);

namespace app\application\ResourcePlugin\Contract;

/**
 * ExternalAlbumScrapeHook 定义专辑实体的最终刮削边界。
 *
 * 插件负责专辑内部的多来源查询和取舍，核心只接收一条标准结果并负责授权、字段状态、封面校验与发布。
 */
interface ExternalAlbumScrapeHook extends PhpResourcePlugin
{
    /** 返回插件内部已完成身份确认和来源选择的一条专辑最终结论。 */
    public function scrapeAlbum(AlbumScrapeCompletionRequest $request): AlbumScrapeCompletionResult;
}
