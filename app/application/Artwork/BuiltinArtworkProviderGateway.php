<?php

declare(strict_types=1);

namespace app\application\Artwork;

use app\application\Media\AlbumEditionNameNormalizer;
use app\application\Scrape\MetadataQueryKeywordService;
use Throwable;

/**
 * 使用 metadata-scrape 插件结论实现歌曲、专辑与艺人封面 Provider 网关。
 *
 * 搜索只接受代表歌曲的路径无关证据，不读取核心固定渠道目录、代理或 Helper。专辑图必须来自达到 85 分、
 * 标题与艺术家可靠且专辑名精确命中，或只相差可证明的周年/珍藏/豪华/重制版次标记；其他情况下即使
 * 歌曲很像也不能采用，以免把合集、现场版或同名翻唱封面挂到错误专辑。每个平台独立失败，成功图片
 * 冻结到私有 runtime 后才发布
 * 摘要；第三方 URL、平台资源 ID、响应正文和图片字节都不会进入浏览器、任务表、审计或日志。
 *
 * song 查询要求标题规范化精确相同且至少一个艺术家精确重合；它保存为歌曲独立选择，不会覆盖所属
 * 专辑。artist 使用独立协议检索艺人资料图，艺人名须精确命中，且同一平台必须用代表歌曲证明署名
 * 关系；因此歌曲/专辑 artworkUrl 永远不会流入艺人候选。
 */
final readonly class BuiltinArtworkProviderGateway implements ArtworkProviderBatchGateway
{
    private const MINIMUM_SCORE = 85;

    public function __construct(
        private PluginMusicArtworkLookupGateway $lookup = new PluginArtworkLookupGateway(),
        private ArtworkRemoteImageFetcher $images = new ArtworkRemoteImageFetcher(),
        private ArtworkProviderAssetStore $store = new ArtworkProviderAssetStore(),
        private MetadataQueryKeywordService $keywords = new MetadataQueryKeywordService(),
        private SongArtworkIdentityMatcher $songIdentity = new SongArtworkIdentityMatcher(),
        private PluginArtistArtworkLookupGateway $artistLookup = new PluginArtworkLookupGateway(),
        private AlbumEditionNameNormalizer $albumEditions = new AlbumEditionNameNormalizer(),
    ) {
    }

    /**
     * 同步完成一次受控查询并返回终态；Worker 外层仍负责 Durable 任务、授权复验和有界重试。
     *
     * idempotencyKey 只派生 opaque 本地 ID；若缓存已有相同 job，直接返回冻结结果而不再次访问平台。
     * 至少有一个可靠封面地址但全部下载临时失败时抛出可重试错误；确实没有精确专辑候选则成功返回空集。
     */
    public function submit(array $evidence, string $locale, string $region, string $idempotencyKey): array
    {
        $item = $this->submitBatch([compact('evidence', 'locale', 'region', 'idempotencyKey')])[0];
        if ($item['failure'] instanceof ArtworkProviderRemoteFailure) throw $item['failure'];
        return $item['remote'] ?? throw new ArtworkProviderRemoteFailure('ARTWORK_PROVIDER_QUERY_UNAVAILABLE', true, 10);
    }

    /**
     * 逐项调用插件的专辑/艺人钩子，再串行下载并冻结候选。
     *
     * 每个输入保留独立幂等 job 与身份评分；缓存命中不再调用插件。插件内部可自行并行访问其受控来源，
     * 核心不读取来源列表或代理。一个查询、图片下载或协议解析失败只写入对应返回项，不会取消另一项。
     * 此方法不在调用期间写业务 SQLite，Worker 会在返回后按任务租约逐条落库。
     */
    public function submitBatch(array $requests): array
    {
        $prepared = [];
        $responses = [];
        foreach ($requests as $index => $request) {
            try {
                $prepared[$index] = $this->prepare(
                    $request['evidence'], $request['locale'], $request['region'], $request['idempotencyKey'],
                );
            } catch (ArtworkProviderRemoteFailure $failure) {
                $responses[$index] = ['remote' => null, 'failure' => $failure];
            } catch (Throwable) {
                $responses[$index] = ['remote' => null,
                    'failure' => new ArtworkProviderRemoteFailure('ARTWORK_PROVIDER_QUERY_UNAVAILABLE', true, 10)];
            }
        }
        foreach ($prepared as $index => $context) {
            try {
                if (is_array($context['existing'])) {
                    $remote = $context['existing'];
                } else {
                    $remote = $this->publishResults($context, $context['results']);
                }
                $responses[$index] = ['remote' => $remote, 'failure' => null];
            } catch (ArtworkProviderRemoteFailure $failure) {
                $responses[$index] = ['remote' => null, 'failure' => $failure];
            } catch (Throwable) {
                $responses[$index] = ['remote' => null,
                    'failure' => new ArtworkProviderRemoteFailure('ARTWORK_PROVIDER_QUERY_UNAVAILABLE', true, 10)];
            }
        }
        ksort($responses);
        return array_values($responses);
    }

    /** @return array<string,mixed> 构建查询上下文并调用插件最终结论；不在调用前读取核心渠道配置。 */
    private function prepare(array $evidence, string $locale, string $region, string $idempotencyKey): array
    {
        $this->evidence($evidence);
        $jobId = 'builtin-job-' . hash('sha256', $idempotencyKey);
        $existing = $this->store->optionalJob($jobId);
        if ($existing !== null) return ['existing' => $existing];
        $resultId = 'builtin-result-' . hash('sha256', $jobId . ':result');
        $entityType = (string) $evidence['artworkEntityType'];
        $query = [
            'title' => trim((string) $evidence['title']),
            'artists' => array_values($evidence['artists']),
            'album' => trim((string) $evidence['album']),
            // 专辑缓存只能按稳定实体 ID 命中，不能用同名专辑猜测归属。
            'albumId' => $entityType === 'album' ? (string) ($evidence['artworkEntityId'] ?? '') : null,
            'durationMs' => (int) $evidence['durationMs'],
            'isrc' => is_string($evidence['isrc'] ?? null) ? $evidence['isrc'] : null,
            'locale' => $locale,
            'region' => $region,
        ];
        try {
            if ($entityType === 'artist') {
                $results = $this->artistLookup->lookup(
                    (string) $evidence['artworkEntityName'], $query, ['metadata-scrape'],
                );
            } else {
                $results = $this->lookup->lookup(
                    $query, $this->keywords->generate($query['title']), ['metadata-scrape'],
                );
            }
        } catch (ArtworkProviderRemoteFailure $failure) {
            throw $failure;
        } catch (Throwable) {
            throw new ArtworkProviderRemoteFailure('ARTWORK_PROVIDER_QUERY_UNAVAILABLE', true, 10);
        }
        return ['existing' => null, 'jobId' => $jobId, 'resultId' => $resultId, 'evidence' => $evidence,
            'query' => $query, 'results' => $results];
    }

    /**
     * 逐项验证身份、下载图片并发布私有缓存。
     *
     * @param array<string,mixed> $context
     * @param list<array<string,mixed>> $results
     * @return array{id:string,status:string,resultId:?string}
     */
    private function publishResults(array $context, array $results): array
    {
        $jobId = $context['jobId'];
        $resultId = $context['resultId'];
        $evidence = $context['evidence'];
        $query = $context['query'];
        $assets = [];
        $downloadAttempts = 0;
        $retryableFailure = false;
        $seen = [];
        foreach ($results as $result) {
            $source = (string) ($result['source'] ?? '');
            $best = is_array($result['best'] ?? null) ? $result['best'] : null;
            $entityType = (string) $evidence['artworkEntityType'];
            $identityMatches = $best !== null && match ($entityType) {
                'album' => $this->albumNamesEquivalent($query['album'], $best['album'] ?? null)
                    || in_array('ALBUM_EXACT', $best['reasons'] ?? [], true),
                'song' => $this->songIdentity->matches($query, $best),
                'artist' => $this->artistIdentityMatches((string) $evidence['artworkEntityName'], $best),
            };
            $attribution = ArtworkProviderAttribution::for($source);
            if (($result['status'] ?? null) !== 'matched' || $best === null
                || (int) ($best['score'] ?? -1) < self::MINIMUM_SCORE || !$identityMatches
                || $attribution === null || !is_string($best['artworkUrl'] ?? null)
                || trim($best['artworkUrl']) === '') continue;
            ++$downloadAttempts;
            try {
                $image = $this->images->fetch($source, $best['artworkUrl']);
            } catch (ArtworkProviderRemoteFailure $failure) {
                $retryableFailure = $retryableFailure || $failure->retryable;
                continue;
            } catch (Throwable) {
                $retryableFailure = true;
                continue;
            }
            $dedupe = $source . ':' . $image['sha256'];
            if (isset($seen[$dedupe])) continue;
            $seen[$dedupe] = true;
            $assets[] = $image + [
                'id' => 'builtin-asset-' . hash('sha256', $jobId . ':' . $dedupe),
                'providerKey' => $source,
                'kind' => 'front',
                'attribution' => ['required' => true, 'text' => $attribution['text'], 'url' => $attribution['url']],
            ];
        }
        if ($assets === [] && $downloadAttempts > 0 && $retryableFailure) {
            throw new ArtworkProviderRemoteFailure('ARTWORK_PROVIDER_REMOTE_UNAVAILABLE', true, 10);
        }
        $this->store->publish($jobId, $resultId, $assets);
        return ['id' => $jobId, 'status' => 'succeeded', 'resultId' => $resultId];
    }

    /** 返回私有缓存中的终态搜索；不会重新访问第三方。 */
    public function job(string $jobId): array
    {
        return $this->store->job($jobId);
    }

    /** 返回多渠道无字节摘要；每张 asset 明确携带自己的固定 providerKey。 */
    public function result(string $resultId): array
    {
        return $this->store->result($resultId);
    }

    /** 返回搜索时冻结并复验摘要的图片字节，不按旧 URL 重新下载。 */
    public function asset(string $assetId): array
    {
        return $this->store->asset($assetId);
    }

    /** 验证录音证据足以区分目标；艺人查询还必须携带服务端实体名。 */
    private function evidence(array $evidence): void
    {
        if (!in_array($evidence['artworkEntityType'] ?? null, ['song', 'album', 'artist'], true)
            || !is_string($evidence['title'] ?? null) || trim($evidence['title']) === ''
            || !is_string($evidence['album'] ?? null)
            || !is_array($evidence['artists'] ?? null) || !array_is_list($evidence['artists'])
            || $evidence['artists'] === [] || !is_int($evidence['durationMs'] ?? null)
            || $evidence['durationMs'] < 1 || $evidence['durationMs'] > 86_400_000) {
            throw new ArtworkProviderRemoteFailure('ARTWORK_PROVIDER_EVIDENCE_INVALID', false);
        }
        if (($evidence['artworkEntityType'] ?? null) === 'artist'
            && (!is_string($evidence['artworkEntityName'] ?? null)
                || trim($evidence['artworkEntityName']) === '' || mb_strlen($evidence['artworkEntityName']) > 500)) {
            throw new ArtworkProviderRemoteFailure('ARTWORK_PROVIDER_EVIDENCE_INVALID', false);
        }
        foreach ($evidence['artists'] as $artist) {
            if (!is_string($artist) || trim($artist) === '') {
                throw new ArtworkProviderRemoteFailure('ARTWORK_PROVIDER_EVIDENCE_INVALID', false);
            }
        }
    }

    /**
     * 复验艺人协议的两项硬证据。
     *
     * 艺人名只忽略 Unicode 标点、空白和大小写，不做包含匹配或繁简自动转换；同名歧义由 Go 查询器
     * 结合平台内代表歌曲关系拒绝。两个原因码缺一不可，防止旧版或损坏 helper 只返回一张热门头像。
     */
    private function artistIdentityMatches(string $expected, array $candidate): bool
    {
        $actual = $candidate['artistName'] ?? null;
        if (!is_string($actual) || trim($actual) === '') return false;
        $normalize = static fn (string $value): string => mb_strtolower(
            (string) preg_replace('/[^\p{L}\p{N}]+/u', '', trim($value)), 'UTF-8',
        );
        $left = $normalize($expected);
        $right = $normalize($actual);
        $reasons = is_array($candidate['reasons'] ?? null) ? $candidate['reasons'] : [];
        return $left !== '' && hash_equals($left, $right)
            && in_array('ARTIST_EXACT', $reasons, true)
            && in_array('REPRESENTATIVE_SONG_MATCH', $reasons, true);
    }

    /**
     * 判断本地专辑名与平台发行名是否只存在明确版次差异。
     *
     * 本地整理常保留“10周年珍藏版、豪华版”等发行说明，而歌曲平台可能只返回基础专辑名。方法只删除
     * 开头独立四位年份和末尾括号内白名单版次词，再忽略标点与大小写；Live、电影原声、地区、卷号等
     * 具有作品区分意义的文字不在白名单，不能借此放宽匹配。空值和完全被删除的名称始终不匹配。
     */
    private function albumNamesEquivalent(string $local, mixed $remote): bool
    {
        if (!is_string($remote) || trim($remote) === '') return false;
        if ($this->albumEditions->equivalent($local, $remote)) return true;

        $left = $this->artworkAlbumKey($local);
        $right = $this->artworkAlbumKey($remote);
        return $left !== '' && hash_equals($left, $right);
    }

    /**
     * 生成仅供高置信度封面准入使用的发行名别名键。
     *
     * 本地标签常把目录年份写成“2011-专辑名”，平台目录则只保留发行名；这里只删除带明确分隔符的
     * 19xx/20xx 前缀，纯数字作品名不受影响。中文专辑还存在“11月/十一月”的等价书写，只转换标题
     * 开头紧邻“月”的 1..12，其他数字、中文数词和正文均保留。该键不能用于合并专辑；调用方已经
     * 要求代表歌曲、艺人和至少 85 分证据，因此别名只解决展示标签差异，不放宽录音身份。
     */
    private function artworkAlbumKey(string $title): string
    {
        $value = $this->albumEditions->baseName($title);
        $value = (string) preg_replace('/^(?:19|20)\d{2}\s*[-_：:]\s*/u', '', trim($value), 1);
        $months = [
            1 => '一', 2 => '二', 3 => '三', 4 => '四', 5 => '五', 6 => '六',
            7 => '七', 8 => '八', 9 => '九', 10 => '十', 11 => '十一', 12 => '十二',
        ];
        $value = (string) preg_replace_callback('/^(1[0-2]|[1-9])月/u',
            static fn (array $match): string => $months[(int) $match[1]] . '月', $value, 1);
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));

        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }
}
