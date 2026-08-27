<?php

declare(strict_types=1);

namespace app\application\Artwork;

/**
 * 提供内置封面渠道的固定展示归属。
 *
 * 映射由产品代码维护，浏览器、数据库和环境变量不能扩展或改写。返回值只包含平台名称与公开主页，
 * 不含候选 URL、资源 ID、Cookie 或 Token；插件聚合结果不伪造外部主页，因此 URL 为 null。未知渠道
 * 返回 null 并由调用方拒绝入库。
 */
final readonly class ArtworkProviderAttribution
{
    private const SOURCES = [
        'netease' => ['网易云音乐', 'https://music.163.com/'],
        'qq' => ['QQ 音乐', 'https://y.qq.com/'],
        'kugou' => ['酷狗音乐', 'https://www.kugou.com/'],
        'kuwo' => ['酷我音乐', 'https://www.kuwo.cn/'],
        'migu' => ['咪咕音乐', 'https://music.migu.cn/'],
        'soda' => ['汽水音乐', 'https://www.douyin.com/qishui/'],
        'apple_music' => ['Apple Music', 'https://music.apple.com/'],
        'musicbrainz' => ['MusicBrainz / Cover Art Archive', 'https://coverartarchive.org/'],
        'metadata-scrape' => ['元数据刮削插件', null],
    ];

    /** @return array{text:string,url:?string}|null */
    public static function for(string $source): ?array
    {
        $value = self::SOURCES[$source] ?? null;
        return is_array($value) ? ['text' => '封面来源：' . $value[0], 'url' => $value[1]] : null;
    }
}
