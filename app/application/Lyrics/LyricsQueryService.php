<?php

declare(strict_types=1);

namespace app\application\Lyrics;

use app\application\Media\MediaQueryService;
use stdClass;
use support\Db;

/**
 * 在歌曲实时媒体授权范围内，从受控文件即时解析并返回结构化歌词。
 *
 * 公开投影不包含定位摘要、内容摘要、匹配证据或文件路径。库停用、媒体缺失、元数据失败、ID 无效和
 * grant 撤回统一返回 null，使 Controller 使用不可枚举的 404；已授权但无歌词的歌曲返回成功空集合。
 */
final class LyricsQueryService
{
    public function __construct(
        private readonly MediaQueryService $media = new MediaQueryService(),
        private readonly LyricsFileStore $files = new LyricsFileStore(),
    )
    {
    }

    /**
     * 按偏好顺序解析一首已授权歌曲的全部可展示歌词版本；正文只存在于当前调用栈。
     *
     * @param array<string, mixed> $actor Authenticated principal with global play capability.
     * @return array{songId: string, lyrics: list<array<string, mixed>>}|null
     * @throws LyricsFileUnavailable 歌词索引对应文件缺失、越界或内容身份已经漂移。
     */
    public function forSong(array $actor, string $songId): ?array
    {
        if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $songId) !== 1) {
            return null;
        }
        $song = $this->media->songsByIds($actor, [$songId])[$songId] ?? null;
        if (!is_array($song) || !is_string($song['id'] ?? null)) return null;
        // 兼容旧来源 ID 时，授权查询在请求 key 下返回保留歌曲投影。歌词索引必须跟随投影中的规范 ID，
        // 否则播放、详情已指向目标而歌词仍来自隐藏来源；证据失效后投影 ID 自然恢复为原来源。
        $songId = $song['id'];

        /** @var list<stdClass> $rows */
        $rows = Db::table('media_lyrics as lyrics')
            ->leftJoin('media_lyrics_primary_selections as primary_selection', 'primary_selection.song_id', '=', 'lyrics.song_id')
            ->where('lyrics.song_id', $songId)
            ->orderByRaw('CASE WHEN lyrics.id = primary_selection.lyric_id THEN 0 ELSE 1 END')
            ->orderByDesc('lyrics.priority')->orderBy('lyrics.language')->orderBy('lyrics.id')
            ->get([
                'lyrics.id', 'lyrics.source_kind', 'lyrics.language', 'lyrics.lyric_kind', 'lyrics.source_format',
                'lyrics.license_policy', 'lyrics.version', 'lyrics.updated_at',
                Db::raw('CASE WHEN lyrics.id = primary_selection.lyric_id THEN 1 ELSE 0 END as is_primary'),
            ])->all();
        $lyrics = [];
        foreach ($rows as $row) {
            $lines = $this->files->readById((string) $row->id)->lines;
            $lyrics[] = [
                'id' => (string) $row->id,
                'language' => (string) $row->language,
                'kind' => (string) $row->lyric_kind,
                'format' => (string) $row->source_format,
                'source' => $this->publicSource((string) $row->source_kind),
                'lines' => $lines,
                'usage' => $this->usage((string) $row->license_policy),
                'version' => (int) $row->version,
                'isPrimary' => (int) $row->is_primary === 1,
                'updatedAt' => (string) $row->updated_at,
            ];
        }

        return ['songId' => $songId, 'lyrics' => $lyrics];
    }

    /** 把内部来源映射为稳定公开类别，不暴露定位或 Provider 细节。 */
    private function publicSource(string $sourceKind): string
    {
        return match ($sourceKind) {
            'sidecar' => 'local_file',
            'embedded' => 'embedded_tag',
            'manual' => 'manual_override',
            default => 'provider',
        };
    }

    /**
     * 投影保守的客户端用途标记；本地控制允许导出，但没有明确可再分发许可时始终禁止公开分享。
     *
     * @return array{canCache: bool, canExport: bool, canShare: bool}
     */
    private function usage(string $policy): array
    {
        return match ($policy) {
            'local_controlled' => ['canCache' => true, 'canExport' => true, 'canShare' => false],
            'cache_allowed' => ['canCache' => true, 'canExport' => false, 'canShare' => false],
            'redistributable' => ['canCache' => true, 'canExport' => true, 'canShare' => true],
            default => ['canCache' => false, 'canExport' => false, 'canShare' => false],
        };
    }
}
