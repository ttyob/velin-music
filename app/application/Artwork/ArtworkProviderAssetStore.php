<?php

declare(strict_types=1);

namespace app\application\Artwork;

use JsonException;
use Throwable;

/**
 * 在私有 runtime 中冻结在线封面搜索结果和图片字节。
 *
 * 搜索任务表只保存 opaque ID 和摘要，不能保存第三方 URL 或图片正文；本存储因此以摘要命名 BLOB，
 * 并用独立 manifest 维持 job/result/asset 身份。所有文件以 0600 原子发布，目录拒绝符号链接；相同 ID
 * 重放必须得到完全相同内容，否则按身份冲突失败。进程崩溃最多留下尚未被 result 引用的摘要文件，不会
 * 产生数据库部分写入；缓存丢失会让预览/导入明确失败，绝不重新下载可能已漂移的资源。
 */
final readonly class ArtworkProviderAssetStore
{
    private string $root;

    public function __construct(?string $root = null)
    {
        $runtime = (string) (getenv('VELIN_RUNTIME_PATH') ?: base_path('runtime'));
        $this->root = $root ?? rtrim($runtime, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'artwork-provider';
    }

    /** @return array{id:string,status:string,resultId:?string}|null */
    public function optionalJob(string $jobId): ?array
    {
        $this->id($jobId, 'job');
        $path = $this->path('jobs', $jobId . '.json');
        if (!is_file($path)) return null;
        $document = $this->document($path);
        if (($document['id'] ?? null) !== $jobId || ($document['status'] ?? null) !== 'succeeded'
            || !is_string($document['resultId'] ?? null)) {
            throw new ArtworkProviderRemoteFailure('ARTWORK_PROVIDER_CACHE_INVALID', false);
        }
        return ['id' => $jobId, 'status' => 'succeeded', 'resultId' => $document['resultId']];
    }

    /** @return array{id:string,status:string,resultId:?string} */
    public function job(string $jobId): array
    {
        return $this->optionalJob($jobId)
            ?? throw new ArtworkProviderRemoteFailure('ARTWORK_PROVIDER_JOB_NOT_FOUND', false);
    }

    /**
     * 原子发布一次成功搜索；assets 的 bytes 只写摘要 BLOB，不进入 result manifest。
     *
     * @param list<array<string,mixed>> $assets
     */
    public function publish(string $jobId, string $resultId, array $assets): void
    {
        $this->id($jobId, 'job');
        $this->id($resultId, 'result');
        $summaries = [];
        foreach ($assets as $asset) {
            $assetId = (string) ($asset['id'] ?? '');
            $this->id($assetId, 'asset');
            $bytes = $asset['bytes'] ?? null;
            if (!is_string($bytes) || $bytes === '' || !hash_equals((string) $asset['sha256'], hash('sha256', $bytes))
                || strlen($bytes) !== (int) $asset['sizeBytes']) {
                throw new ArtworkProviderRemoteFailure('ARTWORK_PROVIDER_CACHE_INPUT_INVALID', false);
            }
            $summary = $asset;
            unset($summary['bytes']);
            $this->immutable($this->path('blobs', $asset['sha256'] . '.bin'), $bytes);
            $this->immutableJson($this->path('assets', $assetId . '.json'), $summary);
            $summaries[] = $summary;
        }
        $this->immutableJson($this->path('results', $resultId . '.json'), [
            'id' => $resultId, 'jobId' => $jobId, 'assets' => $summaries,
        ]);
        $this->immutableJson($this->path('jobs', $jobId . '.json'), [
            'id' => $jobId, 'status' => 'succeeded', 'resultId' => $resultId,
        ]);
    }

    /** @return array{id:string,jobId:string,assets:list<array<string,mixed>>} */
    public function result(string $resultId): array
    {
        $this->id($resultId, 'result');
        $document = $this->document($this->path('results', $resultId . '.json'));
        if (($document['id'] ?? null) !== $resultId || !is_string($document['jobId'] ?? null)
            || !is_array($document['assets'] ?? null) || !array_is_list($document['assets'])) {
            throw new ArtworkProviderRemoteFailure('ARTWORK_PROVIDER_CACHE_INVALID', false);
        }
        return ['id' => $resultId, 'jobId' => $document['jobId'], 'assets' => $document['assets']];
    }

    /** @return array{id:string,mimeType:string,width:int,height:int,sizeBytes:int,sha256:string,bytes:string} */
    public function asset(string $assetId): array
    {
        $this->id($assetId, 'asset');
        $summary = $this->document($this->path('assets', $assetId . '.json'));
        $sha = $summary['sha256'] ?? null;
        if (($summary['id'] ?? null) !== $assetId || !is_string($sha) || preg_match('/^[a-f0-9]{64}$/', $sha) !== 1) {
            throw new ArtworkProviderRemoteFailure('ARTWORK_PROVIDER_CACHE_INVALID', false);
        }
        $path = $this->path('blobs', $sha . '.bin');
        if (!is_file($path) || is_link($path) || filesize($path) !== (int) ($summary['sizeBytes'] ?? -1)) {
            throw new ArtworkProviderRemoteFailure('ARTWORK_PROVIDER_ASSET_NOT_FOUND', false);
        }
        $bytes = @file_get_contents($path);
        if (!is_string($bytes) || !hash_equals($sha, hash('sha256', $bytes))) {
            throw new ArtworkProviderRemoteFailure('ARTWORK_PROVIDER_CACHE_INVALID', false);
        }
        return ['id' => $assetId, 'mimeType' => (string) $summary['mimeType'],
            'width' => (int) $summary['width'], 'height' => (int) $summary['height'],
            'sizeBytes' => strlen($bytes), 'sha256' => $sha, 'bytes' => $bytes];
    }

    /** 只接受网关自己生成的固定前缀加 SHA-256，路径不能由外部文本拼接。 */
    private function id(string $id, string $kind): void
    {
        if (preg_match('/^builtin-' . preg_quote($kind, '/') . '-[a-f0-9]{64}$/', $id) !== 1) {
            throw new ArtworkProviderRemoteFailure('ARTWORK_PROVIDER_IDENTITY_INVALID', false);
        }
    }

    /** 创建并复验私有根及子目录。 */
    private function path(string $directory, string $file): string
    {
        foreach ([$this->root, $this->root . DIRECTORY_SEPARATOR . $directory] as $path) {
            if ((!is_dir($path) && !@mkdir($path, 0700, true) && !is_dir($path))
                || is_link($path) || !is_writable($path)) {
                throw new ArtworkProviderRemoteFailure('ARTWORK_PROVIDER_CACHE_UNAVAILABLE', true, 30);
            }
            @chmod($path, 0700);
        }
        return $this->root . DIRECTORY_SEPARATOR . $directory . DIRECTORY_SEPARATOR . $file;
    }

    /** @return array<string,mixed> */
    private function document(string $path): array
    {
        if (!is_file($path) || is_link($path) || filesize($path) > 131_072) {
            throw new ArtworkProviderRemoteFailure('ARTWORK_PROVIDER_CACHE_INVALID', false);
        }
        $contents = @file_get_contents($path);
        try {
            $decoded = is_string($contents) ? json_decode($contents, true, 32, JSON_THROW_ON_ERROR) : null;
        } catch (JsonException) {
            throw new ArtworkProviderRemoteFailure('ARTWORK_PROVIDER_CACHE_INVALID', false);
        }
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new ArtworkProviderRemoteFailure('ARTWORK_PROVIDER_CACHE_INVALID', false);
        }
        return $decoded;
    }

    /** @param array<string,mixed> $document */
    private function immutableJson(string $path, array $document): void
    {
        try {
            $json = json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (JsonException) {
            throw new ArtworkProviderRemoteFailure('ARTWORK_PROVIDER_CACHE_INPUT_INVALID', false);
        }
        $this->immutable($path, $json);
    }

    /**
     * 使用临时文件加硬链接完成“不覆盖”的原子发布；竞争者内容不同即身份冲突。
     */
    private function immutable(string $path, string $contents): void
    {
        if (is_file($path)) {
            $existing = @file_get_contents($path);
            if (is_string($existing) && hash_equals(hash('sha256', $existing), hash('sha256', $contents))) return;
            throw new ArtworkProviderRemoteFailure('ARTWORK_PROVIDER_IDENTITY_MISMATCH', false);
        }
        $temporary = dirname($path) . DIRECTORY_SEPARATOR . '.' . basename($path) . '.' . bin2hex(random_bytes(8));
        $handle = @fopen($temporary, 'xb');
        if (!is_resource($handle)) throw new ArtworkProviderRemoteFailure('ARTWORK_PROVIDER_CACHE_UNAVAILABLE', true, 30);
        try {
            if (fwrite($handle, $contents) !== strlen($contents) || !fflush($handle)
                || (function_exists('fsync') && !fsync($handle)) || !chmod($temporary, 0600)) {
                throw new ArtworkProviderRemoteFailure('ARTWORK_PROVIDER_CACHE_UNAVAILABLE', true, 30);
            }
            fclose($handle);
            $handle = null;
            if (!@link($temporary, $path)) {
                $existing = @file_get_contents($path);
                if (!is_string($existing) || !hash_equals(hash('sha256', $existing), hash('sha256', $contents))) {
                    throw new ArtworkProviderRemoteFailure('ARTWORK_PROVIDER_IDENTITY_MISMATCH', false);
                }
            }
        } catch (ArtworkProviderRemoteFailure $failure) {
            throw $failure;
        } catch (Throwable) {
            throw new ArtworkProviderRemoteFailure('ARTWORK_PROVIDER_CACHE_UNAVAILABLE', true, 30);
        } finally {
            if (is_resource($handle)) fclose($handle);
            @unlink($temporary);
        }
    }
}
