<?php

declare(strict_types=1);

namespace app\application\Storage;

use Closure;

/**
 * 统一读取受信服务端目录的字节与 inode 容量。
 *
 * 生产环境默认调用操作系统探针；构造器中的读取器只用于应用内部测试和故障注入，HTTP 请求不得提供
 * 读取器或路径。所有样本在返回前都会校验为非负且不超过总量，探测失败统一返回 null，由写入守卫按
 * 容量未知失败关闭，健康诊断则保留 unknown 信息。该服务只读取元数据，不创建目录、不枚举媒体内容，
 * 也没有需要回滚的文件副作用。
 */
final readonly class StorageCapacityProbe
{
    /**
     * @param (Closure(string): array{total: int|float, free: int|float}|null)|null $byteReader
     * @param (Closure(string): array{total: int|float, free: int|float}|null)|null $inodeReader
     */
    public function __construct(
        private ?Closure $byteReader = null,
        private ?Closure $inodeReader = null,
    ) {
    }

    /** @return array{total:int,free:int}|null 无法取得可信样本时返回 null。 */
    public function bytes(string $path): ?array
    {
        $sample = $this->byteReader instanceof Closure
            ? ($this->byteReader)($path)
            : ['total' => @disk_total_space($path), 'free' => @disk_free_space($path)];

        return $this->normalize($sample);
    }

    /**
     * @return array{total:int,free:int}|null
     *
     * Linux 使用固定 argv 调用 `/bin/df`，不经过 shell，也不接受浏览器路径；其他环境或命令失败时返回
     * null，使健康诊断显示未知而不是伪造正常 inode 状态。
     */
    public function inodes(string $path): ?array
    {
        if ($this->inodeReader instanceof Closure) {
            return $this->normalize(($this->inodeReader)($path));
        }
        if (!function_exists('proc_open') || !is_executable('/bin/df')) {
            return null;
        }
        $pipes = [];
        $process = @proc_open(['/bin/df', '-Pi', $path], [
            0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
        ], $pipes);
        if (!is_resource($process)) {
            return null;
        }
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        if ($exit !== 0 || !is_string($output)) {
            return null;
        }
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', $output) ?: [])));
        $fields = preg_split('/\s+/', (string) end($lines)) ?: [];
        if (count($fields) < 6 || !ctype_digit($fields[1]) || !ctype_digit($fields[3])) {
            return null;
        }

        return $this->normalize(['total' => (int) $fields[1], 'free' => (int) $fields[3]]);
    }

    /** @return array{total:int,free:int}|null */
    private function normalize(mixed $sample): ?array
    {
        if (!is_array($sample) || !isset($sample['total'], $sample['free'])
            || (!is_int($sample['total']) && !is_float($sample['total']))
            || (!is_int($sample['free']) && !is_float($sample['free']))) {
            return null;
        }
        $total = (int) $sample['total'];
        $free = (int) $sample['free'];
        if ($total <= 0 || $free < 0 || $free > $total) {
            return null;
        }

        return ['total' => $total, 'free' => $free];
    }
}
