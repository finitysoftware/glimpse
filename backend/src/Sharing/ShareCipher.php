<?php

declare(strict_types=1);

namespace Glimpse\Sharing;

use Glimpse\Failure;
use SensitiveParameter;

final readonly class ShareCipher
{
    private string|false $key;

    public function __construct(#[SensitiveParameter] string $encodedKey)
    {
        $this->key = base64_decode($encodedKey, true);
    }

    public function configured(): bool
    {
        return is_string($this->key) && strlen($this->key) === 32;
    }

    public function encrypt(string $publicId, #[SensitiveParameter] string $secret): string
    {
        $key = $this->requireKey();
        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($secret, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, $this->context($publicId), 16);
        if ($ciphertext === false) {
            $this->unavailable();
        }
        return "\x01" . $iv . $tag . $ciphertext;
    }

    public function decrypt(string $publicId, #[SensitiveParameter] string $payload): string
    {
        $key = $this->requireKey();
        // Check the complete envelope, including the full authentication tag, before decrypting.
        if (strlen($payload) !== 93 || $payload[0] !== "\x01") {
            $this->unavailable();
        }
        $secret = openssl_decrypt(
            substr($payload, 29),
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            substr($payload, 1, 12),
            substr($payload, 13, 16),
            $this->context($publicId),
        );
        if (!is_string($secret) || !preg_match('/^[a-f0-9]{64}$/D', $secret)) {
            $this->unavailable();
        }
        return $secret;
    }

    private function requireKey(): string
    {
        if (!is_string($this->key) || strlen($this->key) !== 32) {
            throw Failure::unavailable('share_storage_unconfigured', 'Access URL storage has not been configured. Set up the private share encryption key on the server.');
        }
        return $this->key;
    }

    private function context(string $publicId): string
    {
        return "glimpse.share-url.v1\0" . $publicId;
    }
    private function unavailable(): never
    {
        throw Failure::conflict('share_url_unavailable', 'The saved URL could not be opened. Restore the original share encryption key or create a new link.');
    }
}
