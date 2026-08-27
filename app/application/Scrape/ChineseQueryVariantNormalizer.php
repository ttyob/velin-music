<?php

declare(strict_types=1);

namespace app\application\Scrape;

use Symfony\Component\Process\Process;
use Throwable;

/**
 * 使用部署内固定 OpenCC 把文件名查询变体转换为简体中文，原始扫描事实保持不变。
 *
 * 可执行文件和词典路径由部署固定，浏览器与音乐库配置均不能覆盖。整首歌曲的最多两个变体以一个有界
 * JSON 文档完成转换，避免逐字段启动进程；启动失败、超时、输出损坏或开发环境未安装 OpenCC 时原样
 * 回退，不能让可查询歌曲失败。转换只发生在 Provider 请求前的内存中，不写数据库、文件或日志。
 */
final readonly class ChineseQueryVariantNormalizer
{
    public function __construct(private ?string $binaryPath = null, private ?string $configPath = null) {}

    /**
     * 将一个元数据字段转换为简体中文。
     *
     * 歌曲标题、艺人和专辑是跨平台身份匹配字段；调用方必须在比较前统一使用该结果，但不得用它覆盖
     * 歌单导入证据或扫描原文。OpenCC 不可用、超时或输出损坏时返回原文，保证转换能力缺失不会把可下载
     * 的歌曲误判为系统故障。转换仍通过 simplify() 的固定路径、超时和输出上限执行。
     */
    public function simplifyText(string $value): string
    {
        $converted = $this->simplify([['value' => $value]]);
        $result = is_array($converted[0] ?? null) ? ($converted[0]['value'] ?? null) : null;
        return is_string($result) ? $result : $value;
    }

    /** @param list<array<string,mixed>> $variants @return list<array<string,mixed>> */
    public function simplify(array $variants): array
    {
        $binary = $this->binaryPath ?? base_path('bin/opencc');
        $config = $this->configPath ?? base_path('bin/opencc-data/t2s.json');
        if (!is_file($binary) || !is_executable($binary) || !is_file($config)) return $variants;
        try {
            $input = json_encode($variants, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (strlen($input) > 8192) return $variants;
            $process = new Process([$binary, '-c', $config]);
            $process->setInput($input);
            $process->setTimeout(2.0);
            $process->run();
            $output = $process->getOutput();
            if (!$process->isSuccessful() || $output === '' || strlen($output) > 8192) return $variants;
            $converted = json_decode($output, true, 16, JSON_THROW_ON_ERROR);
            return is_array($converted) && array_is_list($converted) && count($converted) === count($variants)
                ? $converted : $variants;
        } catch (Throwable) {
            return $variants;
        }
    }
}
