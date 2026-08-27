<?php

declare(strict_types=1);

namespace app\application\ResourcePlugin\Contract;

/**
 * MetadataScrapeAdminHook 定义元数据刮削插件自己的管理边界。
 *
 * 核心只负责 Session、CSRF、manage_system 权限、插件活动状态和数据库版本校验；数据源目录、刮削任务
 * 及执行诊断日志均由插件保存和投影。接口故意不提供通用 SQL、文件路径、第三方 URL、平台私有 ID 或原始
 * 响应，避免“插件页面”退化成核心数据源表的另一套别名。更新命令由插件执行字段白名单和版本 CAS。
 */
interface MetadataScrapeAdminHook extends PhpResourcePlugin
{
    /** @return array<string,mixed> 返回插件自有数据源目录和版本。 */
    public function metadataSources(): array;

    /** @param array<string,mixed> $command @return array<string,mixed> */
    public function updateMetadataSource(string $sourceKey, array $command): array;

    /**
     * 返回插件自有任务的脱敏分页；query 只包含核心校验后的 status、limit 和 offset。
     *
     * @param array<string,mixed> $query
     * @return array{tasks:list<array<string,mixed>>,total:int,limit:int,offset:int}
     */
    public function metadataTasks(array $query): array;

    /**
     * 清理插件任务历史；只允许删除终态任务及其日志，执行中的任务必须保留。
     *
     * @return array{deletedCount:int,activeCount:int}
     */
    public function clearMetadataTasks(): array;

    /** @return array<string,mixed> */
    public function metadataTask(string $taskId): array;

    /**
     * 重试一个允许重放的失败任务；插件必须复用原任务记录并把新的尝试与日志追加到该记录。
     *
     * @return array{accepted:bool,status:string,confidence:?int}
     */
    public function retryMetadataTask(string $taskId): array;

    /**
     * 返回插件管理的艺人辅助 SQLite 状态；响应不得包含物理路径。
     *
     * @return array<string,mixed>
     */
    public function artistDatabaseStatus(): array;

    /** 创建插件管理的唯一活动艺人库上传会话。 */
    public function startArtistDatabaseUpload(string $fileName, int $totalBytes): array;

    /** 追加连续二进制分块，并返回服务端确认的上传偏移。 */
    public function appendArtistDatabaseChunk(string $uploadId, int $offset, string $bytes, string $sha256): array;

    /** 完成校验并原子发布艺人辅助库。 */
    public function completeArtistDatabaseUpload(string $uploadId): array;

    /** 取消上传会话；不得删除已发布数据库。 */
    public function cancelArtistDatabaseUpload(string $uploadId): void;
}
