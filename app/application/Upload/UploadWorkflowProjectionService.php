<?php

declare(strict_types=1);

namespace app\application\Upload;

use stdClass;
use support\Db;

/**
 * 投影后台上传完成后的库级扫描阶段（UPLOAD-008）。
 *
 * 单目录模型中上传文件原子发布到音乐库根，后续只有一次去重的库扫描，不再经过刮削目录发现、整理
 * 或待入库链路。本服务只读取上传文件和会话绑定的扫描任务，不推进任何状态；物理路径、文件身份、
 * Worker 租约和错误原文均不会进入响应。同批文件共享扫描阶段，不能伪造逐文件扫描百分比。
 */
final readonly class UploadWorkflowProjectionService
{
    /**
     * 返回会话的安全扫描摘要。
     *
     * `$includeAdminLinks` 只控制是否生成后台任务链接，不改变权限；调用方必须已经完成会话和音乐库
     * 授权。扫描任务消失或尚未排队时保持等待，失败仅返回稳定阶段，不暴露内部异常。
     *
     * @return array<string,mixed>
     */
    public function summarize(string $sessionId, bool $includeAdminLinks): array
    {
        /** @var stdClass|null $session */
        $session = Db::table('upload_sessions')->where('id', $sessionId)
            ->first(['scan_status', 'scan_job_id']);
        /** @var list<stdClass> $files */
        $files = Db::table('upload_files')->where('session_id', $sessionId)
            ->where('media_kind', 'audio')->orderBy('id')->get(['id', 'relative_path', 'status'])->all();
        /** @var stdClass|null $scan */
        $scan = !$session instanceof stdClass || $session->scan_job_id === null ? null
            : Db::table('library_scan_jobs')->where('id', (string) $session->scan_job_id)
                ->first(['id', 'status']);
        $stage = match (true) {
            $files === [] => 'no_audio',
            $scan instanceof stdClass && (string) $scan->status === 'succeeded' => 'indexed',
            $scan instanceof stdClass && (string) $scan->status === 'running' => 'scanning',
            $scan instanceof stdClass && (string) $scan->status === 'queued' => 'scan_queued',
            $scan instanceof stdClass && in_array((string) $scan->status, ['failed', 'cancelled'], true)
                => 'scan_failed',
            default => 'waiting_for_scan',
        };
        $items = array_map(static fn (stdClass $file): array => [
            'uploadFileId' => (string) $file->id,
            'relativePath' => (string) $file->relative_path,
            'stage' => (string) $file->status === 'published' ? $stage : 'upload_pending',
        ], $files);

        return [
            'stage' => $stage,
            'audioFiles' => count($items),
            'scannedFiles' => $scan instanceof stdClass ? count($items) : 0,
            'indexedFiles' => $stage === 'indexed' ? count($items) : 0,
            'failedFiles' => $stage === 'scan_failed' ? count($items) : 0,
            'scanJob' => $scan instanceof stdClass ? [
                'id' => (string) $scan->id,
                'status' => (string) $scan->status,
                'href' => $includeAdminLinks
                    ? '/admin/jobs?type=scan&jobId=' . rawurlencode((string) $scan->id)
                    : null,
            ] : null,
            'items' => $items,
        ];
    }
}
