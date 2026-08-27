<?php

declare(strict_types=1);

namespace app\application\Playlist;

use JsonException;

/**
 * 解析网易云音乐常见歌单 JSON 导出（playlist.tracks/trackIds 结构）。
 *
 * 导出文件可能来自不同客户端，适配器兼容 `playlist` 包装和根级 tracks，但只接受标题、艺人、专辑
 * 和时长等匹配证据。网易云的 `dt` 单位是毫秒；缺失时长允许继续匹配，不能把缺失解释成零时长。
 */
final class NeteasePlaylistAdapter implements PlaylistPlatformAdapter
{
    public function format(): string
    {
        return 'netease';
    }

    public function parse(string $bytes): PlatformPlaylistDocument
    {
        $data = $this->decode($bytes);
        $playlist = is_array($data['playlist'] ?? null) ? $data['playlist'] : $data;
        $tracks = $playlist['tracks'] ?? $data['tracks'] ?? null;
        if (!is_array($tracks) || !array_is_list($tracks) || $tracks === []) {
            throw new PlaylistImportInvalid('platform_playlist_empty');
        }
        if (count($tracks) > 1000) {
            throw new PlaylistImportInvalid('too_many_entries');
        }
        $entries = [];
        foreach ($tracks as $track) {
            if (!is_array($track)) {
                throw new PlaylistImportInvalid('platform_playlist_entry_invalid');
            }
            $artists = $this->artists($track['ar'] ?? $track['artists'] ?? []);
            $album = $track['al']['name'] ?? ($track['album']['name'] ?? ($track['album'] ?? null));
            $entries[] = new PlatformPlaylistEntry(
                $this->entryText($track['name'] ?? null),
                $artists,
                is_string($album) && trim($album) !== '' ? trim($album) : null,
                $this->duration($track['dt'] ?? ($track['duration'] ?? null)),
                $this->id($track['id'] ?? null),
            );
        }
        $title = $playlist['name'] ?? $data['name'] ?? '网易云歌单';

        return new PlatformPlaylistDocument('netease', $this->text($title, 'platform_playlist_title_invalid'), $entries);
    }

    /** @return array<string, mixed> */
    private function decode(string $bytes): array
    {
        if ($bytes === '' || strlen($bytes) > M3uParser::MAX_BYTES || !mb_check_encoding($bytes, 'UTF-8')) {
            throw new PlaylistImportInvalid('invalid_size_or_encoding');
        }
        try {
            $decoded = json_decode($bytes, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new PlaylistImportInvalid('invalid_platform_json', previous: $exception);
        }
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new PlaylistImportInvalid('invalid_platform_json');
        }

        return $decoded;
    }

    /** @return list<string> */
    private function artists(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            return [];
        }
        $artists = [];
        foreach ($value as $artist) {
            $name = is_array($artist) ? ($artist['name'] ?? null) : $artist;
            if (!is_string($name) || trim($name) === '' || mb_strlen($name) > 300) {
                continue;
            }
            $artists[] = trim($name);
        }

        return array_values(array_unique($artists));
    }

    private function text(mixed $value, string $reason): string
    {
        if (!is_string($value) || trim($value) === '' || mb_strlen($value) > 500) {
            throw new PlaylistImportInvalid($reason);
        }

        return trim($value);
    }

    /** 缺少曲目标题时返回空字符串，由统一匹配层记录 unsupported 而不是丢弃整份歌单。 */
    private function entryText(mixed $value): string
    {
        return is_string($value) && mb_strlen($value) <= 500 ? trim($value) : '';
    }

    private function duration(mixed $value): ?int
    {
        return is_int($value) && $value > 0 && $value <= 86_400_000 ? $value : null;
    }

    private function id(mixed $value): ?string
    {
        return is_int($value) || is_string($value) ? substr((string) $value, 0, 128) : null;
    }
}
