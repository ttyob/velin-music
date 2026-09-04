<?php

declare(strict_types=1);

namespace app\application\Scan;

/**
 * 管理员创建扫描任务时的不可变命令。
 *
 * relativePath 为 NULL 表示整库；非空值只允许用于 incremental，且必须是库根内的正斜杠
 * 相对目录。该值仅表达扫描意图，不授予文件系统或远端对象访问权；应用服务仍复验音乐库
 * manage 范围，Worker 仍从受保护库配置重新解析根与目录身份。
 */
final readonly class ScanCreateInput
{
    public function __construct(public string $scanType, public ?string $relativePath = null)
    {
    }
}
