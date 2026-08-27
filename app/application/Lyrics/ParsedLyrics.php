<?php

declare(strict_types=1);

namespace app\application\Lyrics;

/**
 * Immutable normalized result produced from one bounded local lyrics document.
 *
 * Lines preserve duplicate timestamps and original order for equal timestamps. `startMs` is null
 * only for plain text. This DTO contains no path, provider response, or database concern; callers
 * may safely encode its lines after enforcing the surrounding song authorization boundary.
 */
final readonly class ParsedLyrics
{
    /**
     * @param 'plain'|'line'|'word' $kind Synchronization granularity.
     * @param list<array{startMs:int|null,text:string}|array{startMs:int,endMs:int,text:string,words:list<array{startMs:int,endMs:int,text:string}>}> $lines Normalized UTF-8/LF content.
     */
    public function __construct(
        public string $kind,
        public array $lines,
    ) {
    }
}
