<?php

declare(strict_types=1);

namespace app\application\Subsonic;

use app\application\Auth\UserActorProjector;
use app\application\System\PublicUrlConfig;
use JsonException;
use RuntimeException;

/**
 * 签发和解析供 Subsonic 艺人信息图片使用的短时无状态票据。
 *
 * 部分客户端会直接下载 `artistInfo2` 图片 URL，不会为该子请求附加 u/t/s。票据使用部署凭据密钥派生
 * 的独立 secretbox 密钥加密账号、艺人和一小时过期时间，URL 不出现密码挑战、明文账号或媒体 ID。
 * 解析时仍通过 UserActorProjector 重新检查账号状态、到期、play 能力和实时音乐库授权；票据只恢复待
 * 验证意图，不能授予图片访问。服务不落库、不写审计或日志，密钥轮换会使旧票据立即失效。
 */
final readonly class SubsonicArtworkTicketService
{
    private const MAXIMUM_AGE_SECONDS = 3600;

    public function __construct(private UserActorProjector $actors = new UserActorProjector())
    {
    }

    /**
     * 为已经授权且已确认存在图片的艺人生成绝对 URL 基址。
     *
     * 调用方必须来自 SubsonicAuthenticator，并已通过艺人实时可见性查询。公开 Origin 只取部署配置，
     * 缺失或非法时失败关闭，不能采用请求 Host。随机 nonce 保证同一对象的票据不可关联；生成失败不
     * 修改任何状态，调用方应退化为空 artistInfo，而不是暴露内部配置细节。
     */
    public function createUrl(array $actor, string $artistId): string
    {
        $userId = is_string($actor['id'] ?? null) ? $actor['id'] : '';
        if (!$this->ulid($userId) || !$this->ulid($artistId)
            || !in_array('play', $actor['capabilities'] ?? [], true)) {
            throw new RuntimeException('Subsonic artwork ticket input is invalid.');
        }
        $origin = PublicUrlConfig::publicOrigin();
        if ($origin === null) throw new RuntimeException('Public URL is unavailable.');
        $payload = json_encode([
            'v' => 1, 'u' => $userId, 'a' => $artistId,
            'e' => time() + self::MAXIMUM_AGE_SECONDS,
        ], JSON_THROW_ON_ERROR);
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $token = rtrim(strtr(base64_encode($nonce . sodium_crypto_secretbox(
            $payload,
            $nonce,
            $this->key(),
        )), '+/', '-_'), '=');

        return $origin . '/subsonic/v1/artist-artworks/' . rawurlencode($token);
    }

    /**
     * 解密票据并重建实时账号投影；所有伪造、过期、失效和撤权结果统一返回 null。
     *
     * 解密只接受固定版本和字段集合，最大 token 长度限制在解析前执行，避免任意大输入消耗内存。即使
     * payload 完整，账号被停用、到期或失去 play 后也不能继续读取；艺人及音乐库权限由后续统一图片
     * 服务再次验证。方法不刷新票据，也没有可延长的服务端会话状态。
     *
     * @return array{actor:array<string,mixed>,artistId:string}|null
     */
    public function resolve(string $token): ?array
    {
        if ($token === '' || strlen($token) > 512 || preg_match('/^[A-Za-z0-9_-]+$/D', $token) !== 1) {
            return null;
        }
        $padding = (4 - strlen($token) % 4) % 4;
        $decoded = base64_decode(strtr($token . str_repeat('=', $padding), '-_', '+/'), true);
        if (!is_string($decoded) || strlen($decoded) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) return null;
        $nonce = substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open(
            substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $nonce,
            $this->key(),
        );
        if (!is_string($plain)) return null;
        try {
            $payload = json_decode($plain, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        if (!is_array($payload) || array_keys($payload) !== ['v', 'u', 'a', 'e']
            || ($payload['v'] ?? null) !== 1 || !is_int($payload['e'] ?? null)
            || $payload['e'] < time() || $payload['e'] > time() + self::MAXIMUM_AGE_SECONDS
            || !is_string($payload['u'] ?? null) || !is_string($payload['a'] ?? null)
            || !$this->ulid($payload['u']) || !$this->ulid($payload['a'])) {
            return null;
        }
        $actor = $this->actors->project($payload['u']);
        if (!is_array($actor) || !in_array('play', $actor['capabilities'] ?? [], true)) return null;

        return ['actor' => $actor, 'artistId' => $payload['a']];
    }

    /** 为艺人图片票据派生独立用途密钥，不能与 Subsonic 密码密文互换。 */
    private function key(): string
    {
        $root = getenv('VELIN_CREDENTIAL_KEY') ?: 'velin-development-credential-key-change-me';
        if (strlen($root) < 16) throw new RuntimeException('Credential key is invalid.');
        return hash_hkdf('sha256', $root, SODIUM_CRYPTO_SECRETBOX_KEYBYTES, 'velin-subsonic-artwork-ticket-v1');
    }

    /** 票据只绑定规范 ULID；对象授权在解密之后重新验证。 */
    private function ulid(string $value): bool
    {
        return preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/D', $value) === 1;
    }
}
