<?php

declare(strict_types=1);

namespace app\application\Playlist;

use JsonException;

/**
 * 解析 QQ 音乐常见歌单 JSON 导出（data.songlist/songlist 结构）。
 *
 * QQ 的 interval 通常以秒表示，适配器统一转换为毫秒；songmid、media_mid 等平台 ID 只保留在内存
 * 里作为来源证据，不会被写入 Velin 歌曲实体。不同客户端的包装层允许存在，但条目字段必须完整。
 */
final class QQMusicPlaylistAdapter implements PlaylistPlatformAdapter
{
    public function format(): string
    {
        return 'qq';
    }

    public function parse(string $bytes): PlatformPlaylistDocument
    {
        $data = $this->decode($bytes);
        $root = is_array($data['data'] ?? null) ? $data['data'] : $data;
        $root = is_array($root['playlist'] ?? null) ? $root['playlist'] : $root;
        $songs = $root['songlist'] ?? $root['songs'] ?? $root['tracks'] ?? null;
        if (!is_array($songs) || !array_is_list($songs) || $songs === []) {
            throw new PlaylistImportInvalid('platform_playlist_empty');
        }
        if (count($songs) > 1000) {
            throw new PlaylistImportInvalid('too_many_entries');
        }
        $entries = [];
        foreach ($songs as $song) {
            if (!is_array($song)) {
                throw new PlaylistImportInvalid('platform_playlist_entry_invalid');
            }
            $title = $song['songname'] ?? ($song['songName'] ?? ($song['name'] ?? null));
            $albumValue = $song['albumname'] ?? ($song['album']['name'] ?? ($song['album'] ?? null));
            $entries[] = new PlatformPlaylistEntry(
                $this->entryText($title),
                $this->artists($song['singer'] ?? $song['artists'] ?? $song['artist'] ?? []),
                is_string($albumValue) && trim($albumValue) !== '' ? trim($albumValue) : null,
                $this->duration($song['interval'] ?? ($song['duration'] ?? ($song['dt'] ?? null))),
                $this->id($song['songmid'] ?? ($song['mid'] ?? ($song['media_mid'] ?? null))),
            );
        }
        $title = $root['dissname'] ?? ($root['dirname'] ?? ($root['name'] ?? 'QQ 音乐歌单'));

        return new PlatformPlaylistDocument('qq', $this->text($title, 'platform_playlist_title_invalid'), $entries);
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
        if (!is_int($value) || $value <= 0 || $value > 86_400) {
            return null;
        }

        return $value * 1000;
    }

    private function id(mixed $value): ?string
    {
        return is_int($value) || is_string($value) ? substr((string) $value, 0, 128) : null;
    }
}
