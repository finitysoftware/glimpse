<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$path = dirname(__DIR__) . '/config/github-token-key.local.php';
umask(0077);
// Exclusive creation protects saved GitHub tokens from accidental key replacement.
$file = @fopen($path, 'xb');
if ($file === false) {
    fwrite(STDERR, "Key file already exists or cannot be created. Preserve the existing key.\n");
    exit(1);
}
try {
    $id = 'gh-' . gmdate('Ymd') . '-' . bin2hex(random_bytes(4));
    $content = "<?php\ndeclare(strict_types=1);\n\nreturn ['id' => '" . $id . "', 'key' => '" . base64_encode(random_bytes(32)) . "'];\n";
    if (fwrite($file, $content) !== strlen($content) || !fflush($file)) {
        throw new RuntimeException('Could not write the key file.');
    }
    fclose($file);
    fwrite(STDOUT, "Created private/backend/config/github-token-key.local.php (key ID " . $id . "). Keep a secure backup separate from the share key; never overwrite it during upgrades. The key is not included in release archives.\n");
} catch (Throwable) {
    if (is_resource($file)) {
        fclose($file);
    }
    unlink($path);
    fwrite(STDERR, "Could not generate the private GitHub token key.\n");
    exit(1);
}
