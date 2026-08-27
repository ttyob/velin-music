<?php

declare(strict_types=1);

namespace app\application\Artwork;

/**
 * 为同一个逐曲刮削目标批量启动无副作用的图片远程查询。
 *
 * 实现可以并行外部 HTTP/Helper 等待，但不得并行写业务数据库，也不得把一个请求的证据、结果或失败
 * 归到另一请求。返回项必须与输入顺序一一对应；每项独立携带成功结果或稳定失败，调用方随后按原任务
 * 租约逐条提交 SQLite。该接口不改变浏览器协议，也不允许跨 scrape_target 合批。
 */
interface ArtworkProviderBatchGateway extends ArtworkProviderGateway
{
    /**
     * @param list<array{evidence:array<string,mixed>,locale:string,region:string,idempotencyKey:string}> $requests
     * @return list<array{remote:?array{id:string,status:string,resultId:?string},failure:?ArtworkProviderRemoteFailure}>
     */
    public function submitBatch(array $requests): array;
}
