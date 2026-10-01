<?php

declare(strict_types=1);

namespace Glimpse\Tests\Unit;

use Glimpse\Accounts\Passwords;
use Glimpse\Accounts\Username;
use Glimpse\Audit\AuditLog;
use Glimpse\Cli\SecretInput;
use Glimpse\Failure;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AccountPolicyTest extends TestCase
{
    public function testUsernamesAreTrimmedLowercasedAndLimitedToTheAllowedAsciiSet(): void
    {
        self::assertSame('owner.name', Username::normalize("  Owner.Name \t"));
        self::assertSame('a_b-c.9', Username::normalize('A_B-C.9'));
        self::assertSame(str_repeat('x', 32), Username::normalize(str_repeat('X', 32)));
        foreach (['ab', str_repeat('x', 33), 'two words', 'slash/name', 'ümlaut', "tab\tname", 'at@sign', '', '   '] as $invalid) {
            try {
                Username::normalize($invalid);
                self::fail('Accepted username: ' . $invalid);
            } catch (Failure $error) {
                self::assertSame('invalid_username', $error->errorCode);
            }
        }
    }

    public function testNewPasswordsKeepEveryCharacterWithinCharacterAndByteLimits(): void
    {
        $spaced = '  fifteen chars ';
        self::assertSame(16, strlen($spaced));
        $hash = Passwords::hash($spaced);
        self::assertTrue(password_verify($spaced, $hash));
        self::assertFalse(password_verify(trim($spaced), $hash));
        $unicode = str_repeat('č', 8);
        Passwords::validateNew($unicode);
        Passwords::validateNew(str_repeat('x', 128));
        foreach ([str_repeat('x', 7), str_repeat('x', 129), str_repeat('č', 7), "eight-ch\0x", "\xff" . str_repeat('x', 20)] as $invalid) {
            try {
                Passwords::validateNew($invalid);
                self::fail('Accepted an invalid password.');
            } catch (Failure $error) {
                self::assertSame('invalid_password', $error->errorCode);
            }
        }
        if (Passwords::argon2id()) {
            self::assertSame('argon2id', password_get_info($hash)['algoName']);
            Passwords::validateNew(str_repeat('😀', 128));
        } else {
            $this->expectException(Failure::class);
            Passwords::validateNew(str_repeat('č', 40));
        }
    }

    public function testSecretInputStripsOnlyLineEndingsAndRejectsAmbiguousInput(): void
    {
        self::assertSame('  secret value  ', SecretInput::read($this->stream("  secret value  \r\n"), 64));
        self::assertSame(str_repeat('x', 8), SecretInput::read($this->stream(str_repeat('x', 8) . "\n"), 8));
        foreach (["\xEF\xBB\xBFsecret", '', "\r\n", str_repeat('x', 9), str_repeat('x', 12) . "\n"] as $input) {
            try {
                SecretInput::read($this->stream($input), 8);
                self::fail('Accepted ambiguous input.');
            } catch (RuntimeException $error) {
                self::assertStringNotContainsString('secret', $error->getMessage());
            }
        }
    }

    public function testAuditMetadataIsSmallValidatedJson(): void
    {
        self::assertNull(AuditLog::metadata([]));
        self::assertSame(
            '{"repositories":2,"tokenImported":true,"role":"platform_admin","note":null}',
            AuditLog::metadata(['repositories' => 2, 'tokenImported' => true, 'role' => 'platform_admin', 'note' => null]),
        );
        $tooMany = [];
        for ($i = 0; $i < 17; $i++) {
            $tooMany['k' . $i] = $i;
        }
        foreach ([['bad key' => 1], ['value' => str_repeat('x', 129)], ['value' => "line\nbreak"], $tooMany] as $metadata) {
            try {
                AuditLog::metadata($metadata);
                self::fail('Accepted invalid audit metadata.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    /** @return resource */
    private function stream(string $content)
    {
        $stream = fopen('php://memory', 'w+b');
        if ($stream === false) {
            throw new RuntimeException('Memory stream unavailable.');
        }
        fwrite($stream, $content);
        rewind($stream);
        return $stream;
    }
}
