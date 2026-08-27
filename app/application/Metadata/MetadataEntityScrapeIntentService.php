<?php

declare(strict_types=1);

namespace app\application\Metadata;

use app\application\Artist\ArtistProfileScrapeJobService;
use stdClass;
use support\Db;
use Symfony\Component\Uid\Ulid;
use Throwable;

/**
 * 把歌曲完成与艺人/专辑后续任务拆成事务性意图。
 *
 * 歌曲事务只需写入唯一 song_id；插件 Worker 在事务外分发两个独立实体队列。分发失败保留意图并按
 * 到期时间重试，成功后删除意图；因此不会出现歌曲已成功但后续入队异常被永久吞掉，也不会把实体
 * 插件调用放进歌曲元数据事务或网络阶段。重复消费由两个实体服务自身幂等收敛。
 */
final readonly class MetadataEntityScrapeIntentService
{
    private const RETRY_SECONDS = 60;
    private const STALE_SECONDS = 300;

    public function __construct(
        private ArtistProfileScrapeJobService $artists = new ArtistProfileScrapeJobService(),
        private AlbumMetadataScrapeJobService $albums = new AlbumMetadataScrapeJobService(),
    ) {}

    /** 在歌曲核心事务中登记一次幂等后续分发意图。 */
    public function enqueue(string $songId, string $now): void
    {
        if (!Db::connection()->getSchemaBuilder()->hasTable('metadata_entity_scrape_intents')) return;
        Db::table('metadata_entity_scrape_intents')->insertOrIgnore([
            'id' => (string) new Ulid(), 'song_id' => $songId, 'status' => 'queued', 'attempt' => 0,
            'next_attempt_at' => $now, 'worker_id' => null, 'error_code' => null,
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    /** 分发一条到期意图；失败保留并退避，成功才删除。 */
    public function processOne(string $workerId): bool
    {
        if (!Db::connection()->getSchemaBuilder()->hasTable('metadata_entity_scrape_intents')) return false;
        $now = gmdate('Y-m-d\TH:i:s\Z');
        Db::table('metadata_entity_scrape_intents')->where('status', 'running')
            ->where('updated_at', '<=', gmdate('Y-m-d\TH:i:s\Z', time() - self::STALE_SECONDS))
            ->update(['status' => 'queued', 'worker_id' => null, 'next_attempt_at' => $now,
                'error_code' => 'ENTITY_SCRAPE_INTENT_RECOVERED', 'updated_at' => $now]);
        /** @var stdClass|null $row */
        $row = Db::table('metadata_entity_scrape_intents')->where('status', 'queued')
            ->where('next_attempt_at', '<=', $now)->orderBy('created_at')->first();
        if (!$row instanceof stdClass) return false;
        $claimed = Db::table('metadata_entity_scrape_intents')->where('id', (string) $row->id)
            ->where('status', 'queued')->update([
                'status' => 'running', 'worker_id' => $workerId, 'attempt' => Db::raw('attempt + 1'),
                'updated_at' => $now,
            ]);
        if ($claimed !== 1) return false;
        try {
            $this->artists->enqueueForSong((string) $row->song_id);
            $this->albums->enqueueForSong((string) $row->song_id);
            Db::table('metadata_entity_scrape_intents')->where('id', (string) $row->id)
                ->where('status', 'running')->where('worker_id', $workerId)->delete();
        } catch (Throwable) {
            Db::table('metadata_entity_scrape_intents')->where('id', (string) $row->id)
                ->where('status', 'running')->where('worker_id', $workerId)->update([
                    'status' => 'queued', 'worker_id' => null,
                    'next_attempt_at' => gmdate('Y-m-d\TH:i:s\Z', time() + self::RETRY_SECONDS),
                    'error_code' => 'ENTITY_SCRAPE_ENQUEUE_RETRY', 'updated_at' => gmdate('Y-m-d\TH:i:s\Z'),
                ]);
        }
        return true;
    }
}
