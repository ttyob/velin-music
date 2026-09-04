<?php

declare(strict_types=1);

namespace app\process;

use RuntimeException;
use Workerman\Worker;

/**
 * 把一个受 Workerman 主进程监督的 Worker 替换为内置 Go 媒体网关。
 *
 * 该进程不加载业务数据库，也不接收 PHP 对象；启动参数只包含固定监听地址、回环 Webman 地址、公开
 * 静态根和部署允许根。部署密钥仅从继承环境读取，不进入 argv。`pcntl_exec` 成功后 Go 保留原 PID，
 * 因此 Webman master 的停止、重启和异常拉起仍然有效；执行失败会让本 Worker 非零退出并由 master
 * 重试，不允许静默回退到公开内部 Webman 端口。
 */
final readonly class MediaGatewayProcess
{
    /**
     * @param string $staticRoot 前端构建产物的只读公开根，与媒体允许根相互独立。
     * @param list<string> $allowedRoots 只能由部署配置提供的规范目录，不能来自请求或数据库行。
     */
    public function __construct(
        private string $binaryPath,
        private string $listenAddress,
        private string $upstreamOrigin,
        private string $staticRoot,
        private array $allowedRoots,
    ) {
    }

    /**
     * 在独立 Worker 子进程中执行 Go 网关。
     *
     * 启动前重新要求二进制是非链接普通可执行文件，并要求至少一个允许根；根的 realpath、目录类型和
     * 符号链接身份由 Go 在监听端口前再次验证。方法成功时不会返回；失败不修改文件或端口配置。
     */
    public function onWorkerStart(Worker $worker): never
    {
        $path = realpath($this->binaryPath);
        if ($path === false || $path !== $this->binaryPath || is_link($this->binaryPath)
            || !is_file($path) || !is_executable($path) || $this->allowedRoots === []
            || $this->staticRoot === '' || !is_dir($this->staticRoot) || is_link($this->staticRoot)) {
            throw new RuntimeException('MEDIA_GATEWAY_BINARY_UNAVAILABLE');
        }
        $arguments = [
            '--listen=' . $this->listenAddress,
            '--upstream=' . $this->upstreamOrigin,
            '--static-root=' . $this->staticRoot,
        ];
        foreach ($this->allowedRoots as $root) {
            if (!is_string($root) || $root === '') {
                throw new RuntimeException('MEDIA_GATEWAY_ROOT_INVALID');
            }
            $arguments[] = '--root=' . $root;
        }
        pcntl_exec($path, $arguments);
        throw new RuntimeException('MEDIA_GATEWAY_EXEC_FAILED');
    }
}
