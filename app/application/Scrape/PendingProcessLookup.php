<?php

declare(strict_types=1);

namespace app\application\Scrape;

use Closure;
use LogicException;

/**
 * 保存已经启动的受控 Helper 查询，允许调用方在启动其他查询后再等待结果。
 *
 * 句柄只存在于单个 PHP Worker 内存中，不可序列化，也不携带业务数据库身份。await() 只能调用一次；
 * 无论解析成功、协议失败、超时或调用方遗忘等待，清理回调都会停止残留子进程并擦除内存中的代理
 * 密码。它只改变远程等待的重叠方式，不改变 JSON 协议、结果顺序或上层 SQLite 串行提交边界。
 */
final class PendingProcessLookup
{
    private bool $settled = false;

    public function __construct(
        private ?Closure $resolver,
        private ?Closure $cleanup,
    ) {
    }

    /**
     * 等待全部已启动子进程并返回严格校验后的平台结果。
     *
     * 重复等待属于调用方状态错误并失败关闭；清理在 finally 中执行，因此解析异常不会遗留进程或凭据。
     *
     * @return list<array<string,mixed>>
     */
    public function await(): array
    {
        if ($this->settled || !$this->resolver instanceof Closure) {
            throw new LogicException('受控查询结果只能等待一次。');
        }
        $this->settled = true;
        $resolver = $this->resolver;
        try {
            return $resolver();
        } finally {
            $this->release();
        }
    }

    /** 调用方提前放弃句柄时执行补偿清理；析构不得向 Worker 事件循环抛出异常。 */
    public function __destruct()
    {
        try {
            $this->release();
        } catch (\Throwable) {
        }
    }

    /** 清理回调幂等执行一次，并断开可能捕获请求证据或代理配置的闭包引用。 */
    private function release(): void
    {
        $cleanup = $this->cleanup;
        $this->cleanup = null;
        $this->resolver = null;
        if ($cleanup instanceof Closure) $cleanup();
    }
}
