<?php

declare(strict_types=1);

namespace app\application\Library;

/**
 * 远程音乐库受控写入的最小协议边界。
 *
 * 实现只能在构造时固定的远端根下幂等创建目录，并把核心已经验证的本地普通暂存文件发布为指定相对
 * 路径。调用方保证路径已经过统一文件名策略；实现仍必须拒绝绝对路径、点段和控制字符。发布默认不
 * 覆盖：目标不存在时完成上传，目标已存在时只有内容摘要可证明与本次源文件一致才可作为崩溃恢复成功，
 * 否则抛出 RemotePublicationConflict。网络调用、目录创建和上传都必须位于 SQLite 事务外。
 *
 * 上传失败不得删除本地源文件；实现只可补偿删除当前调用以确定性临时名创建且尚未公布的远端对象，
 * 不得删除最终对象或用户原有对象。返回版本只用于发布账本恢复和审计关联，不得包含凭据、URL 或远端 ID。
 */
interface WritableRemoteLibraryClient
{
    /**
     * 非覆盖地发布一个完整普通文件。
     *
     * @return RemotePublishedObject 已复验的大小、摘要和脱敏版本事实
     */
    public function publishFile(
        string $relativePath,
        string $localPath,
        int $size,
        string $sha256,
    ): RemotePublishedObject;
}
