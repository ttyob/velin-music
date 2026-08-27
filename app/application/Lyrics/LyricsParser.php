<?php

declare(strict_types=1);

namespace app\application\Lyrics;

/**
 * Converts bounded sidecar bytes into Velin Music's plain, line- or word-synchronized model.
 *
 * The caller enforces the 1 MiB file limit before reading; this parser repeats a byte/line bound so
 * it is also safe when used by future upload/provider adapters. It accepts BOM-marked UTF-8/UTF-16,
 * strict UTF-8, and a conservative GB18030/BIG-5 fallback through mbstring. Invalid conversion is
 * rejected rather than persisted with replacement characters. Parsing has no filesystem or DB
 * side effects.
 */
final class LyricsParser
{
    private const MAX_BYTES = 1_048_576;
    private const MAX_LINES = 10_000;
    private const MAX_LINE_BYTES = 4_096;
    private const MAX_WORDS = 50_000;

    /**
     * Parses plain text or LRC timestamps, metadata tags, multiple timestamps, and `[offset:]`.
     *
     * @throws LyricsParseFailed For oversized, undecodable, binary, or empty documents.
     */
    public function parse(string $bytes): ParsedLyrics
    {
        if ($bytes === '' || strlen($bytes) > self::MAX_BYTES) {
            throw new LyricsParseFailed('LYRICS_SIZE_INVALID', '歌词文件为空或超过大小限制。');
        }
        $text = $this->utf8($bytes);
        if (str_contains($text, "\0")) {
            throw new LyricsParseFailed('LYRICS_BINARY_CONTENT', '歌词文件包含无效二进制内容。');
        }
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $rawLines = explode("\n", $text);
        if (count($rawLines) > self::MAX_LINES) {
            throw new LyricsParseFailed('LYRICS_TOO_MANY_LINES', '歌词行数超过安全限制。');
        }

        $offsetMs = 0;
        foreach ($rawLines as $rawLine) {
            if (preg_match('/^\s*\[offset\s*:\s*([+-]?\d+)\s*\]\s*$/i', $rawLine, $match) === 1) {
                $offsetMs = max(-3_600_000, min(3_600_000, (int) $match[1]));
            }
        }

        $timed = [];
        $wordTimed = [];
        $wordCount = 0;
        $plain = [];
        $sequence = 0;
        foreach ($rawLines as $rawLine) {
            if (strlen($rawLine) > self::MAX_LINE_BYTES) {
                throw new LyricsParseFailed('LYRICS_LINE_TOO_LONG', '歌词包含超过长度限制的单行。');
            }
            if ($this->isMetadataLine($rawLine)) {
                continue;
            }
            $enhanced = $this->parseEnhancedLine($rawLine, $offsetMs, $sequence);
            if ($enhanced !== null) {
                $wordCount += count($enhanced['words']);
                if ($wordCount > self::MAX_WORDS) {
                    throw new LyricsParseFailed('LYRICS_TOO_MANY_WORDS', '逐字歌词词项超过安全限制。');
                }
                $wordTimed[] = $enhanced;
                ++$sequence;
                continue;
            }
            preg_match_all('/\[(\d{1,4}):([0-5]\d)(?:[\.:](\d{1,3}))?\]/', $rawLine, $matches, PREG_SET_ORDER);
            if ($matches !== []) {
                $content = trim((string) preg_replace('/\[(\d{1,4}):([0-5]\d)(?:[\.:](\d{1,3}))?\]/', '', $rawLine));
                foreach ($matches as $timestamp) {
                    $milliseconds = (((int) $timestamp[1] * 60) + (int) $timestamp[2]) * 1000;
                    $milliseconds += $this->fractionMs($timestamp[3] ?? '');
                    $timed[] = [
                        'startMs' => max(0, $milliseconds + $offsetMs),
                        'text' => $content,
                        '_sequence' => $sequence++,
                    ];
                }
                continue;
            }
            // Preserve meaningful plain lines; leading/trailing blank file padding is not content.
            $content = trim($rawLine);
            if ($content !== '') {
                $plain[] = ['startMs' => null, 'text' => $content];
            }
        }

        if ($wordTimed !== [] && $timed !== []) {
            throw new LyricsParseFailed('LYRICS_MIXED_SYNC_KIND', '歌词文件混合了逐行和逐字时间格式。');
        }
        if ($wordTimed !== []) {
            usort($wordTimed, static fn (array $left, array $right): int =>
                $left['startMs'] <=> $right['startMs'] ?: $left['_sequence'] <=> $right['_sequence']);
            $lines = array_map(static fn (array $line): array => [
                'startMs' => (int) $line['startMs'],
                'endMs' => (int) $line['endMs'],
                'text' => (string) $line['text'],
                'words' => $line['words'],
            ], $wordTimed);

            return new ParsedLyrics('word', $lines);
        }
        if ($timed !== []) {
            usort($timed, static fn (array $left, array $right): int =>
                $left['startMs'] <=> $right['startMs'] ?: $left['_sequence'] <=> $right['_sequence']);
            $lines = array_map(static fn (array $line): array => [
                'startMs' => (int) $line['startMs'],
                'text' => (string) $line['text'],
            ], $timed);

            return new ParsedLyrics('line', $lines);
        }
        if ($plain === []) {
            throw new LyricsParseFailed('LYRICS_EMPTY_CONTENT', '歌词文件没有可显示内容。');
        }

        return new ParsedLyrics('plain', $plain);
    }

    /**
     * 解析 Velin Enhanced LRC 的严格 `<start>text<end>` 成对 profile。
     *
     * 普通行不含词时间标记时返回 null。只要出现形似词时间的标记，就必须完整满足成对、无额外文本、
     * 时间非重叠和行首范围规则，否则抛出固定错误；禁止把损坏逐字歌词降级成带尖括号的逐行文本。
     *
     * @return null|array{startMs:int,endMs:int,text:string,words:list<array{startMs:int,endMs:int,text:string}>,_sequence:int}
     */
    private function parseEnhancedLine(string $rawLine, int $offsetMs, int $sequence): ?array
    {
        if (preg_match('/<\d{1,4}:[0-5]\d(?:[\.:]\d{1,3})?>/', $rawLine) !== 1) {
            return null;
        }
        if (preg_match('/^\s*\[(\d{1,4}):([0-5]\d)(?:[\.:](\d{1,3}))?\](.*)$/', $rawLine, $line) !== 1) {
            throw new LyricsParseFailed('LYRICS_ENHANCED_INVALID', '逐字歌词行格式无效。');
        }
        $lineStart = max(0, $this->timestampMs($line[1], $line[2], $line[3] ?? '') + $offsetMs);
        $body = (string) $line[4];
        preg_match_all(
            '/<(\d{1,4}):([0-5]\d)(?:[\.:](\d{1,3}))?>/',
            $body,
            $matches,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE,
        );
        if (count($matches) < 2 || count($matches) % 2 !== 0) {
            throw new LyricsParseFailed('LYRICS_ENHANCED_INVALID', '逐字歌词时间标记必须成对。');
        }
        $words = [];
        $joined = '';
        $cursor = 0;
        $previousEnd = $lineStart;
        for ($index = 0; $index < count($matches); $index += 2) {
            $startToken = $matches[$index];
            $endToken = $matches[$index + 1];
            $startOffset = (int) $startToken[0][1];
            $startTokenEnd = $startOffset + strlen((string) $startToken[0][0]);
            $endOffset = (int) $endToken[0][1];
            if ($startOffset !== $cursor || $endOffset <= $startTokenEnd) {
                throw new LyricsParseFailed('LYRICS_ENHANCED_INVALID', '逐字歌词时间标记之间存在不可归属文本。');
            }
            $wordText = substr($body, $startTokenEnd, $endOffset - $startTokenEnd);
            $wordStart = max(0, $this->timestampMs(
                (string) $startToken[1][0],
                (string) $startToken[2][0],
                (string) ($startToken[3][0] ?? ''),
            ) + $offsetMs);
            $wordEnd = max(0, $this->timestampMs(
                (string) $endToken[1][0],
                (string) $endToken[2][0],
                (string) ($endToken[3][0] ?? ''),
            ) + $offsetMs);
            if ($wordText === '' || !mb_check_encoding($wordText, 'UTF-8')
                || $wordStart < $lineStart || $wordStart < $previousEnd || $wordEnd <= $wordStart) {
                throw new LyricsParseFailed('LYRICS_ENHANCED_INVALID', '逐字歌词词项时间轴无效。');
            }
            $words[] = ['startMs' => $wordStart, 'endMs' => $wordEnd, 'text' => $wordText];
            $joined .= $wordText;
            $previousEnd = $wordEnd;
            $cursor = (int) $endToken[0][1] + strlen((string) $endToken[0][0]);
        }
        if ($cursor !== strlen($body) || $joined === '') {
            throw new LyricsParseFailed('LYRICS_ENHANCED_INVALID', '逐字歌词行尾包含不可归属文本。');
        }

        return [
            'startMs' => $lineStart,
            'endMs' => $previousEnd,
            'text' => $joined,
            'words' => $words,
            '_sequence' => $sequence,
        ];
    }

    /** Converts supported bounded encodings to strict UTF-8 without lossy replacement. */
    private function utf8(string $bytes): string
    {
        if (str_starts_with($bytes, "\xEF\xBB\xBF")) {
            $bytes = substr($bytes, 3);
        } elseif (str_starts_with($bytes, "\xFF\xFE")) {
            $bytes = $this->convert(substr($bytes, 2), 'UTF-16LE');
        } elseif (str_starts_with($bytes, "\xFE\xFF")) {
            $bytes = $this->convert(substr($bytes, 2), 'UTF-16BE');
        } elseif (!mb_check_encoding($bytes, 'UTF-8')) {
            $encoding = mb_detect_encoding($bytes, ['GB18030', 'BIG-5'], true);
            if (!is_string($encoding)) {
                throw new LyricsParseFailed('LYRICS_ENCODING_UNSUPPORTED', '歌词文件编码无法识别。');
            }
            $bytes = $this->convert($bytes, $encoding);
        }
        if (!mb_check_encoding($bytes, 'UTF-8')) {
            throw new LyricsParseFailed('LYRICS_ENCODING_INVALID', '歌词文件不是有效的 UTF-8 文本。');
        }

        return $bytes;
    }

    /** Performs one strict mbstring conversion and rejects conversion failures. */
    private function convert(string $bytes, string $sourceEncoding): string
    {
        if (!mb_check_encoding($bytes, $sourceEncoding)) {
            throw new LyricsParseFailed('LYRICS_ENCODING_INVALID', '歌词文件编码转换失败。');
        }
        $converted = @mb_convert_encoding($bytes, 'UTF-8', $sourceEncoding);
        if (!is_string($converted) || !mb_check_encoding($converted, 'UTF-8')) {
            throw new LyricsParseFailed('LYRICS_ENCODING_INVALID', '歌词文件编码转换失败。');
        }

        return $converted;
    }

    /** Identifies non-content LRC header tags without consuming timestamped lyric text. */
    private function isMetadataLine(string $line): bool
    {
        return preg_match('/^\s*\[(ar|al|ti|au|by|re|ve|length|offset)\s*:[^\]]*\]\s*$/i', $line) === 1;
    }

    /** Maps LRC's 1/2/3 fractional digits to milliseconds. */
    private function fractionMs(string $fraction): int
    {
        return match (strlen($fraction)) {
            1 => (int) $fraction * 100,
            2 => (int) $fraction * 10,
            3 => (int) $fraction,
            default => 0,
        };
    }

    /** 把已由正则限定的分、秒和小数转换为毫秒。 */
    private function timestampMs(string $minutes, string $seconds, string $fraction): int
    {
        return (((int) $minutes * 60) + (int) $seconds) * 1000 + $this->fractionMs($fraction);
    }
}
