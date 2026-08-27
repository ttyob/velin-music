<?php

declare(strict_types=1);

namespace app\application\ResourcePlugin\Contract;

/**
 * ExternalMusicPluginRegistry 定义核心统一音乐 API 所依赖的最小插件注册表。
 *
 * 该边界存在是为了让聚合服务只依赖“列出已校验插件、取得搜索钩子、取得下载钩子”三项能力，而不接触
 * 包路径、安装标记或插件数据库生命周期细节。生产实现必须在每次取钩子时复验活动状态、manifest 能力
 * 与 PHP 接口、数据库版本；测试实现只能用于无网络的契约测试，不能成为运行期插件发现旁路。
 */
interface ExternalMusicPluginRegistry
{
    /**
     * 返回插件的脱敏核心投影。
     *
     * 调用方只消费 key、name、version、capabilities、valid 与 databaseInstalled；实现不得在投影中加入
     * PHP 类名、物理目录、第三方凭据或搜索引用。列表是即时快照，不承诺后续钩子调用时插件仍然活动。
     *
     * @return list<array<string,mixed>>
     */
    public function list(): array;

    /**
     * 返回当前仍可调用的搜索钩子。
     *
     * key 必须来自核心已验证的插件目录；插件在列表读取后被停用、升级或数据库版本变化时，本方法必须
     * 失败关闭，不能返回旧实例或猜测兼容性。
     */
    public function search(string $key): ExternalMusicSearchHook;

    /**
     * 返回当前仍可调用的下载钩子。
     *
     * 获取钩子本身不产生下载副作用。真正创建任务时，插件仍需复验租约所有者、到期时间和目标音乐库
     * manage 授权；注册表校验不能替代这些对象级检查。
     */
    public function download(string $key): ExternalDownloadHook;

    /**
     * 返回声明并实现歌曲补全能力的插件钩子。
     *
     * 注册表同时复验 manifest 能力、PHP 接口和插件数据库版本；调用方不能从搜索或下载钩子猜测补全能力。
     */
    public function completion(string $key): ExternalMusicCompletionHook;
}
