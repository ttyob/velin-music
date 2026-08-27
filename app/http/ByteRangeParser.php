<?php

declare(strict_types=1);

namespace app\http;

/**
 * Parses the single-range subset required by browser audio seeking (RFC 7233/RFC 9110).
 *
 * Multipart ranges are deliberately rejected with 416 because Workerman's file sender exposes one
 * offset/length pair and fabricating multipart boundaries would risk incorrect lengths. End values
 * beyond EOF are clamped as required; suffix and open-ended forms remain supported.
 */
final class ByteRangeParser
{
    /**
     * @throws UnsatisfiableByteRange When a non-empty Range header cannot select file bytes.
     */
    public function parse(?string $header, int $fileSize): ?ByteRange
    {
        $header = trim((string) $header);
        if ($header === '') {
            return null;
        }
        if ($fileSize <= 0
            || preg_match('/^bytes=([^,]+)$/i', $header, $matches) !== 1
            || preg_match('/^(\d*)-(\d*)$/', trim($matches[1]), $parts) !== 1
            || ($parts[1] === '' && $parts[2] === '')
        ) {
            throw new UnsatisfiableByteRange('The requested byte range is not satisfiable.');
        }

        if ($parts[1] === '') {
            $suffixLength = $this->number($parts[2]);
            if ($suffixLength <= 0) {
                throw new UnsatisfiableByteRange('The requested byte range is not satisfiable.');
            }
            $length = min($suffixLength, $fileSize);

            return new ByteRange($fileSize - $length, $length);
        }

        $start = $this->number($parts[1]);
        if ($start >= $fileSize) {
            throw new UnsatisfiableByteRange('The requested byte range is not satisfiable.');
        }
        $end = $parts[2] === '' ? $fileSize - 1 : $this->number($parts[2]);
        if ($end < $start) {
            throw new UnsatisfiableByteRange('The requested byte range is not satisfiable.');
        }
        $end = min($end, $fileSize - 1);

        return new ByteRange($start, $end - $start + 1);
    }

    /** Rejects decimal strings that cannot fit safely into the current PHP integer width. */
    private function number(string $value): int
    {
        $normalized = ltrim($value, '0');
        $normalized = $normalized === '' ? '0' : $normalized;
        $maximum = (string) PHP_INT_MAX;
        if (strlen($normalized) > strlen($maximum)
            || (strlen($normalized) === strlen($maximum) && strcmp($normalized, $maximum) > 0)
        ) {
            throw new UnsatisfiableByteRange('The requested byte range is not satisfiable.');
        }

        return (int) $normalized;
    }
}
