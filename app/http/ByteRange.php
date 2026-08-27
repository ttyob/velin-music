<?php

declare(strict_types=1);

namespace app\http;

/** Represents one normalized inclusive HTTP byte range as offset plus positive length. */
final readonly class ByteRange
{
    public function __construct(public int $offset, public int $length)
    {
    }

    /** Returns the inclusive final byte position used by Content-Range. */
    public function end(): int
    {
        return $this->offset + $this->length - 1;
    }
}
