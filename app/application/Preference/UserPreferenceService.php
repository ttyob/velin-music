<?php

declare(strict_types=1);

namespace app\application\Preference;

use app\application\Theme\ThemeService;
use app\infrastructure\Audit\AuditLogger;
use stdClass;
use support\Db;
use Throwable;

/**
 * 管理带共享版本的当前账号自助偏好，不允许调用方指定另一个用户。
 *
 * 用户 ID 只来自复验后的 Session 投影，URL 和请求体均不能提名目标账号。语言、基础颜色和减少动效
 * 命令各自只修改白名单列，但共享同一个乐观版本以防并发静默覆盖；每次成功更新都在同一短事务写入
 * 脱敏审计，不记录 Session、Cookie、浏览器信息、颜色 token 或自由文本。
 */
final class UserPreferenceService
{
    public function __construct(
        private readonly AuditLogger $auditLogger = new AuditLogger(),
        private readonly ThemeService $themeService = new ThemeService(),
    ) {
    }

    /**
     * 使用 SQLite 乐观并发控制提交当前账号语言并返回新版本。
     *
     * `BEGIN IMMEDIATE` 在读取版本前串行化偏好写入，持锁期间只执行有界 SQL 和事务内审计。旧版本命令
     * 会整体回滚，偏好与审计都不变化；客户端拿到新版本后的重试属于新的明确命令，不采用
     * last-write-wins。账号创建必须已在同一事务建立偏好行，缺行按冲突关闭失败。
     *
     * @param array<string, mixed> $actor 当前 Session 权威投影，只使用可信用户 ID。
     * @return array{locale: string, version: int, updatedAt: string}
     * @throws UserPreferenceConflict 偏好行不存在或 `expectedVersion` 已变化。
     */
    public function updateLocale(
        array $actor,
        string $locale,
        int $expectedVersion,
        string $requestId,
    ): array {
        if (!in_array($locale, ['zh-CN', 'en-US'], true) || $expectedVersion < 1) {
            throw new UserPreferenceInvalid('Preference command is invalid.');
        }
        $userId = (string) ($actor['id'] ?? '');
        if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $userId) !== 1) {
            throw new UserPreferenceInvalid('Authenticated user ID is invalid.');
        }

        $pdo = Db::connection()->getPdo();
        $open = false;
        $pdo->exec('BEGIN IMMEDIATE');
        $open = true;
        try {
            /** @var stdClass|null $current */
            $current = Db::table('user_preferences')->where('user_id', $userId)
                ->first(['version']);
            if (!$current instanceof stdClass || (int) $current->version !== $expectedVersion) {
                throw new UserPreferenceConflict('个人设置已在其他页面更新，请重新加载后再试。');
            }

            $now = gmdate('Y-m-d\TH:i:s\Z');
            $nextVersion = $expectedVersion + 1;
            $affected = Db::table('user_preferences')->where('user_id', $userId)
                ->where('version', $expectedVersion)->update([
                    'locale' => $locale,
                    'version' => $nextVersion,
                    'updated_at' => $now,
                ]);
            if ($affected !== 1) {
                throw new UserPreferenceConflict('个人设置已在其他页面更新，请重新加载后再试。');
            }
            $this->auditLogger->record(
                $userId,
                'user.preferences.locale.update',
                'user_preferences',
                $userId,
                'success',
                $requestId,
                ['locale' => $locale, 'version' => $nextVersion],
            );
            $pdo->exec('COMMIT');
            $open = false;
        } catch (Throwable $throwable) {
            if ($open) {
                $pdo->exec('ROLLBACK');
            }
            throw $throwable;
        }

        return ['locale' => $locale, 'version' => $nextVersion, 'updatedAt' => $now];
    }

    /**
     * 在账号偏好版本下保存一个当前发布的基础色主题。
     *
     * 发布状态在取得 SQLite 写预留前复核；管理员并发改变目录后，`/me` 仍会把失效选择解析到站点默认
     * 基础色。事务只更新 Session 身份对应的 `theme_id`，保留语言、时区、动效和播放偏好，并写入不含
     * 颜色值的主题 ID/版本审计。版本过期或行不存在时整体回滚，不做 last-write-wins 或兼容重试。
     *
     * @return array{themeId: string, version: int, updatedAt: string}
     * @throws UserPreferenceInvalid 主题不存在、未发布或身份不合法。
     * @throws UserPreferenceConflict 账号偏好行缺失或版本已经变化。
     */
    public function updateTheme(
        array $actor,
        string $themeId,
        int $expectedVersion,
        string $requestId,
    ): array {
        if ($expectedVersion < 1
            || !$this->themeService->isPublished($themeId)) {
            throw new UserPreferenceInvalid('Theme preference is invalid or unavailable.');
        }
        $userId = (string) ($actor['id'] ?? '');
        if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $userId) !== 1) {
            throw new UserPreferenceInvalid('Authenticated user ID is invalid.');
        }

        $pdo = Db::connection()->getPdo();
        $pdo->exec('BEGIN IMMEDIATE');
        $open = true;
        try {
            $now = gmdate('Y-m-d\TH:i:s\Z');
            $nextVersion = $expectedVersion + 1;
            $affected = Db::table('user_preferences')->where('user_id', $userId)
                ->where('version', $expectedVersion)->update([
                    'theme_id' => $themeId,
                    'version' => $nextVersion,
                    'updated_at' => $now,
                ]);
            if ($affected !== 1) {
                throw new UserPreferenceConflict('个人设置已在其他页面更新，请重新加载后再试。');
            }
            $this->auditLogger->record(
                $userId,
                'user.preferences.theme.update',
                'user_preferences',
                $userId,
                'success',
                $requestId,
                ['themeId' => $themeId, 'version' => $nextVersion],
            );
            $pdo->exec('COMMIT');
            $open = false;
        } catch (Throwable $throwable) {
            if ($open) {
                $pdo->exec('ROLLBACK');
            }
            throw $throwable;
        }

        return [
            'themeId' => $themeId,
            'version' => $nextVersion,
            'updatedAt' => $now,
        ];
    }

    /**
     * 在共享账号偏好版本下保存减少动效辅助选项。
     *
     * 该低风险设置只修改一个受限布尔列，仍使用 `BEGIN IMMEDIATE` 防止与主题、语言、资料、播放设置
     * 并发覆盖。审计不记录浏览器无障碍能力，只记录用户明确选择和新版本。
     *
     * @return array{reduceMotion:bool,version:int,updatedAt:string}
     */
    public function updateMotion(array $actor, bool $reduceMotion, int $expectedVersion, string $requestId): array
    {
        $userId = (string) ($actor['id'] ?? '');
        if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $userId) !== 1 || $expectedVersion < 1) {
            throw new UserPreferenceInvalid('Motion preference command is invalid.');
        }
        $pdo = Db::connection()->getPdo();
        $pdo->exec('BEGIN IMMEDIATE');
        $open = true;
        try {
            $now = gmdate('Y-m-d\TH:i:s\Z');
            $nextVersion = $expectedVersion + 1;
            $changed = Db::table('user_preferences')->where('user_id', $userId)
                ->where('version', $expectedVersion)->update([
                    'reduce_motion' => $reduceMotion ? 1 : 0,
                    'version' => $nextVersion,
                    'updated_at' => $now,
                ]);
            if ($changed !== 1) throw new UserPreferenceConflict('个人设置已在其他页面更新，请重新加载后再试。');
            $this->auditLogger->record(
                $userId, 'user.preferences.motion.update', 'user_preferences', $userId,
                'success', $requestId, ['reduceMotion' => $reduceMotion, 'version' => $nextVersion],
            );
            $pdo->exec('COMMIT');
            $open = false;
        } catch (Throwable $throwable) {
            if ($open) $pdo->exec('ROLLBACK');
            throw $throwable;
        }
        return ['reduceMotion' => $reduceMotion, 'version' => $nextVersion, 'updatedAt' => $now];
    }
}
