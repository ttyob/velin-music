<?php

declare(strict_types=1);

namespace app\application\Playlist;

/**
 * Validates playlist metadata and complete item replacement commands before SQLite locking.
 *
 * Names are trimmed to 1-100 Unicode characters, descriptions to 1000, and ordinary playlists use
 * only private/server visibility. Song arrays allow intentional duplicates but cap at 1000 items;
 * every element must be a canonical stable media ULID. Validation has no persistence side effects.
 */
final class PlaylistValidator
{
    /** @return array{name: string, description: string|null, visibility: string} */
    public function create(array $payload): array
    {
        return $this->metadata($payload);
    }

    /** @return array{expectedVersion: int, name: string, description: string|null, visibility: string} */
    public function update(array $payload): array
    {
        return ['expectedVersion' => $this->version($payload['expectedVersion'] ?? null)] + $this->metadata($payload);
    }

    /** @return array{expectedVersion: int, songIds: list<string>} */
    public function items(array $payload): array
    {
        $songIds = $payload['songIds'] ?? null;
        if (!is_array($songIds) || count($songIds) > 1000) {
            throw new PlaylistInvalid('songIds must be an array with at most 1000 entries.');
        }
        $validated = [];
        foreach ($songIds as $songId) {
            if (!is_string($songId) || preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $songId) !== 1) {
                throw new PlaylistInvalid('songIds contains an invalid media ID.');
            }
            $validated[] = $songId;
        }

        return ['expectedVersion' => $this->version($payload['expectedVersion'] ?? null), 'songIds' => $validated];
    }

    /**
     * 校验一条由歌单所有者手动登记的未入库歌曲。
     *
     * 该命令只保存脱敏显示元数据，不接受媒体 ID、URL、路径或任意扩展字段；歌名和艺人名必填，专辑名
     * 可为空。返回值供服务层在同一个版本锁事务中写入 `playlist_import_entries`，失败时不产生副作用。
     *
     * @return array{expectedVersion:int,title:string,artist:string,album:string|null}
     */
    public function manualEntry(array $payload): array
    {
        if (array_diff(array_keys($payload), ['expectedVersion', 'title', 'artist', 'album']) !== []) {
            throw new PlaylistInvalid('manual entry contains unknown fields.');
        }
        $title = $this->displayText($payload['title'] ?? null, 500, false);
        $artist = $this->displayText($payload['artist'] ?? null, 300, false);
        $album = $this->displayText($payload['album'] ?? null, 500, true);

        return [
            'expectedVersion' => $this->version($payload['expectedVersion'] ?? null),
            'title' => $title,
            'artist' => $artist,
            'album' => $album,
        ];
    }

    /**
     * 校验一次完整歌单编辑命令，不访问数据库也不改变输入中的歌曲顺序。
     *
     * 名称、说明和可见范围沿用普通元数据边界，曲目数组沿用完整替换边界并允许
     * 有意重复。两个子校验共享同一个 expectedVersion；返回值只供服务层在单个
     * 事务中提交，任一字段失败时调用方不得执行部分更新。
     *
     * @return array{expectedVersion: int, name: string, description: string|null, visibility: string, songIds: list<string>}
     */
    public function replaceAll(array $payload): array
    {
        $metadata = $this->update($payload);
        $items = $this->items($payload);

        return $metadata + ['songIds' => $items['songIds']];
    }

    /** Returns a positive optimistic version accepted from JSON or canonical form input. */
    public function version(mixed $value): int
    {
        if (is_string($value) && preg_match('/^[1-9][0-9]*$/', $value) === 1) {
            $value = (int) $value;
        }
        if (!is_int($value) || $value < 1 || $value > 2_147_483_647) {
            throw new PlaylistInvalid('expectedVersion is invalid.');
        }

        return $value;
    }

    /** @return array{name: string, description: string|null, visibility: string} */
    private function metadata(array $payload): array
    {
        $name = is_string($payload['name'] ?? null) ? trim($payload['name']) : '';
        if ($name === '' || mb_strlen($name) > 100) {
            throw new PlaylistInvalid('name must contain 1-100 characters.');
        }
        $description = is_string($payload['description'] ?? null) ? trim($payload['description']) : '';
        if (mb_strlen($description) > 1000) {
            throw new PlaylistInvalid('description exceeds 1000 characters.');
        }
        $visibility = $payload['visibility'] ?? 'private';
        if (!is_string($visibility) || !in_array($visibility, ['private', 'server'], true)) {
            throw new PlaylistInvalid('visibility is invalid.');
        }

        return ['name' => $name, 'description' => $description === '' ? null : $description, 'visibility' => $visibility];
    }

    /** 校验歌单显示字段，拒绝控制字符并把可选空文本规范化为 NULL。 */
    private function displayText(mixed $value, int $maximum, bool $nullable): ?string
    {
        if ($value === null && $nullable) return null;
        if (!is_string($value)) throw new PlaylistInvalid('manual entry text is invalid.');
        $value = trim($value);
        if ($value === '' && $nullable) return null;
        if ($value === '' || mb_strlen($value) > $maximum
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1) {
            throw new PlaylistInvalid('manual entry text is invalid.');
        }
        return $value;
    }
}
