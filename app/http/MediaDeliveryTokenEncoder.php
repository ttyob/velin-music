<?php

declare(strict_types=1);

namespace app\http;

use Closure;
use JsonException;

/**
 * 把当前请求已经授权的本地文件身份编码为 Go 网关可读取的短期加密描述。
 *
 * 根密钥从部署认证摘要密钥经用途隔离 HKDF 派生，只用于容器回环链路上的 AES-256-GCM；token 不形成
 * 浏览器 URL，也不进入日志、数据库或审计。编码前仍会解析真实路径、限制允许根、拒绝符号链接并冻结
 * dev/inode/size/mtime。Go 打开文件后必须重复同一身份校验，所以 PHP 成功不授权路径后续发生的替换。
 */
final class MediaDeliveryTokenEncoder
{
    private const AAD = 'velin-media-delivery-v1';
    private const HKDF_INFO = 'velin-media-delivery-key-v1';
    private const MAX_PATH_BYTES = 4096;
    private const MAX_TOKEN_BYTES = 16_384;

    /** @var list<string> */
    private array $roots;
    private string $key;
    private Closure $clock;
    private Closure $nonceFactory;

    /**
     * @param list<string>|null $allowedRoots 测试可注入隔离根；生产只读取 media_delivery 固定配置。
     * @param Closure():int|null $clock 返回 Unix 秒，测试向量可冻结；生产使用当前系统时间。
     * @param Closure():string|null $nonceFactory 返回恰好 12 字节随机 nonce；重复 nonce 会破坏 GCM 安全性。
     */
    public function __construct(
        ?string $rootSecret = null,
        ?array $allowedRoots = null,
        ?Closure $clock = null,
        ?Closure $nonceFactory = null,
    ) {
        $secret = $rootSecret ?? RequestContext::authenticationHashKey();
        if (strlen($secret) < 16 || !function_exists('openssl_encrypt')) {
            throw new MediaDeliveryUnavailable('MEDIA_DELIVERY_CRYPTO_UNAVAILABLE');
        }
        $this->key = hash_hkdf('sha256', $secret, 32, self::HKDF_INFO);
        $this->roots = $this->canonicalRoots($allowedRoots ?? (array) config('media_delivery.allowed_roots', []));
        $this->clock = $clock ?? static fn (): int => time();
        $this->nonceFactory = $nonceFactory ?? static fn (): string => random_bytes(12);
    }

    /**
     * 判断文件是否可以交给当前网关根集合。
     *
     * 本方法只用于裸机兼容回退：不存在、非规范或根外路径返回 false，调用方继续使用 Workerman 文件响应。
     * 返回 true 不代表文件身份已冻结，`encode` 仍必须执行完整 stat 校验并可能因并发替换而失败。
     */
    public function supports(string $path): bool
    {
        $canonical = realpath($path);
        return $canonical !== false && $this->withinRoots($canonical);
    }

    /**
     * 生成一个只允许读取指定字节范围、十秒后失效的版本 1 token。
     *
     * offset/length 使用字节且必须完全位于当前文件内；完整响应同样显式记录范围，Go 不从 HTTP 请求重新
     * 推导。函数只读取文件元数据并使用随机 nonce，不打开正文、不创建缓存。任一校验或加密失败都会抛出
     * 路径无关异常，Controller 将在响应提交前返回既有 503，不会降级发送身份未知的字节。
     */
    public function encode(string $path, int $offset, int $length): string
    {
        $canonical = realpath($path);
        if ($canonical === false || strlen($canonical) > self::MAX_PATH_BYTES || !$this->withinRoots($canonical)
            || is_link($canonical) || !is_file($canonical) || !is_readable($canonical)) {
            throw new MediaDeliveryUnavailable('MEDIA_DELIVERY_PATH_INVALID');
        }
        $stat = @stat($canonical);
        if (!is_array($stat) || (int) $stat['size'] < 1 || (int) $stat['mtime'] < 1
            || $offset < 0 || $length < 1 || $offset > (int) $stat['size']
            || $length > (int) $stat['size'] - $offset) {
            throw new MediaDeliveryUnavailable('MEDIA_DELIVERY_IDENTITY_INVALID');
        }
        $expiresAt = ($this->clock)() + 10;
        try {
            $payload = json_encode([
                'version' => 1,
                'path' => $canonical,
                'device' => (string) $stat['dev'],
                'inode' => (string) $stat['ino'],
                'size' => (int) $stat['size'],
                'modifiedAt' => (int) $stat['mtime'],
                'offset' => $offset,
                'length' => $length,
                'expiresAt' => $expiresAt,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $exception) {
            throw new MediaDeliveryUnavailable('MEDIA_DELIVERY_PROTOCOL_INVALID', previous: $exception);
        }
        return $this->seal($payload, ($this->nonceFactory)());
    }

    /**
     * 使用调用方提供的 12 字节 nonce 封装协议正文。
     *
     * 生产只由 encode 传入 random_bytes；独立方法让合同测试可以冻结 nonce 并与 Go 共享精确测试向量。
     * 方法不解析 JSON，也不访问文件；相同 key/nonce 绝不能在生产重复，nonce 工厂违反约束时失败关闭。
     */
    private function seal(string $payload, string $nonce): string
    {
        if (strlen($nonce) !== 12) throw new MediaDeliveryUnavailable('MEDIA_DELIVERY_NONCE_INVALID');
        $tag = '';
        $ciphertext = openssl_encrypt(
            $payload,
            'aes-256-gcm',
            $this->key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            self::AAD,
            16,
        );
        if (!is_string($ciphertext) || strlen($tag) !== 16) {
            throw new MediaDeliveryUnavailable('MEDIA_DELIVERY_ENCRYPTION_FAILED');
        }
        $token = 'v1.' . rtrim(strtr(base64_encode($nonce . $ciphertext . $tag), '+/', '-_'), '=');
        if (strlen($token) > self::MAX_TOKEN_BYTES) {
            throw new MediaDeliveryUnavailable('MEDIA_DELIVERY_TOKEN_TOO_LARGE');
        }
        return $token;
    }

    /** @param list<string> $roots @return list<string> */
    private function canonicalRoots(array $roots): array
    {
        $canonical = [];
        foreach ($roots as $root) {
            if (!is_string($root) || $root === '' || is_link($root)) {
                throw new MediaDeliveryUnavailable('MEDIA_DELIVERY_ROOT_INVALID');
            }
            $resolved = realpath($root);
            if ($resolved === false || $resolved !== rtrim($root, DIRECTORY_SEPARATOR) || !is_dir($resolved)) {
                throw new MediaDeliveryUnavailable('MEDIA_DELIVERY_ROOT_INVALID');
            }
            $canonical[] = $resolved;
        }
        if ($canonical === []) throw new MediaDeliveryUnavailable('MEDIA_DELIVERY_ROOT_MISSING');
        return $canonical;
    }

    private function withinRoots(string $path): bool
    {
        foreach ($this->roots as $root) {
            if (str_starts_with($path, $root . DIRECTORY_SEPARATOR)) return true;
        }
        return false;
    }
}
