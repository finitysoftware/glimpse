<?php

declare(strict_types=1);

namespace Glimpse\Sharing;

use Glimpse\Failure;
use Glimpse\Settings;
use SensitiveParameter;

/**
 * The URL of an access link: its public ID and a secret in the fragment. Only a digest of the secret is kept for
 * checking visitors; the secret itself is stored encrypted, so the owner and administrators can show the URL again.
 */
final readonly class ShareUrls
{
    public function __construct(private ShareCipher $cipher, private string $origin) {}

    /** With the private share key and the public address of new links. */
    public static function fromSettings(Settings $settings): self
    {
        return new self(new ShareCipher($settings->shareEncryptionKey), $settings->sharePublicOrigin);
    }

    public function configured(): bool
    {
        return $this->cipher->configured();
    }

    /** @return array{publicId: string, secret: string, digest: string, encryptedSecret: string, url: string} */
    public function issue(): array
    {
        $publicId = bin2hex(random_bytes(16));
        $secret = bin2hex(random_bytes(32));
        return ['publicId' => $publicId, 'secret' => $secret, 'digest' => self::digest($secret),
            'encryptedSecret' => $this->cipher->encrypt($publicId, $secret), 'url' => $this->url($publicId, $secret)];
    }

    /** The URL of a stored link, or a failure when its secret cannot be read with the current key or no longer matches. */
    public function recover(string $publicId, string $encryptedSecret, string $digest): string
    {
        $secret = $this->cipher->decrypt($publicId, $encryptedSecret);
        if (!hash_equals($digest, self::digest($secret))) {
            throw Failure::conflict('share_url_unavailable', 'The saved URL could not be verified. Create a new link.');
        }
        return $this->url($publicId, $secret);
    }

    public static function digest(#[SensitiveParameter] string $secret): string
    {
        return hash('sha256', $secret, true);
    }

    private function url(string $publicId, #[SensitiveParameter] string $secret): string
    {
        return $this->origin . '/s/' . $publicId . '#' . $secret;
    }
}
