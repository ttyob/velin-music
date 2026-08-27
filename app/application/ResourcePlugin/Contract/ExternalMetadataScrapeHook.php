<?php

declare(strict_types=1);

namespace app\application\ResourcePlugin\Contract;

/**
 * ExternalMetadataScrapeHook 定义第三方元数据、歌词和封面补全插件的最终决策边界。
 *
 * 核心按场景传递两种输入之一：已知标题、艺人、专辑，或文件名与上两级目录原文。插件在自身内部完成
 * 文件名语义解析、第三方查询、评分与取舍。插件不得接收物理路径、时长、ISRC、文件证据、关键词、任务
 * ID、代理或凭据，也不得回传候选列表、平台 ID、原始响应、Cookie 或内部渠道信息。核心仍负责身份与授
 * 权复验、审批、字段写入、歌词解析、图片下载校验和发布。
 */
interface ExternalMetadataScrapeHook extends PhpResourcePlugin
{
    /**
     * 返回插件内部已经优选完成的一条可信补全结论。
     */
    public function scrapeMetadata(MetadataScrapeCompletionRequest $request): MetadataScrapeCompletionResult;
}
