<?php

declare(strict_types=1);

namespace app\application\ResourcePlugin;

use app\infrastructure\Audit\AuditLogger;
use Closure;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use JsonException;
use Psr\Http\Message\ResponseInterface;
use stdClass;
use support\Db;
use Throwable;

/**
 * 管理受信插件商店的源地址、索引协议和服务端安装入口。
 *
 * 源地址是管理员明确配置的 HTTPS 索引，也可使用规范 GitHub 仓库首页并转换到该仓库 main 分支的 Raw
 * 索引；不接受凭据、IP 字面量、私网 DNS 或重定向。索引只能声明同源相对 ZIP、SemVer、大小和 SHA-256。
 * 浏览器只看到脱敏的插件描述，真正下载由本服务在短超时内完成，并交给与手工上传相同的 ZIP 校验、
 * 版本 CAS、数据库生命周期和回滚流程。索引不写入业务数据库，远端短暂不可用时不会改变已安装插件
 * 或旧配置。
 */
final readonly class PluginStoreService
{
    private const SETTING_KEY = 'resource.plugin_store';
    private const INDEX_SCHEMA_VERSION = 1;
    private const MAX_INDEX_BYTES = 1_048_576;
    private const MAX_PLUGINS = 100;
    private const MAX_ARCHIVE_BYTES = 16 * 1024 * 1024;
    private const HTTPS_PORT = 443;
    private const SEMVER = '/^(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?$/D';

    public function __construct(
        private ?ClientInterface $http = null,
        private ?Closure $resolver = null,
        private AuditLogger $audit = new AuditLogger(),
        private ?PhpResourcePluginPackageService $packages = null,
    ) {
    }

    /**
     * 返回管理员配置的脱敏插件源设置。
     *
     * 返回的版本用于前端 CAS；读取不访问网络、不解密秘密，也不会把远程索引内容混入设置快照。迁移
     * 缺失或 JSON 结构损坏时失败关闭，不能用空地址掩盖数据库故障。
     *
     * @return array{sourceUrl:string,version:int,updatedAt:string}
     * @throws PluginStoreUnavailable 设置缺失或持久化内容损坏
     */
    public function configuration(): array
    {
        $row = Db::table('system_settings')->where('setting_key', self::SETTING_KEY)->first();
        if (!$row instanceof stdClass) throw new PluginStoreUnavailable();
        try {
            $value = json_decode((string) $row->value_json, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new PluginStoreUnavailable(previous: $exception);
        }
        if (!is_array($value) || array_is_list($value) || array_keys($value) !== ['sourceUrl']
            || !is_string($value['sourceUrl'])) throw new PluginStoreUnavailable();
        try {
            $sourceUrl = $this->normalizeSourceUrl($value['sourceUrl'], false);
        } catch (PluginStoreInvalid $exception) {
            throw new PluginStoreUnavailable(previous: $exception);
        }
        return ['sourceUrl' => $sourceUrl, 'version' => (int) $row->version, 'updatedAt' => (string) $row->updated_at];
    }

    /**
     * 以 expectedVersion 原子保存源地址。
     *
     * 空字符串表示停用插件商店；非空地址只做静态 HTTPS 校验，网络连通性由单独的目录读取请求确认，
     * 因而保存不会因远端暂时故障写入半成品。并发管理员使用旧版本时整个更新回滚，不会覆盖新地址。
     *
     * @return array{sourceUrl:string,version:int,updatedAt:string}
     * @throws PluginStoreInvalid 字段、URL 或版本无效
     * @throws PluginStoreConflict expectedVersion 已过期
     * @throws PluginStoreUnavailable 设置缺失或数据库不可用
     */
    public function update(array $command, string $actorId, string $requestId): array
    {
        if (array_is_list($command) || array_diff(array_keys($command), ['sourceUrl', 'expectedVersion']) !== []
            || count($command) !== 2 || !is_string($command['sourceUrl'] ?? null)
            || !is_int($command['expectedVersion'] ?? null) || $command['expectedVersion'] < 1) {
            throw new PluginStoreInvalid();
        }
        $sourceUrl = $this->normalizeSourceUrl($command['sourceUrl'], false);
        return Db::transaction(function () use ($actorId, $command, $requestId, $sourceUrl): array {
            $before = $this->configuration();
            $version = $before['version'] + 1;
            $changed = Db::table('system_settings')->where('setting_key', self::SETTING_KEY)
                ->where('version', $command['expectedVersion'])->update([
                    'value_json' => json_encode(['sourceUrl' => $sourceUrl], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    'version' => $version, 'updated_by' => $actorId, 'updated_at' => gmdate('Y-m-d\TH:i:s\Z'),
                ]);
            if ($changed !== 1) throw new PluginStoreConflict();
            $this->audit->record($actorId, 'resource.plugin_store.update', 'system_setting', self::SETTING_KEY,
                'success', $requestId, ['enabled' => $sourceUrl !== '', 'version' => $version]);
            return $this->configuration();
        });
    }

    /**
     * 拉取并校验一个远程插件索引，返回与本机安装目录合并后的公开投影。
     *
     * 请求最多读取 1 MiB 索引，单次连接和总耗时均有硬上限，且禁用环境代理继承与 HTTP 重定向。索引失败
     * 不清空上一次页面状态；已安装版本只由核心注册表计算，不由远程站点伪造。
     *
     * @return array{sourceUrl:string,sourceName:?string,schemaVersion:int,fetchedAt:string,plugins:list<array<string,mixed>>}
     * @throws PluginStoreUnavailable 远端连接、HTTP、DNS 或读取失败
     * @throws PluginStoreInvalid 索引结构或插件描述不符合协议
     */
    public function catalog(): array
    {
        $configuration = $this->configuration();
        if ($configuration['sourceUrl'] === '') {
            return ['sourceUrl' => '', 'sourceName' => null, 'schemaVersion' => self::INDEX_SCHEMA_VERSION,
                'fetchedAt' => gmdate('c'), 'plugins' => []];
        }
        $loaded = $this->loadCatalog($configuration['sourceUrl']);
        $installed = [];
        foreach ((new PhpResourcePluginRegistry())->list() as $plugin) {
            if (is_string($plugin['key'] ?? null)) $installed[$plugin['key']] = $plugin;
        }
        $plugins = [];
        foreach ($loaded['plugins'] as $plugin) {
            $local = $installed[$plugin['key']] ?? null;
            $installedVersion = is_array($local) && is_string($local['version'] ?? null) ? $local['version'] : null;
            $plugins[] = [
                'key' => $plugin['key'], 'name' => $plugin['name'], 'version' => $plugin['version'],
                'description' => $plugin['description'], 'author' => $plugin['author'], 'homepage' => $plugin['homepage'],
                'capabilities' => $plugin['capabilities'], 'sizeBytes' => $plugin['sizeBytes'],
                'installed' => is_array($local), 'installedVersion' => $installedVersion,
                'updateAvailable' => $installedVersion !== null && version_compare($plugin['version'], $installedVersion, '>'),
            ];
        }
        return ['sourceUrl' => $configuration['sourceUrl'], 'sourceName' => $loaded['sourceName'],
            'schemaVersion' => self::INDEX_SCHEMA_VERSION, 'fetchedAt' => gmdate('c'), 'plugins' => $plugins];
    }

    /**
     * 按当前索引安装一个插件。
     *
     * 请求只接受索引中的稳定 key；服务端会重新读取索引，拒绝过期或外部地址，并将下载字节流落到一次
     * 性临时文件后校验大小和 SHA-256。安装器成功前临时文件会清理，安装器内部的包目录、数据库事务和
     * 升级备份仍是唯一发布边界；远端下载失败不会创建插件目录。
     *
     * @return array{pluginKey:string,databaseVersion:int,status:string,restartRequired:bool,backupKept?:bool}
     * @throws PluginStoreInvalid key 或索引内容无效
     * @throws PluginStoreNotFound 索引没有该插件
     * @throws PluginStoreUnavailable 远端或本地暂存不可用
     */
    public function install(string $pluginKey, string $actorId, string $requestId): array
    {
        if (preg_match('/^[a-z0-9][a-z0-9_-]{1,47}$/D', $pluginKey) !== 1) throw new PluginStoreInvalid();
        $configuration = $this->configuration();
        if ($configuration['sourceUrl'] === '') throw new PluginStoreUnavailable();
        $loaded = $this->loadCatalog($configuration['sourceUrl']);
        $selected = null;
        foreach ($loaded['plugins'] as $plugin) {
            if ($plugin['key'] === $pluginKey) { $selected = $plugin; break; }
        }
        if (!is_array($selected)) throw new PluginStoreNotFound();
        $archive = tempnam(sys_get_temp_dir(), 'velin-plugin-store-');
        if (!is_string($archive) || is_link($archive)) throw new PluginStoreUnavailable();
        try {
            $this->downloadArchive($selected['downloadUrl'], $selected['sizeBytes'], $selected['sha256'], $archive);
            return ($this->packages ?? new PhpResourcePluginPackageService())->install(
                $archive, $pluginKey . '.zip', $actorId, $requestId,
            );
        } finally {
            if (is_file($archive) && !is_link($archive)) @unlink($archive);
        }
    }

    /** @return array{sourceName:string,plugins:list<array<string,mixed>>} */
    private function loadCatalog(string $sourceUrl): array
    {
        $response = $this->request($sourceUrl);
        $body = $response->getBody();
        $bytes = '';
        while (!$body->eof()) {
            $bytes .= $body->read(min(16_384, self::MAX_INDEX_BYTES + 1 - strlen($bytes)));
            if (strlen($bytes) > self::MAX_INDEX_BYTES) throw new PluginStoreInvalid('PLUGIN_STORE_INDEX_TOO_LARGE');
        }
        try {
            $document = json_decode($bytes, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new PluginStoreInvalid('PLUGIN_STORE_INDEX_INVALID', previous: $exception);
        }
        if (!is_array($document) || array_is_list($document)
            || array_diff(array_keys($document), ['schemaVersion', 'name', 'plugins']) !== []
            || count($document) !== 3 || $document['schemaVersion'] !== self::INDEX_SCHEMA_VERSION
            || !$this->text($document['name'] ?? null, 1, 120) || !is_array($document['plugins'])
            || !array_is_list($document['plugins']) || count($document['plugins']) > self::MAX_PLUGINS) {
            throw new PluginStoreInvalid('PLUGIN_STORE_INDEX_INVALID');
        }
        $items = [];
        $keys = [];
        foreach ($document['plugins'] as $entry) {
            $item = $this->parseItem($entry, $sourceUrl);
            if (isset($keys[$item['key']])) throw new PluginStoreInvalid('PLUGIN_STORE_DUPLICATE_PLUGIN');
            $keys[$item['key']] = true;
            $items[] = $item;
        }
        return ['sourceName' => $document['name'], 'plugins' => $items];
    }

    /** @return array<string,mixed> */
    private function parseItem(mixed $entry, string $sourceUrl): array
    {
        if (!is_array($entry) || array_is_list($entry)) throw new PluginStoreInvalid('PLUGIN_STORE_PLUGIN_INVALID');
        $required = ['key', 'name', 'version', 'description', 'author', 'homepage', 'capabilities', 'download', 'sha256', 'sizeBytes'];
        $keys = array_keys($entry); sort($keys); $expected = $required; sort($expected);
        if ($keys !== $expected || !$this->text($entry['key'] ?? null, 2, 48)
            || preg_match('/^[a-z0-9][a-z0-9_-]{1,47}$/D', $entry['key']) !== 1
            || !$this->text($entry['name'] ?? null, 1, 120) || !$this->text($entry['version'] ?? null, 1, 64)
            || preg_match(self::SEMVER, $entry['version']) !== 1 || !$this->text($entry['description'] ?? null, 1, 500)
            || !$this->text($entry['author'] ?? null, 1, 120) || !is_string($entry['homepage'] ?? null)
            || !is_array($entry['capabilities']) || !array_is_list($entry['capabilities'])
            || count(array_unique($entry['capabilities'])) !== count($entry['capabilities'])
            || !$this->text($entry['download'] ?? null, 1, 2_000)
            || !is_string($entry['sha256']) || preg_match('/^[a-f0-9]{64}$/D', $entry['sha256']) !== 1
            || !is_int($entry['sizeBytes']) || $entry['sizeBytes'] < 1 || $entry['sizeBytes'] > self::MAX_ARCHIVE_BYTES) {
            throw new PluginStoreInvalid('PLUGIN_STORE_PLUGIN_INVALID');
        }
        $homepage = $this->normalizeHttpsUrl($entry['homepage'], 2_000);
        $downloadUrl = $this->resolveDownloadUrl($sourceUrl, $entry['download']);
        foreach ($entry['capabilities'] as $capability) {
            if (!$this->text($capability, 1, 64)) throw new PluginStoreInvalid('PLUGIN_STORE_PLUGIN_INVALID');
        }
        return ['key' => $entry['key'], 'name' => $entry['name'], 'version' => $entry['version'],
            'description' => $entry['description'], 'author' => $entry['author'], 'homepage' => $homepage,
            'capabilities' => array_values($entry['capabilities']), 'sizeBytes' => $entry['sizeBytes'],
            'sha256' => $entry['sha256'], 'downloadUrl' => $downloadUrl];
    }

    private function downloadArchive(string $url, int $expectedSize, string $expectedHash, string $path): void
    {
        $response = $this->request($url);
        $body = $response->getBody();
        $handle = @fopen($path, 'wb');
        if ($handle === false) throw new PluginStoreUnavailable();
        $hash = hash_init('sha256'); $size = 0;
        try {
            while (!$body->eof()) {
                $chunk = $body->read(min(65_536, self::MAX_ARCHIVE_BYTES + 1 - $size));
                if ($chunk === '') break;
                $size += strlen($chunk);
                if ($size > $expectedSize || $size > self::MAX_ARCHIVE_BYTES
                    || fwrite($handle, $chunk) !== strlen($chunk)) throw new PluginStoreInvalid('PLUGIN_STORE_ARCHIVE_INVALID');
                hash_update($hash, $chunk);
            }
        } finally {
            fclose($handle);
        }
        if ($size !== $expectedSize || !hash_equals($expectedHash, hash_final($hash))) {
            throw new PluginStoreInvalid('PLUGIN_STORE_ARCHIVE_DIGEST_INVALID');
        }
    }

    private function request(string $url): ResponseInterface
    {
        $target = $this->resolveRemoteUrl($url);
        if (!extension_loaded('curl') || !defined('CURLOPT_RESOLVE')) throw new PluginStoreUnavailable();
        $ip = $target['ip'];
        try {
            $response = ($this->http ?? new Client(['http_errors' => false]))->request('GET', $target['url'], [
                'allow_redirects' => false, 'connect_timeout' => 4.0, 'timeout' => 12.0,
                'proxy' => '', 'http_errors' => false, 'headers' => ['Accept' => 'application/json,application/zip', 'User-Agent' => 'Velin-Music-PluginStore/1'],
                'curl' => [CURLOPT_RESOLVE => [$target['host'] . ':443:' . $ip]],
            ]);
        } catch (GuzzleException|Throwable $exception) {
            throw new PluginStoreUnavailable(previous: $exception);
        }
        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) throw new PluginStoreUnavailable();
        return $response;
    }

    /** @return array{url:string,host:string,ip:string} */
    private function resolveRemoteUrl(string $url): array
    {
        $normalized = $this->normalizeSourceUrl($url, true);
        $host = (string) parse_url($normalized, PHP_URL_HOST);
        $ips = $this->resolver instanceof Closure ? ($this->resolver)($host) : $this->systemAddresses($host);
        if (!is_array($ips) || $ips === []) throw new PluginStoreUnavailable();
        $public = [];
        foreach ($ips as $ip) {
            if (!is_string($ip) || filter_var($ip, FILTER_VALIDATE_IP) === false
                || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw new PluginStoreUnavailable();
            }
            $public[] = $ip;
        }
        return ['url' => $normalized, 'host' => $host, 'ip' => $public[0]];
    }

    /** @return list<string> */
    private function systemAddresses(string $host): array
    {
        $records = dns_get_record($host, DNS_A | DNS_AAAA);
        if (!is_array($records)) return [];
        $addresses = [];
        foreach ($records as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if (is_string($ip)) $addresses[] = $ip;
        }
        return array_values(array_unique($addresses));
    }

    /**
     * 将管理员源配置收敛为可直接读取索引的 HTTPS URL。
     *
     * 官方文档公开的是便于人工访问的 GitHub 仓库首页，而 HTTP 客户端需要同源 Raw JSON 和 ZIP；这里只
     * 兼容严格的两段 owner/repository 路径，并固定到 main/index.json，不发起网页请求、不跟随重定向，
     * 也不接受 branch、子路径或查询参数。其他 HTTPS 静态源保持原样并继续经过主机、端口、凭据和片段
     * 校验。空值只允许在配置读写阶段表示停用，实际网络请求 strict=true 时仍失败关闭。
     */
    private function normalizeSourceUrl(string $value, bool $strict): string
    {
        $value = trim($value);
        if ($value === '' && !$strict) return '';
        $normalized = $this->normalizeHttpsUrl($value, 2_000);
        $parts = parse_url($normalized);
        if (is_array($parts) && strtolower((string) ($parts['host'] ?? '')) === 'github.com') {
            $path = (string) ($parts['path'] ?? '');
            if (isset($parts['query'])
                || preg_match('~^/([A-Za-z0-9](?:[A-Za-z0-9-]{0,38}))/([A-Za-z0-9._-]{1,100}?)(?:\.git)?/?$~D', $path, $matches) !== 1
                || $matches[2] === '.' || $matches[2] === '..') {
                throw new PluginStoreInvalid('PLUGIN_STORE_URL_INVALID');
            }
            return 'https://raw.githubusercontent.com/' . $matches[1] . '/' . $matches[2] . '/main/index.json';
        }
        return $normalized;
    }

    private function normalizeHttpsUrl(string $value, int $maximum): string
    {
        if ($value === '' || strlen($value) > $maximum || preg_match('/[\x00-\x20\x7F]/', $value) === 1) {
            throw new PluginStoreInvalid('PLUGIN_STORE_URL_INVALID');
        }
        $parts = parse_url($value);
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || $host === ''
            || filter_var($host, FILTER_VALIDATE_IP) !== false || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['fragment'])
            || (isset($parts['port']) && (int) $parts['port'] !== self::HTTPS_PORT)
            || preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $host) !== 1) {
            throw new PluginStoreInvalid('PLUGIN_STORE_URL_INVALID');
        }
        return 'https://' . $host . (string) ($parts['path'] ?? '')
            . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }

    private function resolveDownloadUrl(string $sourceUrl, string $download): string
    {
        $resolved = (string) UriResolver::resolve(new Uri($sourceUrl), new Uri($download));
        $source = parse_url($sourceUrl); $target = parse_url($resolved);
        if (!is_array($target) || !is_array($source) || strtolower((string) ($target['scheme'] ?? '')) !== 'https'
            || strtolower((string) ($target['host'] ?? '')) !== strtolower((string) ($source['host'] ?? ''))
            || (isset($target['port']) && (int) $target['port'] !== self::HTTPS_PORT)
            || isset($target['user']) || isset($target['pass']) || isset($target['fragment'])) {
            throw new PluginStoreInvalid('PLUGIN_STORE_DOWNLOAD_URL_INVALID');
        }
        return $this->normalizeHttpsUrl($resolved, 2_000);
    }

    private function text(mixed $value, int $minimum, int $maximum): bool
    {
        return is_string($value) && mb_check_encoding($value, 'UTF-8')
            && mb_strlen($value, 'UTF-8') >= $minimum && mb_strlen($value, 'UTF-8') <= $maximum
            && preg_match('/[\x00-\x1F\x7F]/u', $value) !== 1;
    }
}

/** 插件商店请求或远程索引内容不符合固定协议。 */
final class PluginStoreInvalid extends \RuntimeException {}

/** 插件商店设置的 expectedVersion 已过期。 */
final class PluginStoreConflict extends \RuntimeException {}

/** 插件商店索引中不存在请求的稳定插件 key。 */
final class PluginStoreNotFound extends \RuntimeException {}

/** 插件商店设置缺失，或远程服务暂时不可用。 */
final class PluginStoreUnavailable extends \RuntimeException {}
