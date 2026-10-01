<?php

declare(strict_types=1);

namespace Glimpse\Source;

/** Quota headers of both platforms: GitHub sends `x-ratelimit-*`, GitLab.com sends `ratelimit-*` (self-managed GitLab may send none). */
final class RateLimit
{
    public static function delay(HttpResult $response): ?int
    {
        $headers = $response->headers;
        $retry = $headers['retry-after'] ?? '';
        $quota = self::quota($response);
        $limited = $response->status === 429 || ($response->status === 403
            && ($quota['remaining'] === 0 || $retry !== '' || stripos($response->body, 'rate limit') !== false));
        if (!$limited) {
            return null;
        }
        $delay = self::retryAfter($response) ?? ($quota['reset'] !== null ? $quota['reset'] - time() : 60);
        return max(1, min(86400, $delay));
    }

    /** A cooldown until the reported reset when the quota is used up, even on a successful response. */
    public static function exhausted(HttpResult $response): ?int
    {
        $quota = self::quota($response);
        return $quota['remaining'] === 0 && $quota['reset'] !== null ? min(86400, max(1, $quota['reset'] - time())) : null;
    }

    public static function retryAfter(HttpResult $response): ?int
    {
        $retry = $response->headers['retry-after'] ?? '';
        if ($retry === '') {
            return null;
        }
        $delay = ctype_digit($retry) ? (int) $retry : (strtotime($retry) ?: time() + 60) - time();
        return max(1, min(86400, $delay));
    }

    /** @return array{limit: int|null, remaining: int|null, reset: int|null} Reset is a Unix time. */
    public static function quota(HttpResult $response): array
    {
        $headers = $response->headers;
        $number = static function (string $name) use ($headers): ?int {
            $value = $headers['x-' . $name] ?? $headers[$name] ?? null;
            return is_string($value) && ctype_digit($value) && strlen($value) <= 12 ? min(2147483647, (int) $value) : null;
        };
        return ['limit' => $number('ratelimit-limit'), 'remaining' => $number('ratelimit-remaining'), 'reset' => $number('ratelimit-reset')];
    }
}
