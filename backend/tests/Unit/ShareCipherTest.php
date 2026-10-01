<?php

declare(strict_types=1);

namespace Glimpse\Tests\Unit;

use Glimpse\Failure;
use Glimpse\Sharing\ShareCipher;
use PHPUnit\Framework\TestCase;

final class ShareCipherTest extends TestCase
{
    public function testRoundTripUsesADifferentNonceForEachEncryption(): void
    {
        $cipher = new ShareCipher(base64_encode(str_repeat('k', 32)));
        $id = str_repeat('a', 32);
        $secret = str_repeat('b', 64);
        $first = $cipher->encrypt($id, $secret);
        $second = $cipher->encrypt($id, $secret);
        self::assertNotSame($first, $second);
        self::assertSame($secret, $cipher->decrypt($id, $first));
        self::assertSame($secret, $cipher->decrypt($id, $second));
    }

    public function testModifiedEnvelopeAndDifferentShareIdCannotBeDecrypted(): void
    {
        $cipher = new ShareCipher(base64_encode(str_repeat('k', 32)));
        $id = str_repeat('a', 32);
        $payload = $cipher->encrypt($id, str_repeat('b', 64));
        $tampered = $payload;
        $tampered[13] = chr(ord($tampered[13]) ^ 1);
        foreach ([[$id, $tampered], [$id, substr($payload, 0, -1)], [$id, "\x02" . substr($payload, 1)],
            [$id, ''], [str_repeat('c', 32), $payload]] as [$target, $value]) {
            try {
                $cipher->decrypt($target, $value);
                self::fail('Unauthenticated envelope was accepted.');
            } catch (Failure $error) {
                self::assertSame('share_url_unavailable', $error->errorCode);
            }
        }
    }

    public function testKeysMustDecodeToExactlyThirtyTwoBytes(): void
    {
        foreach (['', 'invalid%', base64_encode(str_repeat('k', 31)), base64_encode(str_repeat('k', 33))] as $key) {
            $cipher = new ShareCipher($key);
            self::assertFalse($cipher->configured());
            try {
                $cipher->encrypt(str_repeat('a', 32), str_repeat('b', 64));
                self::fail('Invalid key was accepted.');
            } catch (Failure $error) {
                self::assertSame('share_storage_unconfigured', $error->errorCode);
            }
        }
    }
}
