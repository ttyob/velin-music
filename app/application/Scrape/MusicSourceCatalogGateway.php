<?php

declare(strict_types=1);

namespace app\application\Scrape;

/**
 * 提供刮削 Worker 当前启用的平台顺序。
 *
 * 生产实现从 Velin Music 本地数据库读取；独立接口用于测试隔离数据库，并禁止查询编排依赖后台 HTTP
 * Controller。返回键必须属于固定目录且不得重复；调用方可按声明能力收窄，避免把纯歌词渠道用于
 * 封面或艺人图片查询。
 */
interface MusicSourceCatalogGateway
{
    /**
     * 返回按管理员 priority 排序的启用渠道键。
     *
     * capability 为空时返回全部启用渠道；指定时只返回目录中明确声明该能力的渠道。实现不得根据调用
     * 时网络状态悄悄改变目录，未知能力必须失败关闭，避免任务在错误的渠道集合上运行。
     *
     * @return list<string>
     */
    public function enabledSourceKeys(?string $capability = null): array;
}
