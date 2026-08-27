<?php

declare(strict_types=1);

namespace app\application\Dlna;

use JsonException;
use Symfony\Component\Process\Process;

/**
 * 通过版本化 JSON 协议调用随 Velin Music 发布的长驻 DLNA helper。
 *
 * 二进制路径只来自部署配置，调用使用参数数组且不经过 Shell。输入不得包含用户凭据、媒体路径或数据库
 * 标识；play 只传短期投放 URL。生产优先连接容器内 0600 Unix Socket，共享设备描述 HTTP 连接、GENA
 * 订阅和瞬时状态；Socket 在连接前不可用时才启动一次性进程降级。命令一旦写入，响应超时或损坏不会
 * 自动重试，避免重复执行 SetAVTransportURI/Seek。两条路径都限制 20 秒和 256 KiB，stderr、SOAP、
 * 局域网地址和票据不会进入应用日志，helper 也不会修改业务数据库。
 */
final readonly class DlnaHelperClient
{
    public function __construct(
        private ?string $binaryPath = null,
        private ?string $socketPath = null,
    )
    {
    }

    /**
     * 执行一次发现或控制命令并验证协议根字段。
     *
     * @param array<string,mixed> $command 已由 DlnaService 生成的固定字段
     * @return array<string,mixed>
     */
    public function invoke(array $command): array
    {
        $path = $this->binaryPath
            ?? (string) (getenv('VELIN_DLNA_HELPER_PATH') ?: base_path('bin/velin-dlna-helper'));
        if ($path === '' || $path[0] !== DIRECTORY_SEPARATOR || !is_file($path) || !is_executable($path)) {
            throw new DlnaUnavailable('DLNA_HELPER_UNAVAILABLE', 'DLNA 执行器不可用。');
        }
        try {
            $input = json_encode($command, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $exception) {
            throw new DlnaUnavailable('DLNA_PROTOCOL_INVALID', 'DLNA 命令无法编码。');
        }
        $socketPayload = $this->invokeSocket($input);
        if ($socketPayload !== null) return $socketPayload;

        $process = new Process([$path]);
        $process->setInput($input);
        $process->setTimeout(20.0);
        try {
            $process->run();
        } catch (\Throwable) {
            throw new DlnaUnavailable('DLNA_HELPER_TIMEOUT', 'DLNA 执行器启动或执行超时。');
        }
        $output = $process->getOutput();
        if ($output === '' || strlen($output) > 262_144) {
            throw new DlnaUnavailable('DLNA_PROTOCOL_INVALID', 'DLNA 执行器响应无效。');
        }
        return $this->validatedPayload($output, $process->isSuccessful());
    }

    /**
     * 尝试调用容器内共享 Unix Socket；仅连接建立前的缺失/拒绝返回 null 触发一次性降级。
     *
     * 自定义 binaryPath 的测试和裸机调用默认跳过 Socket，避免意外连接另一部署实例。连接成功后使用
     * 完整写循环和读超时；部分写入、超时、EOF 或协议错误均失败关闭，不重复发送可能已经生效的命令。
     * Socket 路径固定来自应用运行目录或构造注入，不接受 HTTP/CLI 请求字段。
     *
     * @return array<string,mixed>|null
     */
    private function invokeSocket(string $input): ?array
    {
        if ($this->binaryPath !== null && $this->socketPath === null) return null;
        $path = $this->socketPath ?? runtime_path('velin-dlna-helper.sock');
        if ($path === '' || $path[0] !== DIRECTORY_SEPARATOR || strlen($path) > 200 || !file_exists($path)) return null;
        $errorCode = 0;
        $errorMessage = '';
        $socket = @stream_socket_client('unix://' . $path, $errorCode, $errorMessage, 0.2, STREAM_CLIENT_CONNECT);
        if (!is_resource($socket)) return null;
        stream_set_timeout($socket, 20);
        $message = $input . "\n";
        $written = 0;
        while ($written < strlen($message)) {
            $count = @fwrite($socket, substr($message, $written));
            if (!is_int($count) || $count <= 0) {
                fclose($socket);
                throw new DlnaUnavailable('DLNA_HELPER_TIMEOUT', 'DLNA 长驻执行器通信失败。');
            }
            $written += $count;
        }
        stream_socket_shutdown($socket, STREAM_SHUT_WR);
        $output = stream_get_contents($socket, 262_145);
        $metadata = stream_get_meta_data($socket);
        fclose($socket);
        if (!is_string($output) || $output === '' || strlen($output) > 262_144 || ($metadata['timed_out'] ?? false)) {
            throw new DlnaUnavailable('DLNA_HELPER_TIMEOUT', 'DLNA 长驻执行器响应超时或无效。');
        }
        return $this->validatedPayload($output, true);
    }

    /**
     * 验证共享 Socket 与一次性进程共同的版本 1 响应。
     *
     * 只接受 JSON 对象、布尔成功标记和白名单形式错误码；原始 stdout、stderr 与第三方响应不会进入领域
     * 异常。transportSuccessful 仅表示承载层完整结束，设备失败仍以 succeeded=false 为准。
     *
     * @return array<string,mixed>
     */
    private function validatedPayload(string $output, bool $transportSuccessful): array
    {
        try {
            $payload = json_decode($output, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new DlnaUnavailable('DLNA_PROTOCOL_INVALID', 'DLNA 执行器响应无效。');
        }
        if (!is_array($payload) || array_is_list($payload) || ($payload['version'] ?? null) !== 1
            || !is_bool($payload['succeeded'] ?? null)) {
            throw new DlnaUnavailable('DLNA_PROTOCOL_INVALID', 'DLNA 执行器协议版本无效。');
        }
        if (!$transportSuccessful || $payload['succeeded'] !== true) {
            $reason = is_string($payload['errorCode'] ?? null)
                && preg_match('/^DLNA_[A-Z0-9_]{2,60}$/', $payload['errorCode']) === 1
                ? $payload['errorCode'] : 'DLNA_OPERATION_FAILED';
            throw new DlnaUnavailable($reason, 'DLNA 设备操作失败。');
        }
        return $payload;
    }
}
