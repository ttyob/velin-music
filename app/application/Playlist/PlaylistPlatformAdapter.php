<?php

declare(strict_types=1);

namespace app\application\Playlist;

/**
 * 解析一个受支持的平台歌单 JSON，并转换为 Velin Music 的通用导入文档。
 *
 * 适配器只负责有限字节、JSON 结构和字段单位校验，不访问网络、不解析本地路径，也不查询数据库。
 * 实现必须在结构错误时抛出 PlaylistImportInvalid，不能返回半截文档，以便上层保持创建事务原子性。
 */
interface PlaylistPlatformAdapter
{
    /** 返回稳定的平台格式键，例如 netease 或 qq。 */
    public function format(): string;

    /** @throws PlaylistImportInvalid 文档编码、结构或条目边界不符合平台协议。 */
    public function parse(string $bytes): PlatformPlaylistDocument;
}
