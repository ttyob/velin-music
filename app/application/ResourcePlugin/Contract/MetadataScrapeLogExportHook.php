<?php

declare(strict_types=1);

namespace app\application\ResourcePlugin\Contract;

/**
 * MetadataScrapeLogExportHook 定义元数据插件逐条任务诊断导出的可选能力。
 *
 * 这是独立的可选合同，避免新增导出能力时让旧版插件的管理接口整体失效。调用者已经在核心固定
 * 路由完成 Session、manage_system、插件活动状态和数据库版本校验；插件只返回服务端私有临时文件
 * 的下载描述，不把文件路径或原始日志结构交给浏览器。实现必须重新按 taskId 查询单条任务，并对
 * 日志字段做白名单投影、长度限制和敏感信息过滤；生成失败时不得返回部分文件或改变任务状态。
 */
interface MetadataScrapeLogExportHook extends PhpResourcePlugin
{
    /**
     * 为一个插件刮削任务生成一次性诊断 JSON 文件。
     *
     * @return array{path:string,downloadName:string,sha256:string,byteSize:int,recordCount:int}
     */
    public function exportMetadataTaskLog(string $taskId): array;
}
