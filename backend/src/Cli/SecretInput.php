<?php

declare(strict_types=1);

namespace Glimpse\Cli;

use RuntimeException;

/** Reads a secret from redirected standard input, never from arguments or an echoing terminal prompt. */
final class SecretInput
{
    /** @param resource $stream */
    public static function read($stream, int $maxBytes): string
    {
        if (stream_isatty($stream)) {
            throw new RuntimeException('Provide the secret through redirected standard input from a password manager or protected temporary file. Do not type it into an echoing terminal or put it in command history.');
        }
        // Two extra bytes allow a trailing CRLF; anything longer is rejected rather than truncated.
        $raw = stream_get_contents($stream, $maxBytes + 3);
        if ($raw === false) {
            throw new RuntimeException('Standard input could not be read.');
        }
        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            throw new RuntimeException('Input starts with a UTF-8 byte order mark. Send UTF-8 without a BOM.');
        }
        $secret = rtrim($raw, "\r\n");
        if (strlen($raw) > $maxBytes + 2 || strlen($secret) > $maxBytes) {
            throw new RuntimeException('Input is too long.');
        }
        if ($secret === '') {
            throw new RuntimeException('Input is empty.');
        }
        return $secret;
    }
}
