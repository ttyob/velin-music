<?php

declare(strict_types=1);

namespace app\application\Upload;

use Throwable;

/**
 * 管理目标音乐库根内、扫描器会忽略的后台上传暂存文件（UPLOAD-002/003/005/009）。
 *
 * 暂存根固定为服务端登记音乐库根下的 `.velin-upload-staging`，会话和文件节点只使用服务端 ULID，绝不
 * 拼接浏览器文件名。所有入口重新解析登记根、拒绝符号链接和非普通文件，并保持至少 512 MiB 空间。
 * 本类不访问业务数据库；调用方通过 appendChunk 的提交回调把已 fsync 字节与短事务状态绑定。
 */
final readonly class UploadStorageService
{
    public const CHUNK_MAX_BYTES = 8 * 1024 * 1024;
    public const FREE_SPACE_RESERVE_BYTES = 512 * 1024 * 1024;
    private const STAGING_DIRECTORY = '.velin-upload-staging';
    private const OWNER_MARKER = '.velin-owner';
    private const OWNER_CONTENT = "velin-upload-staging-v1\n";

    /**
     * 为新会话建立固定私有暂存目录。
     *
     * libraryRoot 参数保存的是数据库登记的 canonical 音乐库根；当前 realpath 不一致、目录不可写、隐藏暂存根被
     * 替换为符号链接或缺少所有权标记时失败关闭。创建成功后扫描器因隐藏目录规则不会观察半成品。
     * 重复 session ID 不视为成功，避免一个新数据库会话接管旧文件。
     */
    public function prepareSession(string $libraryRoot, string $sessionId): void
    {
        $root = $this->libraryRoot($libraryRoot);
        $staging = $this->stagingRoot($root, true);
        $session = $staging . DIRECTORY_SEPARATOR . $this->ulid($sessionId);
        if (file_exists($session) || is_link($session) || !@mkdir($session, 0700)) {
            throw new UploadStorageFailed('上传暂存会话目录无法创建。');
        }
        @chmod($session, 0700);
        $resolved = realpath($session);
        if ($resolved !== $session || !is_dir($session) || is_link($session)) {
            @rmdir($session);
            throw new UploadStorageFailed('上传暂存会话目录身份无效。');
        }
    }

    /**
     * 追加一个已经校验哈希的连续分片，并在同一文件锁下提交数据库接收事实。
     *
     * 只允许文件当前长度等于 byteOffset，防止并发请求覆盖或制造空洞。字节完整写入并 fsync 后才调用
     * commit；若短数据库事务失败，会在仍持有独占锁时截断回原偏移并再次 fsync。若截断补偿失败，
     * 抛出存储错误，后续请求会因磁盘长度与数据库偏移不一致而失败关闭，不能继续发布。
     *
     * @param callable():void $commit 只允许执行短数据库事务，不能在回调内进行文件或网络 I/O。
     */
    public function appendChunk(
        string $libraryRoot,
        string $sessionId,
        string $fileId,
        int $byteOffset,
        string $bytes,
        callable $commit,
    ): void {
        $length = strlen($bytes);
        if ($byteOffset < 0 || $length < 1 || $length > self::CHUNK_MAX_BYTES) {
            throw new UploadInvalid('上传分片范围无效。');
        }
        $session = $this->sessionDirectory($libraryRoot, $sessionId);
        $this->requireFreeSpace($session, $length);
        $path = $session . DIRECTORY_SEPARATOR . $this->ulid($fileId) . '.part';
        if ((file_exists($path) || is_link($path)) && !$this->isRegularNonLink($path)) {
            throw new UploadStorageFailed('上传暂存文件类型无效。');
        }
        if (!file_exists($path) && $byteOffset !== 0) {
            throw new UploadConflict('上传分片偏移与暂存文件不一致。');
        }

        $handle = @fopen($path, 'c+b');
        if (!is_resource($handle)) throw new UploadStorageFailed('上传暂存文件无法打开。');
        $wroteBytes = false;
        try {
            if (!flock($handle, LOCK_EX)) throw new UploadStorageFailed('上传暂存文件无法加锁。');
            $stat = fstat($handle);
            $pathStat = @lstat($path);
            if (!is_array($stat) || !is_array($pathStat) || is_link($path)
                || (($stat['mode'] ?? 0) & 0170000) !== 0100000
                || (($pathStat['mode'] ?? 0) & 0170000) !== 0100000
                || (int) ($stat['dev'] ?? -1) !== (int) ($pathStat['dev'] ?? -2)
                || (int) ($stat['ino'] ?? -1) !== (int) ($pathStat['ino'] ?? -2)
                || (int) ($stat['size'] ?? -1) !== $byteOffset) {
                throw new UploadConflict('上传分片偏移与当前接收范围不一致。');
            }
            if (fseek($handle, 0, SEEK_END) !== 0) throw new UploadStorageFailed('上传暂存文件无法定位。');
            $remaining = $bytes;
            while ($remaining !== '') {
                $written = fwrite($handle, $remaining);
                if (!is_int($written) || $written < 1) throw new UploadStorageFailed('上传分片写入失败。');
                $remaining = substr($remaining, $written);
            }
            $wroteBytes = true;
            if (!fflush($handle) || (function_exists('fsync') && !fsync($handle))) {
                throw new UploadStorageFailed('上传分片无法安全落盘。');
            }
            $commit();
        } catch (Throwable $throwable) {
            if ($wroteBytes) {
                $compensated = ftruncate($handle, $byteOffset) && fflush($handle)
                    && (!function_exists('fsync') || fsync($handle));
                if (!$compensated) {
                    throw new UploadStorageFailed('上传分片状态提交失败且暂存补偿失败。', previous: $throwable);
                }
            }
            throw $throwable;
        } finally {
            @flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * 删除一个尚未发布会话拥有的固定暂存节点。
     *
     * 目录只允许包含所有权标记和 `<file-ulid>.part` 普通文件；任何未知节点、子目录或符号链接都会
     * 失败关闭，不做部分递归删除。成功只影响该会话暂存，音乐库根中其他文件永不遍历或删除。
     */
    public function cleanupSession(string $libraryRoot, string $sessionId): void
    {
        $session = $this->sessionDirectory($libraryRoot, $sessionId);
        $entries = scandir($session);
        if (!is_array($entries)) throw new UploadStorageFailed('上传暂存会话无法检查。');
        $files = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $path = $session . DIRECTORY_SEPARATOR . $entry;
            if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}\.part$/', $entry) !== 1
                || !$this->isRegularNonLink($path)) {
                throw new UploadStorageFailed('上传暂存会话包含未知节点，已停止清理。');
            }
            $files[] = $path;
        }
        foreach ($files as $path) {
            if (!@unlink($path)) throw new UploadStorageFailed('上传暂存文件清理失败。');
        }
        if (!@rmdir($session)) throw new UploadStorageFailed('上传暂存会话目录清理失败。');
    }

    /**
     * 幂等清理一个取消/过期会话；仅在登记根和暂存根所有权已验证后把“不存在”视为成功。
     *
     * 该入口供持久化回收任务重试：进程可能在删除目录后、提交清理终态前退出。若暂存根存在但被替换
     * 为符号链接、所有权标记无效，或会话节点是未知类型，仍然失败关闭，绝不借“不存在”语义跳过
     * 身份验证或递归处理其他节点。
     */
    public function cleanupSessionIfPresent(string $libraryRoot, string $sessionId): void
    {
        $root = $this->libraryRoot($libraryRoot);
        $sessionId = $this->ulid($sessionId);
        $stagingPath = $root . DIRECTORY_SEPARATOR . self::STAGING_DIRECTORY;
        if (!file_exists($stagingPath) && !is_link($stagingPath)) return;
        $staging = $this->stagingRoot($root, false);
        $session = $staging . DIRECTORY_SEPARATOR . $sessionId;
        if (!file_exists($session) && !is_link($session)) return;
        if (is_link($session) || !is_dir($session) || realpath($session) !== $session) {
            throw new UploadStorageFailed('上传暂存会话目录身份无效。');
        }
        $this->cleanupSession($root, $sessionId);
    }

    /**
     * 读取一个完整暂存文件的可信身份和 SHA-256，供发布 Worker 在事务外做媒体校验。
     *
     * 文件路径只由 ULID 组成；读取前后都验证普通非链接文件、长度、device、inode 和 mtime，关闭
     * 哈希期间被替换的竞态。结果中的 path 只能在同一 Worker 调用链内部使用，禁止序列化或记录。
     *
     * @return array{path:string,sha256:string,device:int,inode:int,size:int,modifiedAt:int}
     */
    public function inspectCompleteFile(
        string $libraryRoot,
        string $sessionId,
        string $fileId,
        int $expectedSize,
        ?string $expectedSha256,
    ): array {
        $session = $this->sessionDirectory($libraryRoot, $sessionId);
        $path = $session . DIRECTORY_SEPARATOR . $this->ulid($fileId) . '.part';
        $before = @lstat($path);
        if (!is_array($before) || (($before['mode'] ?? 0) & 0170000) !== 0100000 || is_link($path)
            || (int) ($before['size'] ?? -1) !== $expectedSize) {
            throw new UploadStorageFailed('上传暂存文件身份或大小无效。');
        }
        $sha256 = @hash_file('sha256', $path);
        $after = @lstat($path);
        if (!is_string($sha256) || strlen($sha256) !== 64 || !is_array($after)
            || (int) $before['dev'] !== (int) $after['dev'] || (int) $before['ino'] !== (int) $after['ino']
            || (int) $before['size'] !== (int) $after['size'] || (int) $before['mtime'] !== (int) $after['mtime']) {
            throw new UploadStorageFailed('上传暂存文件在校验期间发生变化。');
        }
        if ($expectedSha256 !== null && !hash_equals($expectedSha256, $sha256)) {
            throw new UploadHashMismatch('上传文件最终哈希不匹配。');
        }
        return [
            'path' => $path, 'sha256' => $sha256, 'device' => max(0, (int) $after['dev']),
            'inode' => max(0, (int) $after['ino']), 'size' => (int) $after['size'],
            'modifiedAt' => max(0, (int) $after['mtime']),
        ];
    }

    /**
     * 将一批已验证暂存文件以“不覆盖”语义公布到动态检测目录，并提交终态回调。
     *
     * 每个目标父目录逐段创建/复验，任何现有目标（含断链符号链接）都会在发布前拒绝。公布使用同盘
     * `link -> unlink staging`，link 不覆盖既有目录项；全部文件公布后才执行短数据库 commit。任一步骤
     * 或 commit 失败时按逆序把同一 inode 链回暂存并移除目标。补偿失败会抛出明确存储错误，不能声称
     * 回滚完成；管理员需根据持久身份进行校准。
     *
     * @param list<array{id:string,relativePath:string,device:int,inode:int,size:int}> $files
     * 数据库终态提交后，空会话目录只是可回收的应用暂存节点；其删除失败不能否定已经
     * 成功公布且提交的文件。返回 false 时调用方必须记录脱敏运维告警，由受控清理流程重试，
     * 不允许将会话改回 failed 或重新公布目标。
     *
     * @param callable():void $commit 只执行短数据库终态事务，不得包含文件、媒体或网络操作。
     * @return bool 公布与数据库提交必定已成功；true 表示空暂存会话目录也已回收。
     */
    public function publishBatch(
        string $libraryRoot,
        string $sessionId,
        array $files,
        callable $commit,
    ): bool {
        $root = $this->libraryRoot($libraryRoot);
        $session = $this->sessionDirectory($root, $sessionId);
        $expectedNames = array_map(static fn (array $file): string => $file['id'] . '.part', $files);
        $this->assertSessionContainsOnly($session, $expectedNames);
        $plans = [];
        foreach ($files as $file) {
            $source = $session . DIRECTORY_SEPARATOR . $this->ulid($file['id']) . '.part';
            $this->assertFileIdentity($source, $file);
            $target = $this->targetPath($root, $file['relativePath']);
            if (file_exists($target) || is_link($target)) throw new UploadConflict('上传目标已存在。');
            $plans[] = ['source' => $source, 'target' => $target] + $file;
        }

        $published = [];
        try {
            foreach ($plans as $plan) {
                if (!@link($plan['source'], $plan['target'])) throw new UploadConflict('上传目标发布冲突。');
                $this->assertFileIdentity($plan['target'], $plan);
                if (!@unlink($plan['source'])) {
                    @unlink($plan['target']);
                    throw new UploadStorageFailed('上传暂存目录项无法移除。');
                }
                $published[] = $plan;
            }
            $commit();
        } catch (Throwable $throwable) {
            $compensated = true;
            foreach (array_reverse($published) as $plan) {
                if (!$this->isSameFile($plan['target'], $plan)) {
                    $compensated = false;
                    continue;
                }
                if (!file_exists($plan['source']) && !is_link($plan['source'])) {
                    if (!@link($plan['target'], $plan['source'])) {
                        $compensated = false;
                        continue;
                    }
                }
                if (!@unlink($plan['target'])) $compensated = false;
            }
            if (!$compensated) {
                throw new UploadStorageFailed('上传发布失败且部分文件无法安全补偿。', previous: $throwable);
            }
            throw $throwable;
        }
        return @rmdir($session);
    }

    /** 返回经重新验证的会话暂存目录；不存在与身份变化均失败关闭。 */
    public function sessionDirectory(string $libraryRoot, string $sessionId): string
    {
        $root = $this->libraryRoot($libraryRoot);
        $staging = $this->stagingRoot($root, false);
        $session = $staging . DIRECTORY_SEPARATOR . $this->ulid($sessionId);
        if (is_link($session) || !is_dir($session) || realpath($session) !== $session) {
            throw new UploadStorageFailed('上传暂存会话目录不可用。');
        }

        return $session;
    }

    /** 验证登记音乐库根的当前身份与可写性，防止挂载或根符号链接被替换。 */
    private function libraryRoot(string $registered): string
    {
        $current = realpath($registered);
        if ($current === false || $current !== rtrim($registered, DIRECTORY_SEPARATOR)
            || !is_dir($current) || !is_writable($current)) {
            throw new UploadStorageFailed('上传目标目录不可用或身份已变化。');
        }

        return $current;
    }

    /** 创建或验证应用拥有的固定隐藏暂存根。 */
    private function stagingRoot(string $libraryRoot, bool $create): string
    {
        $staging = $libraryRoot . DIRECTORY_SEPARATOR . self::STAGING_DIRECTORY;
        if (!file_exists($staging) && !is_link($staging)) {
            if (!$create || !@mkdir($staging, 0700)) throw new UploadStorageFailed('上传暂存根不可用。');
            @chmod($staging, 0700);
            $marker = @fopen($staging . DIRECTORY_SEPARATOR . self::OWNER_MARKER, 'x+b');
            if (!is_resource($marker)) throw new UploadStorageFailed('上传暂存所有权标记无法创建。');
            try {
                if (fwrite($marker, self::OWNER_CONTENT) !== strlen(self::OWNER_CONTENT)
                    || !fflush($marker) || (function_exists('fsync') && !fsync($marker))) {
                    throw new UploadStorageFailed('上传暂存所有权标记无法落盘。');
                }
            } finally {
                fclose($marker);
            }
        }
        $markerPath = $staging . DIRECTORY_SEPARATOR . self::OWNER_MARKER;
        if (is_link($staging) || realpath($staging) !== $staging || !is_dir($staging) || !is_writable($staging)
            || !$this->isRegularNonLink($markerPath)
            || file_get_contents($markerPath) !== self::OWNER_CONTENT) {
            throw new UploadStorageFailed('上传暂存根所有权无法验证。');
        }

        return $staging;
    }

    /** 在写入前保留固定安全余量，无法取得容量时失败关闭。 */
    private function requireFreeSpace(string $path, int $incomingBytes): void
    {
        $free = @disk_free_space($path);
        if (!is_float($free) || $free < $incomingBytes + self::FREE_SPACE_RESERVE_BYTES) {
            throw new UploadStorageFailed('上传暂存空间不足。');
        }
    }

    /** 使用 lstat 拒绝符号链接，并只接受普通文件。 */
    private function isRegularNonLink(string $path): bool
    {
        $stat = @lstat($path);
        return is_array($stat) && (($stat['mode'] ?? 0) & 0170000) === 0100000 && !is_link($path);
    }

    /** 确认会话目录只包含本批次应发布的普通 part 文件，拒绝未知或缺失节点。 */
    private function assertSessionContainsOnly(string $session, array $expectedNames): void
    {
        $entries = scandir($session);
        if (!is_array($entries)) throw new UploadStorageFailed('上传暂存会话无法检查。');
        $actual = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $path = $session . DIRECTORY_SEPARATOR . $entry;
            if (!$this->isRegularNonLink($path)) throw new UploadStorageFailed('上传暂存会话包含非普通文件。');
            $actual[] = $entry;
        }
        sort($actual);
        sort($expectedNames);
        if ($actual !== $expectedNames) throw new UploadStorageFailed('上传暂存会话文件集合不一致。');
    }

    /** 逐段创建或验证目标父目录，不跟随任何子级符号链接。 */
    private function targetPath(string $root, string $relativePath): string
    {
        if ($relativePath === '' || strlen($relativePath) > 1024 || str_contains($relativePath, "\0")
            || str_contains($relativePath, '\\')) throw new UploadInvalid('上传目标相对路径无效。');
        $segments = explode('/', $relativePath);
        if ($segments === [] || in_array('', $segments, true) || in_array('.', $segments, true)
            || in_array('..', $segments, true)) throw new UploadInvalid('上传目标相对路径无效。');
        $fileName = array_pop($segments);
        $directory = $root;
        foreach ($segments as $segment) {
            $directory .= DIRECTORY_SEPARATOR . $segment;
            if (!file_exists($directory) && !is_link($directory) && !@mkdir($directory, 0755)) {
                throw new UploadStorageFailed('上传目标父目录无法创建。');
            }
            if (is_link($directory) || !is_dir($directory)) {
                throw new UploadStorageFailed('上传目标父目录身份无效。');
            }
            $resolved = realpath($directory);
            if ($resolved !== $directory || !str_starts_with($resolved . DIRECTORY_SEPARATOR,
                $root . DIRECTORY_SEPARATOR)) throw new UploadStorageFailed('上传目标父目录越出登记根。');
        }
        return $directory . DIRECTORY_SEPARATOR . $fileName;
    }

    /** 复验普通文件与计划中的 device/inode/size 完全一致。 */
    private function assertFileIdentity(string $path, array $identity): void
    {
        if (!$this->isSameFile($path, $identity)) throw new UploadStorageFailed('上传文件身份已变化。');
    }

    /** 比较一个路径是否仍指向计划捕获的同一普通文件。 */
    private function isSameFile(string $path, array $identity): bool
    {
        $stat = @lstat($path);
        return is_array($stat) && (($stat['mode'] ?? 0) & 0170000) === 0100000 && !is_link($path)
            && (int) $stat['dev'] === (int) $identity['device']
            && (int) $stat['ino'] === (int) $identity['inode']
            && (int) $stat['size'] === (int) $identity['size'];
    }

    /** 严格限制所有磁盘节点标识为服务端 ULID。 */
    private function ulid(string $value): string
    {
        if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $value) !== 1) {
            throw new UploadInvalid('上传对象标识无效。');
        }

        return $value;
    }
}
