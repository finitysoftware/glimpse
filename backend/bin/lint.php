<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$files = [$root . '/bootstrap.php'];
foreach (['src', 'config', 'public', 'bin', 'tests'] as $directory) {
    if (!is_dir($root . '/' . $directory)) {
        continue;
    }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file instanceof SplFileInfo && $file->getExtension() === 'php' && !str_ends_with($file->getFilename(), '.local.php')) {
            $files[] = $file->getPathname();
        }
    }
}
foreach ($files as $file) {
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file), $output, $status);
    if ($status !== 0) {
        fwrite(STDERR, 'Syntax check failed: ' . $file . "\n");
        exit($status);
    }
    $output = [];
}
echo 'PHP syntax checked: ' . count($files) . " files.\n";
