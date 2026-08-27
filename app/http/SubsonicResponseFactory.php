<?php

declare(strict_types=1);

namespace app\http;

use JsonException;
use support\Request;
use support\Response;

/**
 * Serializes the shared Subsonic v1.16.1 envelope as JSON or namespace-correct XML.
 *
 * Controller data must already be authorization-safe. Associative scalar fields become XML
 * attributes, nested maps become child elements, and lists repeat their parent key. The same input
 * remains conventional Subsonic JSON under `subsonic-response`. XML escaping is centralized here;
 * arbitrary raw markup is never accepted. Protocol errors intentionally use HTTP 200 because many
 * established clients inspect the Subsonic status/error body rather than HTTP status.
 */
final class SubsonicResponseFactory
{
    private const API_VERSION = '1.16.1';

    /** @param array<string, mixed> $body Authorized endpoint-specific response members. */
    public static function success(Request $request, array $body = [], ?string $requestId = null): Response
    {
        return self::create($request, ['status' => 'ok'] + $body, $requestId);
    }

    /** Emits one stable Subsonic numeric error without echoing submitted credentials or values. */
    public static function error(
        Request $request,
        int $code,
        string $message,
        ?string $requestId = null,
    ): Response
    {
        return self::create($request, [
            'status' => 'failed',
            'error' => ['code' => $code, 'message' => $message],
        ], $requestId);
    }

    /** @param array<string, mixed> $payload Root status plus endpoint data. */
    private static function create(Request $request, array $payload, ?string $requestId): Response
    {
        $requestId ??= RequestContext::requestId();
        $root = $payload + [
            'version' => self::API_VERSION,
            'type' => 'velin',
            'serverVersion' => (string) (getenv('VELIN_VERSION') ?: '0.1.0-dev'),
            'openSubsonic' => true,
        ];
        if (self::format($request) === 'json') {
            try {
                $body = json_encode(
                    ['subsonic-response' => $root],
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
                );
            } catch (JsonException) {
                $body = '{"subsonic-response":{"status":"failed","version":"1.16.1","error":{"code":0,"message":"Server error"}}}';
            }

            return response($body, 200, self::headers('application/json; charset=utf-8', $requestId));
        }

        $attributes = self::xmlAttributes([
            'status' => $root['status'],
            'version' => $root['version'],
            'type' => $root['type'],
            'serverVersion' => $root['serverVersion'],
            'openSubsonic' => $root['openSubsonic'],
        ]);
        unset($root['status'], $root['version'], $root['type'], $root['serverVersion'], $root['openSubsonic']);
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<subsonic-response xmlns="http://subsonic.org/restapi"' . $attributes . '>'
            . self::xmlChildren($root)
            . '</subsonic-response>';

        return response($xml, 200, self::headers('application/xml; charset=utf-8', $requestId));
    }

    /** Chooses explicit f=json/xml first, then the common .json route suffix. */
    private static function format(Request $request): string
    {
        $requested = $request->get('f');
        if (!is_string($requested) || $requested === '') {
            $requested = $request->post('f');
        }
        if ($requested === 'json' || $requested === 'xml') {
            return $requested;
        }

        return str_ends_with($request->path(), '.json') ? 'json' : 'xml';
    }

    /** @param array<string, mixed> $values @return array<string, string> */
    private static function headers(string $contentType, string $requestId): array
    {
        return [
            'Content-Type' => $contentType,
            'Cache-Control' => 'no-store',
            'X-Request-ID' => $requestId,
        ];
    }

    /** @param array<string, mixed> $children */
    private static function xmlChildren(array $children): string
    {
        $xml = '';
        foreach ($children as $name => $value) {
            if (is_array($value) && array_is_list($value)) {
                foreach ($value as $item) {
                    $xml .= self::xmlElement((string) $name, $item);
                }
                continue;
            }
            $xml .= self::xmlElement((string) $name, $value);
        }

        return $xml;
    }

    /**
     * 按 Subsonic XML schema 序列化一个受控元素。
     *
     * 大多数目录和媒体标量是 XML attribute，但 `artistInfo*`/`albumInfo*` 的简介、外部标识与图片 URL
     * 在协议中是子元素；若一律写成 attribute，JSON 仍正常而 XML 客户端会把这些字段视为缺失。元素名
     * 只来自服务端响应结构，值始终转义，不接受调用方注入原始 XML。序列化失败不会写文件或修改状态，
     * 上层会返回稳定协议错误。
     */
    private static function xmlElement(string $name, mixed $value): string
    {
        if (is_array($value)) {
            $attributes = [];
            $children = [];
            $text = null;
            foreach ($value as $key => $item) {
                // Most Subsonic `value` fields represent XML element text (Genre, Lyrics and lyric
                // Line/Cue). OpenSubsonic songLyrics v2 is the documented exception: cueLine.value
                // is an attribute used as the byte-offset reference for nested cues. Keeping that
                // distinction here makes JSON retain the same schema while XML remains conformant.
                if ($key === 'value' && $name !== 'cueLine' && !is_array($item)) {
                    $text = self::scalar($item);
                    continue;
                }
                if (self::isTextChild($name, (string) $key) && !is_array($item)) {
                    $children[(string) $key] = $item;
                    continue;
                }
                if (is_array($item)) {
                    $children[(string) $key] = $item;
                } else {
                    $attributes[(string) $key] = $item;
                }
            }
            $attributeText = self::xmlAttributes($attributes);
            if ($children === [] && $text === null) {
                return '<' . $name . $attributeText . '/>';
            }

            return '<' . $name . $attributeText . '>'
                . ($text === null ? '' : self::escape($text))
                . self::xmlChildren($children)
                . '</' . $name . '>';
        }

        return '<' . $name . '>' . self::escape(self::scalar($value)) . '</' . $name . '>';
    }

    /**
     * 标识协议明确定义为 XML 子元素、而不是 attribute 的字符串字段。
     *
     * 白名单按父元素收窄，避免同名字段在媒体 `Child` 等其他 schema 中被错误改形；JSON 不经过本分支。
     * 新增协议响应若包含 element-only 标量，必须同步扩展本表和 JSON/XML 契约测试。
     */
    private static function isTextChild(string $parent, string $field): bool
    {
        $fields = match ($parent) {
            'artistInfo', 'artistInfo2' => [
                'biography', 'musicBrainzId', 'lastFmUrl',
                'smallImageUrl', 'mediumImageUrl', 'largeImageUrl',
            ],
            'albumInfo', 'albumInfo2' => [
                'notes', 'musicBrainzId', 'lastFmUrl',
                'smallImageUrl', 'mediumImageUrl', 'largeImageUrl',
            ],
            default => [],
        };

        return in_array($field, $fields, true);
    }

    /** @param array<string, mixed> $attributes */
    private static function xmlAttributes(array $attributes): string
    {
        $xml = '';
        foreach ($attributes as $name => $value) {
            $xml .= ' ' . $name . '="' . self::escape(self::scalar($value)) . '"';
        }

        return $xml;
    }

    /** Converts only protocol scalar types; unsupported values become an empty safe string. */
    private static function scalar(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return is_scalar($value) ? (string) $value : '';
    }

    /** Escapes XML text/attribute values including quotes and invalid substitutions. */
    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
