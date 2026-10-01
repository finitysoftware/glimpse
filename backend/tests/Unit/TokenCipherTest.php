<?php

declare(strict_types=1);

namespace Glimpse\Tests\Unit;

use Glimpse\Failure;
use Glimpse\Settings;
use Glimpse\Source\Provider;
use Glimpse\Source\TokenCipher;
use PHPUnit\Framework\TestCase;

final class TokenCipherTest extends TestCase
{
    private const TOKEN = 'fixture_token_0123456789abcdefABCDEF';

    public function testRoundTripIsBoundToUserConnectionAndKey(): void
    {
        $cipher = new TokenCipher(base64_encode(str_repeat('k', 32)), 'gh-test-1');
        $first = $cipher->encrypt(7, 3, self::TOKEN);
        $second = $cipher->encrypt(7, 3, self::TOKEN);
        self::assertNotSame($first, $second);
        self::assertStringNotContainsString(substr(self::TOKEN, 0, 10), $first);
        self::assertSame(self::TOKEN, $cipher->decrypt(7, 3, 'gh-test-1', $first));
        $tampered = $first;
        $tampered[20] = chr(ord($tampered[20]) ^ 1);
        $otherKey = new TokenCipher(base64_encode(str_repeat('o', 32)), 'gh-test-1');
        foreach ([[$cipher, 8, 3, 'gh-test-1', $first], [$cipher, 7, 4, 'gh-test-1', $first], [$cipher, 7, 3, 'gh-test-2', $first],
            [$cipher, 7, 3, 'gh-test-1', $tampered], [$cipher, 7, 3, 'gh-test-1', substr($first, 0, 29)],
            [$cipher, 7, 3, 'gh-test-1', "\x02" . substr($first, 1)], [$otherKey, 7, 3, 'gh-test-1', $first]] as [$reader, $user, $connection, $keyId, $payload]) {
            try {
                $reader->decrypt($user, $connection, $keyId, $payload);
                self::fail('Unauthenticated token envelope was accepted.');
            } catch (Failure $error) {
                self::assertSame('github_token_unreadable', $error->errorCode);
            }
        }
    }

    public function testGitHubEnvelopesFromEarlierReleasesStayReadable(): void
    {
        $key = str_repeat('k', 32);
        $nonce = str_repeat('n', 12);
        $tag = '';
        $ciphertext = openssl_encrypt(self::TOKEN, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, "glimpse.github-token.v1\0" . '7' . "\0" . '3', 16);
        self::assertIsString($ciphertext);
        $payload = "\x01" . $nonce . $tag . $ciphertext;
        self::assertSame(self::TOKEN, (new TokenCipher(base64_encode($key), 'gh-test-1', Provider::GitHub))->decrypt(7, 3, 'gh-test-1', $payload));
    }

    public function testGitLabTokensAreBoundToTheirInstanceAndPlatform(): void
    {
        $key = base64_encode(str_repeat('k', 32));
        $gitlab = new TokenCipher($key, 'gh-test-1', Provider::GitLab);
        $payload = $gitlab->encrypt(7, 3, self::TOKEN, 'https://gitlab.example');
        self::assertSame(self::TOKEN, $gitlab->decrypt(7, 3, 'gh-test-1', $payload, 'https://gitlab.example'));
        foreach ([[$gitlab, 'https://attacker.example'], [$gitlab, 'https://gitlab.example/'], [new TokenCipher($key, 'gh-test-1', Provider::GitHub), 'https://gitlab.example']] as [$reader, $instance]) {
            try {
                $reader->decrypt(7, 3, 'gh-test-1', $payload, $instance);
                self::fail('A token was decrypted for another instance or platform.');
            } catch (Failure $error) {
                self::assertContains($error->errorCode, ['gitlab_token_unreadable', 'github_token_unreadable']);
            }
        }
        try {
            (new TokenCipher('', 'gh-test-1', Provider::GitLab))->encrypt(1, 1, self::TOKEN, 'https://gitlab.example');
            self::fail('Missing key was accepted.');
        } catch (Failure $error) {
            self::assertSame(['gitlab_token_storage_unconfigured', 'GitLab token storage has not been configured on the server.'], [$error->errorCode, $error->getMessage()]);
        }
    }

    public function testTokensArePrintableAsciiUpToTheLimit(): void
    {
        self::assertTrue(TokenCipher::validToken('x'));
        self::assertTrue(TokenCipher::validToken(str_repeat('t', 1024)));
        foreach (['', str_repeat('t', 1025), 'with space', "tab\tbed", "line\n", "\u{0161}token"] as $token) {
            self::assertFalse(TokenCipher::validToken($token));
        }
        $cipher = new TokenCipher(base64_encode(str_repeat('k', 32)), 'gh-test-1');
        self::assertSame(str_repeat('t', 1024), $cipher->decrypt(1, 1, 'gh-test-1', $cipher->encrypt(1, 1, str_repeat('t', 1024))));
        try {
            $cipher->encrypt(1, 1, 'with space');
            self::fail('Invalid token was accepted.');
        } catch (Failure $error) {
            self::assertSame('github_token_invalid_format', $error->errorCode);
        }
    }

    public function testKeyMustBeThirtyTwoBytesWithAValidIdentifier(): void
    {
        foreach ([['', 'gh-1'], ['invalid%', 'gh-1'], [base64_encode(str_repeat('k', 31)), 'gh-1'],
            [base64_encode(str_repeat('k', 32)), ''], [base64_encode(str_repeat('k', 32)), 'bad id']] as [$key, $id]) {
            $cipher = new TokenCipher($key, $id);
            self::assertFalse($cipher->configured());
            try {
                $cipher->encrypt(1, 1, self::TOKEN);
                self::fail('Invalid key was accepted.');
            } catch (Failure $error) {
                self::assertSame('github_token_storage_unconfigured', $error->errorCode);
            }
        }
    }

    public function testKeyGeneratorNeverOverwritesAndSettingsHonorOverrides(): void
    {
        $root = sys_get_temp_dir() . '/glimpse-token-key-test-' . bin2hex(random_bytes(8));
        mkdir($root . '/bin', 0700, true);
        mkdir($root . '/config', 0700);
        $script = $root . '/bin/generate-github-token-key.php';
        $keyPath = $root . '/config/github-token-key.local.php';
        $settingsPath = $root . '/config/settings.local.php';
        copy(dirname(__DIR__, 2) . '/bin/generate-github-token-key.php', $script);
        $previous = ['GLIMPSE_GITHUB_TOKEN_KEY' => getenv('GLIMPSE_GITHUB_TOKEN_KEY'), 'GLIMPSE_GITHUB_TOKEN_KEY_ID' => getenv('GLIMPSE_GITHUB_TOKEN_KEY_ID')];
        try {
            putenv('GLIMPSE_GITHUB_TOKEN_KEY');
            putenv('GLIMPSE_GITHUB_TOKEN_KEY_ID');
            exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' 2>&1', $output, $status);
            self::assertSame(0, $status);
            $generated = require $keyPath;
            self::assertIsArray($generated);
            self::assertIsString($generated['key']);
            self::assertIsString($generated['id']);
            self::assertStringNotContainsString($generated['key'], implode("\n", $output));
            $settings = Settings::load($root . '/config');
            self::assertSame([$generated['key'], $generated['id']], [$settings->githubTokenKey, $settings->githubTokenKeyId]);
            self::assertTrue((new TokenCipher($settings->githubTokenKey, $settings->githubTokenKeyId))->configured());
            self::assertNotSame($generated['key'], $settings->shareEncryptionKey);
            $before = file_get_contents($keyPath);
            exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' 2>&1', $output, $status);
            self::assertSame(1, $status);
            self::assertSame($before, file_get_contents($keyPath));
            $configured = base64_encode(str_repeat('x', 32));
            file_put_contents($settingsPath, '<?php return ' . var_export(['github_token_key' => $configured, 'github_token_key_id' => 'configured-1'], true) . ';');
            self::assertSame([$configured, 'configured-1'], [Settings::load($root . '/config')->githubTokenKey, Settings::load($root . '/config')->githubTokenKeyId]);
            putenv('GLIMPSE_GITHUB_TOKEN_KEY=');
            self::assertSame('', Settings::load($root . '/config')->githubTokenKey);
        } finally {
            foreach ($previous as $name => $value) {
                putenv($value === false ? $name : $name . '=' . $value);
            }
            foreach ([$script, $keyPath, $settingsPath] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
            rmdir($root . '/bin');
            rmdir($root . '/config');
            rmdir($root);
        }
    }
}
