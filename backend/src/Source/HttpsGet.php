<?php

declare(strict_types=1);

namespace Glimpse\Source;

use CurlHandle;
use Glimpse\Failure;

/**
 * One HTTPS GET to a platform API with the limits every request has: verified TLS, no redirects, a time limit and at
 * most MAX_BYTES of body and 32 KiB of headers. The platform's transport chooses the address, the headers and any
 * further cURL options.
 */
final class HttpsGet
{
    public const MAX_BYTES = 4 * 1024 * 1024;
    private const MAX_HEADER_BYTES = 32768;

    /**
     * @param list<string> $headers
     * @param array<int, mixed> $options Further cURL options, e.g. the checked addresses of a GitLab instance.
     * @return array{HttpResult, string} The response and the IP address it came from.
     */
    public static function send(Provider $provider, string $url, array $headers, ?string $etag, int $timeoutMilliseconds, array $options = []): array
    {
        if ($etag !== null && strlen($etag) <= 255 && !preg_match('/[\x00-\x1f\x7f]/', $etag)) {
            $headers[] = 'If-None-Match: ' . $etag;
        }
        $body = '';
        $received = [];
        $headerBytes = 0;
        $tooLarge = false;
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_HTTPGET => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT_MS => min(3000, $timeoutMilliseconds),
            CURLOPT_TIMEOUT_MS => $timeoutMilliseconds,
            CURLOPT_ENCODING => '',
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_WRITEFUNCTION => static function (CurlHandle $handle, string $chunk) use (&$body, &$tooLarge): int {
                if (strlen($body) + strlen($chunk) > self::MAX_BYTES) {
                    $tooLarge = true;
                    return 0;
                }
                $body .= $chunk;
                return strlen($chunk);
            },
            CURLOPT_HEADERFUNCTION => static function (CurlHandle $handle, string $line) use (&$received, &$headerBytes, &$tooLarge): int {
                $headerBytes += strlen($line);
                if ($headerBytes > self::MAX_HEADER_BYTES) {
                    $tooLarge = true;
                    return 0;
                }
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $received[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($line);
            },
        ] + $options);
        $ok = curl_exec($handle);
        $error = curl_errno($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $connectedTo = (string) curl_getinfo($handle, CURLINFO_PRIMARY_IP);
        $label = $provider->label();
        if ($tooLarge) {
            throw Failure::upstream($provider->code('response_too_large'), $label . ' returned a response beyond the preview limit.');
        }
        if ($ok === false) {
            throw $error === CURLE_OPERATION_TIMEDOUT
                ? Failure::upstream($provider->code('timeout'), $label . ' did not respond in time.')
                : Failure::upstream($provider->code('unavailable'), 'Unable to reach ' . $label . ' securely.');
        }
        return [new HttpResult($status, $received, $body), $connectedTo];
    }
}
