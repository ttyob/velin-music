<?php

declare(strict_types=1);

namespace app\application\Lyrics;

/**
 * 把已验证的结构化普通/逐行/逐字歌词确定性序列化为 UTF-8/LF sidecar。
 *
 * 该类不读取数据库和文件，也不记录输入。相同 `kind` 与 `lines` 必须得到完全相同的字节，供
 * Dry Run 和 Worker 分别计算摘要并做 CAS。逐字使用 Velin Enhanced LRC 严格 profile：行时间放在
 * `[...]`，每个词以 `<start>text<end>` 成对编码。只有词文本完整拼接为行文本且最后词结束等于行
 * 结束时才可无损表示；否则失败关闭，绝不降级成逐行歌词。
 */
final class LyricsDocumentSerializer
{
    private const MAX_BYTES = 1_048_576;
    private const MAX_LINES = 10_000;
    private const MAX_LINE_BYTES = 4_096;
    private const MAX_WORDS = 50_000;

    /**
     * 序列化从歌词文件即时解析或由受权编辑请求提交的结构化行。
     *
     * 前置条件：每行包含非空 UTF-8 文本及与类型一致的时间字段；逐字结构还必须包含可无损映射的
     * `endMs` 和 `words`。失败没有副作用，并使用固定中文错误，不能回显歌词内容。
     *
     * @param list<mixed> $lines
     */
    public function serialize(string $kind, array $lines): string
    {
        if (!in_array($kind, ['plain', 'line', 'word'], true)) {
            throw new LyricsWritebackInvalid('歌词写回类型无效。');
        }
        if ($lines === [] || count($lines) > self::MAX_LINES || !array_is_list($lines)) {
            throw new LyricsWritebackInvalid('歌词行结构无效。');
        }

        $output = [];
        $wordCount = 0;
        $previousLineEnd = -1;
        foreach ($lines as $line) {
            if (!is_array($line) || !is_string($line['text'] ?? null)) {
                throw new LyricsWritebackInvalid('歌词行结构无效。');
            }
            $text = str_replace(["\r\n", "\r", "\n"], ' ', $line['text']);
            if ($text === '' || !mb_check_encoding($text, 'UTF-8') || strlen($text) > self::MAX_LINE_BYTES) {
                throw new LyricsWritebackInvalid('歌词行文本无效。');
            }
            if ($kind === 'plain') {
                if (($line['startMs'] ?? null) !== null) {
                    throw new LyricsWritebackInvalid('普通歌词不能包含时间戳。');
                }
                $output[] = $text;
                continue;
            }
            $startMs = $line['startMs'] ?? null;
            if (!is_int($startMs) || $startMs < 0 || $startMs > 359_999_999) {
                throw new LyricsWritebackInvalid('同步歌词时间戳无效。');
            }
            if ($kind === 'line') {
                $output[] = '[' . $this->timestamp($startMs) . ']' . $text;
                continue;
            }

            $endMs = $line['endMs'] ?? null;
            $words = $line['words'] ?? null;
            if (!is_int($endMs) || $endMs <= $startMs || $endMs > 359_999_999
                || $startMs < $previousLineEnd || !is_array($words) || !array_is_list($words) || $words === []) {
                throw new LyricsWritebackInvalid('逐字歌词行时间轴无效。');
            }
            $encoded = '[' . $this->timestamp($startMs) . ']';
            $joinedText = '';
            $previousWordEnd = $startMs;
            foreach ($words as $word) {
                ++$wordCount;
                if ($wordCount > self::MAX_WORDS || !is_array($word) || !is_string($word['text'] ?? null)) {
                    throw new LyricsWritebackInvalid('逐字歌词词项结构无效。');
                }
                $wordText = str_replace(["\r\n", "\r", "\n"], ' ', $word['text']);
                $wordStart = $word['startMs'] ?? null;
                $wordEnd = $word['endMs'] ?? null;
                if ($wordText === '' || !mb_check_encoding($wordText, 'UTF-8')
                    || !is_int($wordStart) || !is_int($wordEnd)
                    || $wordStart < $startMs || $wordStart < $previousWordEnd
                    || $wordEnd <= $wordStart || $wordEnd > $endMs) {
                    throw new LyricsWritebackInvalid('逐字歌词词项时间轴无效。');
                }
                $encoded .= '<' . $this->timestamp($wordStart) . '>' . $wordText
                    . '<' . $this->timestamp($wordEnd) . '>';
                $joinedText .= $wordText;
                $previousWordEnd = $wordEnd;
            }
            if (!hash_equals($text, $joinedText) || $previousWordEnd !== $endMs
                || strlen($encoded) > self::MAX_LINE_BYTES) {
                throw new LyricsWritebackInvalid('逐字歌词不能无损表示为 Enhanced LRC。');
            }
            $previousLineEnd = $endMs;
            $output[] = $encoded;
        }

        $bytes = implode("\n", $output) . "\n";
        if (strlen($bytes) > self::MAX_BYTES) {
            throw new LyricsWritebackInvalid('序列化歌词超过大小限制。');
        }

        return $bytes;
    }

    /** 以三位毫秒生成行/词共用的确定性时间标记。 */
    private function timestamp(int $milliseconds): string
    {
        return sprintf(
            '%02d:%02d.%03d',
            intdiv($milliseconds, 60_000),
            intdiv($milliseconds % 60_000, 1_000),
            $milliseconds % 1_000,
        );
    }
}
