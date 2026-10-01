<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$path = dirname(__DIR__) . '/config/share-key.local.php';
umask(0077);
// Exclusive creation protects existing URLs from accidental key replacement.
$file = @fopen($path, 'xb');
if ($file === false) {
    fwrite(STDERR, "Key file already exists or cannot be created. Preserve the existing key.\n");
    exit(1);
}
try {
    $content = "<?php\ndeclare(strict_types=1);\n\nreturn '" . base64_encode(random_bytes(32)) . "';\n";
    if (fwrite($file, $content) !== strlen($content) || !fflush($file)) {
        throw new RuntimeException('Could not write the key file.');
    }
    fclose($file);
    fwrite(STDOUT, "Created private/backend/config/share-key.local.php. Keep a secure backup; never overwrite it during upgrades. The key is not included in release archives.\n");
} catch (Throwable) {
    if (is_resource($file)) {
        fclose($file);
    }
    unlink($path);
    fwrite(STDERR, "Could not generate the private share key.\n");
    exit(1);
}
