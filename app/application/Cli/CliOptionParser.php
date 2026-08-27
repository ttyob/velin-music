<?php

declare(strict_types=1);

namespace app\application\Cli;

use InvalidArgumentException;

/**
 * 解析 Velin 管理 CLI 的小型封闭参数语法。
 *
 * 只接受一个服务端已注册命令和 `--name=value`/固定布尔开关，不支持短选项、环境展开、响应文件或
 * 任意子进程参数。重复键和位置参数直接拒绝，避免恢复操作因参数覆盖产生与操作者预期不同的目标。
 * 密码等秘密不属于该语法，后续账号恢复必须从标准输入读取。
 */
final class CliOptionParser
{
    /**
     * 把 argv 转换为命令和唯一选项映射。
     *
     * @param list<string> $arguments 包含脚本名的原始 argv
     * @return array{command:string,options:array<string,string|bool>}
     * @throws InvalidArgumentException 参数为空、格式非法或同一选项重复
     */
    public function parse(array $arguments): array
    {
        array_shift($arguments);
        $command = array_shift($arguments) ?? 'help';
        if (preg_match('/^[a-z][a-z0-9-]*(?::[a-z][a-z0-9-]*)?$/', $command) !== 1) {
            throw new InvalidArgumentException('命令名称无效。');
        }
        $options = [];
        foreach ($arguments as $argument) {
            if (in_array($argument, ['--json', '--password-stdin'], true)) {
                $key = substr($argument, 2);
                $value = true;
            } elseif (preg_match('/^--([a-z][a-z0-9-]*)=(.*)$/s', $argument, $matches) === 1) {
                $key = $matches[1];
                $value = $matches[2];
            } else {
                throw new InvalidArgumentException('选项必须使用 --name=value 格式。');
            }
            if (array_key_exists($key, $options)) throw new InvalidArgumentException('选项不能重复。');
            $options[$key] = $value;
        }
        return ['command' => $command, 'options' => $options];
    }
}
