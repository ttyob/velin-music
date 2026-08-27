<?php

declare(strict_types=1);

namespace app\application\Scan;

/**
 * 在扫描器进入缺失文件校准前验证发现集是否具备最低可信度。
 *
 * 扫描遍历成功并不等于挂载正确：两个连接同一数据库但拥有不同 `/media` 命名空间的 Worker，
 * 可能在一个真实存在的空目录上得到“零失败、零发现”。当库中仍有可用库存时，这种结果与挂载
 * 消失具有相同破坏风险，必须失败关闭并保留旧索引。真正清空一个已有音乐库需要先通过存储健康
 * 与挂载身份确认流程，不能借普通扫描隐式完成。本类只判断计数，不读取文件或修改数据库。
 */
final class ScanReconciliationGuard
{
    /**
     * 拒绝会把一个非空音乐库一次性校准为空的发现结果。
     *
     * 输入来自同一次扫描的发现计数及提交前实时可用库存计数，均应为非负整数。首次扫描或原本
     * 已无可用库存时允许零发现；非空发现集继续由后续事务逐路径校准。拒绝时抛出稳定领域异常，
     * 调用方必须把任务记为失败且不得运行 missing 更新，因此重复执行不会进一步改变媒体状态。
     *
     * @throws SuspiciousEmptyScan 已有可用库存但本次未发现任何音频
     */
    public function assertSafe(int $discoveredFiles, int $availableInventoryFiles): void
    {
        if ($availableInventoryFiles > 0 && $discoveredFiles === 0) {
            throw new SuspiciousEmptyScan('扫描发现集异常为空，已阻止整库缺失校准。');
        }
    }
}
