<?php

declare(strict_types=1);

namespace app\application\Account;

use app\application\Subsonic\SubsonicCredentialCipher;
use app\infrastructure\Audit\AuditLogger;
use stdClass;
use support\Db;
use Throwable;

/**
 * 为 Web 登录不可用的灾难恢复场景提供本机账号密码重置（ND-305）。
 *
 * 调用方只能传入已经从标准输入读取的明文密码；服务不接收 argv、HTTP 请求或任意操作者身份，也不
 * 负责恢复被删除账号。Argon2id 计算和 Subsonic 用途分离加密在写事务外完成，避免昂贵 CPU 工作长期
 * 占用 SQLite 单写锁。成功事务会同时更新两份凭据、撤销全部 Web Session 和个人令牌、释放播放租约
 * 并写入无操作者审计，确保旧认证材料不能在应急改密后继续使用。
 *
 * 本服务不会启用已停用账号、修改角色/音乐库授权或输出任何秘密。失败时事务整体回滚；密码哈希和
 * 密文只存在于当前 PHP 进程内存与目标凭据列，绝不能进入返回值、审计元数据或异常消息。
 */
final readonly class EmergencyPasswordResetService
{
    public function __construct(
        private AuditLogger $audit = new AuditLogger(),
        private SubsonicCredentialCipher $subsonicCipher = new SubsonicCredentialCipher(),
    ) {
    }

    /**
     * 原子重置一个现有未删除本地账号的密码并撤销其全部登录能力。
     *
     * 用户名遵循既有 3-64 位规则并按小写查询；密码必须为 8-128 字节。精确确认词用于防止运维脚本
     * 因参数拼写或管道错误意外改密。账号可以处于 active 或 disabled，后者只更新凭据而不会被本方法
     * 重新启用。并发改密通过原哈希比较失败关闭，其他账号的凭据、会话、令牌和租约均不受影响。
     *
     * @return array{userId:string,sessionsRevoked:int,tokensRevoked:int,playbackLeasesReleased:int,changedAt:string}
     * @throws EmergencyPasswordResetInvalid 输入无效、目标不可恢复、哈希失败或账号在提交前发生变化
     */
    public function reset(string $username, string $password, string $confirmation, string $requestId): array
    {
        $username = strtolower(trim($username));
        if ($confirmation !== 'RESET_PASSWORD'
            || preg_match('/^[a-z0-9][a-z0-9._-]{2,63}$/', $username) !== 1
            || strlen($password) < 8
            || strlen($password) > 128) {
            throw new EmergencyPasswordResetInvalid('应急密码重置参数无效。');
        }

        /** @var stdClass|null $target */
        $target = Db::table('users')->where('username', $username)->whereNull('deleted_at')
            ->first(['id', 'password_hash']);
        if (!$target instanceof stdClass) {
            throw new EmergencyPasswordResetInvalid('目标账号不可用于应急密码重置。');
        }

        $userId = (string) $target->id;
        $originalHash = (string) $target->password_hash;
        $passwordHash = password_hash($password, PASSWORD_ARGON2ID);
        if (!is_string($passwordHash)) {
            throw new EmergencyPasswordResetInvalid('应急密码凭据生成失败。');
        }
        $subsonicCiphertext = $this->subsonicCipher->encrypt($password);

        $pdo = Db::connection()->getPdo();
        $pdo->exec('BEGIN IMMEDIATE');
        $transactionOpen = true;
        try {
            /** @var stdClass|null $locked */
            $locked = Db::table('users')->where('id', $userId)->whereNull('deleted_at')
                ->first(['password_hash']);
            if (!$locked instanceof stdClass || !hash_equals($originalHash, (string) $locked->password_hash)) {
                throw new EmergencyPasswordResetInvalid('目标账号已变化，请重新执行应急密码重置。');
            }

            $changedAt = gmdate('Y-m-d\TH:i:s\Z');
            $updated = Db::table('users')->where('id', $userId)->where('password_hash', $originalHash)
                ->whereNull('deleted_at')->update([
                    'password_hash' => $passwordHash,
                    'subsonic_secret_ciphertext' => $subsonicCiphertext,
                    'updated_at' => $changedAt,
                ]);
            if ($updated !== 1) {
                throw new EmergencyPasswordResetInvalid('目标账号已变化，请重新执行应急密码重置。');
            }

            $sessionsRevoked = Db::table('auth_sessions')->where('user_id', $userId)->whereNull('revoked_at')
                ->update(['revoked_at' => $changedAt, 'revoked_reason' => 'emergency_password_reset']);
            $tokensRevoked = Db::table('personal_access_tokens')->where('user_id', $userId)->whereNull('revoked_at')
                ->update([
                    'revoked_at' => $changedAt,
                    'revoked_reason' => 'emergency_password_reset',
                    'updated_at' => $changedAt,
                ]);
            $playbackLeasesReleased = Db::table('playback_leases')->where('user_id', $userId)->delete();

            // 本机恢复没有已认证 Web 操作者，因此 actor_user_id 必须保持 null，不能伪装成目标账号。
            $this->audit->record(null, 'user.password.emergency_reset', 'user', $userId, 'success', $requestId, [
                'sessionsRevoked' => $sessionsRevoked,
                'tokensRevoked' => $tokensRevoked,
                'playbackLeasesReleased' => $playbackLeasesReleased,
            ]);
            $pdo->exec('COMMIT');
            $transactionOpen = false;
        } catch (Throwable $throwable) {
            if ($transactionOpen) {
                try {
                    $pdo->exec('ROLLBACK');
                } catch (Throwable) {
                    // 回滚异常不能遮蔽原始失败；SQLite 连接关闭时仍会撤销未提交事务。
                }
            }
            throw $throwable;
        }

        return compact(
            'userId',
            'sessionsRevoked',
            'tokensRevoked',
            'playbackLeasesReleased',
            'changedAt',
        );
    }
}
