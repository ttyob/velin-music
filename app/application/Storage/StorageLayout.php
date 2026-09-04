<?php

declare(strict_types=1);

namespace app\application\Storage;

/**
 * 定义容器内不可由请求覆盖的两根存储布局。
 *
 * `/data` 只保存数据库、配置、插件、日志和可重建缓存；`/storage` 只保存最终音乐、插件下载源及同盘
 * 回收站。音乐库与下载目录位于同一挂载点，使硬链接和目录内原子 rename 具有明确前置条件，同时缓存
 * 不会被扫描器误识别。路径变更必须通过版本化数据库迁移和停机文件迁移共同完成；本类不创建、移动或
 * 删除任何文件，也不允许环境变量把生产边界改回任意宿主路径。
 */
final class StorageLayout
{
    public const DATA_ROOT = '/data';
    public const STORAGE_ROOT = '/storage';
    public const RUNTIME_ROOT = '/data/runtime';
    public const LIBRARY_ROOT = '/storage/music';
    public const DOWNLOAD_ROOT = '/storage/downloads';
    public const SCRAPE_CACHE_ROOT = '/data/cache/scrape';
    public const ARTIST_DATABASE_ROOT = '/data/cache/artist-database';
    public const ARTIST_DATABASE_PATH = self::ARTIST_DATABASE_ROOT . '/musicbrainz_artists_zh.sqlite';
    public const TRASH_ROOT = '/storage/.velin-trash';

    private function __construct()
    {
    }
}
