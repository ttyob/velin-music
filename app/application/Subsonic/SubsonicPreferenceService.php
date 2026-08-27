<?php

declare(strict_types=1);

namespace app\application\Subsonic;

use app\application\Preference\MediaPreferenceInvalid;
use app\application\Preference\MediaPreferenceNotFound;
use app\application\Preference\MediaPreferenceService;
use app\application\Media\MediaQueryService;

/**
 * 将 Subsonic 收藏和评分命令适配到 Velin 的用户隔离偏好领域。
 *
 * 歌曲、专辑和艺术家参数在写入前完成数量限制、规范化与实时可见性检查。批量收藏委托给全成或全败的
 * 应用事务；缺失和无权对象统一映射为协议 not-found，任何操作都不会读取或修改其他用户的偏好行。
 */
final readonly class SubsonicPreferenceService
{
    public function __construct(
        private MediaPreferenceService $preferences = new MediaPreferenceService(),
    ) {
    }

    /**
     * Sets or clears favorites for up to 100 IDs of each supported entity type.
     *
     * At least one ID is required. Duplicate IDs are harmless and collapse inside the domain service;
     * all visibility checks complete before one transaction commits, so a revoked object cannot cause
     * a partially applied multi-ID command. The successful Subsonic endpoint body is intentionally empty.
     *
     * @param array<string, mixed> $actor Authenticated principal with current grants.
     * @param array<string, mixed> $parameters Merged query/form protocol fields.
     */
    public function favorite(array $actor, array $parameters, bool $favorite): array
    {
        $this->requirePlay($actor);
        $items = [];
        foreach (['id' => 'songs', 'albumId' => 'albums', 'artistId' => 'artists'] as $field => $type) {
            foreach ($this->ids($parameters[$field] ?? null, $field) as $mediaId) {
                $items[] = ['type' => $type, 'mediaId' => $mediaId];
            }
        }
        if ($items === []) {
            throw new SubsonicRequestInvalid('At least one media ID is required.');
        }
        try {
            $this->preferences->setFavorites($actor, $items, $favorite);
        } catch (MediaPreferenceNotFound $exception) {
            throw new SubsonicEntityNotFound('Media was not found.', previous: $exception);
        }

        return [];
    }

    /**
     * 为一个歌曲、专辑或艺术家设置当前用户评分，评分 0 按 Subsonic 约定清除。
     *
     * 协议只提供无类型 `id`，因此服务端在三种受支持对象的实时可见范围内解析类型；必须且只能命中一个
     * 类型，隐藏对象与不存在对象统一返回 not found，理论上的跨表 ID 冲突也失败关闭。评分仅接受规范整数
     * 0..5，成功正文为空；领域层负责事务、幂等时间戳和收藏共存语义。
     *
     * @param array<string,mixed> $actor 已认证主体。
     * @param array<string,mixed> $parameters 合并后的协议参数。
     */
    public function rating(array $actor, array $parameters): array
    {
        $this->requirePlay($actor);
        $mediaId = $this->singleId($parameters['id'] ?? null, 'id');
        $rawRating = $parameters['rating'] ?? null;
        if (is_int($rawRating)) {
            $rating = $rawRating;
        } elseif (is_string($rawRating) && preg_match('/^(?:0|[1-5])$/D', $rawRating) === 1) {
            $rating = (int) $rawRating;
        } else {
            throw new SubsonicRequestInvalid('rating is invalid.');
        }
        if ($rating < 0 || $rating > 5) {
            throw new SubsonicRequestInvalid('rating is invalid.');
        }

        $media = new MediaQueryService();
        $types = array_values(array_filter(
            ['songs', 'albums', 'artists'],
            static fn (string $type): bool => $media->mediaExists($actor, $type, $mediaId),
        ));
        if (count($types) !== 1) {
            throw new SubsonicEntityNotFound('Media was not found.');
        }
        try {
            $this->preferences->setRating($actor, $types[0], $mediaId, $rating === 0 ? null : $rating);
        } catch (MediaPreferenceNotFound $exception) {
            throw new SubsonicEntityNotFound('Media was not found.', previous: $exception);
        } catch (MediaPreferenceInvalid $exception) {
            throw new SubsonicRequestInvalid('Rating is invalid.', previous: $exception);
        }

        return [];
    }

    /** @return list<string> Converts one scalar/array field into at most 100 strict opaque IDs. */
    private function ids(mixed $value, string $field): array
    {
        if ($value === null) {
            return [];
        }
        $values = is_array($value) ? array_values($value) : [$value];
        if (count($values) > 100) {
            throw new SubsonicRequestInvalid($field . ' contains too many IDs.');
        }

        return array_map(fn (mixed $item): string => $this->singleId($item, $field), $values);
    }

    /** Rejects arrays, path-like tokens, and non-ULID values before authorization queries. */
    private function singleId(mixed $value, string $field): string
    {
        if (!is_string($value) || preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $value) !== 1) {
            throw new SubsonicRequestInvalid($field . ' is invalid.');
        }

        return $value;
    }

    /** Enforces global play capability in addition to object-level live grant checks. */
    private function requirePlay(array $actor): void
    {
        $capabilities = is_array($actor['capabilities'] ?? null) ? $actor['capabilities'] : [];
        if (!in_array('play', $capabilities, true)) {
            throw new SubsonicAuthorizationDenied('Subsonic preference operation is not authorized.');
        }
    }
}
