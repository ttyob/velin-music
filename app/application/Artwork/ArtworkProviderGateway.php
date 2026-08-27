<?php

declare(strict_types=1);

namespace app\application\Artwork;

/**
 * 定义入库后显式在线封面任务的最小端口。
 *
 * 内置实现只接受无路径录音证据；艺人类型额外使用服务端冻结的实体名，并以代表歌曲证明关系。
 * 第三方路由和凭据不能由浏览器提供。任何实现都不得跨此端口共享业务数据库或返回 Provider URL；
 * 多渠道结果由每张 asset 自己声明固定 providerKey。
 */
interface ArtworkProviderGateway
{
    /**
     * 提交一个只请求 artwork capability 的实体查询，不等待远端任务完成。
     *
     * @param array<string,mixed> $evidence 规范化且不含路径、原始标签和文件身份的实体与录音证据。
     * @return array{id:string,status:string,resultId:?string}
     */
    public function submit(array $evidence, string $locale, string $region, string $idempotencyKey): array;

    /** @return array{id:string,status:string,resultId:?string} 读取一次已归属当前服务租户的任务快照。 */
    public function job(string $jobId): array;

    /**
     * @return array{id:string,jobId:string,assets:list<array<string,mixed>>}
     * 读取许可仍有效的无字节图片摘要；未知或多平台来源失败关闭。
     */
    public function result(string $resultId): array;

    /**
     * @return array{id:string,mimeType:string,width:int,height:int,sizeBytes:int,sha256:string,bytes:string}
     * 读取并完整校验一个冻结 asset 的原图；字节只存在于当前调用栈。
     */
    public function asset(string $assetId): array;
}
