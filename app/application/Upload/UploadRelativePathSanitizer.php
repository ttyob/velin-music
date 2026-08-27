<?php

declare(strict_types=1);

namespace app\application\Upload;

/**
 * 把浏览器目录上传中的不可信相对路径转换为受控动态检测目录内的规范路径（UPLOAD-004/006）。
 *
 * 输入允许 `/` 或 Windows `\` 作为相对目录分隔符，但拒绝绝对路径、盘符、协议、`.`/`..`、隐藏段、
 * 空段和过深层级。控制字符及跨平台保留字符会替换为 `_`，Windows 设备名会追加 `_`。输出仍只是
 * 逻辑相对路径，调用者必须在文件操作前重新校验真实父目录没有符号链接且位于登记根内。
 */
final readonly class UploadRelativePathSanitizer
{
    /** @var array<string,string> */
    private const EXTENSIONS = [
        'mp3' => 'audio', 'flac' => 'audio', 'aac' => 'audio', 'm4a' => 'audio', 'm4b' => 'audio',
        'alac' => 'audio', 'ogg' => 'audio', 'oga' => 'audio', 'opus' => 'audio', 'wav' => 'audio',
        'aiff' => 'audio', 'aif' => 'audio', 'wma' => 'audio', 'ape' => 'audio',
        'jpg' => 'image', 'jpeg' => 'image', 'png' => 'image', 'webp' => 'image',
        'lrc' => 'lyrics', 'm3u' => 'playlist', 'm3u8' => 'playlist', 'cue' => 'cue',
    ];

    /**
     * 清洗并分类一条相对路径。
     *
     * 最多保留 16 层、每段 180 字节、总长 1024 字节；扩展名必须属于固定媒体/附属文件白名单。
     * 失败不创建目录、不写数据库。返回路径统一使用 `/`，适合持久化和后续平台无关比较。
     *
     * @return array{relativePath:string,extension:string,mediaKind:string}
     */
    public function sanitize(string $input): array
    {
        if ($input === '' || strlen($input) > 4096 || str_contains($input, "\0")
            || str_starts_with($input, '/') || str_starts_with($input, '\\')
            || preg_match('/^[A-Za-z]:[\\\\\/]/', $input) === 1
            || preg_match('/^[A-Za-z][A-Za-z0-9+.-]*:\/\//', $input) === 1) {
            throw new UploadInvalid('上传相对路径无效。');
        }

        $rawSegments = explode('/', str_replace('\\', '/', $input));
        if (count($rawSegments) > 16) throw new UploadInvalid('上传目录层级过深。');
        $segments = [];
        foreach ($rawSegments as $raw) {
            if ($raw === '' || $raw === '.' || $raw === '..' || str_starts_with($raw, '.')) {
                throw new UploadInvalid('上传相对路径包含禁止的目录段。');
            }
            $segment = preg_replace('/[\x00-\x1F\x7F<>:"|?*]/u', '_', $raw);
            if (!is_string($segment)) throw new UploadInvalid('上传文件名编码无效。');
            $segment = rtrim(trim($segment), ". ");
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new UploadInvalid('上传文件名清洗后为空。');
            }
            $stem = strtolower((string) pathinfo($segment, PATHINFO_FILENAME));
            if (preg_match('/^(con|prn|aux|nul|com[1-9]|lpt[1-9])$/', $stem) === 1) {
                $segment .= '_';
            }
            if (strlen($segment) > 180) throw new UploadInvalid('上传文件名过长。');
            $segments[] = $segment;
        }

        $relativePath = implode('/', $segments);
        if ($relativePath === '' || strlen($relativePath) > 1024) throw new UploadInvalid('上传相对路径过长。');
        $extension = strtolower((string) pathinfo(end($segments), PATHINFO_EXTENSION));
        $mediaKind = self::EXTENSIONS[$extension] ?? null;
        if ($mediaKind === null) throw new UploadInvalid('上传文件类型不受支持。');

        return ['relativePath' => $relativePath, 'extension' => $extension, 'mediaKind' => $mediaKind];
    }
}
