<?php

declare(strict_types=1);

namespace app\application\Transcode;

/**
 * Owns one process-wide transcoding slot until the HTTP stream has fully terminated.
 *
 * The underlying advisory lock is inherited neither by a shell nor by an untrusted command: only
 * the PHP supervisor retains it. Releasing is idempotent, and PHP/OS teardown releases the lock if
 * a worker crashes. The lock file itself contains no user, media, path, or credential information.
 */
final class TranscodeLease
{
    /** @param list<resource> $handles 全局槽位及可选账号槽位的独占锁句柄。 */
    public function __construct(private array $handles, public readonly int $slot)
    {
    }

    /** Releases the concurrency slot exactly once; it does not delete shared lock files. */
    public function release(): void
    {
        foreach ($this->handles as $handle) {
            if (!is_resource($handle)) continue;
            @flock($handle, LOCK_UN);
            @fclose($handle);
        }
        $this->handles = [];
    }

    /** Worker shutdown and abandoned responses cannot permanently consume a slot. */
    public function __destruct()
    {
        $this->release();
    }
}
