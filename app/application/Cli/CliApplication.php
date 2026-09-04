<?php

declare(strict_types=1);

namespace app\application\Cli;

use app\application\Account\EmergencyPasswordResetService;
use app\application\Auth\CapabilityResolver;
use app\application\Artist\ArtistProfileScrapeJobService;
use app\application\Dlna\DlnaService;
use app\application\Dlna\DlnaUnavailable;
use app\application\Library\LibraryAccessResolver;
use app\application\Scan\ScanCreateInput;
use app\application\Scan\ScanJobService;
use app\application\ResourcePlugin\InitialPluginPackageService;
use app\application\ResourcePlugin\PhpResourcePluginPackageService;
use app\application\Metadata\MetadataPolicyReprojectionService;
use app\application\Metadata\MetadataPolicyReprojectionBusy;
use app\application\Metadata\MetadataScrapePolicyConflict;
use app\application\Metadata\MetadataScrapePolicyUnavailable;
use app\application\System\SqliteBackupService;
use InvalidArgumentException;
use stdClass;
use support\Db;
use Throwable;

/**
 * 提供 Web 不可用时仍可执行的本机管理入口（ND-305）。
 *
 * CLI 与 HTTP 共用数据库和领域服务，不复制扫描状态机。只读命令不写数据库；高成本命令必须
 * 同时指定现有活动管理员和精确确认词，且只创建持久任务，由现有 Worker 异步执行。输出不包含物理
 * 路径、密钥、SQL 或堆栈。进程退出码 0 表示成功，2 表示输入/业务拒绝，3 表示完整性检查失败，
 * 1 表示未分类运行故障。
 */
final class CliApplication
{
    /** 解析并执行一个命令；本方法捕获所有异常，防止敏感堆栈进入终端流水线日志。 */
    public function run(array $arguments): int
    {
        try {
            $parsed = (new CliOptionParser())->parse($arguments);
            $result = match ($parsed['command']) {
                'help' => $this->help(),
                'database:check' => $this->databaseCheck(),
                'system:status' => $this->systemStatus(),
                'backup:create' => $this->createBackup($parsed['options']),
                'plugin:initialize-defaults' => $this->initializeDefaultPlugins($parsed['options']),
                'plugin:finalize-pending' => $this->finalizePendingPlugins($parsed['options']),
                'scan:create' => $this->createScan($parsed['options']),
                'artist-profile:refresh-all' => $this->refreshAllArtistProfiles($parsed['options']),
                'metadata-policy:reproject' => $this->reprojectMetadataPolicy($parsed['options']),
                'user:reset-password' => $this->resetPassword($parsed['options']),
                'dlna:devices' => $this->dlnaDevices($parsed['options']),
                'dlna:play' => $this->dlnaPlay($parsed['options']),
                'dlna:pause' => $this->dlnaControl('pause', $parsed['options']),
                'dlna:resume' => $this->dlnaControl('resume', $parsed['options']),
                'dlna:stop' => $this->dlnaControl('stop', $parsed['options']),
                'dlna:seek' => $this->dlnaControl('seek', $parsed['options']),
                'dlna:volume' => $this->dlnaControl('volume', $parsed['options']),
                'dlna:status' => $this->dlnaControl('status', $parsed['options']),
                default => throw new InvalidArgumentException('未知管理命令。'),
            };
            $this->output($result, ($parsed['options']['json'] ?? false) === true);
            return ($result['healthy'] ?? true) === true ? 0 : 3;
        } catch (InvalidArgumentException $invalid) {
            fwrite(STDERR, 'Velin CLI: ' . $invalid->getMessage() . PHP_EOL);
            return 2;
        } catch (DlnaUnavailable) {
            fwrite(STDERR, 'Velin CLI: 当前状态不满足命令执行条件，请重新检查状态和版本。' . PHP_EOL);
            return 2;
        } catch (MetadataPolicyReprojectionBusy|MetadataScrapePolicyConflict|MetadataScrapePolicyUnavailable) {
            fwrite(STDERR, 'Velin CLI: 元数据策略重投影当前不可执行，请等待活动任务结束并检查策略版本。' . PHP_EOL);
            return 2;
        } catch (Throwable $failure) {
            fwrite(STDERR, 'Velin CLI: 命令未执行，错误码 CLI_OPERATION_FAILED。' . PHP_EOL);
            return 1;
        }
    }

    /** 返回静态帮助，不连接数据库。 */
    private function help(): array
    {
        return ['commands' => [
            'database:check [--json]',
            'system:status [--json]',
            'backup:create --actor=USERNAME --confirm=CREATE_DATABASE_BACKUP [--json]',
            'plugin:initialize-defaults [--json]',
            'plugin:finalize-pending [--json]',
            'scan:create --actor=USERNAME --library=ULID --type=incremental|full --confirm=QUEUE_SCAN [--json]',
            'artist-profile:refresh-all --actor=USERNAME --confirm=REFRESH_ALL_ARTIST_PROFILES [--json]',
            'metadata-policy:reproject --actor=USERNAME --confirm=REPROJECT_METADATA_POLICY [--json]',
            'user:reset-password --username=USERNAME --password-stdin --confirm=RESET_PASSWORD [--json]',
            'dlna:devices --actor=USERNAME [--json]',
            'dlna:play --actor=USERNAME --device=UUID --song=ULID [--format=raw|mp3|aac|opus] [--json]',
            'dlna:pause|resume|stop|status --actor=USERNAME --device=UUID [--json]',
            'dlna:seek --actor=USERNAME --device=UUID --position-ms=N [--json]',
            'dlna:volume --actor=USERNAME --device=UUID --volume=0..100 [--json]',
        ]];
    }

    /**
     * 执行 SQLite 快速完整性、外键和迁移版本检查。
     *
     * PRAGMA 在当前配置的只读查询连接上执行，不创建备份、不修复数据也不运行迁移。任何外键行只计数，
     * 不输出表名/主键，避免终端日志泄露内部对象。检查失败返回 healthy=false 和退出码 3。
     */
    private function databaseCheck(): array
    {
        $connection = Db::connection();
        $quickRows = $connection->select('PRAGMA quick_check');
        $quick = count($quickRows) === 1 && strtolower((string) array_values((array) $quickRows[0])[0]) === 'ok';
        $foreignViolations = count($connection->select('PRAGMA foreign_key_check'));
        $schema = (string) (Db::table('phinxlog')->max('version') ?? 'unavailable');
        return ['healthy' => $quick && $foreignViolations === 0, 'quickCheck' => $quick ? 'ok' : 'failed',
            'foreignKeyViolations' => $foreignViolations, 'schemaVersion' => $schema];
    }

    /**
     * 返回无路径、无账号明细的本机运行摘要。
     *
     * 统计仅用于故障排查，不替代 Prometheus。任务只聚合排队/运行数量，不领取任务也不触发媒体遍历。
     * 数据库完整性不健康时仍返回其余安全计数，便于离线判断处理优先级。
     */
    private function systemStatus(): array
    {
        $database = $this->databaseCheck();
        return ['healthy' => $database['healthy'], 'database' => $database,
            'activeUsers' => Db::table('users')->where('status', 'active')->whereNull('deleted_at')->count(),
            'activeLibraries' => Db::table('music_libraries')->where('status', 'active')->count(),
            'queuedOrRunning' => [
                'scans' => Db::table('library_scan_jobs')->whereIn('status', ['queued', 'running', 'cancel_requested'])->count(),
                'syncScrape' => Db::connection()->getSchemaBuilder()->hasTable('metadata_sync_scrape_jobs')
                    ? Db::table('metadata_sync_scrape_jobs')->whereIn('status', ['queued', 'running'])->count() : 0,
                'scrapeAssetPublications' => Db::connection()->getSchemaBuilder()->hasTable('scrape_asset_publications')
                    ? Db::table('scrape_asset_publications')->whereIn('status', ['queued', 'running'])->count() : 0,
                'metadataBatches' => Db::connection()->getSchemaBuilder()->hasTable('metadata_batch_plans')
                    ? Db::table('metadata_batch_plans')->whereIn('status', ['queued', 'running'])->count() : 0,
            ]];
    }

    /**
     * 为现有活动管理员创建在线一致的 SQLite 备份。
     *
     * 命令要求 `manage_system` 和不可缩写确认词，不接受路径或文件名。服务仅返回不透明备份 ID、大小和
     * 创建时间；快照完整性校验及原子发布完成后才写审计，失败不会覆盖上一份有效备份。
     */
    private function createBackup(array $options): array
    {
        $this->allowedOptions($options, ['actor', 'confirm', 'json']);
        if (($options['confirm'] ?? null) !== 'CREATE_DATABASE_BACKUP') {
            throw new InvalidArgumentException('数据库备份确认词必须是 CREATE_DATABASE_BACKUP。');
        }
        $actor = $this->actor($this->required($options, 'actor'), 'manage_system');
        return (new SqliteBackupService())->createManual((string) $actor['id'], $this->requestId());
    }

    /**
     * 在核心迁移完成且任何 Webman/插件 Worker 尚未启动时初始化镜像默认插件。
     *
     * 命令不接受 key、ZIP 或目录参数，默认插件集合只能由已签名镜像内的版本化清单决定。每个 key 只在
     * 当前持久数据根初始化一次；已有安装和管理员后续卸载都会被尊重。任一完整性或数据库错误返回非零，
     * entrypoint 随即停止，不能在缺少声明默认能力的半初始化状态启动业务进程。
     */
    private function initializeDefaultPlugins(array $options): array
    {
        $this->allowedOptions($options, ['json']);
        return (new InitialPluginPackageService())->initialize();
    }

    /**
     * 在 Webman 与插件 Worker 启动前完成后台已经登记的插件卸载。
     *
     * 命令不接受 key 或路径，只扫描固定活动根下具有 `.velin-remove-pending` 标记的安全一级目录；每个
     * 插件分别在数据库事务中删除数据并原子移除包。某项失败时进程非零退出，入口不会启动任何 Worker，
     * 已成功项保持已删除，失败项恢复原目录并可在下次启动重试。
     */
    private function finalizePendingPlugins(array $options): array
    {
        $this->allowedOptions($options, ['json']);
        $packages = new PhpResourcePluginPackageService();
        $purged = $packages->purgeCompletedQuarantines();
        $root = base_path('plugin');
        if (!is_dir($root)) return ['finalized' => [], 'purged' => $purged];
        $keys = [];
        foreach (glob($root . '/*', GLOB_ONLYDIR) ?: [] as $directory) {
            $key = basename($directory);
            if (!is_link($directory) && preg_match('/^[a-z0-9][a-z0-9_-]{1,47}$/D', $key) === 1
                && is_file($directory . '/.velin-remove-pending')
                && !is_link($directory . '/.velin-remove-pending')) $keys[] = $key;
        }
        sort($keys);
        $finalized = [];
        foreach ($keys as $key) {
            $finalized[] = $packages->finalizePendingUninstall(
                $key, $key, 'startup-plugin-uninstall-' . bin2hex(random_bytes(8)),
            );
        }
        return ['finalized' => $finalized, 'purged' => $purged];
    }

    /**
     * 通过现有 ScanJobService 为一个受管活动音乐库排队扫描。
     *
     * 命令要求 manage_library、目标库实时 manage 范围、固定扫描类型和精确确认词。调用只写任务与审计，
     * 文件遍历由 Worker 完成；冲突或失权时领域事务整体拒绝，不产生部分任务。
     */
    private function createScan(array $options): array
    {
        $this->allowedOptions($options, ['actor', 'library', 'type', 'confirm', 'json']);
        if (($options['confirm'] ?? null) !== 'QUEUE_SCAN') throw new InvalidArgumentException('扫描确认词必须是 QUEUE_SCAN。');
        $libraryId = $this->required($options, 'library');
        if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $libraryId) !== 1) throw new InvalidArgumentException('音乐库 ID 无效。');
        $type = $this->required($options, 'type');
        if (!in_array($type, ['incremental', 'full'], true)) throw new InvalidArgumentException('扫描类型必须是 incremental 或 full。');
        $actor = $this->actor($this->required($options, 'actor'), 'manage_library');
        $job = (new ScanJobService())->createJob($libraryId, new ScanCreateInput($type), $actor, $this->requestId());
        return ['queued' => true, 'type' => 'scan', 'jobId' => $job['id'], 'status' => $job['status']];
    }

    /**
     * 为全部当前可播放艺人强制登记资料刷新任务。
     *
     * 该全局高成本命令同时要求 manage_system 与 run_scrape，并使用不可缩写确认词防止误触。命令只写
     * 幂等任务和审计，不删除现有简介，也不在 CLI 进程访问第三方；实际请求继续由单一艺人 Worker
     * 限速执行。部分批次后进程中断时可安全重复同一命令，唯一 artist_id 不会产生重复任务。
     */
    private function refreshAllArtistProfiles(array $options): array
    {
        $this->allowedOptions($options, ['actor', 'confirm', 'json']);
        if (($options['confirm'] ?? null) !== 'REFRESH_ALL_ARTIST_PROFILES') {
            throw new InvalidArgumentException('艺人资料刷新确认词必须是 REFRESH_ALL_ARTIST_PROFILES。');
        }
        $actor = $this->actor($this->required($options, 'actor'), 'manage_system', 'run_scrape');
        $result = (new ArtistProfileScrapeJobService())->forceRefreshAll(
            (string) $actor['id'],
            $this->requestId(),
        );
        return ['queued' => true, 'type' => 'artist_profile_refresh'] + $result;
    }

    /**
     * 把已保存的元数据优先级与繁转简策略重新应用到历史业务投影。
     *
     * 命令要求系统管理与元数据编辑能力及不可缩写确认词。执行只调用分批领域服务，不读取媒体、访问插件
     * 或绕过字段锁；部分批次后中断可使用同一命令安全重跑。输出只含对象/字段计数，不暴露媒体名称或路径。
     */
    private function reprojectMetadataPolicy(array $options): array
    {
        $this->allowedOptions($options, ['actor', 'confirm', 'json']);
        if (($options['confirm'] ?? null) !== 'REPROJECT_METADATA_POLICY') {
            throw new InvalidArgumentException('元数据策略重投影确认词必须是 REPROJECT_METADATA_POLICY。');
        }
        $actor = $this->actor($this->required($options, 'actor'), 'manage_system', 'edit_metadata');
        return ['reprojected' => true] + (new MetadataPolicyReprojectionService())->reprojectAll(
            (string) $actor['id'],
            $this->requestId(),
        );
    }

    /**
     * 从标准输入读取一次密码并调用应急恢复领域服务。
     *
     * `--password-stdin` 必须显式存在且不能携带值，密码不会出现在 argv、Shell 历史、JSON 输出或审计
     * 元数据中。只移除管道通常附带的最后一个换行；空输入、超长输入和读取失败均在写库前拒绝。
     */
    private function resetPassword(array $options): array
    {
        $this->allowedOptions($options, ['username', 'password-stdin', 'confirm', 'json']);
        if (($options['password-stdin'] ?? null) !== true) {
            throw new InvalidArgumentException('密码必须通过 --password-stdin 从标准输入读取。');
        }
        $password = stream_get_contents(STDIN, 130);
        if (!is_string($password)) throw new InvalidArgumentException('无法从标准输入读取密码。');
        if (str_ends_with($password, "\r\n")) {
            $password = substr($password, 0, -2);
        } elseif (str_ends_with($password, "\n")) {
            $password = substr($password, 0, -1);
        }
        $result = (new EmergencyPasswordResetService())->reset(
            $this->required($options, 'username'),
            $password,
            $this->required($options, 'confirm'),
            $this->requestId(),
        );
        return ['changed' => true, 'type' => 'password', 'userId' => $result['userId'],
            'sessionsRevoked' => $result['sessionsRevoked'], 'tokensRevoked' => $result['tokensRevoked'],
            'playbackLeasesReleased' => $result['playbackLeasesReleased'], 'changedAt' => $result['changedAt']];
    }

    /**
     * 通过后端 helper 发现当前可信局域网的 Renderer；输出不包含设备 IP 或控制 URL。
     *
     * 命令要求一个活动且具有 play 的现有账号，使发现入口与实际投放使用相同启用开关和审计身份边界。
     * SSDP 只读操作不创建票据、不修改队列，也不会自动选择或控制任何音响。
     */
    private function dlnaDevices(array $options): array
    {
        $this->allowedOptions($options, ['actor', 'json']);
        $actor = $this->actor($this->required($options, 'actor'), 'play', 'cast');
        return ['devices' => (new DlnaService())->discover($actor)];
    }

    /**
     * 为一首实时授权歌曲创建短期票据并将 URI 交给指定 Renderer 后开始播放。
     *
     * 未提交 format 时使用 raw，直接把原文件交给 Renderer，避免首次播放等待转码；设备不支持源容器时
     * 操作者可明确选择 MP3/AAC/Opus；转码继承 play 与 cast 授权。命令不接受 URL、文件路径或 FFmpeg
     * 参数，helper 失败时领域服务撤销新票据，但设备已接受命令后的外部状态无法事务回滚。
     */
    private function dlnaPlay(array $options): array
    {
        $this->allowedOptions($options, ['actor', 'device', 'song', 'format', 'json']);
        $format = $options['format'] ?? 'raw';
        if (!is_string($format) || !in_array($format, ['raw', 'mp3', 'aac', 'opus'], true)) {
            throw new InvalidArgumentException('DLNA 格式必须是 raw、mp3、aac 或 opus。');
        }
        $actor = $this->actor($this->required($options, 'actor'), 'play', 'cast');
        return ['playback' => (new DlnaService())->play(
            $actor,
            $this->required($options, 'device'),
            $this->ulid($options, 'song'),
            $format,
            $this->requestId(),
        )];
    }

    /**
     * 执行一个基础 Renderer 控制命令；status 纯读取，其他动作写设备但不改媒体或队列。
     *
     * seek 使用毫秒且允许 0，volume 使用 0..100；其他动作拒绝数值，防止拼写错误被静默忽略。网络
     * 失败不会在 CLI 内重试，避免旧固件对重复非幂等 SOAP 命令产生意外状态。
     */
    private function dlnaControl(string $operation, array $options): array
    {
        $allowed = ['actor', 'device', 'json'];
        $value = null;
        if ($operation === 'seek') {
            $allowed[] = 'position-ms';
            $value = $this->boundedInteger($options, 'position-ms', 0, 604_800_000);
        } elseif ($operation === 'volume') {
            $allowed[] = 'volume';
            $value = $this->boundedInteger($options, 'volume', 0, 100);
        }
        $this->allowedOptions($options, $allowed);
        $actor = $this->actor($this->required($options, 'actor'), 'play', 'cast');
        return ['control' => (new DlnaService())->control(
            $actor,
            $this->required($options, 'device'),
            $operation,
            $value,
            $this->requestId(),
        )];
    }

    /** @return array<string,mixed> */
    private function actor(string $username, string ...$requiredCapabilities): array
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{2,63}$/', $username) !== 1) throw new InvalidArgumentException('管理员账号格式无效。');
        /** @var stdClass|null $row */
        $row = Db::table('users')->where('username', strtolower($username))->where('status', 'active')
            ->whereNull('deleted_at')->where(static function ($query): void {
                $query->whereNull('account_expires_at')->orWhere('account_expires_at', '>', gmdate('Y-m-d\TH:i:s\Z'));
            })->first(['id', 'is_super_admin']);
        if (!$row instanceof stdClass) throw new InvalidArgumentException('管理员账号不可用。');
        $super = (int) $row->is_super_admin === 1;
        $capabilities = (new CapabilityResolver())->resolve((string) $row->id, $super);
        foreach ($requiredCapabilities as $capability) {
            if (!in_array($capability, $capabilities, true)) {
                throw new InvalidArgumentException('管理员账号缺少所需能力。');
            }
        }
        return ['id' => (string) $row->id, 'isSuperAdmin' => $super, 'capabilities' => $capabilities,
            'libraries' => (new LibraryAccessResolver())->resolve((string) $row->id, $super)];
    }

    /** 拒绝未知选项，避免拼写错误被静默忽略后执行高风险命令。 */
    private function allowedOptions(array $options, array $allowed): void
    {
        $unknown = array_diff(array_keys($options), $allowed);
        if ($unknown !== []) throw new InvalidArgumentException('存在未知选项：' . implode(', ', $unknown));
    }

    private function required(array $options, string $key): string
    {
        $value = $options[$key] ?? null;
        if (!is_string($value) || trim($value) === '') throw new InvalidArgumentException("缺少 --{$key} 选项。");
        return trim($value);
    }

    /** 解析版本和秒数等正整数，拒绝符号、小数、空白及 PHP 的宽松数值转换。 */
    private function positiveInteger(array $options, string $key): int
    {
        $value = $this->required($options, $key);
        if (preg_match('/^[1-9][0-9]{0,9}$/', $value) !== 1) {
            throw new InvalidArgumentException("--{$key} 必须是正整数。");
        }
        return (int) $value;
    }

    /** 解析包含零的有界十进制参数，拒绝符号、小数和宽松数值转换。 */
    private function boundedInteger(array $options, string $key, int $minimum, int $maximum): int
    {
        $value = $this->required($options, $key);
        if (preg_match('/^(0|[1-9][0-9]{0,9})$/', $value) !== 1) {
            throw new InvalidArgumentException("--{$key} 必须是十进制整数。");
        }
        $integer = (int) $value;
        if ($integer < $minimum || $integer > $maximum) {
            throw new InvalidArgumentException("--{$key} 超出允许范围。");
        }
        return $integer;
    }

    /** 读取并校验固定 ULID 选项，浏览器或终端都不能用物理路径替代恢复对象 ID。 */
    private function ulid(array $options, string $key): string
    {
        $value = $this->required($options, $key);
        if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $value) !== 1) {
            throw new InvalidArgumentException("--{$key} 不是有效的 ULID。");
        }
        return $value;
    }

    /** 输出 JSON 或紧凑可读键值；所有字段均由服务端固定投影产生。 */
    private function output(array $result, bool $json): void
    {
        if ($json) {
            fwrite(STDOUT, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
            return;
        }
        foreach ($result as $key => $value) {
            fwrite(STDOUT, $key . ': ' . (is_array($value)
                ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
                : (is_bool($value) ? ($value ? 'true' : 'false') : (string) $value)) . PHP_EOL);
        }
    }

    /** 生成不含操作者输入的 CLI 审计关联 ID。 */
    private function requestId(): string
    {
        return 'cli-' . bin2hex(random_bytes(14));
    }
}
