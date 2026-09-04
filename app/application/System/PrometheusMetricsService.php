<?php

declare(strict_types=1);

namespace app\application\System;

use app\application\Storage\StorageLayout;

use app\infrastructure\Observability\HttpMetricStore;
use stdClass;
use support\Db;

/**
 * 生成 Prometheus 0.0.4 文本快照（ND-307、NFR-OPS-002）。
 *
 * HTTP 累计值来自跨 Worker 的有界 runtime 状态；队列、目录容量、播放租约和目录规模在抓取时只读
 * 查询。服务不缓存权限、不触发任务领取、不遍历媒体文件，也不输出数据库/媒体路径、用户或任务 ID。
 * SQLite 查询失败会让整个抓取失败，由 Controller 返回 503，避免以缺失时间序列伪装健康。
 */
final readonly class PrometheusMetricsService
{
    /** @var array<string,string> */
    private const JOB_TABLES = [
        'scan' => 'library_scan_jobs',
        'sync_scrape' => 'metadata_sync_scrape_jobs',
        'scrape_asset_publication' => 'scrape_asset_publications',
        'personal_export' => 'personal_data_export_jobs',
        'scrobble' => 'scrobble_delivery_jobs',
        'artwork_search' => 'artwork_provider_search_jobs',
        'artwork_import' => 'artwork_provider_import_jobs',
        'lyrics_writeback' => 'lyrics_writeback_jobs',
        'lyrics_audio_tag_writeback' => 'lyrics_audio_tag_writeback_jobs',
        'metadata_batch' => 'metadata_batch_plans',
    ];

    public function __construct(private HttpMetricStore $http = new HttpMetricStore())
    {
    }

    /**
     * 返回完整 Prometheus 文本并以换行结尾。
     *
     * 指标标签均来自固定词汇或严格清洗的状态约束；任何部署版本自由文本都经过 Prometheus 转义与长度
     * 限制。Job 使用 gauge 表达当前持久记录分布，HTTP 使用跨重启累计 counter/histogram。
     */
    public function render(): string
    {
        $lines = $this->renderHttp($this->http->snapshot());
        $version = substr((string) (getenv('VELIN_VERSION') ?: '0.1.0-dev'), 0, 64);
        $lines[] = '# HELP velin_up Velin Music 指标抓取时应用与 SQLite 是否可查询。';
        $lines[] = '# TYPE velin_up gauge';
        $lines[] = 'velin_up 1';
        $lines[] = '# HELP velin_build_info 当前 Velin Music 构建信息。';
        $lines[] = '# TYPE velin_build_info gauge';
        $lines[] = 'velin_build_info{version="' . $this->escape($version) . '"} 1';

        $lines[] = '# HELP velin_jobs 按类型和状态统计的持久任务记录数。';
        $lines[] = '# TYPE velin_jobs gauge';
        foreach (self::JOB_TABLES as $type => $table) {
            if (!Db::connection()->getSchemaBuilder()->hasTable($table)) continue;
            /** @var list<stdClass> $rows */
            $rows = Db::table($table)->select(['status', Db::raw('COUNT(*) AS aggregate')])
                ->groupBy('status')->orderBy('status')->get()->all();
            foreach ($rows as $row) {
                $status = preg_match('/^[a-z][a-z0-9_]{0,31}$/', (string) $row->status) === 1
                    ? (string) $row->status : 'unknown';
                $lines[] = 'velin_jobs{type="' . $type . '",status="' . $status . '"} ' . (int) $row->aggregate;
            }
        }

        $now = gmdate('Y-m-d\TH:i:s\Z');
        $leases = Db::connection()->getSchemaBuilder()->hasTable('playback_leases')
            ? Db::table('playback_leases')->where('expires_at', '>', $now)->count() : 0;
        $lines[] = '# HELP velin_playback_leases 当前未过期的账号级播放租约。';
        $lines[] = '# TYPE velin_playback_leases gauge';
        $lines[] = 'velin_playback_leases ' . (int) $leases;
        $songs = Db::connection()->getSchemaBuilder()->hasTable('media_songs')
            ? Db::table('media_songs')->count() : 0;
        $lines[] = '# HELP velin_catalog_songs 当前媒体目录中的歌曲记录数。';
        $lines[] = '# TYPE velin_catalog_songs gauge';
        $lines[] = 'velin_catalog_songs ' . (int) $songs;

        $lines[] = '# HELP velin_storage_bytes 数据库与媒体挂载的可用/总字节数。';
        $lines[] = '# TYPE velin_storage_bytes gauge';
        foreach ($this->storage() as $scope => $capacity) {
            $lines[] = 'velin_storage_bytes{scope="' . $scope . '",kind="available"} ' . $capacity['available'];
            $lines[] = 'velin_storage_bytes{scope="' . $scope . '",kind="total"} ' . $capacity['total'];
        }
        $backup = $this->latestBackup();
        $lines[] = '# HELP velin_sqlite_backup_last_success_timestamp_seconds 最近一份已验证自动备份的完成时间。';
        $lines[] = '# TYPE velin_sqlite_backup_last_success_timestamp_seconds gauge';
        $lines[] = 'velin_sqlite_backup_last_success_timestamp_seconds ' . $backup['timestamp'];
        $lines[] = '# HELP velin_sqlite_backup_last_size_bytes 最近一份已验证自动备份的字节数。';
        $lines[] = '# TYPE velin_sqlite_backup_last_size_bytes gauge';
        $lines[] = 'velin_sqlite_backup_last_size_bytes ' . $backup['bytes'];
        $lines[] = '# HELP velin_sqlite_backups 当前自动备份保留数量。';
        $lines[] = '# TYPE velin_sqlite_backups gauge';
        $lines[] = 'velin_sqlite_backups ' . $backup['count'];
        $lines[] = '# HELP velin_transcode_slots FFmpeg 全局转码槽的当前占用与配置上限。';
        $lines[] = '# TYPE velin_transcode_slots gauge';
        $transcode = $this->transcodeSlots();
        $lines[] = 'velin_transcode_slots{state="active"} ' . $transcode['active'];
        $lines[] = 'velin_transcode_slots{state="limit"} ' . $transcode['limit'];
        $lines[] = '# HELP velin_php_worker_memory_bytes 当前响应 Worker 的 PHP 已分配内存。';
        $lines[] = '# TYPE velin_php_worker_memory_bytes gauge';
        $lines[] = 'velin_php_worker_memory_bytes ' . memory_get_usage(true);

        return implode("\n", $lines) . "\n";
    }

    /**
     * 把固定内部键渲染为 HTTP counter 与 Prometheus 累计 histogram。
     *
     * @param array<string,int> $counters 已由 HttpMetricStore 验证的非负累计整数
     * @return list<string>
     */
    public function renderHttp(array $counters): array
    {
        $lines = [
            '# HELP velin_http_requests_total 按方法、低基数路由组和状态类别统计的 HTTP 请求数。',
            '# TYPE velin_http_requests_total counter',
        ];
        $requests = [];
        $combinations = [];
        foreach ($counters as $key => $value) {
            $parts = explode('|', $key);
            if (count($parts) < 4) continue;
            if ($parts[0] === 'http_requests_total' && count($parts) === 4) {
                $requests[implode('|', array_slice($parts, 1))] = $value;
                $combinations[implode('|', array_slice($parts, 1))] = true;
            }
        }
        ksort($requests);
        foreach ($requests as $labels => $value) {
            [$method, $group, $status] = explode('|', $labels);
            $lines[] = 'velin_http_requests_total{method="' . $method . '",route_group="' . $group
                . '",status_class="' . $status . '"} ' . $value;
        }
        $lines[] = '# HELP velin_http_request_duration_seconds HTTP 同步调度延迟直方图。';
        $lines[] = '# TYPE velin_http_request_duration_seconds histogram';
        ksort($combinations);
        foreach (array_keys($combinations) as $labels) {
            [$method, $group, $status] = explode('|', $labels);
            $labelPrefix = 'method="' . $method . '",route_group="' . $group . '",status_class="' . $status . '"';
            $count = $counters['http_duration_count|' . $labels] ?? 0;
            foreach (HttpMetricStore::bucketsUs() as $bucketUs) {
                $bucket = $counters['http_duration_bucket|' . $labels . '|' . $bucketUs] ?? 0;
                $lines[] = 'velin_http_request_duration_seconds_bucket{' . $labelPrefix . ',le="'
                    . $this->seconds($bucketUs) . '"} ' . $bucket;
            }
            $lines[] = 'velin_http_request_duration_seconds_bucket{' . $labelPrefix . ',le="+Inf"} ' . $count;
            $lines[] = 'velin_http_request_duration_seconds_sum{' . $labelPrefix . '} '
                . $this->seconds($counters['http_duration_sum_us|' . $labels] ?? 0);
            $lines[] = 'velin_http_request_duration_seconds_count{' . $labelPrefix . '} ' . $count;
        }
        return $lines;
    }

    /** 返回固定数据库和媒体挂载容量；不可读文件系统用 0 表示，标签不包含实际路径。 */
    private function storage(): array
    {
        $database = dirname((string) (getenv('VELIN_DB_PATH') ?: base_path('database/velin.sqlite')));
        $media = StorageLayout::STORAGE_ROOT;
        $result = [];
        foreach (['database' => $database, 'media' => $media] as $scope => $path) {
            $available = is_dir($path) && !is_link($path) ? @disk_free_space($path) : false;
            $total = is_dir($path) && !is_link($path) ? @disk_total_space($path) : false;
            $result[$scope] = ['available' => is_float($available) ? (int) $available : 0,
                'total' => is_float($total) ? (int) $total : 0];
        }
        return $result;
    }

    /**
     * 通过非阻塞尝试锁读取 FFmpeg 槽占用，不创建文件也不抢占已用槽。
     *
     * 成功取得某个现有槽锁表示当下空闲并立即释放；取得失败表示另一个进程持有。缺失槽文件表示尚未
     * 使用，同样为空闲。该点时快照可能在抓取后立即变化，只用于容量告警，不参与播放准入。
     */
    private function transcodeSlots(): array
    {
        $limit = max(1, min(32, (int) (getenv('VELIN_TRANSCODE_CONCURRENCY') ?: 4)));
        $runtime = (string) (getenv('VELIN_RUNTIME_PATH') ?: base_path('runtime'));
        $directory = rtrim($runtime, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'transcode-locks';
        $active = 0;
        for ($slot = 0; $slot < $limit; ++$slot) {
            $path = $directory . DIRECTORY_SEPARATOR . 'slot-' . $slot . '.lock';
            if (!is_file($path) || is_link($path)) continue;
            $handle = @fopen($path, 'rb');
            if (!is_resource($handle)) continue;
            if (@flock($handle, LOCK_EX | LOCK_NB)) {
                @flock($handle, LOCK_UN);
            } else {
                ++$active;
            }
            fclose($handle);
        }
        return ['active' => $active, 'limit' => $limit];
    }

    /**
     * 只统计固定自动备份目录内符合命名契约的非链接文件。
     *
     * 文件名时间由备份服务在完整性检查后生成并原子发布，因此可作为最近成功时间；不存在或目录身份
     * 异常时返回零，让告警明确触发，绝不把当前抓取时间伪装为成功备份。
     *
     * @return array{timestamp:int,bytes:int,count:int}
     */
    private function latestBackup(): array
    {
        $database = (string) (getenv('VELIN_DB_PATH') ?: base_path('database/velin.sqlite'));
        $resolvedDatabase = realpath($database);
        if (is_string($resolvedDatabase)) $database = $resolvedDatabase;
        $root = dirname($database) . '/backups/automatic';
        if (!is_dir($root) || is_link($root) || realpath($root) !== $root) {
            return ['timestamp' => 0, 'bytes' => 0, 'count' => 0];
        }
        $backups = [];
        foreach (glob($root . '/*.sqlite') ?: [] as $path) {
            if (is_link($path) || !is_file($path)
                || preg_match('/\/(\d{8})T(\d{6})Z-[a-f0-9]{12}\.sqlite$/D', $path, $matches) !== 1) continue;
            $timestamp = strtotime($matches[1] . 'T' . $matches[2] . 'Z');
            $size = filesize($path);
            if ($timestamp !== false && $size !== false && $size > 0) $backups[] = [$timestamp, $size];
        }
        if ($backups === []) return ['timestamp' => 0, 'bytes' => 0, 'count' => 0];
        usort($backups, static fn (array $left, array $right): int => $right[0] <=> $left[0]);
        return ['timestamp' => $backups[0][0], 'bytes' => $backups[0][1], 'count' => count($backups)];
    }

    /** 把微秒整数稳定格式化为不使用科学计数法的秒值。 */
    private function seconds(int $microseconds): string
    {
        return rtrim(rtrim(number_format($microseconds / 1_000_000, 6, '.', ''), '0'), '.') ?: '0';
    }

    /** 按 Prometheus 文本格式转义有限自由标签值。 */
    private function escape(string $value): string
    {
        return str_replace(["\\", "\n", '"'], ["\\\\", '\\n', '\\"'], $value);
    }
}
