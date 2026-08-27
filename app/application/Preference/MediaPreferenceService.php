<?php

declare(strict_types=1);

namespace app\application\Preference;

use app\application\Media\MediaQueryService;
use app\application\Media\SongDuplicateRedirectResolver;
use stdClass;
use support\Db;

/**
 * 在实时媒体授权后维护单个用户的收藏与个人评分。
 *
 * 表名与媒体外键只来自服务端封闭映射，控制器不能提供 SQL 结构。可见性证明先于短写事务执行；若授权在
 * 两者之间被撤销，遗留偏好也不会被读取，因为所有目录投影都会重新应用实时 grant。后续清理任务可以
 * 删除这种休眠行，本服务不通过延长写事务锁定授权表。
 */
final class MediaPreferenceService
{
    /**
     * 设置或清除一个已授权媒体对象的收藏状态。
     *
     * 重复命令幂等；持续收藏期间保留原时间，清除后若仍有评分则只清空收藏字段，稍后再次收藏生成新的
     * UTC 时间。批量方法负责授权验证与事务回滚。
     */
    public function setFavorite(array $actor, string $type, string $mediaId, bool $favorite): array
    {
        return $this->setFavorites($actor, [['type' => $type, 'mediaId' => $mediaId]], $favorite)[0];
    }

    /**
     * 设置或清除一个可见媒体对象的个人评分。
     *
     * 评分只能是 1 到 5；NULL 表示清除。写入前复用目录查询的实时授权边界，缺失与无权对象统一失败，
     * 避免通过评分端点枚举库存。重复设置保留首次 `rated_at`，清除评分时若仍收藏则保留稀疏行；既未收藏
     * 又无评分时删除整行。事务失败会回滚本次写入，不修改共享媒体元数据。
     *
     * @return array{type:string,mediaId:string,rating:int|null,ratedAt:string|null}
     */
    public function setRating(array $actor, string $type, string $mediaId, ?int $rating): array
    {
        if ($rating !== null && ($rating < 1 || $rating > 5)) {
            throw new MediaPreferenceInvalid('Rating is invalid.');
        }
        $mediaId = $this->canonicalMediaId($type, $mediaId);
        [$table, $column] = $this->storage($type, $mediaId);
        if (!(new MediaQueryService())->mediaExists($actor, $type, $mediaId)) {
            throw new MediaPreferenceNotFound('Media not found.');
        }
        $userId = (string) ($actor['id'] ?? '');

        return Db::transaction(function () use ($userId, $type, $mediaId, $table, $column, $rating): array {
            /** @var stdClass|null $existing */
            $existing = Db::table($table)->where('user_id', $userId)->where($column, $mediaId)->first();
            $now = gmdate('Y-m-d\TH:i:s\Z');
            $ratedAt = $rating === null
                ? null
                : ($existing?->rated_at === null ? $now : (string) $existing->rated_at);
            $favorite = (bool) ($existing?->is_favorite ?? false);
            if ($rating === null && !$favorite) {
                Db::table($table)->where('user_id', $userId)->where($column, $mediaId)->delete();
            } else {
                $values = [
                    'is_favorite' => $favorite ? 1 : 0,
                    'favorited_at' => $favorite ? (string) $existing->favorited_at : null,
                    'rating' => $rating,
                    'rated_at' => $ratedAt,
                    'updated_at' => $now,
                ];
                if ($existing instanceof stdClass) {
                    Db::table($table)->where('user_id', $userId)->where($column, $mediaId)->update($values);
                } else {
                    Db::table($table)->insert(['user_id' => $userId, $column => $mediaId] + $values);
                }
            }

            return ['type' => $type, 'mediaId' => $mediaId, 'rating' => $rating, 'ratedAt' => $ratedAt];
        });
    }

    /**
     * Atomically sets favorite state for an already bounded heterogeneous media batch.
     *
     * Every object is validated and proven visible before the write transaction begins. This avoids
     * a malformed or revoked item leaving an earlier Subsonic `star` item committed. Duplicate
     * type/ID pairs are collapsed in first-seen order; retries preserve favorite timestamps.
     *
     * @param list<array{type: string, mediaId: string}> $items Maximum size is enforced by caller.
     * @return list<array{type: string, mediaId: string, favorite: bool, favoritedAt: string|null}>
     */
    public function setFavorites(array $actor, array $items, bool $favorite): array
    {
        $prepared = [];
        foreach ($items as $item) {
            $type = is_string($item['type'] ?? null) ? $item['type'] : '';
            $mediaId = is_string($item['mediaId'] ?? null) ? $item['mediaId'] : '';
            $mediaId = $this->canonicalMediaId($type, $mediaId);
            [$table, $column] = $this->storage($type, $mediaId);
            if (!(new MediaQueryService())->mediaExists($actor, $type, $mediaId)) {
                throw new MediaPreferenceNotFound('Media not found.');
            }
            $prepared[$type . ':' . $mediaId] = [$type, $mediaId, $table, $column];
        }
        if ($prepared === []) {
            throw new MediaPreferenceInvalid('At least one media object is required.');
        }
        $userId = (string) ($actor['id'] ?? '');

        return Db::transaction(function () use ($favorite, $prepared, $userId): array {
            $results = [];
            foreach ($prepared as [$type, $mediaId, $table, $column]) {
                $results[] = $this->mutateStored($userId, $type, $mediaId, $table, $column, $favorite);
            }

            return $results;
        });
    }

    /**
     * 在调用方拥有的事务内应用一个稀疏偏好行变更。
     *
     * 调用前必须已证明该类型/ID 可见。重复收藏保留原时间；取消收藏保留个人评分，只有两种状态都为空才
     * 删除行。本方法不自行打开或提交事务，从而使 Subsonic 异构批量保持全成或全败。
     *
     * @return array{type: string, mediaId: string, favorite: bool, favoritedAt: string|null}
     */
    private function mutateStored(
        string $userId,
        string $type,
        string $mediaId,
        string $table,
        string $column,
        bool $favorite,
    ): array {
        /** @var stdClass|null $existing */
        $existing = Db::table($table)->where('user_id', $userId)->where($column, $mediaId)->first();
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $favoritedAt = $favorite
            ? ($existing?->favorited_at === null ? $now : (string) $existing->favorited_at)
            : null;
        $rating = $existing?->rating === null ? null : (int) $existing->rating;
        $ratedAt = $existing?->rated_at === null ? null : (string) $existing->rated_at;
        if (!$favorite && $rating === null) {
            Db::table($table)->where('user_id', $userId)->where($column, $mediaId)->delete();
        } else {
            $values = [
                'is_favorite' => $favorite ? 1 : 0,
                'favorited_at' => $favoritedAt,
                'rating' => $rating,
                'rated_at' => $ratedAt,
                'updated_at' => $now,
            ];
            if ($existing instanceof stdClass) {
                Db::table($table)->where('user_id', $userId)->where($column, $mediaId)->update($values);
            } else {
                Db::table($table)->insert(['user_id' => $userId, $column => $mediaId] + $values);
            }
        }

        return [
            'type' => $type,
            'mediaId' => $mediaId,
            'favorite' => $favorite,
            'favoritedAt' => $favoritedAt,
        ];
    }

    /** @return array{string, string} Validated table and foreign-key column. */
    private function storage(string $type, string $mediaId): array
    {
        if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $mediaId) !== 1) {
            throw new MediaPreferenceInvalid('Media ID is invalid.');
        }
        return match ($type) {
            'songs' => ['user_song_preferences', 'song_id'],
            'albums' => ['user_album_preferences', 'album_id'],
            'artists' => ['user_artist_preferences', 'artist_id'],
            default => throw new MediaPreferenceInvalid('Media type is invalid.'),
        };
    }

    /**
     * 将仍有强证据支持的旧歌曲 ID 收敛为保留项。
     *
     * 解析不替代授权：调用方随后仍使用 MediaQueryService 对目标重新证明可见性。专辑和艺术家没有
     * 歌曲级逻辑合并关系，保持原 ID；格式错误继续由 storage 统一拒绝。
     */
    private function canonicalMediaId(string $type, string $mediaId): string
    {
        return $type === 'songs' ? (new SongDuplicateRedirectResolver())->resolve($mediaId) : $mediaId;
    }
}
