<?php

declare(strict_types=1);

namespace app\application\Metadata;

use app\application\Lyrics\LyricsFileStore;
use app\application\Lyrics\LyricsFileUnavailable;
use support\Db;

/**
 * 判断自动逐曲流程是否仍需调用歌曲元数据插件。
 *
 * 本策略读取创建 target 时已经冻结的歌曲身份以及当前歌词、歌曲图片事实；歌词行还必须通过统一文件存储
 * 边界的根路径、大小和 SHA 校验，不把“数据库有一行”误当成用户可读取的歌词。
 * 文件标签缺少 title、artist 或 album 时，证据会进入 filename 模式并必须查询插件；标签身份完整时，
 * 只有歌词或歌曲图片仍缺失才查询。专辑/艺人详情和它们的图片由各自策略及资源阶段负责，不能成为调用
 * 歌曲插件的理由。手工刮削和全量刷新由上层显式强制查询，不经过本策略短路。
 *
 * 幂等性：相同冻结证据与相同业务资源事实始终返回同一结果；判断不创建任务、不修改选择或缓存。
 * 失败关闭：滚动部署缺少歌词或图片表时返回 true，宁可保留一次既有查询，也不能误判资源已经完整。
 */
final readonly class SongMetadataScrapePolicy
{
    public function __construct(private LyricsFileStore $lyrics = new LyricsFileStore()) {}

    /**
     * 返回自动任务是否需要调用歌曲插件。
     *
     * @param array<string,mixed> $evidence MetadataSyncScrapeService 生成的无路径冻结证据
     * @return bool true 表示歌曲身份或歌曲资源仍不完整；损坏证据和缺表均失败关闭为 true
     */
    public function shouldQuery(array $evidence): bool
    {
        if (($evidence['scrapeInputMode'] ?? null) !== 'metadata') return true;
        $songId = $evidence['songId'] ?? null;
        $libraryId = $evidence['libraryId'] ?? null;
        if (!is_string($songId) || !is_string($libraryId)) return true;

        $schema = Db::connection()->getSchemaBuilder();
        if (!$schema->hasTable('media_lyrics') || !$this->hasUsableLyrics($songId)) {
            return true;
        }
        if (!$schema->hasTable('media_artwork_selection_overrides')) return true;

        // ArtworkCurrentStateService 会把专辑图视为歌曲的可展示回退；这里判断的是歌曲插件是否仍有
        // 补全空间，因此只有歌曲自己的明确选择才算完整，继承专辑图不能短路歌曲查询。
        return !Db::table('media_artwork_selection_overrides')->where('song_id', $songId)
            ->where('library_id', $libraryId)->exists();
    }

    /**
     * 只要有一条歌词能够按正式读取规则通过校验，就认为歌曲歌词资源完整。
     *
     * 每条记录都可能指向被删除、漂移或被替换的缓存/相邻文件；读取失败只跳过该记录，不能让一个坏索引
     * 阻断其他有效歌词。方法只读文件和数据库，不修改陈旧行；所有异常按资源缺失处理，使自动策略失败关闭。
     */
    private function hasUsableLyrics(string $songId): bool
    {
        /** @var list<string> $ids */
        $ids = Db::table('media_lyrics')->where('song_id', $songId)->pluck('id')->map('strval')->all();
        foreach ($ids as $id) {
            try {
                $this->lyrics->bytesById($id);
                return true;
            } catch (LyricsFileUnavailable) {
                // 继续检查同一歌曲的其他歌词记录；陈旧索引由后续清理流程处理。
            }
        }
        return false;
    }
}
