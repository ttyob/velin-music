<?php

declare(strict_types=1);

namespace app\application\ResourcePlugin\Contract;

/**
 * PhpResourcePlugin 定义 Velin Music 进程内 PHP 资源插件的最小身份合同。
 *
 * 实现类只由部署者安装到固定 `plugin` 目录并由严格 manifest 指定，浏览器不能提交类名或文件路径。
 * descriptor 必须是无秘密、可序列化的稳定投影；插件缺失或 manifest 失效时核心失败关闭，不猜测旧实现。
 * 插件运行在 Webman 权限域内，只允许安装已审查代码，不得把该接口描述为不可信代码沙箱。能力词汇只
 * 对应当前 PHP 钩子，不存在可回退的外部二进制插件协议，也不能从字符串推导登录、播放等未实现能力。
 */
interface PhpResourcePlugin
{
    /**
     * 返回插件的公开描述。
     *
     * 返回值不得包含凭据、物理路径、下载引用或第三方原始响应，并须与安装 manifest 的 key、version 和
     * capabilities 一致。注册表会逐项复验，不一致时整个插件不可用。
     *
     * @return array{key:string,name:string,version:string,capabilities:list<string>,description:string}
     */
    public function descriptor(): array;

    /**
     * 执行无写入副作用的可用性探测。
     *
     * 网络插件可以访问其已保存配置，但不得在探测中创建下载、修改远端设置或泄漏秘密；预期外部故障应
     * 收敛为稳定 errorCode，非预期异常由调用层统一脱敏。
     *
     * @return array{available:bool,errorCode:?string}
     */
    public function probe(): array;
}
