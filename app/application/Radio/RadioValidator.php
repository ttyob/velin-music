<?php

declare(strict_types=1);

namespace app\application\Radio;

/**
 * Validates internet-radio fields and prevents unsafe server/browser target classes.
 *
 * Velin never fetches these URLs server-side in the current architecture, which is the primary SSRF
 * boundary. Validation still rejects credentials, non-HTTP schemes, localhost/special-use names,
 * single-label hosts, and literal private/reserved IPs so saved stations cannot become convenient
 * links into either server or browser-local networks. DNS is deliberately not resolved during save:
 * a one-time answer cannot prevent rebinding and would itself create an outbound request.
 */
final class RadioValidator
{
    /**
     * Normalizes one create/update command without resolving or contacting any submitted host.
     *
     * Web creates do not carry a version because the station does not exist yet. Web updates must
     * carry the exact committed version; legacy Subsonic commands have no version field and are
     * intentionally normalized to last-command-wins. Callers must still enforce management rights.
     *
     * @return array{name: string, streamUrl: string, homepageUrl: string|null, artworkUrl: string|null, enabled: bool, expectedVersion: int|null}
     */
    public function command(array $payload, bool $legacy = false, bool $creating = false): array
    {
        $name = is_string($payload['name'] ?? null) ? trim($payload['name']) : '';
        if ($name === '' || mb_strlen($name, 'UTF-8') > 200) {
            throw new RadioInvalid('Station name is invalid.');
        }
        $streamUrl = $this->url($payload['streamUrl'] ?? null, false);
        $homepageUrl = $this->url($payload['homepageUrl'] ?? $payload['homePageUrl'] ?? null, true);
        $artworkUrl = $this->url($payload['artworkUrl'] ?? $payload['imageUrl'] ?? null, true);
        $enabled = $this->boolean($payload['enabled'] ?? null, true);
        $expectedVersion = ($legacy || $creating)
            ? null
            : $this->integer($payload['expectedVersion'] ?? null, 1, PHP_INT_MAX);

        return compact('name', 'streamUrl', 'homepageUrl', 'artworkUrl', 'enabled', 'expectedVersion');
    }

    /** Accepts canonical opaque station IDs only. */
    public function id(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $value) !== 1) {
            throw new RadioInvalid('Station ID is invalid.');
        }

        return $value;
    }

    /** Web delete requires the exact positive committed version. */
    public function version(mixed $value): int
    {
        return $this->integer($value, 1, PHP_INT_MAX);
    }

    /** Returns a bounded literal search term; wildcard interpretation remains escaped by the query builder. */
    public function search(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (!is_string($value)) {
            throw new RadioInvalid('Station search is invalid.');
        }
        $value = trim($value);
        if (mb_strlen($value, 'UTF-8') > 200) {
            throw new RadioInvalid('Station search is invalid.');
        }

        return $value;
    }

    /** Parses one bounded page integer so an untrusted query cannot create an unbounded catalog read. */
    public function page(mixed $value, int $default, int $minimum, int $maximum): int
    {
        if ($value === null || $value === '') {
            return $default;
        }

        return $this->integer($value, $minimum, $maximum);
    }

    /** Normalizes one required/optional public HTTP URL without making any network request. */
    private function url(mixed $value, bool $optional): ?string
    {
        if (($value === null || $value === '') && $optional) {
            return null;
        }
        if (!is_string($value)) {
            throw new RadioInvalid('Station URL is invalid.');
        }
        $value = trim($value);
        if ($value === '' || strlen($value) > 2048 || preg_match('/[\x00-\x20\x7F]/', $value) === 1) {
            throw new RadioInvalid('Station URL is invalid.');
        }
        $parts = parse_url($value);
        if (!is_array($parts) || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || !is_string($parts['host'] ?? null) || isset($parts['user']) || isset($parts['pass'])) {
            throw new RadioInvalid('Station URL is not a public HTTP URL.');
        }
        $host = strtolower(rtrim($parts['host'], '.'));
        // parse_url preserves brackets around IPv6 literals. Strip exactly that syntax before IP
        // classification; otherwise `[::1]` would be mistaken for a DNS name and evade the range rule.
        $ipCandidate = str_starts_with($host, '[') && str_ends_with($host, ']')
            ? substr($host, 1, -1)
            : $host;
        $isIp = filter_var($ipCandidate, FILTER_VALIDATE_IP) !== false;
        $ambiguousIpv4 = preg_match('/^(?:0x[0-9a-f]+|[0-9]+)(?:\.(?:0x[0-9a-f]+|[0-9]+)){0,3}$/i', $host) === 1;
        if ($host === '' || $host === 'localhost'
            || (!$isIp && !str_contains($host, '.'))
            // Browsers accept historical shortened/octal/hex IPv4 spellings such as 127.1 and
            // 0x7f.0.0.1. PHP does not classify them as IPs, so reject the entire ambiguous class.
            || (!$isIp && $ambiguousIpv4)
            || (!$isIp && preg_match('/(?:^|\.)(?:localhost|local|internal|home|lan)$/', $host) === 1)
            || (!$isIp && preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $host) !== 1)) {
            throw new RadioInvalid('Station URL host is not public.');
        }
        if ($isIp
            && (str_starts_with($ipCandidate, '::ffff:')
                || filter_var($ipCandidate, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false)) {
            // FILTER_FLAG_NO_PRIV_RANGE does not inspect the embedded IPv4 part of IPv4-mapped
            // IPv6 literals. Reject all mapped forms instead of allowing a loopback/private bypass.
            throw new RadioInvalid('Station URL IP is not public.');
        }
        if (isset($parts['port']) && ((int) $parts['port'] < 1 || (int) $parts['port'] > 65535)) {
            throw new RadioInvalid('Station URL port is invalid.');
        }

        return $value;
    }

    /** Parses strict Web/protocol booleans rather than PHP truthiness. */
    private function boolean(mixed $value, bool $default): bool
    {
        if ($value === null) {
            return $default;
        }
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) && ($value === 0 || $value === 1)) {
            return $value === 1;
        }
        if (is_string($value)) {
            return match (strtolower(trim($value))) {
                'true', '1' => true,
                'false', '0' => false,
                default => throw new RadioInvalid('Station enabled value is invalid.'),
            };
        }
        throw new RadioInvalid('Station enabled value is invalid.');
    }

    /** Parses one required canonical decimal integer within an inclusive range. */
    private function integer(mixed $value, int $minimum, int $maximum): int
    {
        if (is_int($value)) {
            $integer = $value;
        } elseif (is_string($value) && preg_match('/^(0|[1-9][0-9]*)$/', $value) === 1) {
            $integer = (int) $value;
        } else {
            throw new RadioInvalid('Station integer is invalid.');
        }
        if ($integer < $minimum || $integer > $maximum) {
            throw new RadioInvalid('Station integer is invalid.');
        }

        return $integer;
    }
}
