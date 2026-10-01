<?php

declare(strict_types=1);

namespace Glimpse\Source;

use Glimpse\Failure;
use SensitiveParameter;

/** Authenticated encryption for per-user platform tokens. Uses its own key; never the share key or ShareCipher envelope.
 * The authenticated data names the platform, user and connection; a GitLab token is also bound to its instance URL,
 * so a token can never be decrypted for another instance.
 */
final readonly class TokenCipher
{
    private const FORMAT = "\x01";
    private const HEADER_BYTES = 29;
    public const MAX_TOKEN_BYTES = 1024;

    private string|false $key;

    public function __construct(#[SensitiveParameter] string $encodedKey, public string $keyId, private Provider $provider = Provider::GitHub)
    {
        $this->key = base64_decode($encodedKey, true);
    }

    public function configured(): bool
    {
        return is_string($this->key) && strlen($this->key) === 32 && self::validKeyId($this->keyId);
    }

    /** Printable ASCII without spaces or control characters; no fixed prefix or length is assumed. */
    public static function validToken(#[SensitiveParameter] string $token): bool
    {
        return strlen($token) <= self::MAX_TOKEN_BYTES && preg_match('/^[\x21-\x7e]+$/D', $token) === 1;
    }

    public static function validKeyId(string $keyId): bool
    {
        return preg_match('/^[A-Za-z0-9._-]{1,64}$/D', $keyId) === 1;
    }

    public function encrypt(int $userId, int $connectionId, #[SensitiveParameter] string $token, string $instance = ''): string
    {
        if (!self::validToken($token)) {
            throw Failure::rejected(
                $this->provider->code('token_invalid_format'),
                'Enter a ' . $this->provider->label() . ' token of at most 1024 printable characters without spaces.',
            );
        }
        $key = $this->requireKey();
        $nonce = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($token, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, $this->context($userId, $connectionId, $instance), 16);
        if ($ciphertext === false || strlen($tag) !== 16) {
            $this->unreadable();
        }
        return self::FORMAT . $nonce . $tag . $ciphertext;
    }

    public function decrypt(int $userId, int $connectionId, string $keyId, #[SensitiveParameter] string $payload, string $instance = ''): string
    {
        $key = $this->requireKey();
        // A token saved under another key must not be reported as a missing connection.
        if (!hash_equals($this->keyId, $keyId)) {
            $this->unreadable();
        }
        if (strlen($payload) <= self::HEADER_BYTES || strlen($payload) > self::HEADER_BYTES + self::MAX_TOKEN_BYTES || $payload[0] !== self::FORMAT) {
            $this->unreadable();
        }
        $token = openssl_decrypt(
            substr($payload, self::HEADER_BYTES),
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            substr($payload, 1, 12),
            substr($payload, 13, 16),
            $this->context($userId, $connectionId, $instance),
        );
        if (!is_string($token) || !self::validToken($token)) {
            $this->unreadable();
        }
        return $token;
    }

    /** The GitHub context is unchanged since the first release, so existing tokens stay readable. */
    private function context(int $userId, int $connectionId, string $instance): string
    {
        return match ($this->provider) {
            Provider::GitHub => "glimpse.github-token.v1\0" . $userId . "\0" . $connectionId,
            Provider::GitLab => "glimpse.gitlab-token.v1\0" . $userId . "\0" . $connectionId . "\0" . $instance,
        };
    }

    private function requireKey(): string
    {
        if (!is_string($this->key) || strlen($this->key) !== 32 || !self::validKeyId($this->keyId)) {
            throw Failure::unavailable(
                $this->provider->code('token_storage_unconfigured'),
                $this->provider->label() . ' token storage has not been configured on the server.',
            );
        }
        return $this->key;
    }

    private function unreadable(): never
    {
        throw Failure::unavailable(
            $this->provider->code('token_unreadable'),
            'The saved ' . $this->provider->label() . ' token could not be decrypted. Restore the original token encryption key.',
        );
    }
}
