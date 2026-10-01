<?php

declare(strict_types=1);

namespace Glimpse\Tests\Unit;

use Glimpse\Settings;
use Glimpse\Sharing\ShareCipher;
use PHPUnit\Framework\TestCase;

final class ShareKeyConfigurationTest extends TestCase
{
    public function testPrivateKeyGeneratorDoesNotOverwriteAndSettingsHonorExplicitOverrides(): void
    {
        $root = sys_get_temp_dir() . '/glimpse-key-test-' . bin2hex(random_bytes(8));
        mkdir($root . '/bin', 0700, true);
        mkdir($root . '/config', 0700);
        $script = $root . '/bin/generate-share-key.php';
        $keyPath = $root . '/config/share-key.local.php';
        $settingsPath = $root . '/config/settings.local.php';
        copy(dirname(__DIR__, 2) . '/bin/generate-share-key.php', $script);
        $previous = getenv('GLIMPSE_SHARE_ENCRYPTION_KEY');
        try {
            putenv('GLIMPSE_SHARE_ENCRYPTION_KEY');
            exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' 2>&1', $output, $status);
            self::assertSame(0, $status);
            $generated = require $keyPath;
            self::assertIsString($generated);
            self::assertTrue((new ShareCipher($generated))->configured());
            self::assertStringNotContainsString($generated, implode("\n", $output));
            self::assertSame($generated, Settings::load($root . '/config')->shareEncryptionKey);
            $before = file_get_contents($keyPath);
            exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' 2>&1', $output, $status);
            self::assertSame(1, $status);
            self::assertSame($before, file_get_contents($keyPath));
            $configured = base64_encode(str_repeat('x', 32));
            file_put_contents($settingsPath, '<?php return ' . var_export(['share_encryption_key' => $configured], true) . ';');
            self::assertSame($configured, Settings::load($root . '/config')->shareEncryptionKey);
            putenv('GLIMPSE_SHARE_ENCRYPTION_KEY=' . $generated);
            self::assertSame($generated, Settings::load($root . '/config')->shareEncryptionKey);
            putenv('GLIMPSE_SHARE_ENCRYPTION_KEY=');
            self::assertSame('', Settings::load($root . '/config')->shareEncryptionKey);
        } finally {
            putenv($previous === false ? 'GLIMPSE_SHARE_ENCRYPTION_KEY' : 'GLIMPSE_SHARE_ENCRYPTION_KEY=' . $previous);
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
