<?php

declare(strict_types=1);

namespace app\application\Scrape;

/**
 * 计算一次刮削候选能否跳过人工确认进入整理队列。
 *
 * 数据库为兼容旧版本仍以 approval_mode=auto 配合可空阈值表达两种自动策略：阈值为空是原有无条件
 * 自动，非空是按分数自动。按分数模式只认可内置平台查询器已经接受的第三方候选；本地文件名或标签分数
 * 不能单独触发自动文件操作。标签替换、已有受管理结果、分析模式和不支持的操作始终失败关闭。
 *
 * 本策略没有数据库、网络或文件副作用，重复输入结果确定。返回 false 表示进入 review，不表示分析失败；
 * Worker 仍会保存最终候选、渠道日志和具体确认原因，管理员可以修改或手动确认。
 */
final readonly class ScrapeApprovalPolicy
{
    public const MINIMUM_SCORE = 85;
    public const MAXIMUM_SCORE = 100;

    /**
     * 判断候选是否满足当前服务的全部自动准入条件。
     *
     * `$storedMode` 只接受数据库兼容值 manual/auto；`$scoreThreshold` 非空时必须位于 85-100，否则按
     * 配置损坏失败关闭。置信度是 0-100 的最终候选分数，但只有 `$externalMatched=true` 才能用于阈值
     * 自动。该方法不替代执行器稍后进行的源身份、路径、空间、哈希和目标冲突复核。
     */
    public function allowsAutomaticQueue(
        string $storedMode,
        ?int $scoreThreshold,
        int $confidence,
        bool $externalMatched,
        bool $requiresTagDecision,
        bool $hasExistingResult,
        string $operationMode,
    ): bool {
        if (
            $storedMode !== 'auto'
            || $requiresTagDecision
            || $hasExistingResult
            || !in_array($operationMode, ['hardlink', 'symlink', 'copy', 'move'], true)
        ) {
            return false;
        }
        if ($scoreThreshold === null) {
            return true;
        }
        if ($scoreThreshold < self::MINIMUM_SCORE || $scoreThreshold > self::MAXIMUM_SCORE) {
            return false;
        }
        return $externalMatched && $confidence >= $scoreThreshold;
    }
}
